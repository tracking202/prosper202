<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

/**
 * SKAN encodings: which conversion value means which event, and what it is
 * worth (plan §4.5).
 *
 * An encoding maps either one fine conversion value (0–63) or one coarse
 * value (low/medium/high) — never both — to an event name and revenue.
 * `registration_id` names the iOS registration it applies to, or is 0 for
 * the account-wide set; an app's own encodings override the account-wide
 * ones for that app. The report decodes through them
 * (AppPostbacksController::resolveEncoding()) and GET /apps/schema encodes
 * through them, so the two directions cannot drift.
 *
 * An encoding must name a registration that exists, belongs to the caller
 * and is an iOS app, or be account-wide: SKAdNetwork values mean nothing on
 * Android, and an encoding for an app nobody registered would decode
 * nothing until someone did.
 */
class AppSkanEncodingsController extends Controller
{
    protected function tableName(): string
    {
        return '202_app_skan_encodings';
    }

    protected function primaryKey(): string
    {
        return 'encoding_id';
    }

    /** Largest value the revenue decimal(11,5) column can hold. */
    private const MAX_REVENUE = 999999.99999;

    protected function fields(): array
    {
        return [
            'registration_id' => ['type' => 'i', 'default' => 0],
            'fine_value'      => ['type' => 'i'],
            'coarse_value'    => ['type' => 's', 'max_length' => 6, 'allowed' => ['low', 'medium', 'high']],
            'event_name'      => ['type' => 's', 'required' => true, 'max_length' => 255],
            'revenue'         => ['type' => 'd', 'default' => 0],
        ];
    }

    /**
     * Which of fine_value/coarse_value the in-flight update() explicitly set
     * to null. validatePayload() drops nulls, so without this capture a
     * kind-switch ({"fine_value": null, "coarse_value": "high"}) would
     * validate a row state the UPDATE never writes.
     *
     * @var array<string, true>
     */
    private array $pendingClears = [];

    #[\Override]
    protected function duplicateKeyConflictMessage(): ?string
    {
        // Two concurrent writes can both pass the beforeCreate/beforeUpdate
        // pre-check; the UNIQUE (user, registration, value) keys decide.
        return 'An encoding for this conversion value already exists for this registration.';
    }

