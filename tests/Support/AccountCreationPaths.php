<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;
use Prosper202\User\InstallAccount;
use Prosper202\User\MysqlUserRepository;

/**
 * One account made through each path that makes one, on a real connection:
 * the installer (InstallAccount), Settings › Users
 * (p202_account_save_user()), POST /users (UsersController::create(),
 * which `p202 user create` and `bin/p202 user:create` call) and
 * MysqlUserRepository. Each makes its preferences row naming user_id alone
 * (NewAccountPrivacyIsTheColumnDefaultTest), so every column of it is the
 * table's DEFAULT, the privacy setting among them.
 */
final class AccountCreationPaths
{
    /** The rows a made account has, in the order they are removed. */
    private const TABLES = ['202_users_pref', '202_user_role', '202_attribution_models', '202_charts', '202_users'];

    /**
     * @return array<string, int> path => the account it made, named "$prefix-<path>"
     */
    public static function makeOneEach(\mysqli $db, string $prefix): array
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/functions-auth.php';
        require_once $root . '/202-config/functions-account-ui.php';
        // The roles the installer seeds (DataSeeder): the account's role row names one.
        $roles = "INSERT IGNORE INTO 202_roles (role_id, role_name) VALUES (1, 'Super user'), (2, 'Admin')";
        if ($db->query($roles) !== true) {
            throw new \RuntimeException('could not seed the roles: ' . $db->error);
        }
        $mode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
        $conn = new Connection($db);
        $made = [];

        try {
            // The pages' and the installer's connections run under the empty
            // sql_mode (connect.php, SchemaInstaller), the API's under the
            // server's strict default.
            self::mode($db, '');
            $made['installer'] = InstallAccount::create($conn, [
                'email' => "$prefix-installer@example.com", 'name' => "$prefix-installer", 'pass_hash' => 'x',
                'timezone' => 'UTC', 'registered' => time(), 'install_hash' => '', 'user_hash' => '',
                'customer_key' => '',
            ]);

            $made['settings-users'] = p202_account_save_user($conn, null, [
                'user_fname' => 'A', 'user_lname' => 'B', 'user_email' => "$prefix-settings@example.com",
                'user_name' => "$prefix-settings", 'user_time_register' => time(), 'user_timezone' => 'UTC',
                'user_pass' => 'x', 'install_hash' => '', 'user_hash' => '', 'user_active' => 1,
            ], 2);

            $made['repository'] = (new MysqlUserRepository($conn))->create([
                'fname' => 'A', 'lname' => 'B', 'name' => "$prefix-repository", 'password' => 'a-password-1',
                'email' => "$prefix-repository@example.com", 'timezone' => 'UTC', 'active' => 1,
            ]);

            self::mode($db, 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION');
            $created = (new UsersController($db))->create([
                'user_name' => "$prefix-api", 'user_email' => "$prefix-api@example.com", 'user_pass' => 'a-password-1',
            ]);
            $made['api'] = (int) $created['data']['user_id'];
        } finally {
            self::mode($db, $mode);
        }

        return $made;
    }

    private static function mode(\mysqli $db, string $mode): void
    {
        if ($db->query("SET SESSION sql_mode = '" . $db->real_escape_string($mode) . "'") !== true) {
            throw new \RuntimeException('could not set the sql_mode: ' . $db->error);
        }
    }

    /** @param array<string, int> $made what makeOneEach() answered */
    public static function remove(\mysqli $db, array $made): void
    {
        $ids = implode(', ', array_map('intval', $made));
        if ($ids === '') {
            return;
        }
        foreach (self::TABLES as $table) {
            $db->query("DELETE FROM $table WHERE user_id IN ($ids)");
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> user id => its stored privacy setting
     */
    public static function privacyOf(\mysqli $db, array $ids): array
    {
        $result = $db->query('SELECT user_id, user_pref_privacy FROM 202_users_pref WHERE user_id IN ('
            . implode(', ', array_map('intval', $ids)) . ') ORDER BY user_id');
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('could not read the privacy settings: ' . $db->error);
        }
        $out = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $out[(int) $row['user_id']] = (string) $row['user_pref_privacy'];
        }

        return $out;
    }
}
