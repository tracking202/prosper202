<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Prosper202\Database\Tables\AppTables;

/**
 * The real-database plumbing the attribution integration tests share: a
 * connection from the P202_TEST_DB_* environment (tests skip without it),
 * the shipped attribution DDL applied to it, and the mysqli report mode
 * the receiver documents.
 *
 * Production report mode (PHP's default since 8.1, and what the receiver
 * documents): failures throw, so the exception branch of insertPostback()
 * is the one exercised. mysqli_report() is process-global and returns
 * bool, not the mode it replaced — PHP exposes no getter — so teardown
 * restores the documented 8.1+ default rather than a captured value.
 * Leaving it set would decide, by class order, whether a later suite's
 * mysqli throws or returns false.
 */
trait ConnectsToTestDatabase
{
    private static ?\mysqli $db = null;
    private static bool $reportModeChanged = false;

    private static function connectTestDatabase(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return; // no DB configured; individual tests will skip
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$reportModeChanged = true;

        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return; // connection failed; tests will skip
        }
        if (!$db) {
            return;
        }

        // The same DDL the installer and the 1.9.76 upgrade step run.
        foreach (AppTables::getDefinitions() as $definition) {
            $db->query($definition->createStatement);
        }
        self::$db = $db;
    }

    private static function disconnectTestDatabase(): void
    {
        if (self::$db) {
            self::$db->close();
            self::$db = null;
        }
        if (self::$reportModeChanged) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$reportModeChanged = false;
        }
    }

    /** Skip without a database; otherwise start from empty attribution tables. */
    private function requireEmptyAttributionTables(): \mysqli
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        self::$db->query('TRUNCATE TABLE 202_app_postbacks');
        self::$db->query('TRUNCATE TABLE 202_app_registrations');
        self::$db->query('TRUNCATE TABLE 202_app_skan_encodings');
        self::$db->query('TRUNCATE TABLE 202_app_skan_encoding_history');
        return self::$db;
    }
}
