<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\AdSettings;

/**
 * AdSettings::forUser() against a real database: the stored choice, the
 * column's default for a user with no preferences row, and a failed read
 * that throws rather than answering a choice nobody made.
 *
 * Skips unless a scratch test database is configured: P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME. It installs the schema there and writes user 5101.
 *
 * @group integration
 */
final class AdSettingsIntegrationTest extends TestCase
{
    private const USER = 5101;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
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
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function storedChoices(): iterable
    {
        yield 'show all' => ['show_all', true];
        yield 'hide on login' => ['hide_login', true];
        yield 'hide all' => ['hide_all', false];
    }

    /** @dataProvider storedChoices */
    public function testTheStoredChoiceDecidesSpecialOffers(string $stored, bool $shown): void
    {
        self::$db->query('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_pref_ad_settings = '" . $stored . "'");

        $setting = AdSettings::forUser(new Connection(self::$db), self::USER);

        self::assertSame($stored, $setting);
        self::assertSame($shown, AdSettings::showsHomeOffers($setting));
    }

    public function testAUserWithNoPreferencesRowGetsTheColumnDefault(): void
    {
        $setting = AdSettings::forUser(new Connection(self::$db), self::USER);

        self::assertSame('show_all', $setting);
        self::assertTrue(AdSettings::showsHomeOffers($setting));
    }

    public function testTheDefaultIsTheColumnsDefault(): void
    {
        $result = self::$db->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '202_users_pref' AND COLUMN_NAME = 'user_pref_ad_settings'");
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row);

        self::assertSame(AdSettings::DEFAULT, trim((string) $row['COLUMN_DEFAULT'], "'"));
    }

    public function testAFailedReadThrows(): void
    {
        $conn = new Connection(self::$db);
        self::$db->query('RENAME TABLE 202_users_pref TO 202_users_pref_gone');
        try {
            $this->expectException(\Throwable::class);
            AdSettings::forUser($conn, self::USER);
        } finally {
            self::$db->query('RENAME TABLE 202_users_pref_gone TO 202_users_pref');
        }
    }
}
