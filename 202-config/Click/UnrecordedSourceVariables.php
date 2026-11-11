<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\RowCache;

/**
 * Says in the server log that a click is stored without the custom variable
 * values it came with, because its traffic source account names a traffic
 * source in another user's account: a click reads only the variables of a
 * source in its own user's account (ClickPathReads), so it reads none of
 * that source's. Account › Settings lists those sources and moves or splits
 * them, and the cron job splits the ones it can
 * (Prosper202\Setup\TrafficSourceOwners); nothing here changes what a click
 * records.
 *
 * Asked only of a click whose traffic source account has no source of its
 * own user's (the click scripts know that from their tracker read, so an
 * account on its own source costs nothing here). A line is written only
 * when the account names a source in another user's account and the request
 * carries a value under at least one of that source's live variables.
 *
 * One line an hour for each traffic source account, on every install: every
 * click on such an account is the same finding, and a line for each would
 * fill a disk. What was said is a row in 202_change_log (SAID_ENTITY), read
 * by its index, first, for each such click and written once an hour;
 * memcache, where it answers, spares the read. When the row cannot be read or written the
 * line is still written at most once an hour by each PHP process, never for
 * each click. The line names ids and a count of values, never a value or the
 * visitor. It carries no count of clicks: that would take a write for each.
 */
final class UnrecordedSourceVariables
{
    public const int SAID_FOR_SECONDS = 3600;

    /** 202_change_log's entity for "said for this traffic source account" (entity_id). */
    public const string SAID_ENTITY = 'unrecorded_variables_said';

    private const KEY_PREFIX = 'p202-unrecorded-variables-';

    /** @var array<string, int> what this process said, and when: the bound when nothing else can be kept */
    private static array $saidHere = [];

    /** @param RowCache|null $said memcache in front of the database's record, where it answers */
    public function __construct(
        private readonly ClickPathReads $reads,
        private readonly Connection $conn,
        private readonly ?RowCache $said = null,
        private readonly string $namespace = '',
    ) {
        if ($said !== null && $namespace === '') {
            throw new \InvalidArgumentException('A kept "said" needs the install\'s namespace');
        }
    }

    /**
     * For a click whose traffic source account names no source of its own
     * user's. Never throws: the visitor goes on, and a check that could not
     * be made is said as that, once an hour by each process.
     *
     * @param array<array-key, mixed> $request the click's query
     */
    public function note(int $userId, int $ppcAccountId, array $request): void
    {
        if ($userId < 1 || $ppcAccountId < 1) {
            return;
        }
        try {
            // What was said first: for a busy account that is the one read
            // a click pays, and the source is read only when nothing was.
            $key = self::KEY_PREFIX . hash('sha256', $this->namespace . "\0" . $userId . "\0" . $ppcAccountId);
            if ($this->said !== null && $this->said->get($key) !== null) {
                return;
            }
            $since = time() - self::SAID_FOR_SECONDS;
            if ($this->oldestSince($ppcAccountId, $since) !== null) {
                $this->said?->set($key, ['said' => '1']);

                return;
            }
            $source = $this->reads->sourceOfAnotherUser($userId, $ppcAccountId);
            if ($source === null || $source['source_user_id'] === null) {
                // Its own source, or one that does not exist: nothing to say
                // whatever a click brings, so kept as a line said is, and the
                // account's next clicks this hour read nothing.
                $this->said?->set($key, ['said' => '0']);

                return;
            }
            $line = self::line($source, $userId, $ppcAccountId, $request);
            if ($line === null) {
                return;
            }
            $mine = $this->noteSaid($userId, $ppcAccountId);
            // Kept in memcache only once the database holds it. Of two
            // clicks that both found nothing said, the older row's says it.
            $this->said?->set($key, ['said' => '1']);
            if ($this->oldestSince($ppcAccountId, $since) === $mine) {
                error_log($line);
            }
        } catch (\Throwable $e) {
            self::sayOnce('p202 click: a click on traffic source account ' . $ppcAccountId . ' of user ' . $userId
                . ' may be stored without its custom variable values, and whether that was already said this'
                . ' hour could not be read or kept (' . $e->getMessage() . '). Account › Settings lists the'
                . ' traffic sources in another user\'s account.', $userId . ':' . $ppcAccountId);
        }
    }

