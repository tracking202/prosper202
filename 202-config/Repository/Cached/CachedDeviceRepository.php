<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Closure;
use Prosper202\Repository\DeviceRepositoryInterface;

final class CachedDeviceRepository implements DeviceRepositoryInterface
{
    private const TTL = 2592000;

    /**
     * @param Closure(string): mixed $cacheGet  getCache($key)
     * @param Closure(string, mixed, int): void $cacheSet  setCache($key, $value, $ttl)
     */
    public function __construct(
        private DeviceRepositoryInterface $inner,
        private Closure $cacheGet,
        private Closure $cacheSet,
        private string $systemHash,
    ) {
    }

    public function findOrCreateBrowser(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $key = md5('browser-id' . $name . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateBrowser($name);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreatePlatform(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $key = md5('platform-id' . $name . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreatePlatform($name);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateDevice(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $key = md5('device-id' . $name . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateDevice($name);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }
}
