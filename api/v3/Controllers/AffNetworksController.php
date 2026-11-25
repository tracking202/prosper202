<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class AffNetworksController extends Controller
{
    protected function tableName(): string { return '202_aff_networks'; }
    protected function primaryKey(): string { return 'aff_network_id'; }

    #[\Override]
    protected function listSearchColumns(): array
    {
        return ['aff_network_name'];
    }

    #[\Override]
    protected function listSortFields(): array
    {
        return ['aff_network_id' => 'aff_network_id', 'aff_network_name' => 'aff_network_name'];
    }

    protected function deletedColumn(): ?string { return 'aff_network_deleted'; }

    protected function fields(): array
    {
        return [
            'aff_network_name' => ['type' => 's', 'required' => true, 'max_length' => 50],
            'dni_network_id'   => ['type' => 'i', 'nullable' => true, 'range' => self::MEDIUMINT],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'aff_network_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
