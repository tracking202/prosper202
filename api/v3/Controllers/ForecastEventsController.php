<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class ForecastEventsController extends Controller
{
    protected function tableName(): string { return '202_forecast_events'; }
    protected function primaryKey(): string { return 'event_id'; }

    // event_id breaks same-date ties so offset/cursor pages stay stable.
    protected function listOrderBy(): string { return 'event_date ASC, event_id ASC'; }

    protected function fields(): array
    {
        return [
            'event_name'          => ['type' => 's', 'required' => true, 'max_length' => 255],
            // DATE columns: a real YYYY-MM-DD day (Controller::isDate()).
            // end_date is cleared with null; "" is refused, not stored.
            'event_date'          => ['type' => 's', 'format' => 'date', 'required' => true],
            'end_date'            => ['type' => 's', 'format' => 'date', 'nullable' => true],
            'recurrence'          => ['type' => 's', 'max_length' => 10, 'allowed' => ['none', 'monthly', 'yearly', 'custom']],
            'impact_type'         => ['type' => 's', 'max_length' => 10, 'allowed' => ['boost', 'suppress', 'neutral']],
            'expected_impact_pct' => ['type' => 'd', 'nullable' => true, 'range' => [-999999.99, 999999.99]],
            'lead_days'           => ['type' => 'i', 'range' => self::INT],
            'lag_days'            => ['type' => 'i', 'range' => self::INT],
            'tags'                => ['type' => 's', 'nullable' => true, 'max_length' => 500],
            'notes'               => ['type' => 's', 'nullable' => true, 'max_length' => 500],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'created_at' => ['type' => 'i', 'value' => time()],
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        return [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    protected function filterCondition(string $field, array $fieldDef, mixed $value): array
    {
        if ($field === 'tags') {
            // Tags are stored as a comma-separated list ("us-holidays,retail");
            // match by membership rather than whole-column equality, tolerating
            // spaces after commas.
            return [
                "CONCAT(',', REPLACE(tags, ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')",
                trim((string)$value),
                's',
            ];
        }
        return parent::filterCondition($field, $fieldDef, $value);
    }
}
