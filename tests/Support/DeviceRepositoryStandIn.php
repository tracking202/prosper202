<?php

declare(strict_types=1);

namespace Tests\Support;

use mysqli;
use Prosper202\Click\ClickPathReads;
use Prosper202\Database\Connection;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\LookupRepositories;
use Prosper202\Repository\LookupRepositoryFactory;
use Prosper202\Repository\NullLocationRepository;
use Prosper202\Repository\NullTrackingRepository;
use ReflectionProperty;
use WeakMap;

/**
 * A connection LookupRepositoryFactory::device() answers with $devices, for
 * code that asks the factory itself (connect2.php's PLATFORMS). The factory
 * keeps no way in for a test, so its map is replaced; the caller keeps the
 * handle, since the map holds it weakly.
 */
final class DeviceRepositoryStandIn
{
    private function __construct()
    {
    }

    public static function connectionFor(DeviceRepositoryInterface $devices): mysqli
    {
        $db = mysqli_init();
        if (!$db instanceof mysqli) {
            throw new \RuntimeException('mysqli_init() made no handle');
        }

        /** @var WeakMap<mysqli, LookupRepositories> $bundles */
        $bundles = new WeakMap();
        $connection = new Connection($db);
        $bundles[$db] = new LookupRepositories(
            $connection,
            new NullLocationRepository(),
            $devices,
            new NullTrackingRepository(),
            ClickPathReads::uncached($connection),
        );
        (new ReflectionProperty(LookupRepositoryFactory::class, 'bundles'))->setValue(null, $bundles);

        if (LookupRepositoryFactory::device($db) !== $devices) {
            throw new \LogicException('LookupRepositoryFactory no longer reads the map this replaces');
        }

        return $db;
    }
}
