<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Prosper202\Database\Connection;
use Prosper202\Setup\TrafficSourceAccountWrite;

class PpcAccountsController extends Controller
{
    protected function tableName(): string { return '202_ppc_accounts'; }
    protected function primaryKey(): string { return 'ppc_account_id'; }

    #[\Override]
    protected function listSearchColumns(): array
    {
        return ['ppc_account_name'];
    }

    #[\Override]
    protected function listSortFields(): array
    {
        return [
            'ppc_account_id' => 'ppc_account_id',
            'ppc_account_name' => 'ppc_account_name',
            'ppc_network_id' => 'ppc_network_id',
        ];
    }

    protected function deletedColumn(): ?string { return 'ppc_account_deleted'; }

    protected function fields(): array
    {
        return [
            'ppc_account_name'    => ['type' => 's', 'required' => true, 'max_length' => 50],
            'ppc_network_id'      => ['type' => 'i', 'required' => true, 'range' => self::MEDIUMINT_UNSIGNED],
            'ppc_account_default' => ['type' => 'i', 'range' => self::TINYINT_UNSIGNED],
        ];
    }

    /**
     * Each write takes the account, then its sources, in the lock order the
     * traffic source repair and split share (TrafficSourceAccountWrite), and
     * asks again under the lock whether the source it puts the account on is
     * a live one of this user's: assertLinksOwned() read it before the lock,
     * and a repair may have moved it to another user since. An update that
     * leaves the account on the source it is on is not asked.
     */
    #[\Override]
    protected function writeRow(?array $current, array $clean, callable $write): mixed
    {
        $accountId = $current === null ? null : (int) $current['ppc_account_id'];
        $sourceId = array_key_exists('ppc_network_id', $clean) ? (int) $clean['ppc_network_id'] : null;

        return TrafficSourceAccountWrite::run(
            new Connection($this->db),
            $accountId === null ? [] : [$accountId],
            $sourceId === null ? [] : [$sourceId],
            function (array $sources, array $onSources) use ($accountId, $sourceId, $write): mixed {
                $moves = $sourceId !== null && ($accountId === null || ($onSources[$accountId] ?? 0) !== $sourceId);
                if ($moves && !TrafficSourceAccountWrite::ownsLive($sources, (int) $sourceId, $this->userId)) {
                    throw new ValidationException('Validation failed', [
                        'ppc_network_id' => "traffic source $sourceId is not one of yours, or it was removed"
                            . ' (GET /ppc-networks lists them)',
                    ]);
                }

                return $write();
            }
        );
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
