<?php

declare(strict_types=1);

namespace Prosper202\Integration;

use Prosper202\Database\Connection;

/**
 * Which accounts a notification endpoint checks a signature against: every
 * active account that has stored the secret. The Integrations page saves a
 * secret on the signed-in account's own preferences, so an endpoint that
 * read only user 1's would ignore every other account's.
 */
final class NotificationSecrets
{
    /** The preference columns that hold a notification endpoint's secret. */
    public const COLUMNS = [
        'jvzoo_ipn_secret_key',
        'zaxaa_api_signature',
        'paykickstart_ipn_secret',
        'user_slack_signing_secret',
    ];

    /**
     * @return list<array{user_id: int, secret: string, currency: string}> by user id
     */
    public static function holders(Connection $conn, string $column): array
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \InvalidArgumentException("$column is not a notification secret");
        }
        $stmt = $conn->prepareRead(
            "SELECT p.user_id, p.`$column` AS secret, p.user_account_currency AS currency
             FROM 202_users_pref AS p
             INNER JOIN 202_users AS u ON u.user_id = p.user_id
             WHERE u.user_deleted = 0 AND u.user_active = 1 AND p.`$column` IS NOT NULL AND p.`$column` <> ''
             ORDER BY p.user_id"
        );
        $out = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $secret = trim((string) $row['secret']);
            if ($secret !== '') {
                $out[] = [
                    'user_id' => (int) $row['user_id'],
                    'secret' => $secret,
                    'currency' => (string) $row['currency'],
                ];
            }
        }

        return $out;
    }
}
