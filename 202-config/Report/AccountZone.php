<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;

/**
 * The account's time zone (202_users.user_timezone), as the reports read
 * it: UTC when it is unset or not a zone PHP knows. One rule for the API
 * controllers (api/v3/Support/AccountTimezone) and for the readers that run
 * without one, such as the attribution export cron.
 */
final class AccountZone
{
    private function __construct()
    {
    }

    public static function normalize(?string $stored): string
    {
        $timezone = trim((string) $stored);
        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    /** The account's zone; a read that fails throws (it is not an account in UTC). */
    public static function read(Connection $conn, int $userId): string
    {
        $stmt = $conn->prepareRead('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return self::normalize(isset($row['user_timezone']) ? (string) $row['user_timezone'] : null);
    }
}
