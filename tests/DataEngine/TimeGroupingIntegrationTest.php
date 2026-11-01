<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\MetricsSql;
use Prosper202\Report\LocalTime;

/**
 * The report pages group clicks by the account's own clock — its hours,
 * weekdays, days and months — and agree with GET /reports/daypart and
 * /reports/weekpart for the same account and window.
 *
 * The engine used to set the connection's zone to the account's offset
 * *today*, rounded to whole hours (`SET time_zone = '+6:00'` for India's
 * +05:30), and group by FROM_UNIXTIME(): every hour of a half-hour zone was
 * half an hour out, every click on the other side of a daylight-saving
 * change from today an hour out, and the setting stayed on the connection
 * for every later query of the request. The groupings are LocalTime now, in
 * the zone the page put in force (AUTH::set_timezone(), the zone
 * grab_timeframe() computed the window in), and nothing touches the
 * connection's zone: the connection here is left at +07:00, which matches
 * neither account.
 *
 * The Breakdown report's hour, day and month were also HOUR(), DAY() and
 * MONTH() of the time alone, so 7 September and 7 October were one row, and
 * every day's 3 pm another; its rows are calendar hours, days, months and
 * years now.
 *
 * Bootstraps the app as the report pages do (connect.php), so it needs a
 * 202-config.php and a scratch database (P202_TEST_DB_*); it installs the
 * schema there and writes users 6201 and 6202's rows.
 *
 * @group integration
 */
final class TimeGroupingIntegrationTest extends TestCase
{
    private const int NEW_YORK = 6201;
    private const int KOLKATA = 6202;
    private const int LOS_ANGELES = 6203;
    private const int LORD_HOWE = 6204;

    /** user => [zone, the clicks' UTC instants] */
    private const array CLICKS = [
        self::NEW_YORK => ['America/New_York', [
            '2026-01-15T12:30:00Z', // Thu 15 Jan 07:30 EST
            '2026-03-07T06:30:00Z', // Sat 7 Mar 01:30 EST, the first hours of a day west of UTC
            '2026-03-08T04:30:00Z', // Sat 7 Mar 23:30 EST
            '2026-03-08T07:30:00Z', // Sun 8 Mar 03:30 EDT, after the spring forward
            '2026-07-01T03:30:00Z', // Tue 30 Jun 23:30 EDT
            '2026-11-01T05:30:00Z', // Sun 1 Nov 01:30 EDT
            '2026-11-01T06:30:00Z', // Sun 1 Nov 01:30 EST, the hour again after the fall back
        ]],
        self::KOLKATA => ['Asia/Kolkata', [
            '2026-10-06T18:15:00Z', // Tue 6 Oct 23:45 IST
            '2026-10-06T18:45:00Z', // Wed 7 Oct 00:15 IST
            '2026-02-28T18:40:00Z', // Sun 1 Mar 00:10 IST, a month and a weekday later than UTC
            '2026-09-07T10:00:00Z', // Mon 7 Sep 15:30 IST: the same day of the month as 7 Oct
        ]],
        self::LOS_ANGELES => ['America/Los_Angeles', [
            '1970-01-01T00:00:00Z', // Signed local seconds before the local epoch.
            '2026-01-01T07:59:59Z', // Outside the account's 2026 window.
            '2026-01-01T08:00:00Z',
            '2026-01-01T08:15:00Z',
            '2026-01-01T08:45:00Z', // A partial report hour must retain only matching clicks.
            '2026-03-08T09:59:59Z',
            '2026-03-08T10:00:00Z', // The spring-forward boundary.
            '2026-07-01T06:59:59Z',
            '2026-07-01T07:00:00Z', // Local midnight within a UTC day.
            '2026-07-01T07:10:00Z',
            '2026-07-01T07:50:00Z',
            '2026-11-01T08:30:00Z',
            '2026-11-01T09:30:00Z', // Repeated local 1:30 am.
            '2027-01-01T07:59:59Z',
            '2027-01-01T08:00:00Z', // Outside the account's 2026 window.
        ]],
        self::LORD_HOWE => ['Australia/Lord_Howe', [
            '2026-04-04T14:45:00Z',
            '2026-04-04T15:15:00Z', // Repeated 1:45 am after a half-hour shift.
            '2026-06-30T13:15:00Z',
            '2026-06-30T13:45:00Z', // Local midnight splits one UTC hour.
            '2026-10-03T15:15:00Z',
            '2026-10-03T15:45:00Z', // Half-hour spring-forward transition.
        ]],
    ];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $dbName = getenv('P202_TEST_DB_NAME') ?: '';
        if ($dbName === '') {
            self::markTestSkipped('Set P202_TEST_DB_NAME (and _HOST/_USER/_PASS) to run the time grouping integration test.');
        }
        // As tests/DataEngine/ReportIntegrationTest: the globals before the
        // bootstrap, which runs inside this method.
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
        $database = self::$db->query('SELECT DATABASE()');
        if (!$database instanceof \mysqli_result || $database->fetch_row()[0] !== $dbName) {
            self::fail('The app did not connect to the configured scratch database; no fixtures were written.');
        }
        $check = self::$db->query("SHOW TABLES LIKE '202_dataengine'");
        if (!$check instanceof \mysqli_result) {
            self::fail('Could not probe for 202_dataengine: ' . self::$db->error);
        }
        if ($check->num_rows === 0) {
            $installed = (new \Prosper202\Database\SchemaInstaller(self::$db))->install();
            if ($installed->hasErrors()) {
                self::fail('Could not install the schema: ' . $installed->getSummary());
            }
        }

