<?php

declare(strict_types=1);

namespace Tests\Attribution;

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelType;
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

    public function testEveryDimensionRuns(): void
    {
        $this->scenario();
        foreach (\Prosper202\Attribution\AttributionReports::dimensions() as $dim) {
            $out = $this->api->breakdown(['group_by' => $dim, 'model_id' => (string) $this->first]);
            self::assertSame('15.00000', $out['totals']['attributed_revenue'], $dim);
            $sum = 0;
            foreach ($out['data'] as $r) {
                $sum += (int) round((float) $r['attributed_revenue'] * 100000);
            }
            self::assertSame(1500000, $sum, "$dim: the rows add up to the total");
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

    public function testBadParametersAreRefusedNotGuessed(): void
    {
        $this->scenario();
        foreach ([
            ['group_by' => 'browser'],
            ['model_id' => 'abc'],
            ['model_id' => '99999'],
            ['limit' => '0'],
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
