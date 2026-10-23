<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Prosper202\Repository\Cache\CachedIds;
use Prosper202\Repository\Cache\IdCache;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;

/** Keys on the name as it is stored, as the location decorator does. */
final readonly class CachedDeviceRepository implements DeviceRepositoryInterface
{
    private CachedIds $ids;

    public function __construct(
        private DeviceRepositoryInterface $inner,
        IdCache $cache,
        string $namespace,
    ) {
        $this->ids = new CachedIds($cache, $namespace);
    }

    #[\Override]
    public function findOrCreateBrowser(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = MysqlDeviceRepository::storedName($name);

        return $this->ids->remember(
            'browser-id' . $name,
            fn (): int => $this->inner->findOrCreateBrowser($name)
        );
    }

    #[\Override]
    public function findOrCreatePlatform(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = MysqlDeviceRepository::storedName($name);

        return $this->ids->remember(
            'platform-id' . $name,
            fn (): int => $this->inner->findOrCreatePlatform($name)
        );
    }

    /** The type is last and all digits, so the key reads back one way. */
    #[\Override]
    public function findOrCreateDevice(string $name, DeviceType $type): int
    {
        if ($name === '') {
            return 0;
        }
        $name = MysqlDeviceRepository::storedName($name);

        return $this->ids->remember(
            'device-id' . $name . '|' . $type->value,
            fn (): int => $this->inner->findOrCreateDevice($name, $type)
        );
    }
}
