<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

/**
 * Conversion-value decoding rules.
 *
 * A rule maps either one fine conversion value (0–63) or one coarse value
 * (low/medium/high) — never both — to an event name and revenue amount.
 * app_id = 0 rules are the account-wide defaults; rules for a specific App
 * Store id override them for that app. The SKAN report resolves values
 * through these rules (see AttributionPostbacksController::resolveRule()).
 *
 * The rule set is the server-side mirror of the conversion-value schema the
 * app itself implements when it calls SKAdNetwork's
 * updatePostbackConversionValue — keep the two in sync or reports decode to
 * the wrong events.
 */
class AttributionConversionValuesController extends Controller
{
    protected function tableName(): string
    {
        return '202_attribution_conversion_values';
    }

    protected function primaryKey(): string
    {
        return 'rule_id';
    }

    /** Largest value the revenue decimal(11,5) column can hold. */
    private const MAX_REVENUE = 999999.99999;

    protected function fields(): array
    {
        return [
            'app_id'       => ['type' => 'i', 'default' => 0],
            'fine_value'   => ['type' => 'i'],
            'coarse_value' => ['type' => 's', 'max_length' => 6, 'allowed' => ['low', 'medium', 'high']],
            'event_name'   => ['type' => 's', 'required' => true, 'max_length' => 255],
            'revenue'      => ['type' => 'd', 'default' => 0],
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
        // pre-check; the UNIQUE (user, app, value) keys decide.
        return 'A rule for this conversion value already exists for this app.';
    }

    #[\Override]
    public function create(array $payload): array
    {
        // The RAW value, before Controller::create() hands the payload to
        // validatePayload() and the 'i' field definition casts it: by the time
        // beforeCreate() runs, 1.5 is 1, '1e2' is 100, ' 1' is 1 and a 20-digit
        // string is PHP_INT_MAX. A guard placed there scopes the rule to a
        // DIFFERENT app and still answers 201 — and GET /attribution/schema
        // then serves that rule to that app's builds (error patterns #4 and
        // #12). An absent app_id is left to the field's declared default,
        // which is the account-wide scope.
        if (array_key_exists('app_id', $payload)) {
            self::assertAppScope($payload['app_id']);
        }
        return parent::create($payload);
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $this->assertRuleShape($payload);
        $this->assertNoDuplicateRule($payload);
        return [
            'created_at' => ['type' => 'i', 'value' => time()],
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        // Raw, for the same reason create() checks it raw: everything past
        // parent::update() — beforeUpdate() included — only ever sees the cast
        // int. app_id is NOT NULL with a declared default, so unlike the two
        // value columns below it has no "clear" spelling: an explicit null is
        // bad input here, not a sentinel.
        if (array_key_exists('app_id', $payload)) {
            self::assertAppScope($payload['app_id']);
        }

        // A rule holds exactly one kind of value, so switching kinds needs
        // the old kind cleared and the new one set in ONE request — an
        // explicit null alongside the replacement value. Capture the nulls
        // here (base validation drops them); beforeUpdate() validates the row
        // as it will exist afterwards and applies them via the extras, which
        // bind SQL NULL.
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
        // patch: adding coarse_value to a fine rule must fail even though
        // neither field alone is invalid. $payload here is the cleaned
        // payload (explicit nulls already dropped); pendingClears restores
        // their meaning.
        $current = (array)$this->get($id)['data'];
        $effective = [
            'app_id' => $current['app_id'],
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
            // The clear arrived without the replacement kind — whatever else
            // the payload carried — so name the one-request switch syntax
            // rather than the generic shape error.
            throw new ValidationException('A rule always maps exactly one conversion value', [
                'fine_value' => 'To switch kinds, send the explicit null and the replacement together, '
                    . 'e.g. {"fine_value": null, "coarse_value": "high"}. To remove the rule, delete it.',
            ]);
        }
        $this->assertRuleShape($effective);
        $this->assertNoDuplicateRule($effective, excludeId: (int)$id);

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
     * @param array<string, mixed> $rule Values after validation/merge; fine
     *                                   and coarse may be null or absent.
     */
    private function assertRuleShape(array $rule): void
    {
        $fine = $rule['fine_value'] ?? null;
        $coarse = $rule['coarse_value'] ?? null;

        if (($fine === null) === ($coarse === null)) {
            throw new ValidationException('A rule maps exactly one conversion value', [
                'fine_value' => 'Set fine_value (0-63) or coarse_value (low/medium/high), not both and not neither',
            ]);
        }
        if ($fine !== null && ((int)$fine < 0 || (int)$fine > 63)) {
            throw new ValidationException('Invalid fine conversion value', [
                'fine_value' => 'Must be an integer from 0 to 63 (SKAN fine values are 6 bits)',
            ]);
        }
        if (array_key_exists('revenue', $rule) && $rule['revenue'] !== null) {
            // Bounded on both sides against the decimal(11,5) column: an
            // out-of-range value would otherwise reach the INSERT and come
            // back as a 500 (strict mode) or be silently clamped to a
            // number the report then decodes as revenue (non-strict).
            $revenue = (float)$rule['revenue'];
            if ($revenue < 0 || $revenue > self::MAX_REVENUE) {
                throw new ValidationException('Invalid revenue', [
                    'revenue' => 'Must be between 0 and ' . self::MAX_REVENUE,
                ]);
            }
        }
        self::assertAppScope($rule['app_id'] ?? 0);
    }

    private static function assertAppScope(mixed $appId): void
    {
        if (!self::isAppScope($appId)) {
            throw new ValidationException('Invalid app_id', [
                'app_id' => 'Must be an App Store id, or 0 for the account-wide default rules',
            ]);
        }
    }

    /**
     * An app scope this table can be keyed on: an App Store id, or 0 — the
     * reserved account-wide-default scope.
     *
     * Deliberately the same spellings AttributionAppsController::isUsableAppId()
     * accepts — a PHP int (a JSON body's number) and a string of digits the int
     * cast leaves unchanged (the Go CLI and form-encoded bodies send it that
     * way) — with one difference: 0 and '0' are valid here and refused there,
     * because the registry reserves 0 for exactly this scope.
     *
     * 1.5, '1e2', '525463029.9', ' 1' and a 20-digit string are the ones the
     * 'i' cast silently rewrote into some OTHER app's scope, which
     * GET /attribution/schema then served to that app. null is refused for a
     * different reason: validatePayload() drops it, so it resolved to the
     * field's default — 0, the widest scope there is. true and an array the
     * base validation would refuse anyway; refusing them here just makes the
     * answer one message instead of two.
     *
     * Called on the RAW payload value by create()/update(), and again from
     * assertRuleShape() on the cast payload / the row merged with the DB.
     */
    private static function isAppScope(mixed $appId): bool
    {
        if (is_int($appId)) {
            return $appId >= 0;
        }
        if (!is_string($appId) || preg_match('/^\d+$/D', $appId) !== 1) {
            return false;
        }
        $digits = ltrim($appId, '0');
        if ($digits === '') {
            return true; // '0', '00' — the account-wide default scope
        }
        // A digit string PHP cannot represent saturates to PHP_INT_MAX under
        // the cast, so the round-trip refuses it rather than scoping the rule
        // to app 9223372036854775807.
        return $digits === (string)(int)$digits;
    }

    /** @param array<string, mixed> $rule */
    private function assertNoDuplicateRule(array $rule, ?int $excludeId = null): void
    {
        $appId = (int)($rule['app_id'] ?? 0);
        $fine = $rule['fine_value'] ?? null;

        if ($fine !== null) {
            $sql = 'SELECT rule_id FROM 202_attribution_conversion_values WHERE user_id = ? AND app_id = ? AND fine_value = ? LIMIT 1';
            $value = (int)$fine;
            $types = 'iii';
        } else {
            $sql = 'SELECT rule_id FROM 202_attribution_conversion_values WHERE user_id = ? AND app_id = ? AND coarse_value = ? LIMIT 1';
            $value = (string)$rule['coarse_value'];
            $types = 'iis';
        }

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, $this->userId, $appId, $value);
        $this->execute($stmt, 'Rule lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Rule lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (is_array($row) && ($excludeId === null || (int)$row['rule_id'] !== $excludeId)) {
            throw new ConflictException('A rule for this conversion value already exists for this app.');
        }
    }
}
