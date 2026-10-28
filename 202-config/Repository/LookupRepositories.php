<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use Prosper202\Click\ClickPathReads;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\IdCache;
use Prosper202\Repository\Cache\RowCache;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

/** The lookup repositories of one database connection. */
final readonly class LookupRepositories
{
    public function __construct(
        public Connection $connection,
        public LocationRepositoryInterface $location,
        public DeviceRepositoryInterface $device,
        public TrackingRepositoryInterface $tracking,
        public ClickPathReads $reads,
    ) {
    }

    public static function uncached(Connection $connection): self
    {
        return new self(
            $connection,
            new MysqlLocationRepository($connection),
            new MysqlDeviceRepository($connection),
            new MysqlTrackingRepository($connection),
            ClickPathReads::uncached($connection),
        );
    }

    /** $namespace keeps one install's entries apart from another's in a shared cache. */
    public static function cached(
        Connection $connection,
        IdCache $cache,
        RowCache $rows,
        string $namespace
    ): self {
        return new self(
            $connection,
            new CachedLocationRepository(
                new MysqlLocationRepository($connection),
                $cache,
                $namespace
            ),
            new CachedDeviceRepository(
                new MysqlDeviceRepository($connection),
                $cache,
                $namespace
            ),
            new CachedTrackingRepository(
                new MysqlTrackingRepository($connection),
                $cache,
                $namespace
            ),
            ClickPathReads::cached($connection, $rows, $namespace),
        );
    }
}
