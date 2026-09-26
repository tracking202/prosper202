<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionRollup;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\DefaultModel;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Prosper202\Report\RollupDirty;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * The report rollup returns the full computation's answer, byte for byte
 * (plan §7.3, "a performance fix must be shown to return the same answer";
 * CLAUDE.md, Verify your assumptions).
 *
 * Differential: every breakdown is computed twice — by AttributionReports
 * with the rollup, and by the same class with it switched off, which is the
 * full computation the reports ran before PR 13 — and the two results are
 * compared with assertSame over every row, name, sum and total.
 *
 * - Randomized tenants (fixed seeds, so a failure reproduces): sparse (a
 *   handful of conversions over two years), medium and dense, with
 *   multi-touch journeys, bots, clicks that exist twice, credits whose click
 *   is gone, dimension values that are NULL, 0 or nameless, per-campaign
 *   model overrides (to an active and to an inactive model), a second
 *   account in the same hours, and the newest hours not summed yet. Every
 *   dimension, the effective model, an explicit model and a comparison;
 *   ranges that start and end mid-hour, on hour and UTC-day boundaries,
 *   inside one hour, and past the summed frontier; the day dimension under
 *   time zones on the hour, at +05:30, +05:45, -03:30 and +14:00 (and a
 *   named zone with DST where the server has zone tables).
 *   P202_ROLLUP_DIFF_SCALE multiplies the tenants' sizes.
 * - Changes after the rollup was summed, through the real writers: a
 *   retraction, a replacement, a partial reversal, a revival, a conversion
 *   recorded for an old hour, a model's config changed, a model switched
 *   off, a campaign override set, the default changed, one click rewritten
 *   and a CPC range update. Each is compared while the hours are dirty (the
 *   report computes them exactly) and after the rollup re-summed them.
 *
 * Every comparison that covers summed hours also asserts the rollup served
 * some, or a report that silently fell back to the full computation would
 * pass it.
 *
 * The journey metrics (AttributionReports::journeyMetrics(), the rollup's
 * journey part) are compared the same way, over the same tenants, ranges
 * and changes, with served hours asserted separately: the tenants carry
 * truncated and unidentified journeys, first touches from minutes to forty
 * days before the conversion and after it, journeys missing their first or
 * last position, and converting clicks with no clicks_advance row, with a
 * browser id that has no row, with browser id 0 that has one, two ids of
 * one name and an id named 'Unknown'. A rollup summed before it kept the
 * journey part is shown to be read in full until it is summed again.
 *
 * @group integration
 */
final class RollupMatchesFullComputationTest extends TestCase
{
    use AttributionDatabase {
        setUp as private databaseSetUp;
    }

    private const EXTRA_RESET = [
        '202_clicks_tracking', '202_device_models', '202_device_types', '202_keywords', '202_locations_country',
        '202_landing_pages', '202_tracking_c1', '202_tracking_c2', '202_tracking_c3', '202_tracking_c4',
    ];

    private const TIME_ZONES = ['+00:00', '+05:30', '+05:45', '-03:30', '+14:00'];

