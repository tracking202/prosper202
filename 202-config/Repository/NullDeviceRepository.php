<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullDeviceRepository implements DeviceRepositoryInterface
{
    public function findOrCreateBrowser(string $name): int
    {
        return 0;
    }

    public function findOrCreatePlatform(string $name): int
    {
        return 0;
    }

    public function findOrCreateDevice(string $name): int
    {
        return 0;
    }
}
