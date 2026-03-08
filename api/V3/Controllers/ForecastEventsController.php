<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class ForecastEventsController extends Controller
{
    protected function tableName(): string { return '202_forecast_events'; }
    protected function primaryKey(): string { return 'event_id'; }

    protected function listOrderBy(): string { return 'event_date ASC'; }

    protected function fields(): array
    {
        return [
            'event_name'          => ['type' => 's', 'required' => true, 'max_length' => 255],
            'event_date'          => ['type' => 's', 'required' => true, 'max_length' => 10],
            'end_date'            => ['type' => 's', 'max_length' => 10],
            'recurrence'          => ['type' => 's', 'max_length' => 10],
            'impact_type'         => ['type' => 's', 'max_length' => 10],
            'expected_impact_pct' => ['type' => 'd'],
            'lead_days'           => ['type' => 'i'],
            'lag_days'            => ['type' => 'i'],
            'tags'                => ['type' => 's', 'max_length' => 500],
            'notes'               => ['type' => 's', 'max_length' => 500],
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
    protected function beforeUpdate(int|string $id, array $payload): void
    {
        // Touch updated_at on every update.
        $stmt = $this->prepare(
            sprintf('UPDATE %s SET updated_at = ? WHERE %s = ?', $this->tableName(), $this->primaryKey())
        );
        $this->bind($stmt, 'ii', time(), (int)$id);
        $this->execute($stmt, 'Failed to update timestamp');
        $stmt->close();
    }
}
