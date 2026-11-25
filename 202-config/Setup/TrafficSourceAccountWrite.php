<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * A write to traffic source accounts, in the lock order TrafficSourceOwners'
 * repair and split share with it, so a repair never commits on accounts that
 * were added, moved or removed while it read them.
 *
 * The order: the accounts the write changes (FOR UPDATE, by id), then the
 * sources they are on and the sources it puts accounts on (LOCK IN SHARE
 * MODE, by id), then the write, in one transaction. The repair locks the
 * source FOR UPDATE and reads the accounts naming it after that: a write in
 * flight holds the source, so the repair waits for it and reads its accounts
 * as committed; a write that comes after waits for the repair and reads the
 * source as it was moved, which the caller checks before it writes.
 *
 * The account comes before the source because the split
 * (TrafficSourceOwners::split()) and a click's report rollup (ClickRollupSql's
 * INSERT … SELECT) each hold an account while they wait for its source: a
 * write that held the source and then waited for the account would deadlock
 * with them. The repair locks no account, so it waits for nothing a write
 * holds. A bulk write (POST /ppc-accounts/bulk-upsert) takes each row's locks in
 * turn in one transaction, so a later row can wait for an account the split
 * holds while an earlier row holds the split's source: the server rolls one
 * back as a deadlock, and the bulk write tries its chunk again.
 */
final class TrafficSourceAccountWrite
{
    private function __construct()
    {
    }

    /**
     * Run $write once the locks are held, in the caller's transaction on
     * $conn or in one of its own. $write gets the locked sources, current as
     * the server holds them, by ppc_network_id (a source with no row is not
     * among them), and the source each locked account is on, by
     * ppc_account_id: whether a write moves an account is asked of these, as
     * a split may have moved it since the caller read it.
     *
     * @template T
     * @param list<int> $accountIds the accounts the write changes (none for a new one)
     * @param list<int> $sourceIds the sources the write puts accounts on
     * @param callable(array<int, array{user_id: int, deleted: bool}>, array<int, int>): T $write
     * @return T
     */
    public static function run(Connection $conn, array $accountIds, array $sourceIds, callable $write): mixed
    {
        $locked = static function () use ($conn, $accountIds, $sourceIds, $write): mixed {
            $onSources = self::lockAccounts($conn, $accountIds);

            return $write(self::lockSources($conn, [...$sourceIds, ...array_values($onSources)]), $onSources);
        };

        return $conn->inTransaction() ? $conn->joinTransaction($locked) : $conn->transaction($locked);
    }

    /**
     * Whether $sourceId is a live source of $userId's among the locked
     * $sources, as Setup and the API ask before an account is put on it.
     *
     * @param array<int, array{user_id: int, deleted: bool}> $sources
     */
    public static function ownsLive(array $sources, int $sourceId, int $userId): bool
    {
        $source = $sources[$sourceId] ?? null;

        return $source !== null && $source['user_id'] === $userId && !$source['deleted'];
    }

    /**
     * @param list<int> $ids
     * @return array<int, int> the source each locked account is on, by ppc_account_id
     */
    private static function lockAccounts(Connection $conn, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $stmt = $conn->prepareWrite('SELECT ppc_account_id, ppc_network_id FROM 202_ppc_accounts'
            . ' WHERE ppc_account_id IN (' . self::marks($ids) . ') ORDER BY ppc_account_id FOR UPDATE');
        $conn->bind($stmt, str_repeat('i', count($ids)), $ids);

        $onSources = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $onSources[(int) $row['ppc_account_id']] = (int) $row['ppc_network_id'];
        }

        return $onSources;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{user_id: int, deleted: bool}>
     */
    private static function lockSources(Connection $conn, array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }
        $stmt = $conn->prepareWrite('SELECT ppc_network_id, user_id, ppc_network_deleted FROM 202_ppc_networks'
            . ' WHERE ppc_network_id IN (' . self::marks($ids) . ') ORDER BY ppc_network_id LOCK IN SHARE MODE');
        $conn->bind($stmt, str_repeat('i', count($ids)), $ids);
        $byId = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $byId[(int) $row['ppc_network_id']] = [
                'user_id' => (int) $row['user_id'],
                'deleted' => (int) $row['ppc_network_deleted'] !== 0,
            ];
        }

        return $byId;
    }

    /**
     * @param list<int> $ids
     * @return list<int> each id above 0 once, ascending
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private static function marks(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
