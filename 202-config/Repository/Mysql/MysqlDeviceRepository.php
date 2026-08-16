<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Repository\DeviceRepositoryInterface;

final class MysqlDeviceRepository implements DeviceRepositoryInterface
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function findOrCreateBrowser(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $stmt = $this->conn->prepareRead(
            'SELECT browser_id FROM 202_browsers WHERE browser_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['browser_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_browsers SET browser_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreatePlatform(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $stmt = $this->conn->prepareRead(
            'SELECT platform_id FROM 202_platforms WHERE platform_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['platform_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_platforms SET platform_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateDevice(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        // The device catalog is 202_device_models; 202_devices is created by no
        // install path, so every call here was destined for a 1146 on the click
        // hot path. device_type is NOT NULL with no default, so supply it.
        $stmt = $this->conn->prepareRead(
            'SELECT device_id FROM 202_device_models WHERE device_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['device_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_device_models SET device_name = ?, device_type = 0'
        );
        $this->conn->bind($stmt, 's', [$name]);

        return $this->conn->executeInsert($stmt);
    }
}
