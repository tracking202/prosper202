<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class PpcNetworksController extends Controller
{
    protected function tableName(): string { return '202_ppc_networks'; }
    protected function primaryKey(): string { return 'ppc_network_id'; }
    protected function deletedColumn(): ?string { return 'ppc_network_deleted'; }

    protected function fields(): array
    {
        return [
            'ppc_network_name' => ['type' => 's', 'required' => true],
        ];
    }
}
