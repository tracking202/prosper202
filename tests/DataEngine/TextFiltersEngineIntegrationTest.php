<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * The Analyze pages' keyword, referer and IP filters through the engine
 * itself — DataEngine::getReportData() reading the stored preferences, as a
 * page does — on a server whose GROUP_CONCAT stops at 1024 bytes (MySQL 8's
 * default; MariaDB's is 1 MB, which a busy referer filter also passes).
 *
 * Before: the referer was resolved to a GROUP_CONCAT id list, cut without an
 * error, so the clicks of the URLs past the cut vanished; the address to the
 * first of its 202_ips rows; the keyword pasted into LIKE.
 * tests/Report/ReportTextFiltersIntegrationTest covers the fragments
 * against the API's; this covers the engine's own path to them.
 *
 * With the app booted, it also drives the pages' window: grab_timeframe()
 * through AUTH::set_timezone() takes "today" from the ACCOUNT's zone when
 * the session's copy is stale, and the Visitors list (query()) keeps a
 * day's first and last second.
 *
 * Boots the app as tests/DataEngine/ReportIntegrationTest does (it needs a
 * 202-config.php and P202_TEST_DB_*).
 *
 * @group integration
 */
final class TextFiltersEngineIntegrationTest extends TestCase
{
    private const USER = 990021;

    /** Referring URLs matching the filter: their ids are well over 1024 bytes. */
    private const MANY = 300;

    private static ?\mysqli $db = null;

    private static int $time = 0;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $dbName = getenv('P202_TEST_DB_NAME') ?: '';
        if ($dbName === '') {
            self::markTestSkipped('Set P202_TEST_DB_NAME (and _HOST/_USER/_PASS) to run the engine integration test.');
        }
        $GLOBALS['dbname'] = $dbName;
        $GLOBALS['dbhost'] = getenv('P202_TEST_DB_HOST') ?: '127.0.0.1';
        $GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
        $GLOBALS['dbuser'] = getenv('P202_TEST_DB_USER') ?: 'root';
        $GLOBALS['dbpass'] = getenv('P202_TEST_DB_PASS') ?: '';
        $GLOBALS['mchost'] = '';
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any test ran. tests/run-integration-suites.sh writes one.');
        }
        $prev = error_reporting(0);
        ob_start();
        require_once $root . '/202-config/connect.php';
        require_once $root . '/202-config/class-dataengine.php';
        ob_get_clean();
        error_reporting($prev);
        foreach (get_defined_vars() as $name => $value) {
            if ($name !== 'name' && $name !== 'value') {
                $GLOBALS[$name] = $value;
            }
        }
        try {
            self::$db = \DB::getInstance()->getConnection();
        } catch (\Throwable) {
            self::$db = null;
        }
        if (!self::$db instanceof \mysqli || self::$db->connect_errno) {
            self::markTestSkipped('No database connection available.');
        }
        $check = self::$db->query("SHOW TABLES LIKE '202_dataengine'");
        if ($check instanceof \mysqli_result && $check->num_rows === 0) {
            (new \Prosper202\Database\SchemaInstaller(self::$db))->install();
        }
        self::cleanUp();
        self::seed();
        // The engine runs on this connection: MySQL 8's default.
        self::q('SET SESSION group_concat_max_len = 1024');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db instanceof \mysqli) {
            self::q('SET SESSION group_concat_max_len = 1048576');
            self::cleanUp();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        $_SESSION['user_id'] = self::USER;
        $_SESSION['user_own_id'] = self::USER;
        $_SESSION['publisher'] = false;
        $_SESSION['user_timezone'] = 'America/New_York';
        $_POST = [];
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_dataengine WHERE user_id = ' . self::USER);
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::$db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::$db->query("DELETE FROM 202_keywords WHERE keyword LIKE 'te990021 %'");
        self::$db->query("DELETE FROM 202_site_urls WHERE site_url_address LIKE 'https://te990021.example/%'");
        self::$db->query("DELETE FROM 202_ips WHERE ip_address = '10.99.0.21'");
    }

    private static function seed(): void
    {
        self::$time = time();
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_pref_show = 'all', user_pref_limit = 200");
        // The account's zone is Tokyo; the session (setUp) still says New York.
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'te990021',"
            . " user_email = 'te990021@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'Asia/Tokyo', user_time_register = 0, install_hash = '', user_hash = ''");
        $id = static function (string $sql): int {
            self::q($sql);

            return (int) self::$db->insert_id;
        };
        $n = 0;
        $click = static function (array $c) use (&$n): void {
            $n++;
            $c += ['keyword_id' => 0, 'ip_id' => 0, 'click_referer_site_url_id' => 0];
            self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, landing_page_id,'
                . ' keyword_id, ip_id, click_referer_site_url_id, clicks, click_out, leads, payout, income, cost) VALUES ('
                . self::USER . ', ' . (990021000 + $n) . ', ' . self::$time . ", 0, 0, {$c['keyword_id']}, {$c['ip_id']},"
                . " {$c['click_referer_site_url_id']}, 1, 1, 0, 0, 0, 0)");
        };
        $click(['keyword_id' => $id("INSERT INTO 202_keywords SET keyword = 'te990021 50% off'")]);
        $click(['keyword_id' => $id("INSERT INTO 202_keywords SET keyword = 'te990021 500 off'")]);
        $click(['ip_id' => $id("INSERT INTO 202_ips SET ip_address = '10.99.0.21'")]);
        $click(['ip_id' => $id("INSERT INTO 202_ips SET ip_address = '10.99.0.21'")]);
        for ($m = 0; $m < self::MANY; $m++) {
            $click(['click_referer_site_url_id' => $id(
                "INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = 'https://te990021.example/p$m'"
            )]);
        }
    }

    /** The clicks a report counts with one text filter stored. */
    private function clicks(string $column, string $value, string $report): int
    {
        $stmt = self::$db->prepare("UPDATE 202_users_pref SET user_pref_keyword = NULL, user_pref_referer = NULL,"
            . " user_pref_ip = NULL, $column = ? WHERE user_id = ?");
        $user = self::USER;
        $stmt->bind_param('si', $value, $user);
        self::assertTrue($stmt->execute(), $stmt->error);
        $stmt->close();

        $data = (new \DataEngine())->getReportData($report, self::$time - 60, self::$time + 60, false);
        $totals = end($data);

        return (int) str_replace(',', '', (string) $totals['total_clicks']);
    }

    public function testARefererFilterCountsEveryMatchingClick(): void
    {
        self::assertSame(self::MANY, $this->clicks('user_pref_referer', 'te990021.example/', 'referer'));
    }

    public function testAKeywordFilterMatchesItsPercentSignLiterally(): void
    {
        self::assertSame(1, $this->clicks('user_pref_keyword', 'te990021 50%', 'keyword'));
    }

    public function testAnIpFilterCountsEveryRowTheAddressIsStoredIn(): void
    {
        self::assertSame(2, $this->clicks('user_pref_ip', '10.99.0.21', 'ip'));
    }

    private function storeWindow(string $preset): void
    {
        self::q("UPDATE 202_users_pref SET user_pref_time_predefined = '$preset', user_pref_time_from = NULL,"
            . ' user_pref_time_to = NULL, user_pref_keyword = NULL, user_pref_referer = NULL, user_pref_ip = NULL'
            . ' WHERE user_id = ' . self::USER);
    }

    public function testTodayIsTheAccountsTodayNotTheZoneTheSessionTookAtSignIn(): void
    {
        $this->storeWindow('today');
        do {
            $day = gmdate('Y-m-d H');
            $time = grab_timeframe();
            $tokyo = \Tracking202\Report\ReportWindow::preset('today', 'Asia/Tokyo', time());
        } while ($day !== gmdate('Y-m-d H'));

        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame([$tokyo['from'], $tokyo['to']], [(int) $time['from'], (int) $time['to']]);
    }

    public function testTheVisitorsListKeepsTheFirstAndLastSecondOfItsDay(): void
    {
        $this->storeWindow('today');
        $today = \Tracking202\Report\ReportWindow::preset('today', 'Asia/Tokyo', time());
        foreach ([990021901 => $today['from'], 990021902 => $today['to']] as $id => $at) {
            self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, landing_page_id,'
                . ' clicks, click_out, leads, payout, income, cost) VALUES (' . self::USER . ", $id, $at, 0, 0, 1, 1, 0, 0, 0, 0)");
        }
        $query = query('SELECT 2c.click_id FROM 202_dataengine AS 2c ', '2c', null, null, null, null, 0, false, false);
        $result = self::$db->query($query['click_sql']);
        self::assertNotFalse($result, self::$db->error);
        $ids = array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'click_id'));

        self::assertContains(990021901, $ids, 'the click at 00:00:00');
        self::assertContains(990021902, $ids, 'the click at 23:59:59');
    }
}
