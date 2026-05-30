<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\ConversionTouchpoint;
use Prosper202\Attribution\Calculation\TimeDecayStrategy;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

final class TimeDecayStrategyTest extends TestCase
{
    /**
     * Verifies that a multi-touch journey has credit distributed by the
     * half-life decay curve and that the weights are normalised to sum to 1.
     *
     * With half_life_hours = 1 the decay constant is ln(2), so the raw weight
     * for a touch is 0.5 ** (age_in_hours / half_life_hours). For touches aged
     * 2h, 1h and 0h before the conversion the raw weights are:
     *   0.5 ** 2 = 0.25, 0.5 ** 1 = 0.50, 0.5 ** 0 = 1.00  (sum = 1.75)
     * After normalisation (weight / sum) the credits become exactly:
     *   0.25 / 1.75 = 1/7, 0.50 / 1.75 = 2/7, 1.00 / 1.75 = 4/7
     * which sum to 1.0, so the full conversion value is conserved.
     */
    public function testDistributesCreditByHalfLifeCurve(): void
    {
        // Fixed, hour-aligned timestamp keeps every touch in the same hour
        // bucket regardless of when the suite runs (avoids wall-clock flakiness).
        $convTime = strtotime('2024-06-01 09:00:00');
        $model = new ModelDefinition(
            modelId: 12,
            userId: 5,
            name: 'Time Decay',
            slug: 'time-decay',
            type: ModelType::TIME_DECAY,
            weightingConfig: ['half_life_hours' => 1],
            isActive: true,
            isDefault: false,
            createdAt: $convTime,
            updatedAt: $convTime
        );

        $conversion = new ConversionRecord(
            conversionId: 201,
            clickId: 2003,
            userId: 5,
            campaignId: 77,
            ppcAccountId: 44,
            convTime: $convTime,
            clickTime: $convTime,
            clickPayout: 35.00,
            clickCost: 7.00,
            journey: [
                new ConversionTouchpoint(2001, $convTime - 7200), // 2h before -> weight 0.25
                new ConversionTouchpoint(2002, $convTime - 3600), // 1h before -> weight 0.50
                new ConversionTouchpoint(2003, $convTime),        // converting click -> weight 1.00
            ]
        );

        $batch = new ConversionBatch(
            userId: 5,
            startTime: $convTime - 3600,
            endTime: $convTime + 3600,
            conversions: [$conversion]
        );

        $strategy = new TimeDecayStrategy();
        $result = $strategy->calculate($model, $batch);

        $bucket = (int) ($convTime - ($convTime % 3600));
        self::assertArrayHasKey($bucket, $result->snapshotsByHour);

        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertSame(3, $snapshot->attributedClicks);

        // Normalised credits conserve the full conversion value.
        self::assertEqualsWithDelta(35.00, $snapshot->attributedRevenue, 0.0001);
        self::assertEqualsWithDelta(7.00, $snapshot->attributedCost, 0.0001);

        // Journey is ordered oldest -> newest, so credit must rise toward the
        // most recent touch, matching the half-life weighting exactly.
        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(3, $touchpoints);
        self::assertEqualsWithDelta(1 / 7, $touchpoints[0]->credit, 0.0000001);
        self::assertEqualsWithDelta(2 / 7, $touchpoints[1]->credit, 0.0000001);
        self::assertEqualsWithDelta(4 / 7, $touchpoints[2]->credit, 0.0000001);

        // The decay curve is the source of the ordering: each step closer to the
        // conversion doubles the raw weight (1h half-life), so the most recent
        // touch must out-credit the oldest, and all credits must sum to 1.
        self::assertGreaterThan($touchpoints[0]->credit, $touchpoints[1]->credit);
        self::assertGreaterThan($touchpoints[1]->credit, $touchpoints[2]->credit);
        self::assertEqualsWithDelta(
            1.0,
            $touchpoints[0]->credit + $touchpoints[1]->credit + $touchpoints[2]->credit,
            0.0000001
        );
    }

    /**
     * A single-touch journey cannot be redistributed, so normalisation must
     * award full credit (1.0) to the lone touch regardless of its age/decay.
     */
    public function testSingleTouchJourneyReceivesFullCredit(): void
    {
        $convTime = strtotime('2024-06-01 09:00:00');
        $model = new ModelDefinition(
            modelId: 13,
            userId: 5,
            name: 'Time Decay',
            slug: 'time-decay',
            type: ModelType::TIME_DECAY,
            weightingConfig: ['half_life_hours' => 1],
            isActive: true,
            isDefault: false,
            createdAt: $convTime,
            updatedAt: $convTime
        );

        // No explicit journey: getJourney() falls back to the converting click
        // alone. Even though the click is 10 minutes old, a single-touch journey
        // normalises to credit 1.0 (decay only redistributes between touches).
        $conversion = new ConversionRecord(
            conversionId: 202,
            clickId: 2002,
            userId: 5,
            campaignId: 77,
            ppcAccountId: 44,
            convTime: $convTime,
            clickTime: $convTime - 600,
            clickPayout: 10.00,
            clickCost: 2.50
        );

        $batch = new ConversionBatch(
            userId: 5,
            startTime: $convTime - 3600,
            endTime: $convTime + 3600,
            conversions: [$conversion]
        );

        $result = (new TimeDecayStrategy())->calculate($model, $batch);

        $bucket = (int) ($convTime - ($convTime % 3600));
        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertSame(1, $snapshot->attributedClicks);
        self::assertEqualsWithDelta(10.00, $snapshot->attributedRevenue, 0.0001);
        self::assertEqualsWithDelta(2.50, $snapshot->attributedCost, 0.0001);

        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(1, $touchpoints);
        self::assertEqualsWithDelta(1.0, $touchpoints[0]->credit, 0.0000001);
    }
}
