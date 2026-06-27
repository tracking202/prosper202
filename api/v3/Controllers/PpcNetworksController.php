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
            'ppc_network_name' => ['type' => 's', 'required' => true, 'max_length' => 255],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        // ppc_network_time is NOT NULL with no default; supply it so the INSERT
        // succeeds under STRICT_TRANS_TABLES (mirrors AffNetworksController).
        return [
            'ppc_network_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
