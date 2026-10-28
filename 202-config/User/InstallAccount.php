<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Attribution\DefaultModel;
use Prosper202\Database\Connection;
use Throwable;

/**
 * The installer's account (202-config/install.php): the user row, its
 * preferences row, its admin role, its default attribution model and, for
 * a user this request created, its dashboard chart — in one transaction, so
 * a failure part way leaves no half-built account behind. The schema is
 * built before it: DDL commits an open transaction.
 */
final class InstallAccount
{
    /** The server's errors a retry of the install can get past. */
    private const TRANSIENT = [
        1205 => 'Lock wait timeout',
        1213 => 'Deadlock found',
        2006 => 'server has gone away',
        2013 => 'Lost connection',
    ];

    /**
     * The default dashboard chart: clicks, click-throughs and leads of every
     * campaign.
     */
    private const CHART = 'a:3:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:6:"clicks";}'
        . 'i:1;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:9:"click_out";}'
        . 'i:2;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:5:"leads";}}';

    private function __construct()
    {
    }

    /**
     * Create the account, or finish one a concurrent install of the same
     * user name created: the user row is INSERT IGNORE, and its id is then
     * read by name.
     *
     * @param array{email: string, name: string, pass_hash: string, timezone: string, registered: int,
     *              install_hash: string, user_hash: string, customer_key: string} $account
     * @return int the user's id
     * @throws Throwable when nothing was written
     */
    public static function create(Connection $conn, array $account): int
    {
        return $conn->transaction(static function () use ($conn, $account): int {
            // user_dash_email is NOT NULL with no default: the account email,
            // so the row is valid under any sql_mode.
            $stmt = $conn->prepareWrite(
                'INSERT IGNORE INTO 202_users SET user_email = ?, user_dash_email = ?, user_name = ?, user_pass = ?,'
                    . ' user_timezone = ?, user_time_register = ?, install_hash = ?, user_hash = ?,'
                    . ' p202_customer_api_key = ?'
            );
            $conn->bind($stmt, 'sssssisss', [
                $account['email'], $account['email'], $account['name'], $account['pass_hash'], $account['timezone'],
                $account['registered'], $account['install_hash'], $account['user_hash'], $account['customer_key'],
            ]);
            $userId = $conn->executeInsert($stmt);
            // Only a user this request created gets a chart: 202_charts has
            // no unique key, so a second one would be a duplicate. The
            // preferences and role rows are INSERT IGNORE, safe either way.
            $created = $userId > 0;
            if (!$created) {
                $stmt = $conn->prepareWrite('SELECT user_id FROM 202_users WHERE user_name = ? LIMIT 1');
                $conn->bind($stmt, 's', [$account['name']]);
                $found = $conn->fetchOne($stmt)['user_id'] ?? null;
                $userId = is_int($found) || (is_string($found) && ctype_digit($found)) ? (int) $found : 0;
            }
            if ($userId <= 0) {
                throw new \RuntimeException('Could not determine a valid user_id');
            }

            $stmt = $conn->prepareWrite('INSERT IGNORE INTO 202_users_pref SET user_id = ?');
            $conn->bind($stmt, 'i', [$userId]);
            $conn->executeUpdate($stmt);

            $stmt = $conn->prepareWrite('INSERT IGNORE INTO `202_user_role` (`user_id`, `role_id`) VALUES (?, 1)');
            $conn->bind($stmt, 'i', [$userId]);
            $conn->executeUpdate($stmt);

            // The same default model the upgrade rung and user creation give
            // an account (plan §6.4).
            DefaultModel::ensureFor($conn, $userId);

            if ($created) {
                // On the account's own id: a rolled-back attempt spends an
                // AUTO_INCREMENT id, so it is not necessarily user 1.
                $stmt = $conn->prepareWrite(
                    'INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`) VALUES (?, ?, ?)'
                );
                $conn->bind($stmt, 'iss', [$userId, self::CHART, 'days']);
                $conn->executeUpdate($stmt);
            }

            return $userId;
        });
    }

    /**
     * Whether the install failed on something a retry can get past: one of
     * those errors as an exception's code (the schema build's), or as
     * Connection reports it.
     */
    public static function isTransient(Throwable $e): bool
    {
        if (isset(self::TRANSIENT[(int) $e->getCode()])) {
            return true;
        }
        foreach (self::TRANSIENT as $errno => $english) {
            if (Connection::isMysqlError($e, $errno, $english)) {
                return true;
            }
        }

        return false;
    }
}
