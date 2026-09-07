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

    #[\Override]
    public function create(array $payload): array
    {
        try {
            return parent::create($payload);
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                throw new ConflictException('A rule for this conversion value already exists for this app.');
            }
            throw $e;
        }
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
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        // Validate the row as it will exist after the update, not just the
        // patch: adding coarse_value to a fine rule must fail even though
        // neither field alone is invalid.
        $current = (array)$this->get($id)['data'];
        $effective = [
            'app_id' => array_key_exists('app_id', $payload) ? $payload['app_id'] : $current['app_id'],
            'fine_value' => array_key_exists('fine_value', $payload) ? $payload['fine_value'] : $current['fine_value'],
            'coarse_value' => array_key_exists('coarse_value', $payload) ? $payload['coarse_value'] : $current['coarse_value'],
        ];
        $this->assertRuleShape($effective);
        $this->assertNoDuplicateRule($effective, excludeId: (int)$id);
        return [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        try {
            return parent::update($id, $payload);
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                throw new ConflictException('A rule for this conversion value already exists for this app.');
            }
            throw $e;
        }
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
