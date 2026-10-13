<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\WriteCommittedException;

class PpcAccountsController extends Controller
{
    protected function tableName(): string { return '202_ppc_accounts'; }
    protected function primaryKey(): string { return 'ppc_account_id'; }
    protected function deletedColumn(): ?string { return 'ppc_account_deleted'; }

    protected function fields(): array
    {
        return [
            'ppc_account_name'    => ['type' => 's', 'required' => true, 'max_length' => 50],
            'ppc_network_id'      => ['type' => 'i', 'required' => true, 'range' => self::MEDIUMINT_UNSIGNED],
            'ppc_account_default' => ['type' => 'i', 'range' => self::TINYINT_UNSIGNED],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'ppc_account_time' => ['type' => 'i', 'value' => time()],
        ];
    }

    /**
     * An account moved to another traffic source queues its clicks' report
     * rows for the cron job to roll up again: the rows keep the source they
     * were rolled up under, and the readers filter and group by it
     * (Prosper202\DataEngine\RollupRefresh says what that looked like).
     */
    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        $before = (int) (((array) $this->get($id)['data'])['ppc_network_id'] ?? 0);
        $updated = parent::update($id, $payload);
        $after = (int) (((array) $updated['data'])['ppc_network_id'] ?? 0);
        if ($after !== $before) {
            // The write has landed (CLAUDE.md #13).
            try {
                $conn = new \Prosper202\Database\Connection($this->db);
                \Prosper202\DataEngine\RollupRefresh::account($conn, $this->userId, (int) $id, time());
            } catch (\Throwable $e) {
                throw new WriteCommittedException('traffic source account', $e);
            }
        }

        return $updated;
    }
}