        self::cleanUp();
        $n = 0;
        foreach (self::CLICKS as $user => [$zone, $instants]) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'tg$user', user_email = 'tg$user@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = '$zone', user_time_register = 0,"
                . " install_hash = '', user_hash = ''");
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_pref_show = 'all', user_pref_limit = 50, user_pref_breakdown = 'day'");
            foreach ($instants as $utc) {
                $t = (new \DateTimeImmutable($utc))->getTimestamp();
                self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = " . (620100 + ++$n) . ", click_time = $t,"
                    . ' aff_campaign_id = 0, ppc_account_id = 0, landing_page_id = 0, keyword_id = 0, ip_id = 0,'
                    . ' click_referer_site_url_id = 0, device_id = 0, click_filtered = 0, click_bot = 0,'
                    . ' clicks = 1, click_out = ' . ($n % 3 === 0 ? 0 : 1)
                    . ', click_lead = ' . ($n % 2) . ', leads = ' . ($n % 2 === 0 ? 0 : 2)
                    . ', payout = 0, income = ' . ($n % 2 === 0 ? '0' : '3.14159')
                    . ', cost = ' . ($n % 3 === 0 ? '0' : '0.12345'));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db instanceof \mysqli) {
            self::cleanUp();
            self::$db->query("SET time_zone = 'SYSTEM'");
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach (array_keys(self::CLICKS) as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users_pref WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users WHERE user_id = $u");
        }
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    protected function setUp(): void
    {
        // A connection zone that matches neither account.
        self::q("SET time_zone = '+07:00'");
        $_POST = [];
    }

    /** The year 2026 in the account's zone, as a report page's window. */
    private static function window(string $zone): array
    {
        $tz = new \DateTimeZone($zone);

        return [
            (new \DateTimeImmutable('2026-01-01 00:00:00', $tz))->getTimestamp(),
            (new \DateTimeImmutable('2026-12-31 23:59:59', $tz))->getTimestamp(),
        ];
    }

    /** Signed in as $user, in the account's zone, as every report page is before it reads its window. */
    private static function signIn(int $user, string $zone): void
    {
        $_SESSION = ['user_id' => $user, 'user_own_id' => $user, 'user_timezone' => $zone];
        \AUTH::set_timezone($zone);
        self::assertSame($zone, date_default_timezone_get());
    }

    /**
     * A page report's rows as label => clicks (the totals row left out).
     *
     * @return array<string, int>
     */
    private static function page(int $user, string $report): array
    {
        [$zone] = self::CLICKS[$user];
        self::signIn($user, $zone);
        [$from, $to] = self::window($zone);
        $rows = (new \DataEngine())->getReportData($report, $from, $to, false);
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['clicks'])) {
                $out[html_entity_decode((string) $row['click_time_from_disp'])] = (int) str_replace(',', '', (string) $row['clicks']);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * What the clicks are on the account's clock, grouped by $format (PHP's
     * own reading, which LocalTime's SQL is held to).
     *
     * @return array<string, int>
     */
    private static function expected(int $user, string $format): array
    {
        [$zone, $instants] = self::CLICKS[$user];
        [$from, $to] = self::window($zone);
        $out = [];
        foreach ($instants as $utc) {
            $date = new \DateTimeImmutable($utc);
            if ($date->getTimestamp() < $from || $date->getTimestamp() > $to) {
                continue;
            }
            $label = $date->setTimezone(new \DateTimeZone($zone))->format($format);
            $out[$label] = ($out[$label] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * An API part report's non-empty rows as the page's labels.
     *
     * @return array<string, int>
     */
    private static function api(int $user, string $report): array
    {
        [$zone] = self::CLICKS[$user];
        $out = [];
        foreach ((new ReportsController(self::$db, $user))->$report(['time_from' => '2026-01-01', 'time_to' => '2026-12-31'])['data'] as $row) {
            if ((int) $row['total_clicks'] === 0) {
                continue;
            }
            $label = $report === 'daypart'
                // The page's '%l %p', which the formatter lowercases.
                ? (($row['hour_of_day'] % 12) ?: 12) . ($row['hour_of_day'] < 12 ? ' am' : ' pm')
                : substr((string) $row['day_name'], 0, 3);
            $out[$label] = (int) $row['total_clicks'];
        }
        ksort($out);

        return $out;
    }

    public function testDayPartingIsTheAccountsHoursAndTheApisDaypart(): void
    {
        foreach (array_keys(self::CLICKS) as $user) {
            $expected = self::expected($user, 'g a');
            self::assertSame($expected, self::page($user, 'hourly'), "user $user: Day Parting");
            self::assertSame($expected, self::api($user, 'daypart'), "user $user: GET /reports/daypart");
        }
        self::assertSame(['1 am' => 3, '11 pm' => 2, '3 am' => 1, '7 am' => 1], self::page(self::NEW_YORK, 'hourly'));
        self::assertSame(['11 pm' => 1, '12 am' => 2, '3 pm' => 1], self::page(self::KOLKATA, 'hourly'), 'quarter to midnight is 11 pm in India, not 12 am at +06:00');
    }

    public function testWeekPartingIsTheAccountsWeekdaysAndTheApisWeekpart(): void
    {
        foreach (array_keys(self::CLICKS) as $user) {
            $expected = self::expected($user, 'D');
            self::assertSame($expected, self::page($user, 'weekly'), "user $user: Week Parting");
            self::assertSame($expected, self::api($user, 'weekpart'), "user $user: GET /reports/weekpart");
        }
        self::assertSame(['Sat' => 2, 'Sun' => 3, 'Thu' => 1, 'Tue' => 1], self::page(self::NEW_YORK, 'weekly'));
    }

    public function testTheBreakdownRowsAreTheAccountsCalendarHoursDaysMonthsAndYears(): void
    {
        $formats = ['day' => 'M d, Y', 'hour' => 'M d, Y \a\t ga', 'month' => 'M Y', 'year' => 'Y'];
        foreach ($formats as $breakdown => $format) {
            foreach (array_keys(self::CLICKS) as $user) {
                self::q("UPDATE 202_users_pref SET user_pref_breakdown = '$breakdown' WHERE user_id = $user");
                self::assertSame(self::expected($user, $format), self::page($user, 'breakdown'), "user $user: Breakdown by $breakdown");
            }
        }
        // The two 7ths are two days, and the repeated 1 am is one hour.
        self::q("UPDATE 202_users_pref SET user_pref_breakdown = 'day' WHERE user_id = " . self::KOLKATA);
        self::assertSame(['Mar 01, 2026' => 1, 'Oct 06, 2026' => 1, 'Oct 07, 2026' => 1, 'Sep 07, 2026' => 1], self::page(self::KOLKATA, 'breakdown'));
        self::q("UPDATE 202_users_pref SET user_pref_breakdown = 'hour' WHERE user_id = " . self::NEW_YORK);
        self::assertSame(2, self::page(self::NEW_YORK, 'breakdown')['Nov 01, 2026 at 1am'] ?? null);
    }

    public function testTheBreakdownRowsRunInCalendarOrder(): void
    {
        self::q("UPDATE 202_users_pref SET user_pref_breakdown = 'day' WHERE user_id = " . self::KOLKATA);
        self::signIn(self::KOLKATA, 'Asia/Kolkata');
        [$from, $to] = self::window('Asia/Kolkata');
        $labels = [];
        foreach ((new \DataEngine())->getReportData('breakdown', $from, $to, false) as $row) {
            if (isset($row['clicks'])) {
                $labels[] = (string) $row['click_time_from_disp'];
            }
        }
        self::assertSame(['Mar 01, 2026', 'Sep 07, 2026', 'Oct 06, 2026', 'Oct 07, 2026'], $labels);
    }

    public function testBreakdownMatchesPerClickGroupingForEveryMetricFilterAndSort(): void
    {
        $previousMode = self::$db->query('SELECT @@session.sql_mode')->fetch_row()[0];
        self::q("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");
        try {
            foreach (self::CLICKS as $user => [$zone]) {
                self::signIn($user, $zone);
                [$from, $to] = self::window($zone);
                // Include a partial UTC hour and a sparse interval as well as the year.
                $windows = [[$from, $to], [$from + 1801, $to - 1801], [$from + 123, $from + 456], [0, 86400]];
                $orders = [
                    'sort_breakdown_time_order asc',
                    'sort_breakdown_time_order desc',
                    'sort_breakdown_income desc',
                ];
                foreach (['hour', 'day', 'month', 'year'] as $granularity) {
                    self::q("UPDATE 202_users_pref SET user_pref_breakdown='$granularity' WHERE user_id=$user");
                    foreach (['all', 'leads'] as $show) {
                        self::q("UPDATE 202_users_pref SET user_pref_show='$show' WHERE user_id=$user");
                        foreach ($orders as $order) {
                            $_POST = ['order' => $order];
                            foreach ($windows as [$start, $end]) {
                                $engine = new \DataEngine();
                                $filters = $engine->getFilters();
                                $format = match ($granularity) {
                                    'hour' => '%b %d, %Y at %l%p',
                                    'month' => '%b %Y',
                                    'year' => '%Y',
                                    default => '%b %d, %Y',
                                };
                                $sql = 'SELECT DATE_FORMAT(' . LocalTime::datetimeSql('click_time', $zone)
                                    . ", '$format') AS click_time_from_disp," . MetricsSql::GROUPED_SELECT
                                    . ' FROM 202_dataengine AS 2st ' . $filters['join']
                                    . " WHERE 2st.user_id=$user AND click_time >= $start AND click_time <= $end"
                                    . $filters['filter'] . ' GROUP BY click_time_from_disp'
                                    . $engine->sortOrder('sort_breakdown_time_order asc');
                                $collect = new \ReflectionMethod(\DataEngine::class, 'collectRows');
                                $legacy = $collect->invoke($engine, $sql, false);
                                $optimized = $engine->getReportData('breakdown', $start, $end, false);
                                self::assertSame($legacy, $optimized, "$zone $granularity $show $order $start..$end");
                            }
                        }
                    }
                }
            }
        } finally {
            self::q('SET SESSION sql_mode=' . "'" . self::$db->real_escape_string($previousMode) . "'");
            foreach (array_keys(self::CLICKS) as $user) {
                self::q('UPDATE 202_users_pref SET user_pref_show=\'all\', user_pref_breakdown=\'day\''
                    . " WHERE user_id=$user");
            }
            $_POST = [];
        }
    }

    /**
     * The Overview chart: its categories are returnRanges()' points, which
     * start at the window's first day or hour on the account's clock, and a
     * point's value is the group of the same name. The points were UTC days
     * and hours (DateTime('@…') is UTC): India's chart started on the day
     * before its window, and New York's hourly chart started at 5 am, so the
     * clicks of its first five hours were on no point at all. And the last
     * point is the window's last day or hour: the points ran a day or an
     * hour past it, so today's chart had two days.
     */
    public function testTheChartsPointsAreTheAccountsDaysAndHours(): void
    {
        self::signIn(self::KOLKATA, 'Asia/Kolkata');
        $tz = new \DateTimeZone('Asia/Kolkata');
        $from = (new \DateTimeImmutable('2026-10-05 00:00:00', $tz))->getTimestamp();
        $to = (new \DateTimeImmutable('2026-10-08 23:59:59', $tz))->getTimestamp();
        [$categories, $points] = self::chart($from, $to, 'days', 'M d');
        self::assertSame('Oct 05', $categories[0], 'the first point is the first day of the window');
        self::assertSame(['Oct 05', 'Oct 06', 'Oct 07', 'Oct 08'], $categories, 'and the last its last day');
        self::assertSame(['Oct 06' => '1', 'Oct 07' => '1'], $points);

        self::signIn(self::NEW_YORK, 'America/New_York');
        $tz = new \DateTimeZone('America/New_York');
        $from = (new \DateTimeImmutable('2026-03-07 00:00:00', $tz))->getTimestamp();
        $to = (new \DateTimeImmutable('2026-03-08 23:59:59', $tz))->getTimestamp();
        [$categories, $points] = self::chart($from, $to, 'hours', 'M d h:iA');
        self::assertSame('Mar 07 12:00AM', $categories[0], 'the first point is the first hour of the window');
        self::assertSame('Mar 08 11:00PM', end($categories), 'and the last point its last hour');
        self::assertCount(24 + 23, $categories, 'one per hour the clock showed: 8 March 2026 lost 2 am');
        self::assertSame(['Mar 07 01:00AM' => '1', 'Mar 07 11:00PM' => '1', 'Mar 08 03:00AM' => '1'], $points);
    }

    /** @return array{0: list<string>, 1: array<string, string>} the categories, and category => clicks for the non-zero points */
    private static function chart(int $from, int $to, string $range, string $format): array
    {
        // As tracking202/ajax/account_overview.php and charts.php build it.
        $period = returnRanges(new \DateTime('@' . $from), new \DateTime('@' . $to), $range);
        $chart = (new \DataEngine())->getChart($from, $to, [['campaign_id' => '0', 'value_type' => 'clicks']], $range, $format, $period);
        $categories = [];
        foreach ($period as $point) {
            $categories[] = $point->format($format);
        }
        self::assertCount(count($categories), $chart['series'][0]['data']);
        $out = [];
        foreach ($chart['series'][0]['data'] as $i => $value) {
            if ((string) $value !== '0') {
                $out[$categories[$i]] = (string) $value;
            }
        }

        return [$categories, $out];
    }

    public function testTheEngineLeavesTheConnectionsZoneAlone(): void
    {
        self::signIn(self::KOLKATA, 'Asia/Kolkata');
        new \DataEngine();
        $zone = self::$db->query('SELECT @@session.time_zone AS z');
        self::assertInstanceOf(\mysqli_result::class, $zone);
        self::assertSame('+07:00', $zone->fetch_assoc()['z'], 'constructing the engine set the connection zone for the rest of the request');
    }

    public function testFractionalTimezoneCampaignChartsKeepEveryMetricAndMissingLabels(): void
    {
        $user = self::KOLKATA;
        self::signIn($user, 'Asia/Kolkata');
        [$from, $to] = self::window('Asia/Kolkata');
        // A removed campaign still has clicks; its missing label must not drop them.
        self::q("UPDATE 202_dataengine SET aff_campaign_id=$user WHERE user_id=$user");
        $metrics = ['clicks', 'click_out', 'ctr', 'leads', 'su_ratio', 'payout', 'epc', 'cpc',
            'income', 'cost', 'net', 'roi', 'unknown'];
        try {
            foreach (['days', 'hours'] as $range) {
                $period = returnRanges(new \DateTime('@' . $from), new \DateTime('@' . $to), $range);
                $lines = [];
                foreach (['0', (string) $user, '999999999'] as $campaign) {
                    foreach ($metrics as $metric) {
                        $lines[] = ['campaign_id' => $campaign, 'value_type' => $metric];
                    }
                }
                $chart = (new \DataEngine())->getChart($from, $to, $lines, $range, 'M d', $period);
                foreach (array_keys($metrics) as $index) {
                    self::assertSame($chart['series'][$index]['data'], $chart['series'][13 + $index]['data']);
                    self::assertSame(['0'], array_values(array_unique($chart['series'][26 + $index]['data'])));
                }
                self::assertContains(null, $chart['series'][5]['data'], 'payout stays NULL for a zero-lead group');
            }
        } finally {
            self::q("UPDATE 202_dataengine SET aff_campaign_id=0 WHERE user_id=$user");
        }
    }
}
