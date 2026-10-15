<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use mysqli;
use Prosper202\Click\ClickPathReads;
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
 * answered (connect2.php's $memcacheWorking, with its getCache()/setCache()),
 * and the install's cache namespace (systemHash()).
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
            \systemHash()
        );
    }

    /**
     * Memcache through the bootstrap's own helpers, when it answered. Only
     * connect2.php defines getCache(), so the signed-in pages' bootstrap
     * (connect.php) reads the database every time, as it always has. No
     * systemHash() is no cache: an entry needs the install's namespace, and
     * one shared by every install would answer another install's ids.
     */
    private static function bootstrapCacheWorks(): bool
    {
        return function_exists('getCache')
            && function_exists('setCache')
            && function_exists('systemHash')
            && ($GLOBALS['memcacheWorking'] ?? false) === true;
    }
}
