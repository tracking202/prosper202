<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\LedgerReportSql;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\RollupRefresh;
use Prosper202\Lpo\DimensionSync;

/**
 * Traffic sources that are in one user's account while traffic source
 * accounts of another user name them, and the repair that moves one to the
 * user whose accounts name it. Served to an install admin by GET
 * /system/traffic-source-owners and POST /system/traffic-source-owners/repair
 * (`p202 system traffic-source-owners`).
 *
 * Before 1.9.79 Setup › Traffic Sources renamed a source with `UPDATE
 * 202_ppc_networks SET user_id = <the session's>, … WHERE ppc_network_id =
 * <the posted id>` and checked nothing, so a user who reached Setup could
 * rename another user's source and take it. Its accounts stayed where they
 * were: the sign it leaves is a 202_ppc_accounts row whose user_id is not its
 * source's. The other Setup saves checked ownership before they wrote, so no
 * other table was moved that way. A source taken with no account under it
 * leaves no sign: nothing else records whose it was (its variables have no
 * user_id, and 202_dataengine copies the source from the account).
 *
 * The same sign has other origins, so nothing here runs by itself, and the
 * upgrade neither looks nor moves. Before 1.9.77 the API put a
 * traffic source account under whatever source it was sent (229df10), so a
 * user's account can name a source that was never theirs, such as one an
 * admin made for them; and an admin may have used the rename on purpose.
 * A source is marked `ambiguous` when:
 *
 * - owner_has_accounts: the user it is in has accounts on it too;
 * - several_users: accounts of more than one other user name it;
 * - saved_after_rename: an account of another user was saved after the
 *   source was last saved, or in the same second. The rename stamped
 *   ppc_network_time, so every account of the user a source was taken from
 *   was saved before it; an account the API put on another user's source was
 *   saved after that source. A source renamed by its own user after that
 *   reads as taken;
 * - no_such_user: another user it names has no 202_users row;
 * - deleted_user: another user it names is deleted. Nobody signs in as a
 *   deleted user, and once a source is in that account the report no longer
 *   lists it, so no repair moves it there, named or not. A switched-off user
 *   (user_active = 0) can be switched on again, and its source is its own
 *   either way, so that is shown (user_active) and does not stop a repair.
 *
 * An unmarked source has one user to go back to (repair_to_user_id), and the
 * repair takes it there without being told. A marked one moves only to a user
 * the operator names, who must be one whose accounts name it.
 */
