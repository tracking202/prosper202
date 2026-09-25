<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\InvalidGoalDefinition;

/**
 * SKAN encodings: which conversion value means which goal was reached
 * (plan §4.5). What an outcome is worth is the goal's; how iOS carries it in
 * six bits is the encoding's.
 *
 * An encoding maps either one fine conversion value (0–63) or one coarse
 * value (low/medium/high) — never both — to a goal, with an optional
 * `revenue_override` for tiered decoding (two values meaning one goal at two
 * prices). `registration_id` names the iOS registration it applies to, or is
 * 0 for the account-wide set; an app's own encodings override the
 * account-wide ones for that app. The report decodes through them
 * (AppPostbacksController::resolveEncoding()) and GET /apps/schema encodes
 * through them, so the two directions cannot drift.
 *
 * An encoding must name a registration that exists, belongs to the caller
 * and is an iOS app, or be account-wide: SKAdNetwork values mean nothing on
 * Android, and an encoding for an app nobody registered would decode
 * nothing until someone did. Its goal must be the caller's, live, and owned
 * by that registration or by the account (an account-wide encoding names an
 * account goal); and until the on-device evaluator ships (PR 8) the goal
 * must be a plain event goal, because the iOS SDK encodes by event name.
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

    protected function fields(): array
    {
        return [
            'registration_id'  => ['type' => 'i', 'default' => 0],
            'fine_value'       => ['type' => 'i'],
            'coarse_value'     => ['type' => 's', 'max_length' => 6, 'allowed' => ['low', 'medium', 'high']],
            'goal_id'          => ['type' => 'i', 'required' => true],
            'revenue_override' => ['type' => 'd'],
        ];
    }

    /**
     * Which of fine_value/coarse_value/revenue_override the in-flight
     * update() explicitly set to null. validatePayload() drops nulls, so
     * without this capture a kind-switch ({"fine_value": null,
     * "coarse_value": "high"}) would validate a row state the UPDATE never
     * writes, and a cleared override would silently stay.
     *
     * @var array<string, true>
     */
    private array $pendingClears = [];

    /**
     * The raw body's shape, checked before validatePayload() casts anything
     * (CLAUDE.md #18) and before it drops fields it does not know (#4): an
     * encoding written the old way (event_name, revenue) is refused by name,
     * not stored without its event.
     *
     * @param array<string, mixed> $payload
     */
    private static function assertRawBody(array $payload): void
    {
        $allowed = ['registration_id', 'fine_value', 'coarse_value', 'goal_id', 'revenue_override'];
        $errors = [];
        foreach (array_keys($payload) as $key) {
            if (in_array((string) $key, ['event_name', 'revenue'], true)) {
                $errors[(string) $key] = 'An encoding names a goal now: send goal_id (a goal from GET /goals) and, for tiered '
                    . 'decoding, revenue_override. The goal says what reaching it is worth.';
            } elseif (!in_array((string) $key, $allowed, true)) {
                $errors[(string) $key] = 'is not accepted here (accepted: ' . implode(', ', $allowed) . ')';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Unknown field', $errors);
        }
        if (array_key_exists('registration_id', $payload)) {
            self::assertRegistrationScope($payload['registration_id']);
        }
        if (array_key_exists('goal_id', $payload) && $payload['goal_id'] !== null && !self::isPositiveId($payload['goal_id'])) {
            throw new ValidationException('Invalid goal_id', ['goal_id' => 'Must be a goal id from GET /goals']);
        }
        if (array_key_exists('revenue_override', $payload) && $payload['revenue_override'] !== null
            && GoalDefinition::amountUnits($payload['revenue_override']) === null) {
            throw new ValidationException('Invalid revenue_override', [
                'revenue_override' => 'Must be an amount from 0 to 999999.99999 with at most 5 decimal places, or null for the goal\'s own value',
            ]);
        }
    }

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
        // The RAW values, before Controller::create() hands the payload to
        // validatePayload() and the 'i' field definition casts them: by then
        // 1.5 is 1, '1e2' is 100 and a 20-digit string is PHP_INT_MAX. A
        // guard placed after that scopes the encoding to a DIFFERENT
        // registration (or goal) and still answers 201 — and GET /apps/schema
        // then serves it to that app's builds (CLAUDE.md #18). An absent
        // registration_id is left to the field's declared default, the
        // account-wide set.
        self::assertRawBody($payload);
        return parent::create($payload);
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $this->assertEncodingShape($payload);
        $this->assertRegistrationIsMineAndIos((int)($payload['registration_id'] ?? 0));
        $this->assertGoalFits((int)$payload['goal_id'], (int)($payload['registration_id'] ?? 0));
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
        // Raw, for the same reason create() checks it raw. registration_id
        // and goal_id are NOT NULL, so they have no "clear" spelling: an
        // explicit null is bad input here, not a sentinel.
        self::assertRawBody($payload);
        if (array_key_exists('goal_id', $payload) && $payload['goal_id'] === null) {
            throw new ValidationException('Invalid goal_id', ['goal_id' => 'An encoding always names a goal; send another goal id, or delete the encoding.']);
        }

        // An encoding holds exactly one kind of value, so switching kinds
        // needs the old kind cleared and the new one set in ONE request.
        $this->pendingClears = [];
        foreach (['fine_value', 'coarse_value', 'revenue_override'] as $col) {
            if (array_key_exists($col, $payload) && $payload[$col] === null) {
                $this->pendingClears[$col] = true;
            }
        }

        // Clearing only the override leaves the base class no column to SET,
        // and it refuses an update with none; re-sending the current goal is
        // the no-op that lets the clear through.
        if (isset($this->pendingClears['revenue_override']) && array_filter($payload, static fn ($v) => $v !== null) === []) {
            $payload['goal_id'] = (int)((array)$this->get($id)['data'])['goal_id'];
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
            'goal_id' => $current['goal_id'],
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
        if (array_key_exists('registration_id', $payload) || array_key_exists('goal_id', $payload)) {
            $this->assertGoalFits((int)$effective['goal_id'], (int)$effective['registration_id']);
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
        if (isset($this->pendingClears['revenue_override'])) {
            $extras['revenue_override'] = ['type' => 'd', 'value' => null];
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
        self::assertRegistrationScope($encoding['registration_id'] ?? 0);
    }

    private static function isPositiveId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/D', $value) === 1 && (string)(int)$value === $value;
    }

    /**
     * The goal is the caller's, live, owned by the encoding's registration
     * or by the account (and by the account when the encoding is
     * account-wide), and a plain event goal. Answered as a 422 on goal_id:
     * it is the value that is wrong.
     */
    private function assertGoalFits(int $goalId, int $registrationId): void
    {
        $stmt = $this->prepare(
            'SELECT g.scope, g.scope_id, g.archived_at, v.definition FROM 202_goals g
             JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE g.goal_id = ? AND g.user_id = ? LIMIT 1'
        );
        $this->bind($stmt, 'ii', $goalId, $this->userId);
        $this->execute($stmt, 'Goal lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Goal lookup failed');
        }
        $goal = $result->fetch_assoc();
        $stmt->close();

        if (!is_array($goal) || $goal['archived_at'] !== null) {
            throw new ValidationException('Invalid goal_id', [
                'goal_id' => 'No live goal ' . $goalId . ' in this account. Use an id from GET /goals'
                    . ($registrationId > 0 ? '?registration_id=' . $registrationId : '?scope=account') . '.',
            ]);
        }
        $scope = (string)$goal['scope'];
        $fits = $scope === GoalScope::ACCOUNT->value
            || ($registrationId > 0 && $scope === GoalScope::REGISTRATION->value && (int)$goal['scope_id'] === $registrationId);
        if (!$fits) {
            throw new ValidationException('Invalid goal_id', [
                'goal_id' => $registrationId > 0
                    ? 'Goal ' . $goalId . ' belongs to another ' . $scope . '; an encoding for registration ' . $registrationId
                        . ' names one of that app\'s goals or an account goal.'
                    : 'An account-wide encoding (registration_id 0) names an account goal; goal ' . $goalId . ' belongs to a ' . $scope . '.',
            ]);
        }
        try {
            $definition = GoalDefinition::fromJson((string)$goal['definition'], $goalId);
        } catch (InvalidGoalDefinition) {
            throw new ValidationException('Invalid goal_id', ['goal_id' => 'Goal ' . $goalId . '\'s current definition is invalid; fix the goal first.']);
        }
        if (!$definition->isPlainEvent()) {
            throw new ValidationException('Invalid goal_id', [
                'goal_id' => 'Goal ' . $goalId . ' is not a plain event goal (one event, no where, count 1, no after, no within, repeat once). '
                    . 'Until the on-device evaluator ships, the iOS SDK sets a conversion value by event name, so an encoding can name only such a goal.',
            ]);
        }
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