    #[\Override]
    public function create(array $payload): array
    {
        // The RAW value, before Controller::create() hands the payload to
        // validatePayload() and the 'i' field definition casts it: by then
        // 1.5 is 1, '1e2' is 100 and a 20-digit string is PHP_INT_MAX. A
        // guard placed after that scopes the encoding to a DIFFERENT
        // registration and still answers 201 — and GET /apps/schema then
        // serves it to that app's builds (CLAUDE.md #18). An absent
        // registration_id is left to the field's declared default, the
        // account-wide set.
        if (array_key_exists('registration_id', $payload)) {
            self::assertRegistrationScope($payload['registration_id']);
        }
        return parent::create($payload);
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $this->assertEncodingShape($payload);
        $this->assertRegistrationIsMineAndIos((int)($payload['registration_id'] ?? 0));
        $this->assertNoDuplicateEncoding($payload);
        $now = time();
        return [
            'created_at' => ['type' => 'i', 'value' => $now],
            'updated_at' => ['type' => 'i', 'value' => $now],
        ];
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        // Raw, for the same reason create() checks it raw. registration_id is
        // NOT NULL with a declared default, so it has no "clear" spelling: an
        // explicit null is bad input here, not a sentinel.
        if (array_key_exists('registration_id', $payload)) {
            self::assertRegistrationScope($payload['registration_id']);
        }

        // An encoding holds exactly one kind of value, so switching kinds
        // needs the old kind cleared and the new one set in ONE request.
        $this->pendingClears = [];
        foreach (['fine_value', 'coarse_value'] as $col) {
            if (array_key_exists($col, $payload) && $payload[$col] === null) {
                $this->pendingClears[$col] = true;
            }
        }

        try {
            return parent::update($id, $payload);
        } finally {
            $this->pendingClears = [];
        }
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        // Validate the row as it will exist after the update, not just the
        // patch: adding coarse_value to a fine encoding must fail even though
        // neither field alone is invalid.
        $current = (array)$this->get($id)['data'];
        $effective = [
            'registration_id' => $current['registration_id'],
            'fine_value' => $current['fine_value'],
            'coarse_value' => $current['coarse_value'],
            'revenue' => $current['revenue'],
        ];
        foreach ($effective as $col => $unused) {
            if (isset($this->pendingClears[$col])) {
                $effective[$col] = null;
            } elseif (array_key_exists($col, $payload)) {
                $effective[$col] = $payload[$col];
            }
        }
        if ($this->pendingClears !== [] && $effective['fine_value'] === null && $effective['coarse_value'] === null) {
            throw new ValidationException('An encoding always maps exactly one conversion value', [
                'fine_value' => 'To switch kinds, send the explicit null and the replacement together, '
                    . 'e.g. {"fine_value": null, "coarse_value": "high"}. To remove the encoding, delete it.',
            ]);
        }
        $this->assertEncodingShape($effective);
        if (array_key_exists('registration_id', $payload)) {
            $this->assertRegistrationIsMineAndIos((int)$effective['registration_id']);
        }
        $this->assertNoDuplicateEncoding($effective, excludeId: (int)$id);

        $extras = [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
        if (isset($this->pendingClears['fine_value'])) {
            $extras['fine_value'] = ['type' => 'i', 'value' => null];
        }
        if (isset($this->pendingClears['coarse_value'])) {
            $extras['coarse_value'] = ['type' => 's', 'value' => null];
        }
        return $extras;
    }

    /**
     * @param array<string, mixed> $encoding Values after validation/merge;
     *                                       fine and coarse may be null or absent.
     */
    private function assertEncodingShape(array $encoding): void
    {
        $fine = $encoding['fine_value'] ?? null;
        $coarse = $encoding['coarse_value'] ?? null;

        if (($fine === null) === ($coarse === null)) {
            throw new ValidationException('An encoding maps exactly one conversion value', [
                'fine_value' => 'Set fine_value (0-63) or coarse_value (low/medium/high), not both and not neither',
            ]);
        }
        if ($fine !== null && ((int)$fine < 0 || (int)$fine > 63)) {
            throw new ValidationException('Invalid fine conversion value', [
                'fine_value' => 'Must be an integer from 0 to 63 (SKAN fine values are 6 bits)',
            ]);
        }
        if (array_key_exists('revenue', $encoding) && $encoding['revenue'] !== null) {
            // Bounded on both sides against the decimal(11,5) column: an
            // out-of-range value would otherwise come back as a 500 (strict
            // mode) or be silently clamped (non-strict).
            $revenue = (float)$encoding['revenue'];
            if ($revenue < 0 || $revenue > self::MAX_REVENUE) {
                throw new ValidationException('Invalid revenue', [
                    'revenue' => 'Must be between 0 and ' . self::MAX_REVENUE,
                ]);
            }
        }
        self::assertRegistrationScope($encoding['registration_id'] ?? 0);
    }

    private static function assertRegistrationScope(mixed $registrationId): void
    {
        if (!self::isRegistrationScope($registrationId)) {
            throw new ValidationException('Invalid registration_id', [
                'registration_id' => 'Must be a registration id from GET /apps, or 0 for the account-wide encodings',
            ]);
        }
    }

    /**
     * A scope this table can be keyed on: a registration id, or 0 — the
     * account-wide set. A PHP int (a JSON number) or a string of digits the
     * int cast leaves unchanged (the CLI and form bodies send it that way).
     *
     * 1.5, '1e2', ' 1' and a 20-digit string are the values the 'i' cast
     * silently rewrites into some OTHER registration. null is refused for a
     * different reason: validatePayload() drops it, so it resolved to the
     * field's default — 0, the widest scope there is.
     */
    private static function isRegistrationScope(mixed $registrationId): bool
    {
        if (is_int($registrationId)) {
            return $registrationId >= 0;
        }
        if (!is_string($registrationId) || preg_match('/^\d+$/D', $registrationId) !== 1) {
            return false;
        }
        $digits = ltrim($registrationId, '0');
        if ($digits === '') {
            return true; // '0', '00' — the account-wide set
        }
        return $digits === (string)(int)$digits;
    }

    /**
     * A non-zero scope must be the caller's own iOS registration. Answered as
     * a 422 on the field rather than a 404: the encoding is what is being
     * written, and its registration_id is the value that is wrong.
     */
    private function assertRegistrationIsMineAndIos(int $registrationId): void
    {
        if ($registrationId === 0) {
            return;
        }
        $stmt = $this->prepare('SELECT platform FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Registration lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Registration lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!is_array($row)) {
            throw new ValidationException('Invalid registration_id', [
                'registration_id' => 'No registration ' . $registrationId . ' in this account. Use an id from GET /apps, '
                    . 'or 0 for the account-wide encodings.',
            ]);
        }
        if ((string)$row['platform'] !== AppIdentity::IOS) {
            throw new ValidationException('Invalid registration_id', [
                'registration_id' => 'Registration ' . $registrationId . ' is '
                    . ((string)$row['platform'] === AppIdentity::ANDROID ? 'an Android' : 'a ' . $row['platform'])
                    . ' app; SKAN encodings apply to iOS apps only.',
            ]);
        }
    }

    /** @param array<string, mixed> $encoding */
    private function assertNoDuplicateEncoding(array $encoding, ?int $excludeId = null): void
    {
        $registrationId = (int)($encoding['registration_id'] ?? 0);
        $fine = $encoding['fine_value'] ?? null;

        if ($fine !== null) {
            $sql = 'SELECT encoding_id FROM 202_app_skan_encodings WHERE user_id = ? AND registration_id = ? AND fine_value = ? LIMIT 1';
            $value = (int)$fine;
            $types = 'iii';
        } else {
            $sql = 'SELECT encoding_id FROM 202_app_skan_encodings WHERE user_id = ? AND registration_id = ? AND coarse_value = ? LIMIT 1';
            $value = (string)$encoding['coarse_value'];
            $types = 'iis';
        }

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, $this->userId, $registrationId, $value);
        $this->execute($stmt, 'Encoding lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Encoding lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (is_array($row) && ($excludeId === null || (int)$row['encoding_id'] !== $excludeId)) {
            throw new ConflictException('An encoding for this conversion value already exists for this registration.');
        }
    }
}
