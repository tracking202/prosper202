<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use mysqli;
use Prosper202\Click\ClickPathReads;
use Prosper202\Click\UnrecordedSourceVariables;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\MemcacheIdCache;
use Prosper202\Repository\Cache\MemcacheRowCache;
use WeakMap;

/**
 * The click scripts' way to the lookup repositories: one bundle per mysqli,
 * built on its first use. Keyed by the connection object itself, so a
 * second connection gets its own repositories rather than the first one's,
 * and a connection freed and replaced cannot inherit its predecessor's.
 *
 * This is the edge where the bootstrap's globals are read: whether memcache
 * answered ($memcacheWorking, with getCache()/setCache()), and the install's
 * cache namespace (systemHash(), with this code's version: cacheNamespace()).
 */
final class LookupRepositoryFactory
{
    /** @var WeakMap<mysqli, LookupRepositories>|null */
    private static ?WeakMap $bundles = null;

    private function __construct()
    {
    }

    public static function connection(mysqli $db): Connection
    {
        return self::bundle($db)->connection;
    }

    public static function location(mysqli $db): LocationRepositoryInterface
    {
        return self::bundle($db)->location;
    }

    public static function device(mysqli $db): DeviceRepositoryInterface
    {
        return self::bundle($db)->device;
    }

    public static function tracking(mysqli $db): TrackingRepositoryInterface
    {
        return self::bundle($db)->tracking;
    }

    public static function reads(mysqli $db): ClickPathReads
    {
        return self::bundle($db)->reads;
    }

    /**
     * The same reads, never cached: for a page that has always read its
     * setup live (the landing page's script and its variables), so a Setup
     * edit, the identity-capture switch among them, shows on the next view.
     */
    public static function liveReads(mysqli $db): ClickPathReads
    {
        return ClickPathReads::uncached(new Connection($db));
    }

    /**
     * The click scripts' note of a click stored without its source's
     * variables: once an hour for each traffic source account, kept in the
     * database, with memcache in front of it where it answers.
     */
    public static function unrecordedVariables(mysqli $db): UnrecordedSourceVariables
    {
        $bundle = self::bundle($db);
        if (!self::bootstrapCacheWorks()) {
            return new UnrecordedSourceVariables($bundle->reads, $bundle->connection);
        }

        return new UnrecordedSourceVariables(
            $bundle->reads,
            $bundle->connection,
            new MemcacheRowCache(
                static fn (string $key): mixed => \getCache($key),
                static fn (string $key, string $entry, int $ttl): mixed
                    => \setCache($key, $entry, $ttl),
                UnrecordedSourceVariables::SAID_FOR_SECONDS,
            ),
            self::cacheNamespace()
        );
    }

    /**
     * The click scripts' one call, after each has read its tracker.
     * $ownSource: the click's traffic source account names a source of its
     * own user's (the tracker read's own_ppc_network_id), which asks nothing
     * more. Never throws: the visitor goes on, whatever became of the note.
     *
     * @param array<array-key, mixed> $request the click's query
     */
    public static function noteUnrecordedVariables(
        mysqli $db,
        int $userId,
        int $ppcAccountId,
        bool $ownSource,
        array $request
    ): void {
        if ($ownSource || $userId < 1 || $ppcAccountId < 1) {
            return;
        }
        try {
            self::unrecordedVariables($db)->note($userId, $ppcAccountId, $request);
        } catch (\Throwable $e) {
            UnrecordedSourceVariables::sayOnce('p202 click: the check for unrecorded custom variables failed: '
                . $e->getMessage());
        }
    }

    public static function reset(): void
    {
        self::$bundles = null;
    }

    private static function bundle(mysqli $db): LookupRepositories
    {
        /** @var WeakMap<mysqli, LookupRepositories> $bundles */
        $bundles = self::$bundles ??= new WeakMap();

        return $bundles[$db] ??= self::build(new Connection($db));
    }

    private static function build(Connection $connection): LookupRepositories
    {
        if (!self::bootstrapCacheWorks()) {
            return LookupRepositories::uncached($connection);
        }

        return LookupRepositories::cached(
            $connection,
            new MemcacheIdCache(
                static fn (string $key): mixed => \getCache($key),
                static fn (string $key, int $id, int $ttl): mixed
                    => \setCache($key, $id, $ttl),
            ),
            new MemcacheRowCache(
                static fn (string $key): mixed => \getCache($key),
                static fn (string $key, string $entry, int $ttl): mixed
                    => \setCache($key, $entry, $ttl),
            ),
            self::cacheNamespace()
        );
    }

    /**
     * The install (systemHash()) and this code's version. A cached id is
     * right only while its row stands and the lookup answers as it did, and
     * an upgrade can change either, so ids another version cached are not
     * read: each upgrade starts cold, one database read per lookup until
     * the cache fills. Entries cached between deploying the files and
     * running the upgrade are this version's, so a step that removes lookup
     * rows still changes the key subjects (MemcacheIdCache), and a restored
     * database still needs memcached flushed. The click path's user-agent
     * parse and bot-range verdict, kept 30 days, take it too; every key on
     * the bare install hash is listed with why (CacheKeyNamespaceTest).
     */
    public static function cacheNamespace(): string
    {
        require_once dirname(__DIR__) . '/version.php';

        return \systemHash() . "\0" . \PROSPER202_VERSION;
    }

    /**
     * Memcache through the bootstrap's own helpers, on the click path only,
     * when it answered. Both bootstraps load the helpers, but the signed-in
     * pages, the API and the cron jobs read the database every time, as they
     * always have: nothing there holds a cache write until a transaction
     * commits (p202WithHeldCacheWrites() is the click scripts'), so a lookup
     * in one that rolls back would cache the id of a row the rollback took
     * away. No systemHash() is no cache: an entry needs the install's
     * namespace, and one shared by every install would answer another
     * install's ids.
     */
    private static function bootstrapCacheWorks(): bool
    {
        return function_exists('p202OnTheClickPath')
            && \p202OnTheClickPath()
            && function_exists('getCache')
            && function_exists('setCache')
            && function_exists('systemHash')
            && ($GLOBALS['memcacheWorking'] ?? false) === true;
    }
}
