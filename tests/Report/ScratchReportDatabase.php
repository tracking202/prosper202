<?php

declare(strict_types=1);

namespace Tests\Report;

use Prosper202\Database\SchemaInstaller;

/**
 * The scratch database the report-page integration tests run their SQL on:
 * the one named by P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 * P202_TEST_DB_PASS and P202_TEST_DB_NAME, with the schema installed. Without
 * one the tests skip. Each class owns its user ids and removes their rows at
 * both ends, so the classes can share one database.
 */
trait ScratchReportDatabase
{
    private static ?\mysqli $db = null;

    /** The strict mode a MySQL 8 server runs with, ONLY_FULL_GROUP_BY included. */
    private static string $strict = "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
        . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

    private static function connectScratch(): bool
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return false;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return false;
        }
        if (!$db) {
            return false;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query(self::$strict);
        self::$db = $db;

        return true;
    }

    private static function requireScratch(): \mysqli
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }

        return self::$db;
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function insert(string $sql): int
    {
        self::q($sql);

        return (int) self::$db->insert_id;
    }

    private static function str(string $value): string
    {
        return "'" . self::$db->real_escape_string($value) . "'";
    }

    /**
     * One row of $table from a column => value map; null is SQL NULL.
     *
     * @param array<string, int|float|string|null> $row
     */
    private static function row(string $table, array $row): void
    {
        self::q("INSERT INTO $table SET " . implode(', ', array_map(
            static fn (string $k, mixed $v): string => "`$k` = " . ($v === null ? 'NULL' : (is_string($v) ? self::str($v) : $v)),
            array_keys($row),
            $row
        )));
    }

    /** A user row the reports can read the time zone of. */
    private static function user(int $userId, string $timezone = 'UTC'): void
    {
        self::q("INSERT INTO 202_users SET user_id = $userId, user_name = 'rp$userId',"
            . " user_email = 'rp$userId@example.com', user_dash_email = '', user_pass = 'x',"
            . ' user_timezone = ' . self::str($timezone) . ", user_time_register = 0, install_hash = '', user_hash = ''");
    }
}
