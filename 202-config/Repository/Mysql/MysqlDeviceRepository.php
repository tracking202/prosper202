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
        // hot path. device_type is NOT NULL with no default, so supply it — and
        // it must be one of the seeded types (1=Desktop, 2=Mobile, 3=Tablet,
        // 4=Bot). A 0 joined to no row in 202_device_types and dropped the model
        // out of every `device_type = N` filter for good, because rows are keyed
        // on device_name. 1 matches connect2.php's fallback for an unrecognised
        // device; this interface carries no type, so callers that know it should
        // go through the detector in connect2.php.
        $stmt = $this->conn->prepareRead(
            'SELECT device_id FROM 202_device_models WHERE device_name = ?'
        );
        $this->conn->bind($stmt, 's', [$name]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['device_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_device_models SET device_name = ?, device_type = 1'
        );
        $this->conn->bind($stmt, 's', [$name]);

        return $this->conn->executeInsert($stmt);
    }
}
