<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\LastTouchStrategy;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

final class LastTouchStrategyTest extends TestCase
{
    public function testAggregatesConversionsPerHour(): void
    {
        $timestamp = strtotime('2024-01-01 12:15:00');
        $model = new ModelDefinition(
            modelId: 10,
            userId: 1,
            name: 'Last Touch',
            slug: 'last-touch',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: true,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $conversions = [
            new ConversionRecord(
                conversionId: 101,
                clickId: 1001,
                userId: 1,
                campaignId: 33,
                ppcAccountId: 20,
                convTime: $timestamp,
                clickTime: $timestamp - 120,
                clickPayout: 12.50,
                clickCost: 4.10
            ),
            new ConversionRecord(
                conversionId: 102,
                clickId: 1002,
                userId: 1,
                campaignId: 33,
                ppcAccountId: 20,
                convTime: $timestamp + 120,
                clickTime: $timestamp - 60,
                clickPayout: 7.25,
                clickCost: 2.50
            ),
            new ConversionRecord(
                conversionId: 103,
                clickId: 1003,
                userId: 1,
                campaignId: 33,
                ppcAccountId: 20,
                convTime: $timestamp + 3600,
                clickTime: $timestamp + 3500,
                clickPayout: 9.00,
                clickCost: 3.00
            ),
        ];

        $batch = new ConversionBatch(
            userId: 1,
            startTime: $timestamp - 3600,
            endTime: $timestamp + 7200,
            conversions: $conversions
        );

        $strategy = new LastTouchStrategy();
        $result = $strategy->calculate($model, $batch);

        $firstBucket = (int) ($timestamp - ($timestamp % 3600));
        $secondBucket = $firstBucket + 3600;

        self::assertCount(2, $result->snapshotsByHour);
        self::assertArrayHasKey($firstBucket, $result->snapshotsByHour);
        self::assertArrayHasKey($secondBucket, $result->snapshotsByHour);

        $firstSnapshot = $result->snapshotsByHour[$firstBucket];
        self::assertSame(2, $firstSnapshot->attributedConversions);
        self::assertSame(2, $firstSnapshot->attributedClicks);
        self::assertEquals(19.75, $firstSnapshot->attributedRevenue);
        self::assertEquals(6.60, $firstSnapshot->attributedCost);

        $secondSnapshot = $result->snapshotsByHour[$secondBucket];
        self::assertSame(1, $secondSnapshot->attributedConversions);
        self::assertSame(1, $secondSnapshot->attributedClicks);
        self::assertEquals(9.00, $secondSnapshot->attributedRevenue);
        self::assertEquals(3.00, $secondSnapshot->attributedCost);

        self::assertCount(2, $result->touchpointsByHour[$firstBucket]);
        self::assertCount(1, $result->touchpointsByHour[$secondBucket]);
        self::assertSame(1.0, $result->touchpointsByHour[$firstBucket][0]->credit);
    }
}
