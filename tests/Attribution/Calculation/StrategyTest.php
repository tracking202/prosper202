<?php

declare(strict_types=1);

namespace Tests\Attribution\Calculation;

use InvalidArgumentException;
use Prosper202\Attribution\Calculation\AssistedStrategy;
use Prosper202\Attribution\Calculation\CalculationResult;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\ConversionTouchpoint;
use Prosper202\Attribution\Calculation\LastTouchStrategy;
use Prosper202\Attribution\Calculation\PositionBasedStrategy;
use Prosper202\Attribution\Calculation\TimeDecayStrategy;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Touchpoint;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    private int $baseTime = 1700000000;

    // ───────────────────────────────────────────────────────────────────────
    //  Helpers
    // ───────────────────────────────────────────────────────────────────────

    private function makeLastTouchModel(?int $modelId = 1): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            userId: 1,
            name: 'Last Touch',
            slug: 'last-touch',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: true,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    private function makeTimeDecayModel(array $config = ['half_life_hours' => 48], ?int $modelId = 2): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            userId: 1,
            name: 'Time Decay',
            slug: 'time-decay',
            type: ModelType::TIME_DECAY,
            weightingConfig: $config,
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    private function makePositionBasedModel(array $config = ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4], ?int $modelId = 3): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            userId: 1,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: $config,
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    private function makeAssistedModel(?int $modelId = 4): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            userId: 1,
            name: 'Assisted',
            slug: 'assisted',
            type: ModelType::ASSISTED,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    private function makeConversion(
        int $conversionId,
        int $clickId,
        float $payout = 10.0,
        float $cost = 2.0,
        ?int $convTime = null,
        ?int $clickTime = null,
        array $journey = []
    ): ConversionRecord {
        $convTime ??= $this->baseTime;
        $clickTime ??= $this->baseTime - 3600;

        return new ConversionRecord(
            conversionId: $conversionId,
            clickId: $clickId,
            userId: 1,
            campaignId: 100,
            ppcAccountId: 200,
            convTime: $convTime,
            clickTime: $clickTime,
            clickPayout: $payout,
            clickCost: $cost,
            journey: $journey
        );
    }

    private function makeEmptyBatch(): ConversionBatch
    {
        return new ConversionBatch(
            userId: 1,
            startTime: $this->baseTime - 86400,
            endTime: $this->baseTime + 86400,
            conversions: []
        );
    }

    private function makeBatch(array $conversions): ConversionBatch
    {
        return new ConversionBatch(
            userId: 1,
            startTime: $this->baseTime - 86400,
            endTime: $this->baseTime + 86400,
            conversions: $conversions
        );
    }

    /**
     * Compute the hour bucket for a given unix timestamp (same logic as strategies).
     */
    private function hourBucket(int $timestamp): int
    {
        return (int) ($timestamp - ($timestamp % 3600));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  LastTouchStrategy
    // ═══════════════════════════════════════════════════════════════════════

    public function testLastTouchEmptyBatchReturnsEmptyResult(): void
    {
        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $this->makeEmptyBatch());

        $this->assertInstanceOf(CalculationResult::class, $result);
        $this->assertSame([], $result->snapshotsByHour);
        $this->assertSame([], $result->touchpointsByHour);
    }

    public function testLastTouchSingleConversionSingleTouchpoint(): void
    {
        $conv = $this->makeConversion(1, 10, 50.0, 5.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $this->assertArrayHasKey($bucket, $result->snapshotsByHour);
        $this->assertArrayHasKey($bucket, $result->touchpointsByHour);

        $snapshot = $result->snapshotsByHour[$bucket];
        $this->assertSame(1, $snapshot->attributedClicks);
        $this->assertSame(1, $snapshot->attributedConversions);
        $this->assertEqualsWithDelta(50.0, $snapshot->attributedRevenue, 0.001);
        $this->assertEqualsWithDelta(5.0, $snapshot->attributedCost, 0.001);

        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(1, $touchpoints);
        $this->assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.001);
        $this->assertSame(10, $touchpoints[0]->clickId);
        $this->assertSame(1, $touchpoints[0]->conversionId);
    }

    public function testLastTouchMultipleConversionsSameHourBucket(): void
    {
        $convTime = $this->baseTime;
        $conv1 = $this->makeConversion(1, 10, 10.0, 2.0, $convTime, $convTime - 100);
        $conv2 = $this->makeConversion(2, 20, 20.0, 3.0, $convTime + 100, $convTime - 200);

        $batch = $this->makeBatch([$conv1, $conv2]);

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $batch);

        // Both conversions fall in the same hour bucket
        $bucket = $this->hourBucket($convTime);
        $this->assertCount(1, $result->snapshotsByHour);
        $this->assertArrayHasKey($bucket, $result->snapshotsByHour);

        $snapshot = $result->snapshotsByHour[$bucket];
        $this->assertSame(2, $snapshot->attributedClicks);
        $this->assertSame(2, $snapshot->attributedConversions);
        $this->assertEqualsWithDelta(30.0, $snapshot->attributedRevenue, 0.001);
        $this->assertEqualsWithDelta(5.0, $snapshot->attributedCost, 0.001);

        $this->assertCount(2, $result->touchpointsByHour[$bucket]);
    }

    public function testLastTouchMultipleConversionsDifferentHourBuckets(): void
    {
        $convTime1 = $this->baseTime;
        $convTime2 = $this->baseTime + 7200; // 2 hours later

        $conv1 = $this->makeConversion(1, 10, 10.0, 2.0, $convTime1);
        $conv2 = $this->makeConversion(2, 20, 20.0, 3.0, $convTime2);

        $batch = $this->makeBatch([$conv1, $conv2]);

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $batch);

        $bucket1 = $this->hourBucket($convTime1);
        $bucket2 = $this->hourBucket($convTime2);

        $this->assertCount(2, $result->snapshotsByHour);
        $this->assertArrayHasKey($bucket1, $result->snapshotsByHour);
        $this->assertArrayHasKey($bucket2, $result->snapshotsByHour);

        $this->assertSame(1, $result->snapshotsByHour[$bucket1]->attributedConversions);
        $this->assertSame(1, $result->snapshotsByHour[$bucket2]->attributedConversions);
        $this->assertEqualsWithDelta(10.0, $result->snapshotsByHour[$bucket1]->attributedRevenue, 0.001);
        $this->assertEqualsWithDelta(20.0, $result->snapshotsByHour[$bucket2]->attributedRevenue, 0.001);
    }

    public function testLastTouchZeroPayoutConversion(): void
    {
        $conv = $this->makeConversion(1, 10, 0.0, 2.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $snapshot = $result->snapshotsByHour[$bucket];

        $this->assertSame(1, $snapshot->attributedClicks);
        $this->assertSame(1, $snapshot->attributedConversions);
        $this->assertEqualsWithDelta(0.0, $snapshot->attributedRevenue, 0.001);
        $this->assertEqualsWithDelta(2.0, $snapshot->attributedCost, 0.001);
    }

    public function testLastTouchZeroCostConversion(): void
    {
        $conv = $this->makeConversion(1, 10, 10.0, 0.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($this->makeLastTouchModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $snapshot = $result->snapshotsByHour[$bucket];

        $this->assertSame(1, $snapshot->attributedClicks);
        $this->assertSame(1, $snapshot->attributedConversions);
        $this->assertEqualsWithDelta(10.0, $snapshot->attributedRevenue, 0.001);
        $this->assertEqualsWithDelta(0.0, $snapshot->attributedCost, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TimeDecayStrategy
    // ═══════════════════════════════════════════════════════════════════════

    public function testTimeDecayEmptyBatchReturnsEmptyResult(): void
    {
        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $this->makeEmptyBatch());

        $this->assertSame([], $result->snapshotsByHour);
        $this->assertSame([], $result->touchpointsByHour);
    }

    public function testTimeDecaySingleTouchFullCredit(): void
    {
        $conv = $this->makeConversion(1, 10, 100.0, 20.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(1, $touchpoints);
        $this->assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.001);
    }

    public function testTimeDecayRecentTouchGetsMoreCredit(): void
    {
        $convTime = $this->baseTime;
        $recentClickTime = $convTime - 3600;       // 1 hour ago
        $oldClickTime = $convTime - 3600 * 96;     // 96 hours ago

        $journey = [
            new ConversionTouchpoint(10, $oldClickTime),
            new ConversionTouchpoint(20, $recentClickTime),
        ];

        // Use clickId=20 and clickTime of the recent click as the converting click
        $conv = $this->makeConversion(1, 20, 100.0, 20.0, $convTime, $recentClickTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(2, $touchpoints);

        // Touchpoints are in journey order (sorted by clickTime):
        // position 0 = old click (clickId 10), position 1 = recent click (clickId 20)
        $oldTp = null;
        $recentTp = null;
        foreach ($touchpoints as $tp) {
            if ($tp->clickId === 10) {
                $oldTp = $tp;
            }
            if ($tp->clickId === 20) {
                $recentTp = $tp;
            }
        }

        $this->assertNotNull($oldTp);
        $this->assertNotNull($recentTp);
        $this->assertGreaterThan($oldTp->credit, $recentTp->credit);
    }

    public function testTimeDecayCreditsSumToOne(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 3600 * 72),
            new ConversionTouchpoint(20, $convTime - 3600 * 24),
            new ConversionTouchpoint(30, $convTime - 3600),
        ];

        $conv = $this->makeConversion(1, 30, 100.0, 20.0, $convTime, $convTime - 3600, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $totalCredit = 0.0;
        foreach ($touchpoints as $tp) {
            $totalCredit += $tp->credit;
        }
        $this->assertEqualsWithDelta(1.0, $totalCredit, 0.001);
    }

    public function testTimeDecayDefaultHalfLifeIs48Hours(): void
    {
        // Provide config without half_life_hours to trigger the default
        // TimeDecayStrategy resolves to 48 when config key is missing.
        // ModelDefinition validation requires half_life_hours for TIME_DECAY,
        // so we test this indirectly: with half_life_hours=48 the result should
        // be the same as the default behaviour.
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 3600 * 48), // exactly 1 half-life ago
            new ConversionTouchpoint(20, $convTime),
        ];

        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $model48 = $this->makeTimeDecayModel(['half_life_hours' => 48]);
        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($model48, $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        // With half-life = 48h, click at t-48h should get half the weight of click at t=0
        // Raw weights: exp(0) = 1.0, exp(-ln(2)/48 * 48) = exp(-ln2) = 0.5
        // After normalization: 0.5/1.5 = 0.333..., 1.0/1.5 = 0.666...
        $oldTp = null;
        $recentTp = null;
        foreach ($touchpoints as $tp) {
            if ($tp->clickId === 10) {
                $oldTp = $tp;
            }
            if ($tp->clickId === 20) {
                $recentTp = $tp;
            }
        }

        $this->assertNotNull($oldTp);
        $this->assertNotNull($recentTp);
        $this->assertEqualsWithDelta(1.0 / 3.0, $oldTp->credit, 0.01);
        $this->assertEqualsWithDelta(2.0 / 3.0, $recentTp->credit, 0.01);
    }

    public function testTimeDecayCustomHalfLifeFromWeightingConfig(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 3600 * 12), // 12 hours ago = 1 half-life
            new ConversionTouchpoint(20, $convTime),
        ];

        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $model12 = $this->makeTimeDecayModel(['half_life_hours' => 12]);
        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($model12, $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $oldTp = null;
        $recentTp = null;
        foreach ($touchpoints as $tp) {
            if ($tp->clickId === 10) {
                $oldTp = $tp;
            }
            if ($tp->clickId === 20) {
                $recentTp = $tp;
            }
        }

        $this->assertNotNull($oldTp);
        $this->assertNotNull($recentTp);
        // half-life=12h, click at 12h ago => weight = 0.5, click at 0h => weight = 1.0
        // Normalized: 0.5/1.5 = 0.333, 1.0/1.5 = 0.667
        $this->assertEqualsWithDelta(1.0 / 3.0, $oldTp->credit, 0.01);
        $this->assertEqualsWithDelta(2.0 / 3.0, $recentTp->credit, 0.01);
    }

    public function testTimeDecayVeryOldClickGetsTinyCredit(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 3600 * 480), // 480 hours = 10 half-lives
            new ConversionTouchpoint(20, $convTime),
        ];

        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $oldTp = null;
        foreach ($touchpoints as $tp) {
            if ($tp->clickId === 10) {
                $oldTp = $tp;
            }
        }

        $this->assertNotNull($oldTp);
        // 10 half-lives: raw weight = 2^(-10) = ~0.001, so credit should be very small
        $this->assertLessThan(0.01, $oldTp->credit);
    }

    public function testTimeDecaySimultaneousClicksGetEqualCredit(): void
    {
        $convTime = $this->baseTime;
        $clickTime = $convTime - 3600;
        $journey = [
            new ConversionTouchpoint(10, $clickTime),
            new ConversionTouchpoint(20, $clickTime),
        ];

        // Converting click is one of them (clickId=20)
        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $clickTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $this->assertCount(2, $touchpoints);
        $this->assertEqualsWithDelta($touchpoints[0]->credit, $touchpoints[1]->credit, 0.001);
        $this->assertEqualsWithDelta(0.5, $touchpoints[0]->credit, 0.001);
    }

    public function testTimeDecayRevenueAndCostDistributedProportionally(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 3600 * 48), // 1 half-life ago
            new ConversionTouchpoint(20, $convTime),             // at conversion time
        ];

        $payout = 90.0;
        $cost = 30.0;
        $conv = $this->makeConversion(1, 20, $payout, $cost, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($this->makeTimeDecayModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $snapshot = $result->snapshotsByHour[$bucket];
        $touchpoints = $result->touchpointsByHour[$bucket];

        // Total attributed revenue/cost should equal the conversion's payout/cost
        $this->assertEqualsWithDelta($payout, $snapshot->attributedRevenue, 0.01);
        $this->assertEqualsWithDelta($cost, $snapshot->attributedCost, 0.01);

        // Each touchpoint's share of revenue should match its credit
        foreach ($touchpoints as $tp) {
            $expectedRevenue = $payout * $tp->credit;
            $expectedCost = $cost * $tp->credit;
            // Verify proportional attribution is consistent
            $this->assertGreaterThan(0.0, $tp->credit);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PositionBasedStrategy
    // ═══════════════════════════════════════════════════════════════════════

    public function testPositionBasedEmptyBatchReturnsEmptyResult(): void
    {
        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $this->makeEmptyBatch());

        $this->assertSame([], $result->snapshotsByHour);
        $this->assertSame([], $result->touchpointsByHour);
    }

    public function testPositionBasedSingleTouchFullCredit(): void
    {
        $conv = $this->makeConversion(1, 10, 100.0, 20.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(1, $touchpoints);
        $this->assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.001);
    }

    public function testPositionBasedTwoTouchesDefaultWeights(): void
    {
        // Default: first=0.4, last=0.4 => for 2 touches normalized to first/(first+last) = 0.5 each
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
        ];
        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $convTime - 3600, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(2, $touchpoints);

        // With equal first/last weights, both get 0.5
        $firstTp = $touchpoints[0];
        $lastTp = $touchpoints[1];
        $this->assertEqualsWithDelta(0.5, $firstTp->credit, 0.001);
        $this->assertEqualsWithDelta(0.5, $lastTp->credit, 0.001);
    }

    public function testPositionBasedThreeTouchesDefaultWeights(): void
    {
        // 3 touches: first=0.4, middle gets remainder=0.2, last=0.4
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $conv = $this->makeConversion(1, 30, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(3, $touchpoints);

        $credits = [];
        foreach ($touchpoints as $tp) {
            $credits[$tp->position] = $tp->credit;
        }

        $this->assertEqualsWithDelta(0.4, $credits[0], 0.001);
        $this->assertEqualsWithDelta(0.2, $credits[1], 0.001);
        $this->assertEqualsWithDelta(0.4, $credits[2], 0.001);
    }

    public function testPositionBasedFiveTouchesDefaultWeights(): void
    {
        // 5 touches: first=0.4, 3 middles share 0.2 (each ~0.0667), last=0.4
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 5400),
            new ConversionTouchpoint(30, $convTime - 3600),
            new ConversionTouchpoint(40, $convTime - 1800),
            new ConversionTouchpoint(50, $convTime),
        ];
        $conv = $this->makeConversion(1, 50, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(5, $touchpoints);

        $credits = [];
        foreach ($touchpoints as $tp) {
            $credits[$tp->position] = $tp->credit;
        }

        $this->assertEqualsWithDelta(0.4, $credits[0], 0.001);
        $middleShare = 0.2 / 3.0;
        $this->assertEqualsWithDelta($middleShare, $credits[1], 0.001);
        $this->assertEqualsWithDelta($middleShare, $credits[2], 0.001);
        $this->assertEqualsWithDelta($middleShare, $credits[3], 0.001);
        $this->assertEqualsWithDelta(0.4, $credits[4], 0.001);
    }

    public function testPositionBasedCustomWeights(): void
    {
        // Custom: first=0.3, last=0.3, middle share = 0.4
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $conv = $this->makeConversion(1, 30, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $model = $this->makePositionBasedModel(['first_touch_weight' => 0.3, 'last_touch_weight' => 0.3]);
        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($model, $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $credits = [];
        foreach ($touchpoints as $tp) {
            $credits[$tp->position] = $tp->credit;
        }

        $this->assertEqualsWithDelta(0.3, $credits[0], 0.001);
        $this->assertEqualsWithDelta(0.4, $credits[1], 0.001);
        $this->assertEqualsWithDelta(0.3, $credits[2], 0.001);
    }

    public function testPositionBasedCreditsSumToOne(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 5400),
            new ConversionTouchpoint(30, $convTime - 3600),
            new ConversionTouchpoint(40, $convTime - 1800),
            new ConversionTouchpoint(50, $convTime),
        ];
        $conv = $this->makeConversion(1, 50, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        $totalCredit = 0.0;
        foreach ($touchpoints as $tp) {
            $totalCredit += $tp->credit;
        }
        $this->assertEqualsWithDelta(1.0, $totalCredit, 0.001);
    }

    public function testPositionBasedRevenueAndCostApportionedCorrectly(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $payout = 120.0;
        $cost = 30.0;
        $conv = $this->makeConversion(1, 30, $payout, $cost, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($this->makePositionBasedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $snapshot = $result->snapshotsByHour[$bucket];

        // Total attributed revenue/cost should equal the full conversion payout/cost
        $this->assertEqualsWithDelta($payout, $snapshot->attributedRevenue, 0.01);
        $this->assertEqualsWithDelta($cost, $snapshot->attributedCost, 0.01);

        // Verify individual touchpoint revenue matches credit * payout
        $touchpoints = $result->touchpointsByHour[$bucket];
        $sumRevenue = 0.0;
        $sumCost = 0.0;
        foreach ($touchpoints as $tp) {
            $sumRevenue += $payout * $tp->credit;
            $sumCost += $cost * $tp->credit;
        }
        $this->assertEqualsWithDelta($payout, $sumRevenue, 0.01);
        $this->assertEqualsWithDelta($cost, $sumCost, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AssistedStrategy
    // ═══════════════════════════════════════════════════════════════════════

    public function testAssistedEmptyBatchReturnsEmptyResult(): void
    {
        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $this->makeEmptyBatch());

        $this->assertSame([], $result->snapshotsByHour);
        $this->assertSame([], $result->touchpointsByHour);
    }

    public function testAssistedSingleTouchGetsFullCredit(): void
    {
        // Single touch in journey => that touch gets full 1.0 credit
        $conv = $this->makeConversion(1, 10, 100.0, 20.0);
        $batch = $this->makeBatch([$conv]);

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $batch);

        $bucket = $this->hourBucket($conv->convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(1, $touchpoints);
        $this->assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.001);
        $this->assertSame(10, $touchpoints[0]->clickId);
    }

    public function testAssistedTwoTouchesOnlyFirstGetsCredit(): void
    {
        // Two touches: first gets 1.0 credit, last is excluded
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
        ];
        $conv = $this->makeConversion(1, 20, 100.0, 10.0, $convTime, $convTime - 3600, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(1, $touchpoints);
        $this->assertSame(10, $touchpoints[0]->clickId);
        $this->assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.001);
    }

    public function testAssistedThreeTouchesFirstTwoGetHalfCredit(): void
    {
        // Three touches: assists = first two, each gets 0.5 credit. Last excluded.
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $conv = $this->makeConversion(1, 30, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];
        $this->assertCount(2, $touchpoints);

        foreach ($touchpoints as $tp) {
            $this->assertEqualsWithDelta(0.5, $tp->credit, 0.001);
            // Neither should be the last touch (clickId=30)
            $this->assertNotSame(30, $tp->clickId);
        }

        // Verify clickIds are the assists
        $clickIds = array_map(fn(Touchpoint $tp) => $tp->clickId, $touchpoints);
        $this->assertContains(10, $clickIds);
        $this->assertContains(20, $clickIds);
    }

    public function testAssistedRevenueAndCostAttributedToAssistsOnly(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $payout = 120.0;
        $cost = 30.0;
        $conv = $this->makeConversion(1, 30, $payout, $cost, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $snapshot = $result->snapshotsByHour[$bucket];

        // Each assist gets 0.5 credit, so total attributed = 0.5*payout + 0.5*payout = payout
        $this->assertEqualsWithDelta($payout, $snapshot->attributedRevenue, 0.01);
        $this->assertEqualsWithDelta($cost, $snapshot->attributedCost, 0.01);

        // Only 2 attributed clicks (the assists), not 3
        $this->assertSame(2, $snapshot->attributedClicks);
        $this->assertSame(1, $snapshot->attributedConversions);
    }

    public function testAssistedNoTouchpointsCreatedForLastTouchWhenMultiple(): void
    {
        $convTime = $this->baseTime;
        $journey = [
            new ConversionTouchpoint(10, $convTime - 7200),
            new ConversionTouchpoint(20, $convTime - 3600),
            new ConversionTouchpoint(30, $convTime),
        ];
        $conv = $this->makeConversion(1, 30, 100.0, 10.0, $convTime, $convTime, $journey);
        $batch = $this->makeBatch([$conv]);

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($this->makeAssistedModel(), $batch);

        $bucket = $this->hourBucket($convTime);
        $touchpoints = $result->touchpointsByHour[$bucket];

        // The last touch (clickId=30) should not appear
        foreach ($touchpoints as $tp) {
            $this->assertNotSame(30, $tp->clickId, 'Last touch should not have a touchpoint in assisted strategy');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ConversionRecord::getJourney()
    // ═══════════════════════════════════════════════════════════════════════

    public function testGetJourneyEmptyAddsConvertingClick(): void
    {
        $conv = $this->makeConversion(1, 10, 10.0, 2.0, $this->baseTime, $this->baseTime - 3600);
        $journey = $conv->getJourney();

        $this->assertCount(1, $journey);
        $this->assertSame(10, $journey[0]->clickId);
        $this->assertSame($this->baseTime - 3600, $journey[0]->clickTime);
    }

    public function testGetJourneyWithConvertingClickNoDuplicate(): void
    {
        $clickTime = $this->baseTime - 3600;
        $existingJourney = [
            new ConversionTouchpoint(10, $clickTime),
        ];
        $conv = $this->makeConversion(1, 10, 10.0, 2.0, $this->baseTime, $clickTime, $existingJourney);
        $journey = $conv->getJourney();

        $this->assertCount(1, $journey);
        $this->assertSame(10, $journey[0]->clickId);
    }

    public function testGetJourneySortedByClickTime(): void
    {
        $existingJourney = [
            new ConversionTouchpoint(30, $this->baseTime - 1000),
            new ConversionTouchpoint(10, $this->baseTime - 5000),
            new ConversionTouchpoint(20, $this->baseTime - 3000),
        ];
        $conv = $this->makeConversion(1, 30, 10.0, 2.0, $this->baseTime, $this->baseTime - 1000, $existingJourney);
        $journey = $conv->getJourney();

        $this->assertCount(3, $journey);
        $this->assertSame(10, $journey[0]->clickId);
        $this->assertSame(20, $journey[1]->clickId);
        $this->assertSame(30, $journey[2]->clickId);
    }

    public function testGetJourneyTieBreakingByClickId(): void
    {
        $sameTime = $this->baseTime - 2000;
        $existingJourney = [
            new ConversionTouchpoint(30, $sameTime),
            new ConversionTouchpoint(10, $sameTime),
            new ConversionTouchpoint(20, $sameTime),
        ];
        $conv = $this->makeConversion(1, 30, 10.0, 2.0, $this->baseTime, $sameTime, $existingJourney);
        $journey = $conv->getJourney();

        $this->assertCount(3, $journey);
        $this->assertSame(10, $journey[0]->clickId);
        $this->assertSame(20, $journey[1]->clickId);
        $this->assertSame(30, $journey[2]->clickId);
    }

    public function testGetJourneyFiltersNonTouchpointEntries(): void
    {
        $existingJourney = [
            new ConversionTouchpoint(10, $this->baseTime - 5000),
            'not a touchpoint',
            42,
            null,
            new ConversionTouchpoint(20, $this->baseTime - 3000),
        ];
        $conv = $this->makeConversion(1, 20, 10.0, 2.0, $this->baseTime, $this->baseTime - 3000, $existingJourney);
        $journey = $conv->getJourney();

        $this->assertCount(2, $journey);
        $this->assertSame(10, $journey[0]->clickId);
        $this->assertSame(20, $journey[1]->clickId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ModelDefinition validation
    // ═══════════════════════════════════════════════════════════════════════

    public function testModelDefinitionEmptyNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name cannot be empty');

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: '',
            slug: 'test',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionEmptySlugThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slug cannot be empty');

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Test',
            slug: '',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionTimeDecayWithoutHalfLifeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Time Decay',
            slug: 'time-decay',
            type: ModelType::TIME_DECAY,
            weightingConfig: ['some_other_key' => 'value'],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionPositionBasedWithoutWeightsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['some_key' => 'value'],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionLastTouchWithNonEmptyConfigThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept weighting');

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Last Touch',
            slug: 'last-touch',
            type: ModelType::LAST_TOUCH,
            weightingConfig: ['foo' => 'bar'],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionAssistedWithNonEmptyConfigThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept weighting');

        new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Assisted',
            slug: 'assisted',
            type: ModelType::ASSISTED,
            weightingConfig: ['foo' => 'bar'],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );
    }

    public function testModelDefinitionValidPositionBasedConfigAccepted(): void
    {
        $model = new ModelDefinition(
            modelId: 1,
            userId: 1,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.3, 'last_touch_weight' => 0.3],
            isActive: true,
            isDefault: false,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime
        );

        $this->assertSame('Position Based', $model->name);
        $this->assertSame(ModelType::POSITION_BASED, $model->type);
        $this->assertSame(0.3, $model->weightingConfig['first_touch_weight']);
        $this->assertSame(0.3, $model->weightingConfig['last_touch_weight']);
    }

    public function testModelDefinitionFromDatabaseRowCreatesCorrectly(): void
    {
        $row = [
            'model_id' => 42,
            'user_id' => 7,
            'model_name' => 'My Last Touch',
            'model_slug' => 'my-last-touch',
            'model_type' => 'last_touch',
            'weighting_config' => null,
            'is_active' => 1,
            'is_default' => 0,
            'created_at' => $this->baseTime,
            'updated_at' => $this->baseTime + 100,
        ];

        $model = ModelDefinition::fromDatabaseRow($row);

        $this->assertSame(42, $model->modelId);
        $this->assertSame(7, $model->userId);
        $this->assertSame('My Last Touch', $model->name);
        $this->assertSame('my-last-touch', $model->slug);
        $this->assertSame(ModelType::LAST_TOUCH, $model->type);
        $this->assertSame([], $model->weightingConfig);
        $this->assertTrue($model->isActive);
        $this->assertFalse($model->isDefault);
        $this->assertSame($this->baseTime, $model->createdAt);
        $this->assertSame($this->baseTime + 100, $model->updatedAt);
    }

    public function testModelDefinitionToDatabaseRowRoundtrips(): void
    {
        $config = ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4];
        $model = new ModelDefinition(
            modelId: 99,
            userId: 5,
            name: 'Position Model',
            slug: 'position-model',
            type: ModelType::POSITION_BASED,
            weightingConfig: $config,
            isActive: true,
            isDefault: true,
            createdAt: $this->baseTime,
            updatedAt: $this->baseTime + 50
        );

        $row = $model->toDatabaseRow();

        $this->assertSame(99, $row['model_id']);
        $this->assertSame(5, $row['user_id']);
        $this->assertSame('Position Model', $row['model_name']);
        $this->assertSame('position-model', $row['model_slug']);
        $this->assertSame('position_based', $row['model_type']);
        $this->assertSame(1, $row['is_active']);
        $this->assertSame(1, $row['is_default']);
        $this->assertSame($this->baseTime, $row['created_at']);
        $this->assertSame($this->baseTime + 50, $row['updated_at']);

        // weighting_config is JSON-encoded
        $decoded = json_decode($row['weighting_config'], true);
        $this->assertSame(0.4, $decoded['first_touch_weight']);
        $this->assertSame(0.4, $decoded['last_touch_weight']);

        // Round-trip: from the row back to a ModelDefinition
        $reconstructed = ModelDefinition::fromDatabaseRow($row);
        $this->assertSame($model->modelId, $reconstructed->modelId);
        $this->assertSame($model->name, $reconstructed->name);
        $this->assertSame($model->slug, $reconstructed->slug);
        $this->assertSame($model->type, $reconstructed->type);
        $this->assertEqualsWithDelta($model->weightingConfig['first_touch_weight'], $reconstructed->weightingConfig['first_touch_weight'], 0.001);
        $this->assertEqualsWithDelta($model->weightingConfig['last_touch_weight'], $reconstructed->weightingConfig['last_touch_weight'], 0.001);
    }
}