    /**
     * $line, at most once in SAID_FOR_SECONDS by this PHP process for
     * $subject: the bound that needs nothing but the process itself.
     */
    public static function sayOnce(string $line, string $subject = ''): void
    {
        $now = time();
        if (($now - (self::$saidHere[$subject] ?? 0)) < self::SAID_FOR_SECONDS) {
            return;
        }
        if (count(self::$saidHere) > 1000) {
            self::$saidHere = [];
        }
        self::$saidHere[$subject] = $now;
        error_log($line);
    }

    /**
     * Remove the "said" rows older than a day: the cron job's hourly tier
     * calls this, so the table holds a day of them at most.
     */
    public static function prune(Connection $conn): void
    {
        $delete = $conn->prepareWrite('DELETE FROM 202_change_log WHERE entity = ? AND changed_at < ?');
        $conn->bind($delete, 'si', [self::SAID_ENTITY, time() - 86400]);
        $conn->executeUpdate($delete);
    }

    /** For a test: what this process remembers saying. */
    public static function forget(): void
    {
        self::$saidHere = [];
    }

    /**
     * @param array<string, string|null> $source sourceOfAnotherUser()'s row, of another user's source
     * @param array<array-key, mixed> $request
     */
    private static function line(array $source, int $userId, int $ppcAccountId, array $request): ?string
    {
        $came = 0;
        foreach (TrackerVariables::pairs($source) as [$parameter]) {
            if (ClickRequest::text($request, $parameter) !== '') {
                $came++;
            }
        }
        if ($came === 0) {
            return null;
        }

        return 'p202 click: a click on traffic source account ' . $ppcAccountId . ' of user ' . $userId
            . ' came with ' . $came . ' custom variable ' . ($came === 1 ? 'value' : 'values')
            . ' and is stored without ' . ($came === 1 ? 'it' : 'them') . ': the account names traffic source '
            . (int) $source['ppc_network_id'] . ', which is in user ' . (int) $source['source_user_id']
            . '\'s account, and a click records only the variables of a source in its own user\'s account.'
            . ' Every click on this account loses them until the source is moved back or split. The cron job'
            . ' splits it by itself within the hour where it can; where it cannot, the source waits for a'
            . ' decision on Account › Settings, "Traffic sources in another user\'s account" (p202 system'
            . ' traffic-source-owners). Said once an hour for each traffic source account, not for each click.';
    }

    /** Note that it is said for the account now; the row's id. */
    private function noteSaid(int $userId, int $ppcAccountId): int
    {
        $write = $this->conn->prepareWrite('INSERT INTO 202_change_log'
            . ' (entity, entity_id, operation, natural_key_digest, actor_user_id, changed_at)'
            . " VALUES (?, ?, 'create', ?, ?, ?)");
        $this->conn->bind($write, 'sssii', [
            self::SAID_ENTITY,
            (string) $ppcAccountId,
            sha1(self::SAID_ENTITY . ':' . $ppcAccountId),
            $userId,
            time(),
        ]);

        return $this->conn->executeInsert($write);
    }

    /** The oldest "said" row of the account since $since, or null. */
    private function oldestSince(int $ppcAccountId, int $since): ?int
    {
        $read = $this->conn->prepareWrite('SELECT MIN(change_id) AS first FROM 202_change_log'
            . ' WHERE entity = ? AND changed_at >= ? AND entity_id = ?');
        $this->conn->bind($read, 'sis', [self::SAID_ENTITY, $since, (string) $ppcAccountId]);
        $row = $this->conn->fetchOne($read);
        if ($row === null) {
            throw new \RuntimeException('MIN() answered no row');
        }

        return $row['first'] === null ? null : (int) $row['first'];
    }
}
