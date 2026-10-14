<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\User\SignInRecord;

/**
 * A sign-in's record on a real database: the address and the survey flags
 * land on the user, and a write the database refuses throws (202-login.php
 * then refuses the sign-in) with the user's row as it was.
 *
 * Skips unless a scratch test database is configured: P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME. It installs the schema there and writes user 5002.
 *
 * @group integration
 */
final class SignInRecordIntegrationTest extends TestCase
{
    private const USER = 5002;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null)'
                . ' { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        $db = mysqli_connect(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $db);
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->query('DROP TRIGGER IF EXISTS p202_refuse_sign_in');
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        $u = self::USER;
        self::q("DELETE FROM 202_users WHERE user_id = $u");
        self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'signin$u', user_pass = 'x',"
            . " user_email = 's$u@example.com', user_dash_email = '', user_timezone = 'UTC', user_time_register = 0,"
            . " install_hash = '', user_hash = '', user_deleted = 0, user_last_login_ip_id = 0,"
            . " modal_status = 0, vip_perks_status = 0");
    }

    public function testTheAddressAndFlagsLandOnTheUser(): void
    {
        $record = self::record();
        $record->record(self::USER, '2001:db8::5a1', 1, 1);

        $row = self::row();
        self::assertSame(['1', '1'], [$row['modal_status'], $row['vip_perks_status']]);
        self::assertSame('2001:db8::5a1', self::addressOf((int) $row['user_last_login_ip_id']));

        $record->record(self::USER, '198.51.100.7', 0, 0);
        self::assertSame('198.51.100.7', self::addressOf((int) self::row()['user_last_login_ip_id']));
    }

    public function testARefusedUpdateThrowsAndLeavesTheUserAsItWas(): void
    {
        self::q('DROP TRIGGER IF EXISTS p202_refuse_sign_in');
        self::q("CREATE TRIGGER p202_refuse_sign_in BEFORE UPDATE ON 202_users FOR EACH ROW BEGIN"
            . " IF NEW.user_id = " . self::USER . " THEN"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the sign-in update is refused'; END IF; END");
        try {
            self::record()->record(self::USER, '198.51.100.8', 1, 1);
            self::fail('a sign-in the database refused to record was answered');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('the sign-in update is refused', $e->getMessage());
        } finally {
            self::q('DROP TRIGGER p202_refuse_sign_in');
        }

        $row = self::row();
        self::assertSame(
            ['0', '0', '0'],
            [$row['user_last_login_ip_id'], $row['modal_status'], $row['vip_perks_status']]
        );
    }

    public function testARefusedAddressLookupThrowsBeforeTheUpdate(): void
    {
        self::q('RENAME TABLE 202_ips TO 202_ips_away');
        try {
            self::record()->record(self::USER, '198.51.100.9', 1, 1);
            self::fail('a sign-in whose address could not be stored was answered');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('202_ips', $e->getMessage());
        } finally {
            self::q('RENAME TABLE 202_ips_away TO 202_ips');
        }

        self::assertSame('0', self::row()['modal_status'], 'the user was not updated');
    }

    private static function record(): SignInRecord
    {
        self::assertNotNull(self::$db);
        $conn = new Connection(self::$db);

        return new SignInRecord($conn, new MysqlLocationRepository($conn));
    }

    /** @return array<string, string> */
    private static function row(): array
    {
        self::assertNotNull(self::$db);
        $result = self::$db->query('SELECT user_last_login_ip_id, modal_status, vip_perks_status FROM 202_users'
            . ' WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row);

        return array_map('strval', $row);
    }

    private static function addressOf(int $ipId): string
    {
        self::assertNotNull(self::$db);
        $result = self::$db->query("SELECT CASE WHEN i.ip_address REGEXP '^[0-9]+$' THEN INET6_NTOA(v.ip_address)"
            . ' ELSE i.ip_address END FROM 202_ips i LEFT JOIN 202_ips_v6 v ON (i.ip_address REGEXP \'^[0-9]+$\''
            . ' AND v.ip_id = CAST(i.ip_address AS UNSIGNED)) WHERE i.ip_id = ' . $ipId);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        self::assertIsArray($row);

        return (string) $row[0];
    }

    private static function q(string $sql): void
    {
        self::assertNotNull(self::$db);
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }
}
