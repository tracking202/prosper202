<?php

declare(strict_types=1);

namespace Prosper202\Repository\InMemory;

use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;

final class InMemoryDeviceRepository implements DeviceRepositoryInterface
{
    /** @var array<string, int> */
    private array $browsers = [];
    /** @var array<string, int> */
    private array $platforms = [];
    /** @var array<string, int> */
    private array $devices = [];
    private int $nextId = 1;

    #[\Override]
    public function findOrCreateBrowser(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $name = MysqlDeviceRepository::storedName($name);

        return $this->browsers[$name] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreatePlatform(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $name = MysqlDeviceRepository::storedName($name);

        return $this->platforms[$name] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateDevice(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $name = MysqlDeviceRepository::storedName($name);

        return $this->devices[$name] ??= $this->nextId++;
    }
}