final class TrafficSourceOwners
{
    public const OWNER_HAS_ACCOUNTS = 'owner_has_accounts';
    public const SEVERAL_USERS = 'several_users';
    public const SAVED_AFTER_RENAME = 'saved_after_rename';
    public const NO_SUCH_USER = 'no_such_user';
    public const DELETED_USER = 'deleted_user';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Every source another user's accounts name, by id, each with the clicks
     * and the counted conversions recorded on each user's accounts.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT pa.ppc_network_id FROM 202_ppc_accounts AS pa'
            . ' INNER JOIN 202_ppc_networks AS pn ON pn.ppc_network_id = pa.ppc_network_id'
            . ' WHERE pa.user_id <> pn.user_id ORDER BY pa.ppc_network_id'
        );
        $ids = array_map(static fn (array $row): int => (int) $row['ppc_network_id'], $this->conn->fetchAll($stmt));
        if ($ids === []) {
            return [];
        }
        $sources = $this->sources($ids);
        $accounts = $this->accounts($ids);

        $entries = [];
        foreach ($ids as $id) {
            if (!isset($sources[$id])) {
                continue;
            }
            $named = $accounts[$id] ?? [];
            $entry = self::classify($sources[$id], $named, $this->users($sources[$id], $named));
            if ($entry === null) {
                continue;
            }
            $entry['owner'] = $this->withCounts($entry['owner']);
            $entry['named_by'] = array_map($this->withCounts(...), $entry['named_by']);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Where a repair of $sourceId would take it: to $toUserId when the
     * operator names one, else to the one user the report names.
     * $shownOwner and $shownTo are the owner and repair_to_user_id the
     * operator was shown, when the caller sends them back: by now the source
     * may be in another account, or go to another user, and the move is then
     * refused rather than made from or to a user nobody saw.
     *
     * @return array{from: int, to: int, entry: array<string, mixed>}
     * @throws TrafficSourceRepairRefused when it is not listed, or not to there
     */
    public function plan(int $sourceId, ?int $toUserId, ?int $shownOwner = null, ?int $shownTo = null): array
    {
        $sources = $this->sources([$sourceId]);
        $accounts = $this->accounts([$sourceId]);
        $plan = $this->resolve($sourceId, $sources[$sourceId] ?? null, $accounts[$sourceId] ?? [], $toUserId);
        $again = ' Nothing was moved. Read the report again.';
        if ($shownOwner !== null && $plan['from'] !== $shownOwner) {
            throw new TrafficSourceRepairRefused('owner_user_id', 'Traffic source ' . $sourceId . ' is now in user '
                . $plan['from'] . "'s account, not in user " . $shownOwner . "'s as the report you read said."
                . $again);
        }
        if ($shownTo !== null && $plan['to'] !== $shownTo) {
            throw new TrafficSourceRepairRefused('repair_to_user_id', 'Traffic source ' . $sourceId
                . ' would now go to user ' . $plan['to'] . ', not to user ' . $shownTo
                . ' as the report you read said.' . $again);
        }

        return $plan;
    }

    /**
     * Move the source from $fromUserId to $toUserId, as plan() answered them,
     * in a transaction of its own: the two users and the source are locked,
     * everything is read again, and nothing is written unless it still
     * resolves to the same two users. Every account naming it is queued for
     * the report rollup, whose rows take the source from it (RollupRefresh).
     * The move is logged.
     *
     * @return array<string, mixed>
     * @throws TrafficSourceRepairRefused when it no longer resolves so
     */
    public function repair(int $sourceId, int $fromUserId, int $toUserId, ?int $named, int $actorUserId): array
    {
        $moved = $this->conn->transaction(function () use ($sourceId, $fromUserId, $toUserId, $named): array {
            // Locked before any plain read, so every read after them is
            // current (CLAUDE.md #36). The users come first, as
            // UserDataPurge::deleteUser() locks the user it deletes before
            // anything else: a delete in flight is waited for, and the user is
            // then read as deleted; the delete touches no source or account.
            // The accounts are read, not locked: a click's rollup (INSERT …
            // SELECT, ClickRollupSql) share-locks its account and then the
            // source, so a repair holding the source and waiting for an
            // account deadlocked with it. Only the source's own user can put an
            // account on it, and one put there meanwhile is listed again.
            $this->lockUsers([$fromUserId, $toUserId]);
            $sources = $this->sources([$sourceId], true);
            $accounts = $this->accounts([$sourceId]);
            $now = $this->resolve($sourceId, $sources[$sourceId] ?? null, $accounts[$sourceId] ?? [], $named);
            if ($now['from'] !== $fromUserId || $now['to'] !== $toUserId) {
                throw new TrafficSourceRepairRefused('ppc_network_id', 'Traffic source ' . $sourceId
                    . ' changed after it was read: it is in user ' . $now['from'] . "'s account and would go to user "
                    . $now['to'] . '. Nothing was moved. Read the report again.');
            }

            $write = $this->conn->prepareWrite(
                'UPDATE 202_ppc_networks SET user_id = ? WHERE ppc_network_id = ? AND user_id = ?'
            );
            $this->conn->bind($write, 'iii', [$toUserId, $sourceId, $fromUserId]);
            if ($this->conn->executeUpdate($write) !== 1) {
                throw new \LogicException('Traffic source ' . $sourceId . ' was locked in user ' . $fromUserId
                    . "'s account, and the move from it changed no row");
            }

            $at = time();
            $queued = 0;
            foreach ($accounts[$sourceId] ?? [] as $account) {
                $owner = (int) $account['user_id'];
                if ($owner < 1) {
                    continue;
                }
                RollupRefresh::account($this->conn, $owner, (int) $account['ppc_account_id'], $at);
                $queued++;
            }
            $target = self::partyOf($now['entry'], $toUserId);

            return [
                'ppc_network_id' => $sourceId,
                'ppc_network_name' => $now['entry']['ppc_network_name'],
                'removed' => $now['entry']['removed'],
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'ppc_accounts' => $target['ppc_accounts'] + $target['removed_ppc_accounts'],
                'queued_ppc_accounts' => $queued,
            ];
        });

        error_log(sprintf(
            'Prosper202 traffic source repair: traffic source %d ("%s") moved from user %d to user %d,'
            . ' whose %d traffic source account(s) name it, at user %d\'s request',
            $sourceId,
            (string) preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $moved['ppc_network_name']),
            $fromUserId,
            $toUserId,
            $moved['ppc_accounts'],
            $actorUserId
        ));
        // After the commit: each user's Landing Page Optimizer snapshot lists
        // its sources (DimensionSync never throws).
        DimensionSync::markDirty($this->conn->writeConnection(), $fromUserId);
        DimensionSync::markDirty($this->conn->writeConnection(), $toUserId);

        return $moved;
    }

    /**
     * The report's entry for one source, or null when no account of another
     * user names it. Pure: the rows are read by the caller.
     *
     * @param array<string, mixed> $source its 202_ppc_networks row
     * @param list<array<string, mixed>> $accounts the 202_ppc_accounts rows naming it
     * @param array<int, array{user_name: string, user_deleted: bool, user_active: bool}> $users by user_id
     * @return array<string, mixed>|null
     */
    public static function classify(array $source, array $accounts, array $users): ?array
    {
        $ownerId = (int) $source['user_id'];
        $sourceTime = (int) $source['ppc_network_time'];
        $byUser = [];
        foreach ($accounts as $account) {
            $user = (int) $account['user_id'];
            $byUser[$user] ??= ['ids' => [], 'live' => 0, 'removed' => 0, 'last' => 0];
            $byUser[$user]['ids'][] = (int) $account['ppc_account_id'];
            $byUser[$user][(int) $account['ppc_account_deleted'] === 0 ? 'live' : 'removed']++;
            $byUser[$user]['last'] = max($byUser[$user]['last'], (int) $account['ppc_account_time']);
        }
        $others = array_values(array_diff(array_keys($byUser), [$ownerId]));
        if ($others === []) {
            return null;
        }
        sort($others);

        $ambiguous = [];
        if (isset($byUser[$ownerId])) {
            $ambiguous[] = self::OWNER_HAS_ACCOUNTS;
        }
        if (count($others) > 1) {
            $ambiguous[] = self::SEVERAL_USERS;
        }
        // Whole seconds: an account saved in the source's own second is not
        // shown to predate it.
        foreach ($others as $user) {
            if ($byUser[$user]['last'] >= $sourceTime) {
                $ambiguous[] = self::SAVED_AFTER_RENAME;
                break;
            }
        }
        foreach ($others as $user) {
            if (!isset($users[$user])) {
                $ambiguous[] = self::NO_SUCH_USER;
                break;
            }
        }
        foreach ($others as $user) {
            if ($users[$user]['user_deleted'] ?? false) {
                $ambiguous[] = self::DELETED_USER;
                break;
            }
        }

        $party = static function (int $user) use ($byUser, $users): array {
            $mine = $byUser[$user] ?? ['ids' => [], 'live' => 0, 'removed' => 0, 'last' => 0];
            sort($mine['ids']);

            return [
                'user_id' => $user,
                'user_name' => $users[$user]['user_name'] ?? null,
                'user_deleted' => $users[$user]['user_deleted'] ?? null,
                'user_active' => $users[$user]['user_active'] ?? null,
                'ppc_accounts' => $mine['live'],
                'removed_ppc_accounts' => $mine['removed'],
                'ppc_account_ids' => $mine['ids'],
                'last_ppc_account_time' => $mine['ids'] === [] ? null : $mine['last'],
            ];
        };

        return [
            'ppc_network_id' => (int) $source['ppc_network_id'],
            'ppc_network_name' => (string) $source['ppc_network_name'],
            'removed' => (int) $source['ppc_network_deleted'] !== 0,
            'ppc_network_time' => $sourceTime,
            'owner' => $party($ownerId),
            'named_by' => array_map($party, $others),
            'repair_to_user_id' => $ambiguous === [] ? $others[0] : null,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param array<string, mixed>|null $found
     * @param list<array<string, mixed>> $accounts
     * @return array{from: int, to: int, entry: array<string, mixed>}
     */
    private function resolve(int $sourceId, ?array $found, array $accounts, ?int $named): array
    {
        $what = 'Traffic source ' . $sourceId;
        $none = ' Nothing was moved.';
        if ($found === null) {
            throw new TrafficSourceRepairRefused('ppc_network_id', 'There is no traffic source ' . $sourceId
                . ' on this install.' . $none);
        }
        $entry = self::classify($found, $accounts, $this->users($found, $accounts));
        $from = (int) $found['user_id'];
        if ($entry === null) {
            throw new TrafficSourceRepairRefused('ppc_network_id', $what . ' is not in the report: ' . ($accounts === []
                ? 'no traffic source account names it.'
                : 'every traffic source account that names it is in user ' . $from . "'s account, as it is.") . $none);
        }
        $candidates = implode(', ', array_map(static fn (array $party): int => $party['user_id'], $entry['named_by']));

        if ($named === null) {
            if ($entry['repair_to_user_id'] === null) {
                throw new TrafficSourceRepairRefused('to_user_id', $what . ' is ambiguous ('
                    . implode(', ', $entry['ambiguous']) . '), so it is moved only to a user you name:'
                    . ' to_user_id, one of ' . $candidates . '.' . $none);
            }

            return ['from' => $from, 'to' => (int) $entry['repair_to_user_id'], 'entry' => $entry];
        }
        if ($named === $from) {
            throw new TrafficSourceRepairRefused('to_user_id', $what . ' is in user ' . $from . "'s account already;"
                . ' to_user_id must be a user whose accounts name it: one of ' . $candidates . '.' . $none);
        }
        if (!in_array($named, array_column($entry['named_by'], 'user_id'), true)) {
            throw new TrafficSourceRepairRefused('to_user_id', 'No traffic source account of user ' . $named
                . ' names traffic source ' . $sourceId . '; it goes back only to a user whose accounts name it:'
                . ' one of ' . $candidates . '.' . $none);
        }
        $target = self::partyOf($entry, $named);
        if ($target['user_name'] === null) {
            throw new TrafficSourceRepairRefused('to_user_id', 'User ' . $named . ' does not exist on this install.'
                . $none);
        }
        if ($target['user_deleted'] === true) {
            throw new TrafficSourceRepairRefused('to_user_id', 'User ' . $named . ' is deleted: nobody signs in as'
                . ' them, and a source moved there leaves the report for good.' . $none);
        }

        return ['from' => $from, 'to' => $named, 'entry' => $entry];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function partyOf(array $entry, int $user): array
    {
        foreach ($entry['named_by'] as $party) {
            if ($party['user_id'] === $user) {
                return $party;
            }
        }
        throw new \LogicException('user ' . $user . ' is not one the entry names');
    }

    /**
     * Lock users' rows, by id in ascending order, as UserDataPurge::LOCK_USER
     * locks the one it deletes. An id with no row locks nothing.
     *
     * @param list<int> $ids
     */
    private function lockUsers(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $stmt = $this->conn->prepareWrite(
            'SELECT user_id FROM 202_users WHERE user_id IN (' . self::marks($ids) . ') ORDER BY user_id FOR UPDATE'
        );
        $this->conn->bind($stmt, str_repeat('i', count($ids)), $ids);
        $this->conn->fetchAll($stmt);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> by ppc_network_id
     */
    private function sources(array $ids, bool $lock = false): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ppc_network_id, user_id, ppc_network_name, ppc_network_deleted, ppc_network_time'
            . ' FROM 202_ppc_networks WHERE ppc_network_id IN (' . self::marks($ids) . ')'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $this->conn->bind($stmt, str_repeat('i', count($ids)), $ids);
        $byId = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $byId[(int) $row['ppc_network_id']] = $row;
        }

        return $byId;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>> by ppc_network_id
     */
    private function accounts(array $ids): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ppc_account_id, ppc_network_id, user_id, ppc_account_deleted, ppc_account_time'
            . ' FROM 202_ppc_accounts WHERE ppc_network_id IN (' . self::marks($ids) . ') ORDER BY ppc_account_id'
        );
        $this->conn->bind($stmt, str_repeat('i', count($ids)), $ids);
        $bySource = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $bySource[(int) $row['ppc_network_id']][] = $row;
        }

        return $bySource;
    }

    /**
     * The users a source and its accounts name.
     *
     * @param array<string, mixed> $source
     * @param list<array<string, mixed>> $accounts
     * @return array<int, array{user_name: string, user_deleted: bool, user_active: bool}>
     */
    private function users(array $source, array $accounts): array
    {
        $ids = array_values(array_unique(array_merge(
            [(int) $source['user_id']],
            array_map(static fn (array $row): int => (int) $row['user_id'], $accounts)
        )));
        $stmt = $this->conn->prepareWrite(
            'SELECT user_id, user_name, user_deleted, user_active FROM 202_users'
            . ' WHERE user_id IN (' . self::marks($ids) . ')'
        );
        $this->conn->bind($stmt, str_repeat('i', count($ids)), $ids);
        $users = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $users[(int) $row['user_id']] = [
                'user_name' => (string) $row['user_name'],
                'user_deleted' => (int) $row['user_deleted'] !== 0,
                'user_active' => (int) $row['user_active'] === 1,
            ];
        }

        return $users;
    }

    /**
     * A party's clicks (each click once, at its latest visit) and the
     * conversions that count on them (LedgerReportSql's counted rows, a
     * reversal not counted again), on the accounts it holds on the source.
     *
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    private function withCounts(array $party): array
    {
        $ids = $party['ppc_account_ids'];
        if ($ids === []) {
            return $party + ['clicks' => 0, 'conversions' => 0];
        }
        $types = str_repeat('i', count($ids));

        $clicks = $this->conn->prepareWrite(
            'SELECT COUNT(DISTINCT c.click_id) AS clicks FROM 202_clicks AS c'
            . ' WHERE c.ppc_account_id IN (' . self::marks($ids) . ') AND ' . LatestVisit::isLatest('c')
        );
        $this->conn->bind($clicks, $types, $ids);
        $clickCount = self::counted($this->conn->fetchOne($clicks), 'clicks');

        $conversions = $this->conn->prepareWrite(
            'SELECT COUNT(DISTINCT cv.conv_id) AS conversions FROM 202_clicks AS c'
            . ' INNER JOIN 202_conversion_logs AS cv ON cv.click_id = c.click_id AND cv.user_id = c.user_id'
            . ' WHERE c.ppc_account_id IN (' . self::marks($ids) . ') AND ' . LatestVisit::isLatest('c')
            . ' AND cv.reverses_conv_id IS NULL AND ' . LedgerReportSql::countedPredicate('cv')
        );
        $this->conn->bind($conversions, $types, $ids);
        $conversionCount = self::counted($this->conn->fetchOne($conversions), 'conversions');

        return $party + ['clicks' => $clickCount, 'conversions' => $conversionCount];
    }

    /** @param array<string, mixed>|null $row a COUNT's one row */
    private static function counted(?array $row, string $column): int
    {
        if ($row === null || !isset($row[$column])) {
            throw new \Prosper202\Database\Exceptions\QueryException('COUNT answered no ' . $column);
        }

        return (int) $row[$column];
    }

    /** @param list<int> $ids */
    private static function marks(array $ids): string
    {
        if ($ids === []) {
            throw new \InvalidArgumentException('an IN list names at least one id');
        }

        return implode(',', array_fill(0, count($ids), '?'));
    }
}
