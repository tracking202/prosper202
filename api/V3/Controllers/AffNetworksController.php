<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class AffNetworksController extends Controller
{
    protected function tableName(): string { return '202_aff_networks'; }
    protected function primaryKey(): string { return 'aff_network_id'; }
    protected function deletedColumn(): ?string { return 'aff_network_deleted'; }

    protected function fields(): array
    {
        return [
            'aff_network_name' => ['type' => 's', 'required' => true, 'max_length' => 255],
            'dni_network_id'   => ['type' => 'i'],
        ];
    }
}
