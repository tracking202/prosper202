<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\StoredText;

final readonly class MysqlDeviceRepository implements DeviceRepositoryInterface
{
    /** browser_name, platform_name and device_name. */
    public const NAME_CHARS = 50;

    private IdQuery $ids;

    public function __construct(Connection $conn)
    {
        $this->ids = new IdQuery($conn);
    }

    public static function storedName(string $name): string
    {
        return StoredText::chars($name, self::NAME_CHARS);
    }

    #[\Override]
    public function findOrCreateBrowser(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT browser_id FROM 202_browsers WHERE browser_name = ?',
            's',
            [$name],
            'browser_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_browsers SET browser_name = ?',
            's',
            [$name]
        );
    }

    #[\Override]
    public function findOrCreatePlatform(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT platform_id FROM 202_platforms WHERE platform_name = ?',
            's',
            [$name],
            'platform_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_platforms SET platform_name = ?',
            's',
            [$name]
        );
    }

    /**
     * The catalog is 202_device_models (202_devices is created by no install
     * path). device_type is NOT NULL with no default and must be a seeded
     * type, or the model drops out of every `device_type = N` filter for
     * good; 1 (Desktop) is connect2.php's fallback for an unrecognised
     * device, and this interface carries no type.
     */
    #[\Override]
    public function findOrCreateDevice(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT device_id FROM 202_device_models WHERE device_name = ?',
            's',
            [$name],
            'device_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_device_models SET device_name = ?, device_type = 1',
            's',
            [$name]
        );
    }
}