    private int $comparisons = 0;

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (self::EXTRA_RESET as $t) {
            if (self::$db->query('TRUNCATE TABLE ' . $t) !== true) {
                throw new \RuntimeException('reset failed: ' . $t . ': ' . self::$db->error);
            }
        }
        self::$db->query("SET time_zone = '+00:00'");
    }

    protected function tearDown(): void
    {
        self::$db?->query("SET time_zone = '+00:00'");
    }

    /** @return array<string, array{0: int, 1: array<string, int>}> */
    public static function tenants(): array
    {
        $scale = max(1, (int) (getenv('P202_ROLLUP_DIFF_SCALE') ?: 1));

        return [
            'sparse: 7 conversions over two years' => [11, ['conversions' => 7, 'span' => 700 * 86400, 'campaigns' => 4]],
            'medium: 300 conversions over 45 days' => [23, ['conversions' => 300 * $scale, 'span' => 45 * 86400, 'campaigns' => 8]],
            'dense: 900 conversions over 3 days' => [37, ['conversions' => 900 * $scale, 'span' => 3 * 86400, 'campaigns' => 6]],
        ];
    }

    /**
     * @dataProvider tenants
     * @param array<string, int> $cfg
     */
    public function testRandomizedTenantsReadTheSameFromTheRollup(int $seed, array $cfg): void
    {
        $t = $this->generate($seed, $cfg);
        $this->buildRollup($t['now']);
        $built = (int) self::scalar('SELECT built_through_hour FROM 202_attribution_rollup_state WHERE user_id = 1');
        self::assertGreaterThan(intdiv($t['first'], 3600), $built, 'the rollup summed the tenant\'s hours');
        self::assertLessThan(intdiv($t['last'], 3600), $built, 'and left its newest hours unsummed, which reports compute exactly');

        $ranges = $this->ranges($seed, $t['first'], $t['last'], $built);
        $variants = [
            'effective' => [null, null],
            'first touch' => [$t['models']['first'], null],
            'linear vs default' => [$t['models']['linear'], $t['models']['default']],
            'effective vs first touch' => [null, $t['models']['first']],
        ];
        $served = 0;
        foreach ($ranges as $label => [$from, $to]) {
            foreach (AttributionReports::dimensions() as $dim) {
                foreach ($variants as $variant => [$model, $compare]) {
                    $served += $this->assertSameAnswer("$label, $dim, $variant", 1, $model, $compare, $t['models']['default'], $dim, $from, $to);
                }
            }
            foreach (self::timeZones() as $tz) {
                self::$db->query("SET time_zone = '$tz'");
                foreach ($variants as $variant => [$model, $compare]) {
                    $served += $this->assertSameAnswer("$label, day in $tz, $variant", 1, $model, $compare, $t['models']['default'], 'day', $from, $to);
                }
            }
            self::$db->query("SET time_zone = '+00:00'");
        }
        // The other account, in the same hours, reads only its own rows.
        $served += $this->assertSameAnswer('the other account', 2, null, null, $t['models']['other'], 'campaign', $t['first'] - 7200, $t['last'] + 7200);
        self::assertGreaterThan(0, $served, 'the rollup served hours; a comparison of the full computation with itself proves nothing');

        if ($cfg['conversions'] >= 300) {
            // The shapes the journey comparisons are for are in the data.
            $all = (new AttributionReports($this->conn, false))->journeyMetrics(1, $t['first'] - 7200, $t['last'] + 7200);
            self::assertSame([], array_keys(array_filter($all['time_to_convert'], static fn (int $n): bool => $n === 0)), 'every time-to-convert bucket is populated');
            self::assertGreaterThan(0, $all['truncated']);
            self::assertGreaterThan(0, $all['unidentified']);
            $browsers = array_column($all['one_touch_by_browser'], 'browser');
            foreach (['Unknown', 'Browser zero', 'Browser 4', 'Browser 1'] as $name) {
                self::assertContains($name, $browsers);
            }
            self::assertGreaterThan(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_journey_meta jm JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position = 0 WHERE jm.user_id = 1 AND j.click_time > jm.conv_time'), 'a conversion dated before its first touch');
            self::assertGreaterThan(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_journey_meta jm WHERE jm.user_id = 1 AND NOT EXISTS (SELECT 1 FROM 202_attribution_journeys j WHERE j.conv_id = jm.conv_id AND j.position = 0)'), 'a journey missing its first position');
            self::assertGreaterThan(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_journey_meta jm WHERE jm.user_id = 1 AND jm.touches > 1 AND NOT EXISTS (SELECT 1 FROM 202_attribution_journeys j WHERE j.conv_id = jm.conv_id AND j.position + 1 = jm.touches)'), 'a journey missing its last position');
            self::assertGreaterThan(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_journey_meta jm JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 = jm.touches JOIN 202_clicks_advance ca ON ca.click_id = j.click_id WHERE jm.user_id = 1 AND ca.browser_id IN (7, 8)'), 'a converting click whose browser has no row');
        }

        $journeysServed = 0;
        foreach ($ranges as $label => [$from, $to]) {
            $journeysServed += $this->assertSameJourneys("$label, journey metrics", 1, $from, $to);
        }
        $journeysServed += $this->assertSameJourneys('the other account, journey metrics', 2, $t['first'] - 7200, $t['last'] + 7200);
        self::assertGreaterThan(0, $journeysServed, 'the rollup served journey hours');
        $full = (new AttributionReports($this->conn));
        $full->breakdownAll(1, null, null, $t['models']['default'], 'keyword', $t['first'] - 7200, $t['last'] + 7200);
        self::assertGreaterThan(0, $full->lastServedHours(), 'the whole range is read from the rollup where it is summed');
    }

    public function testATimeZoneOffTheHourSplitsOnlyTheHourThatStraddlesMidnight(): void
    {
        // 2025-01-10 18:00 UTC is 23:30 in +05:30: the hour 18:00–19:00 UTC
        // is on two local dates, the hours either side on one.
        $t = $this->generate(5, ['conversions' => 40, 'span' => 3 * 86400, 'campaigns' => 3], 1_736_400_000);
        $this->buildRollup($t['now']);
        self::$db->query("SET time_zone = '+05:30'");
        $straddling = intdiv(1_736_532_000, 3600);
        self::assertSame(0, (int) self::scalar('SELECT ' . AttributionRollup::hourIsOneLocalDateSql((string) $straddling)));
        self::assertSame(1, (int) self::scalar('SELECT ' . AttributionRollup::hourIsOneLocalDateSql((string) ($straddling + 1))));
        self::assertSame(1, (int) self::scalar('SELECT ' . AttributionRollup::hourIsOneLocalDateSql((string) ($straddling - 1))));
        self::$db->query("SET time_zone = '+00:00'");
        self::assertSame(1, (int) self::scalar('SELECT ' . AttributionRollup::hourIsOneLocalDateSql((string) $straddling)));
    }

    public function testARollupSummedBeforeItKeptTheJourneyPartIsReadInFullUntilSummedAgain(): void
    {
        $t = $this->generate(19, ['conversions' => 120, 'span' => 6 * 86400, 'campaigns' => 4]);
        $this->buildRollup($t['now']);
        $window = [$t['first'] - 7200, $t['last'] + 7200];
        self::assertGreaterThan(0, $this->assertSameJourneys('summed', 1, ...$window));

        // What a rollup summed before this part existed holds: every other
        // part, no journey rows and no marker.
        self::fixture('DELETE FROM 202_attribution_rollup WHERE user_id = 1 AND part = ' . AttributionRollup::PART_JOURNEYS);
        self::assertSame(0, $this->assertSameJourneys('without the journey part', 1, ...$window), 'the journey counts are computed in full');
        self::assertGreaterThan(0, (new AttributionReports($this->conn))->breakdownAll(1, null, null, $t['models']['default'], 'campaign', ...$window)['totals']['conversions']);

        // The next run marks every built hour, and each is summed again.
        $built = (int) self::scalar('SELECT built_through_hour FROM 202_attribution_rollup_state WHERE user_id = 1');
        $rollup = new AttributionRollup($this->conn, static fn (): int => $t['now']);
        $rollup->sync(1);
        self::assertSame(1, (int) self::scalar('SELECT ' . AttributionRollup::journeysMarkerSql(1)));
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_rollup_dirty WHERE user_id = 1 AND hour_from = 0 AND hour_to = ' . ($built - 1)), 'every built hour is marked');
        self::assertSame(0, $this->assertSameJourneys('marked, not summed again', 1, ...$window), 'no clean hour lacks journey rows');
        $this->buildRollup($t['now']);
        self::assertGreaterThan(0, $this->assertSameJourneys('summed again', 1, ...$window));
        $rollup->sync(1);
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_rollup_dirty WHERE user_id = 1 AND hour_from = 0'), 'a rollup that keeps the part is not marked again');
    }

    /**
     * The window between planning and reading, made deterministic: a plan is
     * made, the rollup moves under it, and each of the journey statements
     * must refuse to answer from it (its own guard, in its own snapshot).
     * No differential run can see a missing guard, since nothing moves
     * between the two there.
     */
    public function testEachJourneyStatementRefusesAPlanTheRollupMovedUnder(): void
    {
        $t = $this->generate(29, ['conversions' => 60, 'span' => 4 * 86400, 'campaigns' => 3]);
        $this->buildRollup($t['now']);
        $reports = new AttributionReports($this->conn, true);
        $call = static fn (string $method, mixed ...$args): mixed => (new \ReflectionMethod(AttributionReports::class, $method))->invoke($reports, ...$args);
        $window = [$t['first'] - 7200, $t['last'] + 7200];
        $moves = [
            'a planned hour marked dirty' => fn (array $plan) => self::fixture('INSERT INTO 202_attribution_rollup_dirty (user_id, hour_from, hour_to) VALUES (1, ' . $plan['runs'][0][0] . ', ' . $plan['runs'][0][0] . ')'),
            'a changed click unresolved' => fn () => self::fixture('INSERT INTO 202_attribution_rollup_dirty_clicks (user_id, click_id) VALUES (1, 1)'),
            'the journey part no longer kept' => fn () => self::fixture('DELETE FROM 202_attribution_rollup WHERE user_id = 1 AND grain = ' . AttributionRollup::GRAIN_MARKER),
            'the frontier moved back' => fn (array $plan) => self::fixture('UPDATE 202_attribution_rollup_state SET built_through_hour = ' . $plan['maxHour'] . ' WHERE user_id = 1'),
        ];
        foreach ($moves as $label => $move) {
            self::fixture('DELETE FROM 202_attribution_rollup_dirty');
            self::fixture('DELETE FROM 202_attribution_rollup_dirty_clicks');
            $this->buildRollup($t['now']);
            (new AttributionRollup($this->conn, static fn (): int => $t['now']))->sync(1);
            $plan = $call('rollupPlan', 1, false, [AttributionRollup::EFFECTIVE], 0, 'journeys', ...$window);
            self::assertIsArray($plan, "$label: a plan is made");
            self::assertNotNull($call('rolledJourneyTallies', $plan), "$label: before the move the tallies read");
            self::assertNotNull($call('rolledJourneyBrowsers', $plan), "$label: before the move the browsers read");
            $move($plan);
            self::assertNull($call('rolledJourneyTallies', $plan), "$label: the tallies refuse the plan");
            self::assertNull($call('rolledJourneyBrowsers', $plan), "$label: the browsers refuse the plan");
            self::fixture('DELETE FROM 202_attribution_rollup WHERE user_id = 1');
            self::fixture('DELETE FROM 202_attribution_rollup_state WHERE user_id = 1');
        }
    }

    public function testChangesAfterTheRollupWasSummedAreReadExactlyUntilItIsSummedAgain(): void
    {
        $base = 1_700_000_000; // long sealed: the worker's own rollup pass sums it
        $this->campaign(1, 'replace');
        $this->campaign(2, 'accumulate');
        $this->campaign(3);
        $first = $this->addModel('First', ModelType::FIRST_TOUCH);
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $default = $this->defaultModelId();
        $clicks = [];
        for ($i = 1; $i <= 30; $i++) {
            $time = $base + $i * 5_417;
            $this->click($i, 1 + $i % 3, $time, sprintf('0.%02d', $i));
            $this->visit($i, $time, self::cookie('p' . ($i % 7)));
            $clicks[$i] = $time;
        }
        $convs = [];
        foreach ([5, 9, 14, 20, 26, 29] as $n => $c) {
            $convs[$c] = $this->convert($c, (string) (10 + $n), 'T' . $c, $clicks[$c] + 1_800);
        }
        $this->work();
        $window = [$base - 3_000, $base + 40 * 5_417];
        $this->compareAll('summed', $window, $first, $linear, true);

        $worker = new AttributionWorker($this->conn);
        $processOutbox = function () use ($worker): void {
            foreach (self::all('SELECT conv_id, reason, enqueue_seq FROM 202_attribution_pending') as $p) {
                $this->conn->transaction(function () use ($worker, $p): void {
                    $worker->processConversion((int) $p['conv_id'], (string) $p['reason']);
                    self::$db->query('DELETE FROM 202_attribution_pending WHERE conv_id = ' . (int) $p['conv_id']);
                });
            }
        };
        $journeysMoved = [];
        $step = function (string $label, callable $change) use ($processOutbox, $window, $first, $linear, &$journeysMoved): void {
            $before = $this->fullComputation($window, $first, $linear);
            $change();
            $processOutbox();
            $after = $this->fullComputation($window, $first, $linear);
            self::assertNotSame($before, $after, "$label changes what the reports answer, or comparing after it proves nothing");
            if ($before['journeys'] !== $after['journeys']) {
                $journeysMoved[] = $label;
            }
            $this->compareAll("$label (dirty)", $window, $first, $linear, false);
            (new AttributionRollup($this->conn))->run(60);
            self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_rollup_dirty WHERE user_id = 1'), "$label: every dirty hour was summed again");
            self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_rollup_dirty_clicks WHERE user_id = 1'), "$label: every changed click was resolved");
            $this->compareAll("$label (summed again)", $window, $first, $linear, true);
        };

        $step('a retraction', fn () => $this->ledger->softDelete($convs[9], 1));
        $step('a replacement', fn () => $this->convert(14, '99', 'T14b', $clicks[14] + 7_200));
        $step('a partial reversal', fn () => $this->ledger->record(1, ['click_id' => 20, 'source' => 'postback', 'transaction_id' => 'T20', 'payout' => '-4']));
        $step('a revival', function () use ($convs): void {
            self::$db->query('UPDATE 202_conversion_logs SET deleted = 0 WHERE conv_id = ' . $convs[9]);
            (new MysqlConversionLedger($this->conn))->enqueue([$convs[9]], 'counted_state');
        });
        $step('a conversion for an old hour', fn () => $this->convert(2, '7', 'T2', $clicks[2] + 60));
        $step('a model\'s config changed', function () use ($linear): void {
            (new ModelRepository($this->conn))->update(1, $linear, 'Linear', 'linear', ModelType::TIME_DECAY, ['half_life_hours' => 5.0], 30, 'active', false, true);
            $w = new AttributionWorker($this->conn);
            $w->fanOutModelRecomputes();
        });
        // Campaign 3 holds most of the conversions, on multi-touch journeys.
        $step('a campaign override set', fn () => self::$db->query("UPDATE 202_aff_campaigns SET attribution_model_id = $first WHERE aff_campaign_id = 3"));
        $step('the default changed', function () use ($first): void {
            self::$db->query('UPDATE 202_attribution_models SET is_default = NULL WHERE user_id = 1');
            self::$db->query("UPDATE 202_attribution_models SET is_default = 1 WHERE model_id = $first");
        });
        $step('one click rewritten', function () use ($clicks): void {
            // What a rotator re-click does to an existing click (rtr.php):
            // the click row again, now, and its keyword, country and browser
            // replaced (a browser the journey metrics name). Each statement
            // is checked: an unchecked one that fails is a change that
            // never happened, compared as though it had.
            $q = static function (string $sql): void {
                if (self::$db->query($sql) !== true) {
                    throw new \RuntimeException('the rewrite failed: ' . self::$db->error);
                }
            };
            $q("INSERT INTO 202_browsers SET browser_id = 3, browser_name = 'Rewritten browser'");
            $this->conn->transaction(function () use ($clicks, $q): void {
                $q('INSERT INTO 202_clicks SET click_id = 14, user_id = 1, aff_campaign_id = 3, ppc_account_id = 0, click_payout = 0, click_cpc = 0.5, click_lead = 0, click_filtered = 0, click_bot = 0, click_time = ' . ($clicks[14] + 90_000));
                $q('REPLACE INTO 202_clicks_advance SET click_id = 14, keyword_id = 77, ip_id = 0, country_id = 3, region_id = 0, city_id = 0, device_id = 0, browser_id = 3, platform_id = 0');
                RollupDirty::click($this->conn, 1, 14);
            });
            self::assertSame('77', self::scalar('SELECT keyword_id FROM 202_clicks_advance WHERE click_id = 14'));
        });
        $step('a CPC range update', function () use ($base): void {
            $this->conn->transaction(function () use ($base): void {
                self::$db->query('UPDATE 202_clicks SET click_cpc = 0.77 WHERE user_id = 1 AND click_time BETWEEN ' . ($base + 20_000) . ' AND ' . ($base + 60_000));
                RollupDirty::timeRange($this->conn, 1, $base + 20_000, $base + 60_000);
            });
        });
        // The journey comparisons above proved something only where the
        // change moved the journey metrics: the counts (a conversion gone,
        // back, added, moved to another hour; the converting click's
        // browser) or, for the reversal, the amount the recent list shows.
        // Model, override, default and cost changes move no journey.
        self::assertSame(['a retraction', 'a replacement', 'a partial reversal', 'a revival', 'a conversion for an old hour', 'one click rewritten'], $journeysMoved);
        // A conversion dated before its own click, which is young: the hour
        // of the conversion is summed but left dirty while its journey holds
        // a click younger than the seal, because the redirects that rewrite a
        // young click do not mark it (RollupDirty::HOT_PATH_SECONDS).
        $young = time() - 60;
        $this->click(40, 1, $young);
        $this->visit(40, $young, self::cookie('p1'));
        $this->convert(40, '5', 'T40', $base + 30_000);
        $processOutbox();
        (new AttributionRollup($this->conn))->run(60);
        $hour = intdiv($base + 30_000, 3600);
        self::assertGreaterThan(0, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_rollup_dirty WHERE user_id = 1 AND hour_from <= $hour AND hour_to >= $hour"), 'the hour holding a young touch stays dirty');
        $this->compareAll('a conversion dated before its young click', $window, $first, $linear, true);
        $before = $this->fullComputation($window, $first, $linear);
        self::$db->query('UPDATE 202_clicks SET aff_campaign_id = 2 WHERE click_id = 40'); // what off.php does, unmarked
        self::assertNotSame($before, $this->fullComputation($window, $first, $linear), 'the young click\'s campaign moved the reports');
        $this->compareAll('a young click rewritten without a mark', $window, $first, $linear, true);

        // The default is the first-touch model now; switch the linear one
        // off (its credits and its rollup rows go in one transaction).
        (new ModelRepository($this->conn))->update(1, $linear, 'Linear', 'linear', ModelType::TIME_DECAY, ['half_life_hours' => 5.0], 30, 'inactive', false, false);
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_rollup WHERE model_id = $linear"));
        $this->compareAll('a model switched off', $window, $default, $first, true);
    }

    // --- comparison ---

    /**
     * Every dimension, effective and explicit, under the account's current
     * default (what the API passes).
     *
     * @param array{0: int, 1: int} $window
     */
    private function compareAll(string $label, array $window, int $model, int $other, bool $expectServed): void
    {
        $default = $this->defaultModelId();
        $served = 0;
        $ranges = [
            'whole window' => $window,
            'mid-hour edges' => [$window[0] + 4_321, $window[1] - 2_345],
        ];
        $journeysServed = 0;
        foreach ($ranges as $rangeLabel => [$from, $to]) {
            $journeysServed += $this->assertSameJourneys("$label, $rangeLabel, journey metrics", 1, $from, $to);
            foreach (AttributionReports::dimensions() as $dim) {
                foreach ([[null, null], [$model, $other], [null, $model]] as [$m, $c]) {
                    $served += $this->assertSameAnswer("$label, $rangeLabel, $dim", 1, $m, $c, $default, $dim, $from, $to);
                }
            }
            self::$db->query("SET time_zone = '+05:30'");
            $served += $this->assertSameAnswer("$label, $rangeLabel, day in +05:30", 1, null, $model, $default, 'day', $from, $to);
            self::$db->query("SET time_zone = '+00:00'");
        }
        if ($expectServed) {
            self::assertGreaterThan(0, $served, "$label: the rollup served hours");
            self::assertGreaterThan(0, $journeysServed, "$label: the rollup served journey hours");
        }
    }

    /**
     * Every breakdown of the window computed in full, for telling whether a
     * change moved anything.
     *
     * @param array{0: int, 1: int} $window
     * @return array{breakdowns: list<array<string, mixed>>, journeys: array<string, mixed>}
     */
    private function fullComputation(array $window, int $model, int $other): array
    {
        $full = new AttributionReports($this->conn, false);
        $out = [];
        foreach (AttributionReports::dimensions() as $dim) {
            foreach ([[null, null], [$model, $other]] as [$m, $c]) {
                $out[] = $full->breakdownAll(1, $m, $c, $this->defaultModelId(), $dim, $window[0], $window[1]);
            }
        }

        return ['breakdowns' => $out, 'journeys' => $full->journeyMetrics(1, $window[0], $window[1])];
    }

    /** @return int hours the rollup served */
    private function assertSameAnswer(string $context, int $userId, ?int $model, ?int $compare, int $default, string $dim, int $from, int $to): int
    {
        $expected = (new AttributionReports($this->conn, false))->breakdownAll($userId, $model, $compare, $default, $dim, $from, $to);
        $reports = new AttributionReports($this->conn, true);
        $actual = $reports->breakdownAll($userId, $model, $compare, $default, $dim, $from, $to);
        self::assertSame($expected, $actual, "$context [$from, $to]");
        $this->comparisons++;

        return $reports->lastServedHours();
    }

    /** @return int hours the rollup served */
    private function assertSameJourneys(string $context, int $userId, int $from, int $to): int
    {
        $expected = (new AttributionReports($this->conn, false))->journeyMetrics($userId, $from, $to);
        $reports = new AttributionReports($this->conn, true);
        $actual = $reports->journeyMetrics($userId, $from, $to);
        self::assertSame($expected, $actual, "$context [$from, $to]");
        $this->comparisons++;

        return $reports->lastServedHours();
    }

    /** @return list<string> */
    private static function timeZones(): array
    {
        $zones = self::TIME_ZONES;
        // A named zone with daylight saving, where the server has zone tables.
        if (self::scalar("SELECT CONVERT_TZ('2024-03-10 12:00:00', 'UTC', 'America/New_York')") !== null) {
            $zones[] = 'America/New_York';
            $zones[] = 'Australia/Lord_Howe'; // a 30-minute DST shift
        }

        return $zones;
    }

    // --- data ---

    private function buildRollup(int $now): void
    {
        // Until nothing new is summed. An hour whose journeys hold a click
        // younger than the seal is re-summed and left dirty on every pass
        // (the data has a click row dated after its conversion), so the
        // passes settle at a constant count rather than zero.
        $rollup = new AttributionRollup($this->conn, static fn (): int => $now);
        $previous = -1;
        for ($i = 0; $i < 200; $i++) {
            $report = $rollup->run(3600);
            if ($report->hoursBuilt === 0 && $report->clicksResolved === 0 && $report->hoursRebuilt === $previous) {
                return;
            }
            $previous = $report->hoursRebuilt;
        }
        self::fail('the rollup did not settle');
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    private function ranges(int $seed, int $first, int $last, int $builtHour): array
    {
        mt_srand($seed * 7 + 1);
        $span = max(1, $last - $first);
        $h = intdiv($first, 3600) + 1;
        $d = intdiv($first, 86400) + 1;
        $ranges = [
            'everything' => [$first - 7200, $last + 7200],
            'hour aligned' => [$h * 3600, ($h + 30) * 3600 - 1],
            'UTC days' => [$d * 86400, ($d + 2) * 86400 - 1],
            'one second either side of an hour' => [$h * 3600 - 1, ($h + 5) * 3600],
            'inside one hour' => [$h * 3600 + 100, $h * 3600 + 3000],
            'across the summed frontier' => [$builtHour * 3600 - 5 * 86400 - 17, $last + 60],
            'only unsummed hours' => [$builtHour * 3600 + 1, $last + 60],
        ];
        for ($i = 0; $i < 6; $i++) {
            $from = $first - 3600 + mt_rand(0, $span);
            $ranges["random $i"] = [$from, $from + mt_rand(1, $span)];
        }

        return $ranges;
    }

    /**
     * A tenant (user 1) and a neighbour (user 2), written directly: the
     * rollup sums whatever the tables hold, so the shapes the writers can
     * leave — and a few they should not, like a credit whose click is gone —
     * are all here.
     *
     * @param array<string, int> $cfg
     * @return array{first: int, last: int, now: int, models: array<string, int>}
     */
    private function generate(int $seed, array $cfg, ?int $base = null): array
    {
        mt_srand($seed);
        $base ??= 1_735_000_000 + mt_rand(0, 3_599_999);
        $span = $cfg['span'];
        $campaigns = $cfg['campaigns'];

        $repo = new ModelRepository($this->conn);
        $models = [
            'default' => $this->defaultModelId(),
            'first' => $this->addModel('First', ModelType::FIRST_TOUCH),
            'linear' => $this->addModel('Linear', ModelType::LINEAR),
            'off' => $repo->insert(1, 'Off', 'off', ModelType::FIRST_TOUCH, [], 30, 'inactive', false),
        ];
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='other', user_email='x@example.test', user_time_register=1, user_deleted=0, user_active=1");
        $models['other'] = DefaultModel::ensureFor($this->conn, 2);

        for ($c = 1; $c <= $campaigns; $c++) {
            $override = match ($c) {
                2 => $models['first'],
                3 => $models['off'],
                4 => $models['linear'],
                default => null,
            };
            $this->campaign($c, 'accumulate', $override);
        }
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=900, user_id=2, aff_network_id=1, aff_campaign_name='Other', aff_campaign_url='http://x', aff_campaign_payout=1, aff_campaign_time=1, aff_campaign_foreign_payout=1");
        $this->bulk('202_ppc_accounts', ['ppc_account_id', 'user_id', 'ppc_network_id', 'ppc_account_name'], array_map(static fn (int $i): array => [$i, 1, 1, "'Source $i'"], range(1, 4)));
        $this->bulk('202_landing_pages', ['landing_page_id', 'user_id', 'landing_page_nickname'], array_map(static fn (int $i): array => [$i, 1, "'Page $i'"], range(1, 3)));
        $this->bulk('202_keywords', ['keyword_id', 'keyword'], array_map(static fn (int $i): array => [$i, "'kw $i'"], range(1, 38)));
        $this->bulk('202_locations_country', ['country_id', 'country_code', 'country_name'], array_map(static fn (int $i): array => [$i, "'C$i'", "'Country $i'"], range(1, 12)));
        $this->bulk('202_device_types', ['type_id', 'type_name'], array_map(static fn (int $i): array => [$i, "'Type $i'"], range(1, 3)));
        $this->bulk('202_device_models', ['device_id', 'device_name', 'device_type'], array_map(static fn (int $i): array => [$i, "'Model $i'", 1 + $i % 4], range(1, 6)));
        foreach (['c1', 'c2', 'c3', 'c4'] as $cn) {
            $this->bulk("202_tracking_$cn", ["{$cn}_id", $cn], array_map(static fn (int $i): array => [$i, "'$cn value $i'"], range(1, 5)));
        }
        // Browsers 7 and 8 have no row; 4 and 5 share a name; 6 is named
        // what a missing one reads as; 0 has a row (which only a session
        // that keeps an explicit 0 can insert).
        $this->bulk('202_browsers', ['browser_id', 'browser_name'], [[1, "'Browser 1'"], [2, "'Browser 2'"], [3, "'Browser 3'"], [4, "'Browser 4'"], [5, "'Browser 4'"], [6, "'Unknown'"]]);
        self::$db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
        $zero = self::$db->query("INSERT INTO 202_browsers SET browser_id = 0, browser_name = 'Browser zero'");
        self::$db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
        self::assertTrue($zero, 'browser 0 inserted: ' . self::$db->error);
        self::assertSame('Browser zero', self::scalar('SELECT browser_name FROM 202_browsers WHERE browser_id = 0'));

        $clickId = 0;
        $convId = 0;
        $clickRows = [];
        $advance = [];
        $tracking = [];
        $convRows = [];
        $meta = [];
        $journeys = [];
        $credits = [];
        $campaignOf = [];
        $newClick = function (int $user, int $time) use (&$clickId, &$clickRows, &$advance, &$tracking, &$campaignOf, $campaigns): int {
            $id = ++$clickId;
            $campaign = $user === 2 ? 900 : mt_rand(1, $campaigns + 1); // campaign $campaigns+1 has no row
            $row = [$id, $user, $campaign, mt_rand(0, 5), mt_rand(0, 4), sprintf("'%.5f'", mt_rand(0, 99_999) / 100_000), mt_rand(0, 19) === 0 ? 1 : 0, $time];
            $clickRows[] = $row;
            $campaignOf[$id] = $campaign;
            if (mt_rand(0, 49) === 0) {
                // The same click_id twice, as a rotator re-click leaves it.
                $again = $row;
                $again[2] = mt_rand(1, $campaigns);
                $again[7] = $time + mt_rand(0, 90_000);
                $clickRows[] = $again;
            }
            if (mt_rand(0, 9) > 0) {
                $advance[] = [$id, mt_rand(0, 40), mt_rand(0, 13), mt_rand(0, 7), mt_rand(0, 8)];
            }
            if (mt_rand(0, 4) > 0) {
                $tracking[] = [$id, mt_rand(0, 6), mt_rand(0, 6), mt_rand(0, 6), mt_rand(0, 6)];
            }

            return $id;
        };

        $first = PHP_INT_MAX;
        $last = 0;
        $plan = [];
        for ($i = 0; $i < $cfg['conversions']; $i++) {
            $plan[] = [1, $base + mt_rand(0, $span)];
        }
        for ($i = 0; $i < max(2, intdiv($cfg['conversions'], 10)); $i++) {
            $plan[] = [2, $base + mt_rand(0, $span)];
        }
        foreach ($plan as [$user, $convTime]) {
            $touches = mt_rand(1, 5);
            $times = [];
            for ($k = 0; $k < $touches; $k++) {
                $times[] = $convTime - (mt_rand(0, 3) === 0 ? mt_rand(0, 40 * 86400) : mt_rand(0, 5 * 86400));
            }
            sort($times);
            if (mt_rand(0, 24) === 0) {
                // A conversion dated before its clicks (the first touch after it).
                $times = array_map(static fn (int $tm): int => $tm + 40 * 86400 + mt_rand(1, 7200), $times);
            }
            $ids = array_map(fn (int $tm): int => $newClick($user, $tm), $times);
            if (mt_rand(0, 99) === 0) {
                $ids[0] = 5_000_000 + $clickId; // a touch whose click row is gone
            }
            $id = ++$convId;
            $amount = mt_rand(1, 50_000) / 100;
            $last_click = $ids[$touches - 1];
            $convCampaign = $user === 2 ? 900 : (mt_rand(0, 9) === 0 ? mt_rand(1, $campaigns) : ($campaignOf[$last_click] ?? 1));
            $convRows[] = [$id, $last_click, $convCampaign, sprintf("'%.5f'", $amount), $user, $times[$touches - 1], $convTime, "'d$id'"];
            $meta[] = [$id, $user, $convTime, $touches, 30, 1, mt_rand(0, 6) === 0 ? 1 : 0, mt_rand(0, 3) === 0 ? 0 : 1];
            // Now and then a journey missing its first or its last position
            // (what a half-written journey would leave).
            $missing = mt_rand(0, 49) === 0 ? mt_rand(0, 1) * ($touches - 1) : -1;
            foreach ($ids as $pos => $cid) {
                if ($pos !== $missing) {
                    $journeys[] = [$id, $pos, $cid, $times[$pos]];
                }
            }
            $modelFor = $user === 2 ? ['default' => $models['other']] : ['default' => $models['default'], 'first' => $models['first'], 'linear' => $models['linear']];
            foreach ($modelFor as $name => $mid) {
                if ($name === 'default') {
                    $credits[] = [$id, $mid, $last_click, $touches - 1, "'1.00000000'", sprintf("'%.5f'", $amount), $convTime];
                } elseif ($name === 'first') {
                    $credits[] = [$id, $mid, $ids[0], 0, "'1.00000000'", sprintf("'%.5f'", $amount), $convTime];
                } else {
                    foreach (array_unique($ids) as $pos => $cid) {
                        $credits[] = [$id, $mid, $cid, $pos, sprintf("'%.8f'", 1 / $touches), sprintf("'%.5f'", $amount / $touches), $convTime];
                    }
                }
            }
            $first = min($first, $times[0]);
            $last = max($last, $convTime);
        }
        // Clicks nobody converted on: cost only.
        for ($i = 0; $i < intdiv($cfg['conversions'] * 3, 2) + 3; $i++) {
            $newClick(mt_rand(0, 9) === 0 ? 2 : 1, $base + mt_rand(0, $span));
        }

        $this->bulk('202_clicks', ['click_id', 'user_id', 'aff_campaign_id', 'ppc_account_id', 'landing_page_id', 'click_cpc', 'click_bot', 'click_time'], $clickRows);
        $this->bulk('202_clicks_advance', ['click_id', 'keyword_id', 'country_id', 'device_id', 'browser_id'], $advance);
        $this->bulk('202_clicks_tracking', ['click_id', 'c1_id', 'c2_id', 'c3_id', 'c4_id'], $tracking);
        $this->bulk('202_conversion_logs', ['conv_id', 'click_id', 'campaign_id', 'click_payout', 'user_id', 'click_time', 'conv_time', 'dedupe_key'], $convRows);
        $this->bulk('202_attribution_journey_meta', ['conv_id', 'user_id', 'conv_time', 'touches', 'built_lookback_days', 'built_at', 'truncated', 'identified'], $meta);
        $this->bulk('202_attribution_journeys', ['conv_id', 'position', 'click_id', 'click_time'], $journeys);
        $this->bulk('202_attribution_credits', ['conv_id', 'model_id', 'click_id', 'position', 'credit', 'revenue', 'conv_time'], $credits);

        foreach ($clickRows as $r) {
            $first = min($first, $r[7]);
            $last = max($last, $r[7]);
        }

        return [
            'first' => $first,
            'last' => $last,
            // Summed up to about six hours before the newest data.
            'now' => $last - 6 * 3600 + AttributionRollup::SEAL_SECONDS,
            'models' => $models,
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<list<int|string>> $rows values already SQL literals
     */
    private function bulk(string $table, array $columns, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            self::fixture("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES '
                . implode(', ', array_map(static fn (array $r): string => '(' . implode(', ', $r) . ')', $chunk)));
        }
    }
}
