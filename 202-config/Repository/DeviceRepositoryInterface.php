<?php

declare(strict_types=1);

namespace Prosper202\Repository;

interface DeviceRepositoryInterface
{
    public function findOrCreateBrowser(string $name): int;

    public function findOrCreatePlatform(string $name): int;

    public function findOrCreateDevice(string $name): int;
}
