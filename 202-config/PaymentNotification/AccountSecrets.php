<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Database\Connection;

/**
 * The one read of a payment provider's stored secrets, which decides the
 * accounts a notification can belong to (ProviderAdapter::storedSecrets()).
 *
 * A deleted account owns no key. Its preferences row stays behind with
 * whatever secret it held, and read here it would credit the deleted
 * account's clicks, or, where a provider refuses a key two accounts store,
 * refuse every notification of the live account that stores it now, with
 * nothing in the UI to clear the deleted account's copy. A user switched
 * off in Account › Users (user_active = 0) still owns its keys: a sale the
 * provider reports for that account is not that person acting.
 */
final class AccountSecrets
{
    private function __construct()
    {
    }

    /**
     * Each account's value of a 202_users_pref secret column, as stored and
     * not empty, by account id in account order.
     *
     * @return array<int, string>
     */
    public static function stored(Connection $conn, string $column): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1) {
            throw new \LogicException('"' . $column . '" is not a 202_users_pref column name');
        }
        $stmt = $conn->prepareRead(
            'SELECT p.user_id, p.`' . $column . '` AS secret FROM 202_users_pref AS p'
            . ' INNER JOIN 202_users AS u ON u.user_id = p.user_id'
            . ' WHERE u.user_deleted = 0 AND p.`' . $column . '` IS NOT NULL AND p.`' . $column . "` <> ''"
            . ' ORDER BY p.user_id'
        );
        $secrets = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            if (is_string($row['secret'])) {
                $secrets[(int) $row['user_id']] = $row['secret'];
            }
        }

        return $secrets;
    }
}
