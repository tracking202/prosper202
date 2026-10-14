<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use mysqli;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\IdCache;
use Prosper202\Repository\Cache\MemcacheIdCache;
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
        $cache = self::bootstrapCache();
        if ($cache === null) {
            return LookupRepositories::uncached($connection);
        }

        return LookupRepositories::cached(
            $connection,
            $cache,
            function_exists('systemHash') ? \systemHash() : ''
        );
    }

    /**
     * Memcache through the bootstrap's own helpers, when it answered. Only
     * connect2.php defines getCache(), so the signed-in pages' bootstrap
     * (connect.php) reads the database every time, as it always has.
     */
    private static function bootstrapCache(): ?IdCache
    {
        if (
            !function_exists('getCache')
            || !function_exists('setCache')
            || ($GLOBALS['memcacheWorking'] ?? false) !== true
        ) {
            return null;
        }

        return new MemcacheIdCache(
            static fn (string $key): mixed => \getCache($key),
            static fn (string $key, int $id, int $ttl): mixed
                => \setCache($key, $id, $ttl),
        );
    }
}
