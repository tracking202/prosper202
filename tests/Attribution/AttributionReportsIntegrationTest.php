<?php

declare(strict_types=1);

namespace Tests\Attribution;

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ModelType;
use Prosper202\Conversion\Ledger\Amount;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * The reports API over real credits: grouped by a click dimension, where
 * the models differ (the property the old engine could not produce), with
 * cost from the dimension's own clicks, assists, the effective per-campaign
 * model, the comparison, journey metrics and one conversion explained.
 *
 * @group integration
 */
final class AttributionReportsIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private int $first;
    private AttributionController $api;

    /**
     * One person: a click on campaign 1 (cost 0.50), then campaign 2 (0.25),
     * then campaign 1 again, which converts for $12. A stranger's click on
     * campaign 2 converts for $3.
     */
    private function scenario(): array
    {
        $now = time();
        $this->campaign(1);
        $this->campaign(2);
        $this->click(1, 1, $now - 3 * 86400, '0.50');
        $this->click(2, 2, $now - 2 * 86400, '0.25');
        $this->click(3, 1, $now - 3600, '0.50');
        foreach ([1, 2, 3] as $c) {
            $this->visit($c, (int) self::scalar("SELECT click_time FROM 202_clicks WHERE click_id=$c"), self::cookie('p'));
        }
        $this->click(4, 2, $now - 1800, '0.25');
        $this->visit(4, $now - 1800, self::cookie('stranger'));
        $a = $this->convert(3, '12.00', 'A');
        $b = $this->convert(4, '3.00', 'B');
        $this->first = $this->addModel('First', ModelType::FIRST_TOUCH);
        $this->work();
        $this->api = new AttributionController(self::$db, 1);

        return [$a, $b];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function byKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r;
        }

        return $out;
    }

    public function testFirstAndLastTouchDisagreeByCampaignAndBothSumToTheRevenue(): void
    {
        $this->scenario();
        $last = self::byKey($this->api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $this->defaultModelId()])['data']);
        $first = $this->api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $this->first]);
        $firstRows = self::byKey($first['data']);

        self::assertSame('12.00000', $last['1']['attributed_revenue']);
        self::assertSame('3.00000', $last['2']['attributed_revenue']);
        self::assertSame('12.00000', $firstRows['1']['attributed_revenue'], 'the first touch was campaign 1 too');
        self::assertSame('3.00000', $firstRows['2']['attributed_revenue']);

        // Linear shows the difference by campaign: 2/3 and 1/3 of $12.
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        $lin = self::byKey($this->api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $linear])['data']);
        self::assertSame('8.00000', $lin['1']['attributed_revenue']);
        self::assertSame('7.00000', $lin['2']['attributed_revenue'], '$4 of the $12 plus the stranger\'s $3');

        foreach ([$this->defaultModelId(), $this->first, $linear] as $m) {
            $t = $this->api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $m])['totals'];
            self::assertSame('15.00000', $t['attributed_revenue'], "model $m");
            self::assertSame('2.00000000', $t['attributed_conversions'], "model $m");
            self::assertSame(2, $t['conversions']);
        }
        self::assertSame('Campaign 1', $lin['1']['name']);
    }

    public function testCostComesFromTheDimensionsOwnClicksAndAssistsAreCounted(): void
    {
        $this->scenario();
        $rows = self::byKey($this->api->breakdown(['group_by' => 'campaign'])['data']);
        self::assertSame(2, $rows['1']['clicks']);
        self::assertSame('1.00000', $rows['1']['cost']);
        self::assertSame(2, $rows['2']['clicks']);
        self::assertSame('0.50000', $rows['2']['cost']);
        self::assertSame(1100.0, $rows['1']['roi'], '(12 - 1) / 1');
        self::assertSame(1, $rows['1']['assisted_conversions'], 'click 1 assisted conversion A');
        self::assertSame(1, $rows['2']['assisted_conversions'], 'click 2 assisted conversion A');
    }

    public function testTheCampaignOverrideIsWhatTheEffectiveReportReads(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        // Conversion A (campaign 1's) under campaign 1's linear override; B under the default.
        self::$db->query("UPDATE 202_aff_campaigns SET attribution_model_id = $linear WHERE aff_campaign_id = 1");
        $out = $this->api->breakdown(['group_by' => 'campaign']);
        $rows = self::byKey($out['data']);
        self::assertSame('8.00000', $rows['1']['attributed_revenue']);
        self::assertSame('7.00000', $rows['2']['attributed_revenue']);
        self::assertSame('15.00000', $out['totals']['attributed_revenue']);
        self::assertSame('effective', $out['meta']['model']['mode']);

        // An override naming another account's (or no longer active) model falls back to the default.
        self::$db->query("UPDATE 202_attribution_models SET status = 'inactive' WHERE model_id = $linear");
        $rows = self::byKey($this->api->breakdown(['group_by' => 'campaign'])['data']);
        self::assertSame('12.00000', $rows['1']['attributed_revenue']);
    }

    public function testAModelOfAnotherAccountReadsNothingEvenWithoutTheCallersCheck(): void
    {
        // The controller refuses another account's model id before it asks;
        // the report query holds the same line on its own, so a future
        // caller that skips the check cannot read account 1's credits as
        // account 2.
        $this->scenario();
        $reports = new \Prosper202\Attribution\AttributionReports($this->conn);
        $own = $reports->breakdown(1, $this->first, null, $this->defaultModelId(), 'campaign', 0, time() + 60, 100);
        self::assertSame('15.00000', (string) $own['totals']['attributed_revenue'], 'the owner reads the model');
        $stranger = $reports->breakdown(2, $this->first, $this->defaultModelId(), $this->defaultModelId(), 'campaign', 0, time() + 60, 100);
        self::assertSame(0.0, (float) $stranger['totals']['attributed_revenue'], 'another account reads no credits under it');
        self::assertSame(0.0, (float) $stranger['totals']['compare_attributed_revenue'], 'nor under a compared model');
        foreach ($stranger['rows'] as $row) {
            self::assertSame(0.0, (float) $row['attributed_revenue'], json_encode($row));
        }
    }

    public function testTheComparisonPutsTwoModelsSideBySide(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        $out = $this->api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $this->defaultModelId(), 'compare_model_id' => (string) $linear]);
        $rows = self::byKey($out['data']);
        self::assertSame('12.00000', $rows['1']['attributed_revenue']);
        self::assertSame('8.00000', $rows['1']['compare_attributed_revenue']);
        self::assertSame('15.00000', $out['totals']['compare_attributed_revenue']);
    }

    public function testOffsetPagesThroughTheRowsInTheReportsOrder(): void
    {
        // Campaign 1 has $12 under the default (last touch), campaign 2 $3,
        // so the order is 1 then 2. A reader past `limit` pages with offset.
        $this->scenario();
        $page = fn (string $offset): array => $this->api->breakdown(['group_by' => 'campaign', 'limit' => '1', 'offset' => $offset]);

        $first = $page('0');
        self::assertSame(['1'], array_column($first['data'], 'key'));
        self::assertSame([2, 1, 0], [$first['meta']['groups'], $first['meta']['limit'], $first['meta']['offset']]);
        $second = $page('1');
        self::assertSame(['2'], array_column($second['data'], 'key'));
        self::assertSame(1, $second['meta']['offset']);
        self::assertSame($first['totals'], $second['totals'], 'totals cover every group on every page');
        self::assertSame([], $page('2')['data'], 'past the last group: no rows, not an error');
        self::assertSame(0, $this->api->breakdown(['group_by' => 'campaign'])['meta']['offset'], 'no offset reads from the top');
    }

    /**
     * keys reads only the rows asked for, in one request: a reader that
     * checks a few rows doesn't page through the report, which computes every
     * group for each page.
     */
    public function testKeysReadOnlyTheRowsAskedFor(): void
    {
        $this->scenario();
        foreach (AttributionReports::cohorts() as $cohort) {
            $out = $this->api->breakdown(['group_by' => 'campaign', 'keys' => '2,99', 'cohort' => $cohort]);
            self::assertSame(['2'], array_column($out['data'], 'key'), "$cohort: key 99 has no row");
            self::assertSame(1, $out['meta']['groups'], 'groups counts the rows matched');
            self::assertSame('15.00000', $out['totals']['attributed_revenue'], 'totals are still the whole report');
        }
        $both = $this->api->breakdown(['group_by' => 'campaign', 'keys' => '2,1']);
        self::assertSame(['1', '2'], array_column($both['data'], 'key'), 'in the report\'s order, not the order asked');
        self::assertSame(['2'], array_column($this->api->breakdown(['group_by' => 'campaign', 'keys' => '2,1', 'offset' => '1'])['data'], 'key'));
    }

    public function testEveryDimensionRuns(): void
    {
        $this->scenario();
        foreach (\Prosper202\Attribution\AttributionReports::dimensions() as $dim) {
            foreach (AttributionReports::cohorts() as $cohort) {
                // Every click and conversion is inside the default 30 days, so both cohorts hold all of it.
                $out = $this->api->breakdown(['group_by' => $dim, 'model_id' => (string) $this->first, 'cohort' => $cohort]);
                self::assertSame('15.00000', $out['totals']['attributed_revenue'], "$dim, $cohort");
                $sum = 0;
                foreach ($out['data'] as $r) {
                    $sum += (int) round((float) $r['attributed_revenue'] * 100000);
                }
                self::assertSame(1500000, $sum, "$dim, $cohort: the rows add up to the total");
            }
        }
    }

    /**
     * The click cohort reads credits and assists by the clock of the click
     * they land on. A range holding only click 1 (A's first touch, three
     * days before A converted) shows nothing in the conversion cohort and
     * A's first-touch $12 and an assist in the click cohort; a range holding
     * only the last hour shows the reverse.
     */
    public function testTheClickCohortCountsWhatTheRangesClicksEarned(): void
    {
        $this->scenario();
        $now = time();
        $read = fn (string $cohort, int $from, int $to, int $model): array => $this->api->breakdown([
            'group_by' => 'campaign', 'model_id' => (string) $model, 'cohort' => $cohort,
            'time_from' => (string) $from, 'time_to' => (string) $to,
        ]);

        // Click 1 alone.
        $from = $now - 4 * 86400;
        $to = $now - 60 * 3600;
        $conv = $read('conversion', $from, $to, $this->first);
        $rows = self::byKey($conv['data']);
        self::assertSame('conversion', $conv['meta']['cohort']);
        self::assertSame('0.00000', $rows['1']['attributed_revenue'], 'no sale was made in the range');
        self::assertSame(0, $rows['1']['assisted_conversions']);
        self::assertSame(1, $rows['1']['clicks'], 'clicks and cost are the range\'s clicks in both cohorts');

        $click = $read('click', $from, $to, $this->first);
        $rows = self::byKey($click['data']);
        self::assertSame('click', $click['meta']['cohort']);
        self::assertSame('12.00000', $rows['1']['attributed_revenue'], 'click 1 started A');
        self::assertSame('1.00000000', $rows['1']['attributed_conversions']);
        self::assertSame(2300.0, $rows['1']['roi'], '(12 - 0.50) / 0.50, revenue and cost of the same click');
        self::assertSame(1, $rows['1']['assisted_conversions'], 'click 1 assisted A');
        self::assertSame(['conversions' => 1, 'attributed_conversions' => '1.00000000', 'attributed_revenue' => '12.00000'], $click['totals']);
        $last = self::byKey($read('click', $from, $to, $this->defaultModelId())['data']);
        self::assertSame('0.00000', $last['1']['attributed_revenue'], 'last touch credits click 3, outside the range');

        // The last two hours: clicks 3 and 4 and both sales.
        $from = $now - 7200;
        $to = $now;
        $rows = self::byKey($read('conversion', $from, $to, $this->first)['data']);
        self::assertSame('12.00000', $rows['1']['attributed_revenue'], 'A, credited to click 1 three days back');
        self::assertSame(1, $rows['2']['assisted_conversions'], 'click 2 assisted A');
        $rows = self::byKey($read('click', $from, $to, $this->first)['data']);
        self::assertSame('0.00000', $rows['1']['attributed_revenue'], 'A started before the range');
        self::assertSame('3.00000', $rows['2']['attributed_revenue']);
        self::assertSame(0, $rows['2']['assisted_conversions'], 'click 2 is outside the range');

        // Last touch in the click cohort is the classic population: each row is the payout of its own converting
        // clicks in range.
        $last = self::byKey($read('click', $from, $to, $this->defaultModelId())['data']);
        $classic = self::all("SELECT aff_campaign_id AS k, SUM(click_payout) AS revenue FROM 202_clicks
            WHERE user_id = 1 AND click_lead = 1 AND click_bot = 0 AND click_time BETWEEN $from AND $to GROUP BY aff_campaign_id");
        self::assertCount(2, $classic);
        foreach ($classic as $c) {
            self::assertSame((string) $c['revenue'], $last[(string) $c['k']]['attributed_revenue'], 'campaign ' . $c['k']);
        }

        // The effective report (no model named: here every campaign reads the last-touch default) reads the same.
        $effective = $this->api->breakdown(['group_by' => 'campaign', 'cohort' => 'click', 'time_from' => (string) $from, 'time_to' => (string) $to]);
        foreach (self::byKey($effective['data']) as $k => $r) {
            self::assertSame($last[$k]['attributed_revenue'], $r['attributed_revenue'], "campaign $k");
        }
        self::assertSame('15.00000', $effective['totals']['attributed_revenue']);
        self::assertSame(2, $effective['totals']['conversions']);
    }

    /**
     * group_by=day is the account's calendar days, whatever zone the
     * connection is in, from the full computation and the rollup alike,
     * through the API and through an export's breakdownAll() (the cron's
     * call, which names no zone). It was FROM_UNIXTIME() in the
     * connection's zone: the server's for the API, and UTC in the cron once
     * the report engine had set it.
     */
    public function testTheDayIsTheAccountsDay(): void
    {
        $this->campaign(1);
        $conversions = [
            1 => '2026-03-08T03:30:00Z', // New York: Sat 7 Mar 22:30 EST.   India: Sun 8 Mar 09:00.
            2 => '2026-07-01T02:00:00Z', // New York: Tue 30 Jun 22:00 EDT.  India: Wed 1 Jul 07:30.
            3 => '2026-10-01T18:45:00Z', // New York: Thu 1 Oct 14:45 EDT.   India: Fri 2 Oct 00:15.
        ];
        foreach ($conversions as $id => $utc) {
            $t = (new \DateTimeImmutable($utc))->getTimestamp();
            $this->click($id, 1, $t - 120, '0.10');
            $this->visit($id, $t - 120, self::cookie("day$id"));
            $this->convert($id, '5.00', "D$id", $t);
        }
        $this->work();
        $rollup = new \Prosper202\Attribution\AttributionRollup($this->conn, static fn (): int => 1_791_331_200);
        for ($i = 0; $i < 20 && $rollup->run(3600)->hoursBuilt > 0; $i++) {
        }
        self::assertGreaterThan(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_rollup WHERE user_id = 1'), 'the rollup summed the hours');

        self::$db->query("SET time_zone = '+07:00'");
        try {
            foreach ([
                'America/New_York' => ['2026-03-07', '2026-06-30', '2026-10-01'],
                'Asia/Kolkata' => ['2026-03-08', '2026-07-01', '2026-10-02'],
                'UTC' => ['2026-03-08', '2026-07-01', '2026-10-01'],
            ] as $zone => $days) {
                self::fixture("UPDATE 202_users SET user_timezone = '$zone' WHERE user_id = 1");
                $out = (new AttributionController(self::$db, 1))->breakdown(['group_by' => 'day', 'time_from' => '2026-01-01', 'time_to' => '2026-12-31']);
                $keys = array_column($out['data'], 'key');
                sort($keys);
                self::assertSame($days, $keys, "$zone: the conversions' days");
                self::assertSame($zone, $out['meta']['timezone'] ?? null, "$zone: the response names the zone its days are in");
                foreach ($out['data'] as $row) {
                    self::assertSame(1, $row['clicks'], "$zone {$row['key']}: the click two minutes before is on the same day");
                    self::assertSame($row['key'], $row['name']);
                }
                [$from, $to] = [(int) $out['meta']['time_from'], (int) $out['meta']['time_to']];
                $default = $this->defaultModelId();
                $full = (new AttributionReports($this->conn, false))->breakdownAll(1, null, null, $default, 'day', $from, $to);
                $rolled = new AttributionReports($this->conn, true);
                self::assertSame($full, $rolled->breakdownAll(1, null, null, $default, 'day', $from, $to), "$zone: the rollup's days are the full computation's");
                self::assertGreaterThan(0, $rolled->lastServedHours(), "$zone: and the rollup served hours");
                $byClick = array_column((new AttributionReports($this->conn))->breakdownAll(1, null, null, $default, 'day', $from, $to, AttributionReports::COHORT_CLICK)['rows'], 'key');
                sort($byClick);
                self::assertSame($days, $byClick, "$zone: the click cohort's days");
            }
        } finally {
            self::$db->query("SET time_zone = 'SYSTEM'");
        }
    }

    public function testJourneyMetricsAndOneConversionExplained(): void
    {
        [$a, $b] = $this->scenario();
        $m = $this->api->journeyMetrics([])['data'];
        self::assertSame(2, $m['conversions']);
        self::assertSame(1, $m['one_touch']);
        self::assertSame(0.5, $m['one_touch_share']);
        self::assertSame([['touches' => 1, 'conversions' => 1], ['touches' => 3, 'conversions' => 1]], $m['length_distribution']);
        self::assertSame(1, $m['time_to_convert']['1d_to_7d'], 'A\'s first click was three days before');
        self::assertSame(1, $m['time_to_convert']['under_1h']);

        $j = $this->api->journey($a)['data'];
        self::assertSame([1, 2, 3], array_column($j['touches'], 'click_id'));
        self::assertSame(['vid'], $j['touches'][0]['signals'], 'the evidence: which signal linked the click');
        self::assertTrue($j['journey']['identified']);
        $byModel = [];
        foreach ($j['credits'] as $c) {
            $byModel[$c['model_id']] = array_column($c['touches'], 'click_id');
        }
        self::assertSame([3], $byModel[$this->defaultModelId()]);
        self::assertSame([1], $byModel[$this->first]);

        $this->expectException(\Api\V3\Exception\NotFoundException::class);
        (new AttributionController(self::$db, 2))->journey($a);
    }

    /**
     * A $10 sale with a −$4 reversal counts $6 in MTA (the worker credits
     * the remainder), so every read that shows the conversion's amount next
     * to its credits has to show $6: the drill-down and the recent list —
     * or a column "summing to the whole conversion" sums to something else.
     * The breakdown and the rows an export writes (breakdownAll) sum the
     * credits, so they read $6 too.
     */
    public function testAPartiallyReversedConversionShowsTheAmountItsCreditsSumTo(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(70, 7, time() - 7200, '0.10');
        $this->visit(70, time() - 7200, self::cookie('rev'));
        $this->click(71, 7, time() - 3600, '0.10');
        $this->visit(71, time() - 3600, self::cookie('rev'));
        $sale = $this->convert(71, '10', 'R-1');
        $postback = ['source' => 'postback'];
        $this->ledger->record(1, $postback + ['click_id' => 71, 'transaction_id' => 'R-1', 'payout' => '-4']);
        $reversal = (int) self::scalar("SELECT conv_id FROM 202_conversion_logs WHERE reverses_conv_id = $sale");
        self::assertGreaterThan(0, $reversal, 'the reversal was recorded against the sale');
        $this->click(72, 7, time() - 1800, '0.10');
        $gone = $this->convert(72, '3', 'R-2');
        $this->ledger->record(1, $postback + ['click_id' => 72, 'transaction_id' => 'R-2', 'reversal' => true]);
        $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        $api = new AttributionController(self::$db, 1);

        $j = $api->journey($sale)['data'];
        self::assertSame('6.00000', $j['amount'], 'the drill-down shows what counts: $10 net of the $4 reversal');
        self::assertSame('10.00000', $j['recorded_amount'], 'and what was recorded, beside it');
        self::assertTrue($j['counted']);
        self::assertCount(2, $j['credits'], 'both models credited it');
        foreach ($j['credits'] as $c) {
            $revenue = array_column($c['touches'], 'revenue');
            $units = array_sum(array_map(static fn (string $v): int => Amount::toUnits($v), $revenue));
            $why = 'model ' . $c['model_name'] . ': its revenue column sums to the amount shown';
            self::assertSame($j['amount'], Amount::fromUnits($units), $why);
        }

        $recent = [];
        foreach ($api->journeyMetrics([])['data']['recent_conversions'] as $r) {
            $recent[$r['conv_id']] = [$r['amount'], $r['recorded_amount'], $r['counted']];
        }
        self::assertSame(['6.00000', '10.00000', true], $recent[$sale], 'the recent list shows the same amount');
        self::assertArrayNotHasKey($gone, $recent, 'a sale reversed to nothing has no journey to list');

        $default = $this->defaultModelId();
        $breakdown = $api->breakdown(['group_by' => 'campaign', 'model_id' => (string) $default]);
        self::assertSame('6.00000', $breakdown['totals']['attributed_revenue'], 'the breakdown totals the same');
        $all = (new AttributionReports($this->conn))->breakdownAll(1, $default, null, $default, 'campaign', 0, time());
        $exported = array_column($all['rows'], 'attributed_revenue');
        self::assertSame(['6.00000'], $exported, 'and so do the rows an export writes');

        $none = $api->journey($gone)['data'];
        $noneFields = [$none['amount'], $none['recorded_amount'], $none['counted'], $none['credits']];
        self::assertSame(['0.00000', '3.00000', false, []], $noneFields, 'a sale reversed to nothing counts nothing');
        $rev = $api->journey($reversal)['data'];
        $revFields = [$rev['amount'], $rev['recorded_amount'], $rev['counted']];
        self::assertSame(['0.00000', '-4.00000', false], $revFields, 'a reversal row counts nothing of its own');
    }

    public function testBadParametersAreRefusedNotGuessed(): void
    {
        $this->scenario();
        foreach ([
            ['group_by' => 'browser'],
            ['model_id' => 'abc'],
            ['model_id' => '99999'],
            ['limit' => '0'],
            ['offset' => '-1'],
            ['offset' => '1.5'],
            ['offset' => 'abc'],
            ['offset' => '01'], // the contract refuses leading zeros
            ['cohort' => 'clicks'],
            ['cohort' => 'Click'],
            ['cohort' => ''],
            ['keys' => ''],
            ['keys' => '1,,2'],
            ['keys' => ' 1'],
            ['keys' => implode(',', range(1, 1001))],
            ['time_from' => 'yesterday'],
            ['period' => 'last7', 'time_from' => '1'],
            ['groupby' => 'campaign'],
            ['model_id' => (string) $this->first, 'compare_model_id' => (string) $this->first],
        ] as $params) {
            try {
                $this->api->breakdown($params);
                self::fail('accepted ' . json_encode($params));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::$db->query("UPDATE 202_attribution_models SET status = 'invalid', status_reason = 'broken' WHERE model_id = {$this->first}");
        $this->expectException(ConflictException::class);
        $this->api->breakdown(['model_id' => (string) $this->first]);
    }

    public function testModelWritesThroughTheApi(): void
    {
        $this->api = new AttributionController(self::$db, 1);
        $created = $this->api->createModel(['model_name' => 'Decay', 'model_type' => 'time_decay', 'weighting_config' => ['half_life_hours' => 12], 'lookback_days' => 14])['data'];
        self::assertSame(['half_life_hours' => 12.0], (array) $created['weighting_config']);
        self::assertTrue($created['recompute_pending']);
        self::assertFalse($created['is_default']);

        foreach ([
            ['model_name' => 'X', 'model_type' => 'algorithmic'],
            ['model_name' => 'X', 'model_type' => 'linear', 'lookback_days' => '30'],
            ['model_name' => 'X', 'model_type' => 'linear', 'weighting_config' => '{}'],
            ['model_name' => 'X', 'model_type' => 'time_decay', 'weighting_config' => ['half_life' => 3]],
            ['model_name' => 'X', 'model_type' => 'linear', 'status' => 'invalid'],
            ['model_name' => '', 'model_type' => 'linear'],
            ['model_name' => 'X', 'model_type' => 'linear', 'is_default' => 'yes'],
        ] as $payload) {
            try {
                $this->api->createModel($payload);
                self::fail('accepted ' . json_encode($payload));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->api->createModel(['model_name' => 'decay', 'model_type' => 'linear']);
            self::fail('a second model with the same slug');
        } catch (ConflictException) {
            self::assertTrue(true);
        }

        // Changing the type resets the config; making it default moves the default.
        $id = $created['model_id'];
        $updated = $this->api->updateModel($id, ['model_type' => 'linear', 'is_default' => true])['data'];
        self::assertSame('linear', $updated['model_type']);
        self::assertTrue($updated['is_default']);
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = 1 AND is_default = 1'));

        foreach ([['is_default' => false], ['status' => 'inactive'], ['nope' => 1]] as $payload) {
            try {
                $this->api->updateModel($id, $payload);
                self::fail('accepted ' . json_encode($payload));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->api->deleteModel($id);
            self::fail('deleted the default model');
        } catch (ConflictException) {
            self::assertTrue(true);
        }
        $old = (int) self::scalar("SELECT model_id FROM 202_attribution_models WHERE user_id = 1 AND model_slug = 'last-touch'");
        $this->api->deleteModel($old);
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = 1'));
        self::assertSame(3, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_audit WHERE user_id = 1'));
    }
}
