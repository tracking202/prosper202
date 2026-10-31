<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Timeseries, daypart and weekpart bucket a click by the account's own
 * clock: its date, hour and weekday in the account's time zone, on both
 * sides of a daylight-saving change and in a half-hour zone, whatever zone
 * the database connection is in and whether or not MySQL has zone tables.
 * Timeseries bucketed in the connection's zone; daypart and weekpart asked
 * MySQL to convert, got NULL from a server without zone tables, and counted
 * UTC hours.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6101 and 6102's rows.
 *
 * @group integration
 */
final class ReportTimezoneIntegrationTest extends TestCase
{
    private const int NEW_YORK = 6101;
    private const int KOLKATA = 6102;

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
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::NEW_YORK, self::KOLKATA] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users WHERE user_id = $u");
        }
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    private static function user(int $id, string $zone): void
    {
        self::q("INSERT INTO 202_users SET user_id = $id, user_name = 'tz$id', user_email = 'tz$id@example.com',"
            . " user_dash_email = '', user_pass = 'x', user_timezone = '$zone', user_time_register = 0,"
            . " install_hash = '', user_hash = ''");
    }

    private static function click(int $user, string $utc, int $n): void
    {
        $t = (new \DateTimeImmutable($utc))->getTimestamp();
        self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = " . (610100 + $n) . ", click_time = $t,"
            . ' aff_campaign_id = 0, ppc_account_id = 0, landing_page_id = 0, keyword_id = 0, ip_id = 0,'
            . ' click_referer_site_url_id = 0, device_id = 0, click_filtered = 0, click_bot = 0,'
            . ' click_lead = 0, clicks = 1, click_out = 1, leads = 0, payout = 0, income = 0, cost = 0');
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        self::user(self::NEW_YORK, 'America/New_York');
        self::user(self::KOLKATA, 'Asia/Kolkata');
        // New York changes from EST to EDT at 2026-03-08 07:00 UTC.
        self::click(self::NEW_YORK, '2026-03-08T04:30:00Z', 1); // Sat 2026-03-07 23:30 EST
        self::click(self::NEW_YORK, '2026-03-08T07:30:00Z', 2); // Sun 2026-03-08 03:30 EDT
        self::click(self::NEW_YORK, '2026-07-01T03:30:00Z', 3); // Tue 2026-06-30 23:30 EDT
        self::click(self::KOLKATA, '2026-10-06T18:45:00Z', 4);  // Wed 2026-10-07 00:15 IST
        // A connection zone that matches neither account.
        self::q("SET time_zone = '+07:00'");
    }

    /** @return array<string, int> bucket => clicks */
    private static function counts(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['total_clicks'] > 0) {
                $out[(string) $row[$key]] = $row['total_clicks'];
            }
        }
        ksort($out);

        return $out;
    }

    public function testBucketsAreTheAccountsDatesHoursAndWeekdays(): void
    {
        $ny = new ReportsController(self::$db, self::NEW_YORK);
        $window = ['time_from' => '2026-01-01', 'time_to' => '2026-12-31'];

        $days = $ny->timeseries($window + ['interval' => 'day']);
        self::assertSame('America/New_York', $days['timezone']);
        self::assertSame(['2026-03-07' => 1, '2026-03-08' => 1, '2026-06-30' => 1], self::counts($days['data'], 'period'));
        self::assertSame(
            ['2026-03-07 23:00' => 1, '2026-03-08 03:00' => 1, '2026-06-30 23:00' => 1],
            self::counts($ny->timeseries($window + ['interval' => 'hour'])['data'], 'period')
        );
        self::assertSame(
            ['2026-03' => 2, '2026-06' => 1],
            self::counts($ny->timeseries($window + ['interval' => 'month'])['data'], 'period')
        );
        self::assertSame(
            ['2026-W10' => 2, '2026-W27' => 1],
            self::counts($ny->timeseries($window + ['interval' => 'week'])['data'], 'period'),
            'Saturday 7 March and Sunday 8 March are both ISO week 10'
        );
        self::assertSame(['3' => 1, '23' => 2], self::counts($ny->daypart($window)['data'], 'hour_of_day'));
        self::assertSame(['1' => 1, '5' => 1, '6' => 1], self::counts($ny->weekpart($window)['data'], 'day_of_week'), 'Tue, Sat, Sun');

        $in = new ReportsController(self::$db, self::KOLKATA);
        self::assertSame(['2026-10-07' => 1], self::counts($in->timeseries($window + ['interval' => 'day'])['data'], 'period'));
        self::assertSame(['0' => 1], self::counts($in->daypart($window)['data'], 'hour_of_day'), 'quarter past midnight, not 18 or 0 UTC-rounded');
        self::assertSame(['2' => 1], self::counts($in->weekpart($window)['data'], 'day_of_week'), 'Wednesday');
    }
}
