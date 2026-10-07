<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRetention;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * The cron job's two click deletions, on a real schema: exactly the clicks
 * they promise go, from every table that holds a click's rows, and nothing
 * else does.
 *
 * The automatic deletion used to take MIN(click_id) of the expired clicks
 * and delete the ids below it: below the oldest expired click, so the
 * expired clicks stayed — and, since ids are not ordered by time, a newer
 * click with a smaller id went. The clicks here sit on both sides of the
 * cutoff in both id orders, so either half of that reads as a failure.
 *
 * @group integration
 */
final class ClickRetentionIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private Connection $conn;
    private string $zone = 'UTC';

    private const NOW = 1_780_000_000;
    private const DAYS = 30;

    /** What each table needs beside click_id (the rest take their defaults). */
    private const ROW = [
        '202_clicks' => 'user_id = {u}, click_time = {t}',
        '202_clicks_spy' => 'user_id = {u}, click_time = {t}',
        '202_clicks_advance' => '',
        '202_clicks_record' => 'click_id_public = {id}',
        '202_clicks_site' => '',
        '202_clicks_tracking' => '',
        '202_clicks_variable' => 'variable_set_id = 1',
        '202_clicks_rotator' => '',
        '202_cpa_trackers' => 'tracker_id_public = 1',
        '202_google' => "gclid = 'g{id}'",
        '202_bing' => "msclkid = 'b{id}'",
        '202_facebook' => "fbclid = 'f{id}'",
        '202_dataengine' => 'user_id = {u}, click_time = {t}',
        '202_identity_observations' => "signal_type = 'cookie', signal_hash = SHA2('{id}', 256), observed_at = {t}",
        '202_clicks_visitor' => 'user_id = {u}, visitor_key = {id}, click_time = {t}',
    ];

    /** Rows in tables ClickRetention keeps, naming a click it deletes. */
    private const KEPT_ROWS = [
        '202_clicks_counter' => 'click_id = {id}',
        '202_conversion_logs' => "click_id = {id}, user_id = {u}, dedupe_key = 'k{id}', conv_time = {t}, click_time = {t}",
        '202_attribution_journeys' => 'conv_id = {id}, position = 0, click_id = {id}, click_time = {t}',
        '202_attribution_credits' => 'conv_id = {id}, model_id = 1, click_id = {id}, position = 0, conv_time = {t}',
        '202_attribution_rollup_dirty_clicks' => 'user_id = {u}, click_id = {id}',
        '202_identity_merges' => "user_id = {u}, from_key = 1, into_key = 2, click_id = {id}, signal_type = 'cookie', signal_hash = 'h', merged_at = {t}",
        '202_app_installs' => "click_id = {id}, install_uuid = 'i{id}'",
        '202_revenue_events' => "user_id = {u}, click_id = {id}, conv_id = {id}, idempotency_key = 'r{id}'",
        '202_engagement_events' => 'user_id = {u}, click_id = {id}',
        '202_personalization_tokens' => 'user_id = {u}, click_id = {id}, token_hash = UNHEX(SHA2(\'{id}\', 256))',
        '202_customers' => 'user_id = {u}, first_click_id = {id}',
    ];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            require_once __DIR__ . '/../../202-config/functions.php';
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
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        $reset = array_merge(ClickRetention::TABLES, array_keys(self::KEPT_ROWS), [
            '202_attribution_journey_meta', '202_attribution_models', '202_attribution_rollup_dirty', '202_users_pref',
        ]);
        foreach ($reset as $t) {
            self::q('TRUNCATE TABLE ' . $t);
        }
        self::q('INSERT INTO 202_users_pref SET user_id = 1');
        $this->conn = new Connection(self::$db);
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) !== true) {
            throw new \RuntimeException('fixture failed: ' . self::$db->error . ' in ' . $sql);
        }
    }

    /** @return list<int> */
    private static function ids(string $table): array
    {
        $col = $table === '202_customers' ? 'first_click_id' : 'click_id';
        $r = self::$db->query("SELECT DISTINCT $col AS id FROM $table ORDER BY id");

        return array_map(static fn (array $row): int => (int) $row['id'], $r->fetch_all(MYSQLI_ASSOC));
    }

    private static function rowCount(string $table): int
    {
        return (int) self::$db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
    }

    /** A click with a row in every table that holds a click's rows. */
    private static function click(int $id, int $time, int $user = 1, bool $childRowsOnly = false): void
    {
        foreach (self::ROW as $table => $set) {
            if ($childRowsOnly && $table === '202_clicks') {
                continue;
            }
            $set = strtr($set, ['{u}' => (string) $user, '{t}' => (string) $time, '{id}' => (string) $id]);
            self::q("INSERT INTO $table SET click_id = $id" . ($set === '' ? '' : ", $set"));
        }
    }

    private static function keptRows(int $id, int $time, int $user = 1): void
    {
        foreach (self::KEPT_ROWS as $table => $set) {
            self::q("INSERT INTO $table SET " . strtr($set, ['{u}' => (string) $user, '{t}' => (string) $time, '{id}' => (string) $id]));
        }
    }

    /** @param list<int> $expected */
    private static function assertEveryTableHolds(array $expected, string $context): void
    {
        sort($expected);
        foreach (ClickRetention::TABLES as $table) {
            self::assertSame($expected, self::ids($table), "$context: the clicks left in $table");
        }
    }

    private function retention(int $batch = ClickRetention::BATCH, ?callable $clock = null): ClickRetention
    {
        return new ClickRetention($this->conn, $batch, $clock ?? static fn (): int => self::NOW);
    }

    public function testAutomaticDeletionDeletesExactlyTheExpiredClicksFromEveryTable(): void
    {
        $cutoff = ClickRetention::cutoff(self::DAYS, self::NOW);
        self::assertSame(gmmktime(0, 0, 0, 4, 28, 2026), $cutoff, 'midnight that began the day 30 days before 2026-05-28 (NOW)');

        $expired = [];
        $kept = [];
        // Ids out of time order on both sides of the cutoff: the newest
        // expired click has the largest id below, a kept one the smallest.
        foreach ([
            5 => $cutoff + 500,     // kept: the smallest id, recorded after the cutoff
            7 => $cutoff - 200,
            10 => $cutoff - 3 * 86400,
            11 => $cutoff - 1,      // the last second before the cutoff
            12 => $cutoff,          // at the cutoff: kept
            13 => $cutoff + 100,
            20 => $cutoff - 5000,   // expired with a larger id than kept ones
        ] as $id => $time) {
            self::click($id, $time);
            if ($time < $cutoff) {
                $expired[] = $id;
            } else {
                $kept[] = $id;
            }
        }
        // Another account's clicks, on both sides.
        self::click(40, $cutoff - 10, 2);
        $expired[] = 40;
        self::click(41, $cutoff + 10, 2);
        $kept[] = 41;
        // A click a rotator re-click gave a second row after the cutoff:
        // its other rows are the re-click's, so it is kept whole.
        self::click(30, $cutoff - 90_000);
        self::q('INSERT INTO 202_clicks SET click_id = 30, user_id = 1, click_time = ' . ($cutoff + 60));
        $kept[] = 30;
        // A backlog of expired clicks, deleted a few at a time.
        for ($id = 100; $id < 125; $id++) {
            self::click($id, $cutoff - 86400 * 40 + $id);
            $expired[] = $id;
        }
        // Rows of the expired clicks in tables that keep theirs.
        foreach ([7, 10, 40, 100] as $id) {
            self::keptRows($id, $cutoff - 86400, $id === 40 ? 2 : 1);
        }
        $keptCounts = array_map(static fn (string $t): int => self::rowCount($t), array_combine(array_keys(self::KEPT_ROWS), array_keys(self::KEPT_ROWS)));
        self::q('UPDATE 202_users_pref SET user_auto_database_optimization_days = ' . self::DAYS . ' WHERE user_id = 1');

        $report = $this->retention(4)->runAutomatic(PHP_INT_MAX);

        self::assertSame(self::DAYS, $report['days']);
        self::assertSame($cutoff, $report['cutoff']);
        self::assertTrue($report['complete']);
        self::assertSame(count($expired), $report['clicks']);
        self::assertGreaterThan(5, $report['batches'], 'the backlog went a batch at a time');
        self::assertEveryTableHolds($kept, 'after the automatic deletion');
        self::assertSame([$cutoff - 90_000, $cutoff + 60], array_map('intval', array_column(
            self::$db->query('SELECT click_time FROM 202_clicks WHERE click_id = 30 ORDER BY click_time')->fetch_all(MYSQLI_ASSOC),
            'click_time'
        )), 'the re-clicked click keeps both rows');
        foreach ($keptCounts as $table => $n) {
            self::assertSame($n, self::rowCount($table), "$table keeps its rows");
        }
        foreach (ClickRetention::TABLES as $table) {
            self::assertSame(count($expired), $report['rows'][$table], "$table: the expired clicks' rows, one each, were counted");
        }

        // Nothing is left to delete: the next run deletes nothing.
        $again = $this->retention(4)->runAutomatic(PHP_INT_MAX);
        self::assertSame(0, $again['batches']);
        self::assertTrue($again['complete']);
        self::assertEveryTableHolds($kept, 'after a second run');
    }

    public function testABacklogDrainsAcrossRunsWithEveryTableConsistentInBetween(): void
    {
        $cutoff = ClickRetention::cutoff(self::DAYS, self::NOW);
        for ($id = 1; $id <= 30; $id++) {
            self::click($id, $cutoff - 86400 + $id);
        }
        self::click(31, $cutoff + 1);
        self::q('UPDATE 202_users_pref SET user_auto_database_optimization_days = ' . self::DAYS . ' WHERE user_id = 1');

        // A clock that moves a second a reading, so a run's budget runs out.
        $runs = 0;
        $complete = false;
        while (!$complete) {
            $t = self::NOW;
            $retention = $this->retention(4, static function () use (&$t): int {
                return $t++;
            });
            $report = $retention->runAutomatic(self::NOW + 3);
            $runs++;
            $complete = $report['complete'];
            self::assertTrue($complete || $report['batches'] > 0, "run $runs made progress or found nothing left");
            $left = self::ids('202_clicks');
            self::assertEveryTableHolds($left, "after run $runs");
            self::assertLessThan(20, $runs, 'the backlog drains');
        }
        self::assertGreaterThan(2, $runs, 'the budget split the backlog over runs');
        self::assertEveryTableHolds([31], 'when drained');
    }

    public function testTheScheduledDeletionDeletesEveryRowBelowTheMarkerFromEveryTable(): void
    {
        foreach ([3 => 1000, 9 => 900, 14 => 5000, 15 => 4000, 16 => 100, 40 => 50] as $id => $time) {
            self::click($id, $time);
        }
        // Rows whose 202_clicks row is already gone: the preview counted
        // them, so they go too.
        self::click(6, 700, 1, true);
        self::keptRows(9, 900);
        $keptCounts = array_map(static fn (string $t): int => self::rowCount($t), array_combine(array_keys(self::KEPT_ROWS), array_keys(self::KEPT_ROWS)));
        self::q('UPDATE 202_users_pref SET user_delete_data_clickid = 15 WHERE user_id = 1');

        $report = $this->retention(2)->runScheduled(PHP_INT_MAX);

        self::assertSame(15, $report['marker']);
        self::assertTrue($report['complete']);
        self::assertSame(3, $report['clicks'], 'clicks 3, 9 and 14');
        self::assertEveryTableHolds([15, 16, 40], 'below the marker nothing is left; the marker\'s own click stays');
        foreach ($keptCounts as $table => $n) {
            self::assertSame($n, self::rowCount($table), "$table keeps its rows");
        }
        self::assertSame('15', (string) self::$db->query('SELECT user_delete_data_clickid FROM 202_users_pref WHERE user_id = 1')->fetch_row()[0], 'the marker stays, as the API reports it');

        $again = $this->retention(2)->runScheduled(PHP_INT_MAX);
        self::assertSame(0, $again['batches'], 'a finished deletion deletes nothing more');
        self::assertTrue($again['complete']);
    }

    public function testOffDeletesNothing(): void
    {
        self::click(1, 1000);
        $auto = $this->retention()->runAutomatic(PHP_INT_MAX);
        $once = $this->retention()->runScheduled(PHP_INT_MAX);
        self::assertSame(0, $auto['days']);
        self::assertNull($once['marker']);
        self::assertSame(0, $auto['batches'] + $once['batches']);
        self::assertEveryTableHolds([1], 'with both deletions off');
    }

    public function testAFailedBatchRollsBackWhole(): void
    {
        $cutoff = ClickRetention::cutoff(self::DAYS, self::NOW);
        self::click(1, $cutoff - 10);
        self::q('UPDATE 202_users_pref SET user_auto_database_optimization_days = ' . self::DAYS . ' WHERE user_id = 1');
        // The next-to-last table the batch deletes from is missing: what the
        // batch deleted before it must come back.
        $missing = ClickRetention::TABLES[count(ClickRetention::TABLES) - 2];
        self::assertSame('202_clicks_visitor', $missing);
        self::q("RENAME TABLE $missing TO p202_retention_hidden");
        try {
            $this->retention()->runAutomatic(PHP_INT_MAX);
            self::fail('a batch that cannot delete from every table is an error');
        } catch (\Throwable $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        } finally {
            self::q("RENAME TABLE p202_retention_hidden TO $missing");
        }
        self::assertEveryTableHolds([1], 'after a batch that failed on its next-to-last table');
    }
}
