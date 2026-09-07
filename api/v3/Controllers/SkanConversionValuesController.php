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
 * through these rules (see SkanPostbacksController::resolveRule()).
 *
 * The rule set is the server-side mirror of the conversion-value schema the
 * app itself implements when it calls SKAdNetwork's
 * updatePostbackConversionValue — keep the two in sync or reports decode to
 * the wrong events.
 */
class SkanConversionValuesController extends Controller
{
    protected function tableName(): string
    {
        return '202_skan_conversion_values';
    }

    protected function primaryKey(): string
    {
        return 'rule_id';
    }

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
        if (array_key_exists('revenue', $rule) && $rule['revenue'] !== null && (float)$rule['revenue'] < 0) {
            throw new ValidationException('Invalid revenue', [
                'revenue' => 'Must be zero or a positive amount',
            ]);
        }
        if ((int)($rule['app_id'] ?? 0) < 0) {
            throw new ValidationException('Invalid app_id', [
                'app_id' => 'Must be an App Store id, or 0 for the account-wide default rules',
            ]);
        }
    }

    /** @param array<string, mixed> $rule */
    private function assertNoDuplicateRule(array $rule, ?int $excludeId = null): void
    {
        $appId = (int)($rule['app_id'] ?? 0);
        $fine = $rule['fine_value'] ?? null;

        if ($fine !== null) {
            $sql = 'SELECT rule_id FROM 202_skan_conversion_values WHERE user_id = ? AND app_id = ? AND fine_value = ? LIMIT 1';
            $value = (int)$fine;
            $types = 'iii';
        } else {
            $sql = 'SELECT rule_id FROM 202_skan_conversion_values WHERE user_id = ? AND app_id = ? AND coarse_value = ? LIMIT 1';
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
