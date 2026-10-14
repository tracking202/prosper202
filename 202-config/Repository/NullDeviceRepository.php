<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullDeviceRepository implements DeviceRepositoryInterface
{
    #[\Override]
    public function findOrCreateBrowser(string $name): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreatePlatform(string $name): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateDevice(string $name): int
    {
        return 0;
    }
}
