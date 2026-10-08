<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LastClickFromAddress;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * "This visitor's last click" by the address the click path stored it
 * under — IPv4 by its text, IPv6 through 202_ips_v6, as findOrCreateIp()
 * writes them — for one account, inside a window.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes clicks 970000-970008 for users
 * 5701 and 5702.
 *
 * @group integration
 */
final class LastClickFromAddressIntegrationTest extends TestCase
{
    private const USER = 5701;
    private const OTHER = 5702;
    private const CLICKS = [970000, 970001, 970002, 970003, 970004, 970005, 970006, 970007, 970008];

    private static ?\mysqli $db = null;
    private static ?Connection $conn = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // SchemaInstaller calls the global helper connect.php defines.
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        self::$conn = new Connection($db);
        self::cleanUp();

        $now = time();
        $locations = new MysqlLocationRepository(self::$conn);
        $v4 = $locations->findOrCreateIp('203.0.113.9');
        $v4Other = $locations->findOrCreateIp('198.51.100.7');
        $v6 = $locations->findOrCreateIp('2001:db8:abcd:12::9');
        $masked = $locations->findOrCreateIp('203.0.113.0');
        // A second 202_ips row for the same address, as the legacy writer
        // (INDEXES::insert_ip) and the repository could each leave one.
        self::q("INSERT INTO 202_ips SET ip_address = '203.0.113.9'");
        $v4Duplicate = (int) self::$db->insert_id;

        // [click, user, ip_id, seconds ago, public id]; click ids rise with
        // time, as allocated ones do ("last" is the highest id).
        $clicks = [
            [970000, self::USER, $v4Duplicate, 7200, '1970000'],
            [970001, self::USER, $v4, 3600, '1970001'],
            [970002, self::USER, $v4Other, 1800, '1970002'],
            [970003, self::OTHER, $v4, 60, '1970003'],
            [970004, self::USER, $v6, 600, '1970004'],
            [970005, self::USER, $v4, 40 * 86400, '1970005'],
            [970006, self::USER, $masked, 300, '1970006'],
            [970008, self::OTHER, $v6, 30, '1970008'],
        ];
        foreach ($clicks as [$click, $user, $ipId, $ago, $public]) {
            self::insertClick($click, $user, $now - $ago);
            $advance = 'INSERT INTO 202_clicks_advance SET click_id = %d, ip_id = %d, keyword_id = 11';
            self::q(sprintf($advance, $click, $ipId));
            $record = "INSERT INTO 202_clicks_record SET click_id = %d, click_id_public = '%s'";
            self::q(sprintf($record, $click, $public));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
        self::$conn = null;
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
    }

    public function testTheLatestClickOfThisAddressForThisAccount(): void
    {
        $row = LastClickFromAddress::find(self::$conn, '203.0.113.9', self::USER, time() - 30 * 86400, false);
        // 970003 is newer but another account's, 970005 outside the window,
        // 970002 and 970006 other addresses.
        self::assertSame(
            ['click_id' => 970001, 'ppc_account_id' => 7, 'click_id_public' => '1970001', 'keyword_id' => 11],
            $row
        );
    }

    public function testEveryRowStoredForTheAddressIsSearched(): void
    {
        // Both 202_ips rows for the address hold a click inside three hours;
        // 970001 is the newer. With it gone, the other row's click is found.
        $row = LastClickFromAddress::find(self::$conn, '203.0.113.9', self::USER, time() - 3 * 3600, false);
        self::assertSame(970001, $row['click_id'] ?? null);
        self::q('DELETE FROM 202_clicks WHERE click_id = 970001');
        $row = LastClickFromAddress::find(self::$conn, '203.0.113.9', self::USER, time() - 3 * 3600, false);
        self::assertSame(970000, $row['click_id'] ?? null, 'the click stored under the second 202_ips row');
        self::insertClick(970001, self::USER, time() - 3600);
    }

    public function testAnIpv6ClickIsFoundThroughItsTable(): void
    {
        $row = LastClickFromAddress::find(self::$conn, '2001:db8:abcd:12::9', self::USER, time() - 86400, false);
        self::assertSame(970004, $row['click_id'] ?? null);
    }

    public function testAMaskedAddressFindsOnlyClicksStoredMasked(): void
    {
        $row = LastClickFromAddress::find(self::$conn, '203.0.113.0', self::USER, time() - 86400, true);
        self::assertSame(970006, $row['click_id'] ?? null);
    }

    /**
     * A masked address names a block, not a visitor: with a second click
     * from the block in the window, the latest of them is anyone's in it,
     * so none is credited. Unmasked, the same rows answer the latest.
     */
    public function testAMaskedAddressAnswersOnlyWhenOneClickMatches(): void
    {
        $masked = (int) self::$db->query("SELECT ip_id FROM 202_ips WHERE ip_address = '203.0.113.0' ORDER BY ip_id LIMIT 1")->fetch_row()[0];
        self::insertClick(970007, self::USER, time() - 120);
        self::q("INSERT INTO 202_clicks_advance SET click_id = 970007, ip_id = $masked, keyword_id = 11");
        try {
            self::assertNull(LastClickFromAddress::find(self::$conn, '203.0.113.0', self::USER, time() - 86400, true), 'two clicks from the block: nobody\'s to choose');
            self::assertSame(970007, LastClickFromAddress::find(self::$conn, '203.0.113.0', self::USER, time() - 86400, false)['click_id'] ?? null, 'unmasked, the latest');
            self::assertSame(970007, LastClickFromAddress::find(self::$conn, '203.0.113.0', self::USER, time() - 200, true)['click_id'] ?? null, 'one click in a shorter window is answered');
        } finally {
            self::q('DELETE FROM 202_clicks WHERE click_id = 970007');
            self::q('DELETE FROM 202_clicks_advance WHERE click_id = 970007');
        }
    }

    public function testNoClickIsNull(): void
    {
        self::assertNull(LastClickFromAddress::find(self::$conn, '192.0.2.1', self::USER, 0, false));
        self::assertNull(LastClickFromAddress::find(self::$conn, '203.0.113.9', 999999, 0, false));
        self::assertNull(LastClickFromAddress::find(self::$conn, '203.0.113.9', self::USER, time() + 60, false));
    }

    public function testNoAddressIsNoLookup(): void
    {
        self::assertNull(LastClickFromAddress::find(self::$conn, '', self::USER, 0, false));
        self::assertNull(LastClickFromAddress::find(self::$conn, 'nope', self::USER, 0, false));
    }

    private static function cleanUp(): void
    {
        $ids = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($ids)");
        }
    }

    private static function insertClick(int $click, int $user, int $time): void
    {
        self::q(sprintf(
            'INSERT INTO 202_clicks SET click_id = %d, user_id = %d, click_time = %d, ppc_account_id = 7',
            $click,
            $user,
            $time
        ));
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }
}
