<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use mysqli;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

final class LookupRepositoryFactory
{
    private static ?Connection $conn = null;
    private static ?LocationRepositoryInterface $location = null;
    private static ?DeviceRepositoryInterface $device = null;
    private static ?TrackingRepositoryInterface $tracking = null;

    public static function connection(mysqli $db): Connection
    {
        return self::$conn ??= new Connection($db);
    }

    public static function location(mysqli $db): LocationRepositoryInterface
    {
        return self::$location ??= self::buildLocation($db);
    }

    public static function device(mysqli $db): DeviceRepositoryInterface
    {
        return self::$device ??= self::buildDevice($db);
    }

    public static function tracking(mysqli $db): TrackingRepositoryInterface
    {
        return self::$tracking ??= self::buildTracking($db);
    }

    public static function reset(): void
    {
        self::$conn = null;
        self::$location = null;
        self::$device = null;
        self::$tracking = null;
    }

    private static function buildLocation(mysqli $db): LocationRepositoryInterface
    {
        $conn = self::connection($db);
        $mysql = new MysqlLocationRepository($conn);

        if (!self::isCacheAvailable()) {
            return $mysql;
        }

        return new CachedLocationRepository(
            $mysql,
            self::cacheGetFn(),
            self::cacheSetFn(),
            self::systemHash(),
        );
    }

    private static function buildDevice(mysqli $db): DeviceRepositoryInterface
    {
        $conn = self::connection($db);
        $mysql = new MysqlDeviceRepository($conn);

        if (!self::isCacheAvailable()) {
            return $mysql;
        }

        return new CachedDeviceRepository(
            $mysql,
            self::cacheGetFn(),
            self::cacheSetFn(),
            self::systemHash(),
        );
    }

    private static function buildTracking(mysqli $db): TrackingRepositoryInterface
    {
        $conn = self::connection($db);
        $mysql = new MysqlTrackingRepository($conn);

        if (!self::isCacheAvailable()) {
            return $mysql;
        }

        return new CachedTrackingRepository(
            $mysql,
            self::cacheGetFn(),
            self::cacheSetFn(),
            self::systemHash(),
        );
    }

    private static function isCacheAvailable(): bool
    {
        if (function_exists('getCache') && function_exists('setCache')) {
            global $memcacheWorking;
            return !empty($memcacheWorking);
        }

        return false;
    }

    private static function systemHash(): string
    {
        if (function_exists('systemHash')) {
            return systemHash();
        }

        return '';
    }

    /** @return \Closure(string): mixed */
    private static function cacheGetFn(): \Closure
    {
        return static function (string $key): mixed {
            return getCache($key); // @phpstan-ignore function.notFound
        };
    }

    /** @return \Closure(string, mixed, int): void */
    private static function cacheSetFn(): \Closure
    {
        return static function (string $key, mixed $value, int $ttl): void {
            setCache($key, $value, $ttl); // @phpstan-ignore function.notFound
        };
    }
}
