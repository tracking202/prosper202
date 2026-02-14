<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class PpcAccountsController extends Controller
{
    protected function tableName(): string { return '202_ppc_accounts'; }
    protected function primaryKey(): string { return 'ppc_account_id'; }
    protected function deletedColumn(): ?string { return 'ppc_account_deleted'; }

    protected function fields(): array
    {
        return [
            'ppc_account_name'    => ['type' => 's', 'required' => true],
            'ppc_network_id'      => ['type' => 'i', 'required' => true],
            'ppc_account_default' => ['type' => 'i', 'required' => false],
        ];
    }
}
