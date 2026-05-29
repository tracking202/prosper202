<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\ConversionTouchpoint;
use Prosper202\Attribution\Calculation\PositionBasedStrategy;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

final class PositionBasedStrategyTest extends TestCase
{
    public function testSingleTouchJourneyDefaultsToFullCredit(): void
    {
        $now = time();
        $model = new ModelDefinition(
            modelId: 42,
            userId: 7,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4],
            isActive: true,
            isDefault: false,
            createdAt: $now,
            updatedAt: $now
        );

        $conversions = [
            new ConversionRecord(
                conversionId: 501,
                clickId: 9001,
                userId: 7,
                campaignId: 77,
                ppcAccountId: 12,
                convTime: $now,
                clickTime: $now - 60,
                clickPayout: 15.00,
                clickCost: 3.00
            ),
        ];

        $batch = new ConversionBatch(
            userId: 7,
            startTime: $now - 3600,
            endTime: $now + 3600,
            conversions: $conversions
        );

        $strategy = new PositionBasedStrategy();
        $result = $strategy->calculate($model, $batch);

        $bucket = (int) ($now - ($now % 3600));
        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertEqualsWithDelta(15.0, $snapshot->attributedRevenue, 0.001);
        self::assertSame(1, $snapshot->attributedClicks);

        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(1, $touchpoints);
        self::assertEquals(1.0, $touchpoints[0]->credit);
    }

    public function testDistributesCreditAcrossJourney(): void
    {
        $now = time();
        $model = new ModelDefinition(
            modelId: 99,
            userId: 7,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4],
            isActive: true,
            isDefault: false,
            createdAt: $now,
            updatedAt: $now
        );

        $conversion = new ConversionRecord(
            conversionId: 601,
            clickId: 9103,
            userId: 7,
            campaignId: 77,
            ppcAccountId: 12,
            convTime: $now,
            clickTime: $now - 60,
            clickPayout: 30.00,
            clickCost: 6.00,
            journey: [
                new ConversionTouchpoint(9101, $now - 600),
                new ConversionTouchpoint(9102, $now - 300),
                new ConversionTouchpoint(9103, $now - 60),
            ]
        );

        $batch = new ConversionBatch(
            userId: 7,
            startTime: $now - 3600,
            endTime: $now + 3600,
            conversions: [$conversion]
        );

        $result = (new PositionBasedStrategy())->calculate($model, $batch);

        $bucket = (int) ($now - ($now % 3600));
        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertSame(3, $snapshot->attributedClicks);
        self::assertEqualsWithDelta(30.00, $snapshot->attributedRevenue, 0.001);
        self::assertEqualsWithDelta(6.00, $snapshot->attributedCost, 0.001);

        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(3, $touchpoints);
        self::assertEqualsWithDelta(0.4, $touchpoints[0]->credit, 0.0001);
        self::assertEqualsWithDelta(0.2, $touchpoints[1]->credit, 0.0001);
        self::assertEqualsWithDelta(0.4, $touchpoints[2]->credit, 0.0001);
    }

    public function testTwoTouchJourneyNormalisesWeights(): void
    {
        $now = time();
        $model = new ModelDefinition(
            modelId: 101,
            userId: 8,
            name: 'Position Based',
            slug: 'position-based',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4],
            isActive: true,
            isDefault: false,
            createdAt: $now,
            updatedAt: $now
        );

        $conversion = new ConversionRecord(
            conversionId: 701,
            clickId: 9202,
            userId: 8,
            campaignId: 88,
            ppcAccountId: 51,
            convTime: $now,
            clickTime: $now - 30,
            clickPayout: 20.00,
            clickCost: 4.00,
            journey: [
                new ConversionTouchpoint(9201, $now - 200),
                new ConversionTouchpoint(9202, $now - 30),
            ]
        );

        $batch = new ConversionBatch(
            userId: 8,
            startTime: $now - 3600,
            endTime: $now + 3600,
            conversions: [$conversion]
        );

        $result = (new PositionBasedStrategy())->calculate($model, $batch);

        $bucket = (int) ($now - ($now % 3600));
        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(2, $touchpoints);
        self::assertEqualsWithDelta(0.5, $touchpoints[0]->credit, 0.0001);
        self::assertEqualsWithDelta(0.5, $touchpoints[1]->credit, 0.0001);
    }
}
