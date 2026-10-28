<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * The click filter's "don't count my own clicks" check, run as the click
 * path runs it: the real FILTER class, extracted from connect2.php (which a
 * test cannot include), against a real database.
 *
 * A user's sign-in address is stored as it arrived under every privacy
 * setting; a click's is stored masked under privacy. The check compared the
 * click's stored ip_id with the sign-in's, so under privacy it never
 * matched: a click from the very address the owner signed in from was
 * counted (measured live, then here). It now compares the address the click
 * arrived from, read without storing it — and only that address: a
 * neighbour in the owner's /24 is not the owner.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5801-5802 and their
 * 202_last_ips rows.
 *
 * @group integration
 */
final class FilterSignedInAddressIntegrationTest extends TestCase
{
    private const OWNER = 5801;
    private const OWNER_V6 = 5802;

    private static ?\mysqli $db = null;

    /** @var array<string, int> address => its 202_ips row */
    private static array $ids = [];

    /** @var array<string, mixed> */
    private array $server = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('FILTER', false)) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
            $start = strpos($src, "\nclass FILTER\n");
            $end = strpos($src, "\nfunction rotateTrackerUrl(");
            self::assertIsInt($start, 'class FILTER not found in connect2.php');
            self::assertIsInt($end);
            eval(substr($src, $start, $end - $start));
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
        self::cleanUp();

        // Stored the way the click path and the sign-in store them.
        $locations = new MysqlLocationRepository(new Connection($db));
        foreach (['192.0.2.21', '192.0.2.0', '198.51.100.7', '2001:db8:77::77', '2001:db8:77::'] as $address) {
            self::$ids[$address] = $locations->findOrCreateIp($address);
        }
        foreach ([self::OWNER => '192.0.2.21', self::OWNER_V6 => '2001:db8:77::77'] as $user => $signedInFrom) {
            self::q('INSERT INTO 202_users SET user_id = ' . $user . ", user_name = 'filter-owner-" . $user . "',"
                . ' user_last_login_ip_id = ' . self::$ids[$signedInFrom]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        $this->server = $_SERVER;
        // The duplicate-address check records what it sees; each case is a
        // first click.
        self::q('DELETE FROM 202_last_ips WHERE user_id IN (' . self::OWNER . ', ' . self::OWNER_V6 . ')');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_last_ips WHERE user_id IN (' . self::OWNER . ', ' . self::OWNER_V6 . ')');
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::OWNER . ', ' . self::OWNER_V6 . ')');
    }

    /** The request the click arrives in: from $address, through no proxy. */
    private static function arrivesFrom(string $address): void
    {
        $_SERVER = ['REMOTE_ADDR' => $address, 'REQUEST_METHOD' => 'GET'];
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) !== true) {
            throw new \RuntimeException('query failed: ' . self::$db->error . ' — ' . $sql);
        }
    }

    /**
     * [the address the click arrived from, the address it was stored as,
     * whose click, filtered].
     *
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function clicks(): iterable
    {
        yield 'privacy off: from the sign-in address' => ['192.0.2.21', '192.0.2.21', self::OWNER, 1];
        yield 'privacy on: from the sign-in address, stored masked' => ['192.0.2.21', '192.0.2.0', self::OWNER, 1];
        yield 'privacy on: a neighbour in the owner\'s /24' => ['192.0.2.22', '192.0.2.0', self::OWNER, 0];
        yield 'another address' => ['198.51.100.7', '198.51.100.7', self::OWNER, 0];
        yield 'privacy on, IPv6: from the sign-in address' => ['2001:db8:77::77', '2001:db8:77::', self::OWNER_V6, 1];
        yield 'privacy on, IPv6: a neighbour in its /48' => ['2001:db8:77::78', '2001:db8:77::', self::OWNER_V6, 0];
    }

    /** @dataProvider clicks */
    public function testTheOwnersOwnClickIsFilteredUnderEverySetting(
        string $arrived,
        string $stored,
        int $owner,
        int $filtered
    ): void {
        self::arrivesFrom($arrived);
        self::assertSame($filtered, \FILTER::startFilter(self::$db, 0, self::$ids[$stored], $owner));
    }

    public function testTheCheckStoresNothingAboutTheAddressItLooksUp(): void
    {
        $before = (int) self::$db->query('SELECT COUNT(*) AS n FROM 202_ips')->fetch_assoc()['n'];
        self::arrivesFrom('192.0.2.99');
        \FILTER::startFilter(self::$db, 0, self::$ids['192.0.2.0'], self::OWNER);
        self::arrivesFrom('2001:db8:77::99');
        \FILTER::startFilter(self::$db, 0, self::$ids['2001:db8:77::'], self::OWNER_V6);
        self::assertSame($before, (int) self::$db->query('SELECT COUNT(*) AS n FROM 202_ips')->fetch_assoc()['n']);
    }
}
