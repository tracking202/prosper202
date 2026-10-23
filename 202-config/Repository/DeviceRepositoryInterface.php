<?php

declare(strict_types=1);

namespace Prosper202\Repository;

interface DeviceRepositoryInterface
{
    public function findOrCreateBrowser(string $name): int;

    public function findOrCreatePlatform(string $name): int;

    /**
     * The 202_device_models row of this name and type. One name can be two
     * types: Chrome on Android names every phone and every tablet "K".
     */
    public function findOrCreateDevice(string $name, DeviceType $type): int;
}
