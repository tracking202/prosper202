<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Calculation\AssistedStrategy;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\ConversionTouchpoint;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

final class AssistedStrategyTest extends TestCase
{
    public function testSingleTouchStillCreditsConversion(): void
    {
        $now = time();
        $model = new ModelDefinition(
            modelId: 33,
            userId: 4,
            name: 'Assisted',
            slug: 'assisted',
            type: ModelType::ASSISTED,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $now,
            updatedAt: $now
        );

        $conversions = [
            new ConversionRecord(
                conversionId: 601,
                clickId: 9101,
                userId: 4,
                campaignId: 22,
                ppcAccountId: 10,
                convTime: $now,
                clickTime: $now - 100,
                clickPayout: 0.00,
                clickCost: 0.50
            ),
        ];

        $batch = new ConversionBatch(
            userId: 4,
            startTime: $now - 3600,
            endTime: $now + 3600,
            conversions: $conversions
        );

        $strategy = new AssistedStrategy();
        $result = $strategy->calculate($model, $batch);

        $bucket = (int) ($now - ($now % 3600));
        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertSame(1, $snapshot->attributedClicks);
        self::assertEquals(0.0, $snapshot->attributedRevenue);

        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(1, $touchpoints);
        self::assertSame(1.0, $touchpoints[0]->credit);
        self::assertSame(1.0, $touchpoints[0]->weight);
    }

    public function testMultiTouchCreditsAssists(): void
    {
        $now = time();
        $model = new ModelDefinition(
            modelId: 34,
            userId: 4,
            name: 'Assisted',
            slug: 'assisted',
            type: ModelType::ASSISTED,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $now,
            updatedAt: $now
        );

        $conversion = new ConversionRecord(
            conversionId: 701,
            clickId: 9303,
            userId: 4,
            campaignId: 22,
            ppcAccountId: 10,
            convTime: $now,
            clickTime: $now - 20,
            clickPayout: 12.00,
            clickCost: 2.40,
            journey: [
                new ConversionTouchpoint(9301, $now - 400),
                new ConversionTouchpoint(9302, $now - 200),
                new ConversionTouchpoint(9303, $now - 20),
            ]
        );

        $batch = new ConversionBatch(
            userId: 4,
            startTime: $now - 3600,
            endTime: $now + 3600,
            conversions: [$conversion]
        );

        $result = (new AssistedStrategy())->calculate($model, $batch);

        $bucket = (int) ($now - ($now % 3600));
        $snapshot = $result->snapshotsByHour[$bucket];
        self::assertSame(1, $snapshot->attributedConversions);
        self::assertSame(2, $snapshot->attributedClicks);
        self::assertEqualsWithDelta(12.00, $snapshot->attributedRevenue, 0.0001);
        self::assertEqualsWithDelta(2.40, $snapshot->attributedCost, 0.0001);

        $touchpoints = $result->touchpointsByHour[$bucket];
        self::assertCount(2, $touchpoints);
        self::assertSame(9301, $touchpoints[0]->clickId);
        self::assertSame(9302, $touchpoints[1]->clickId);
        self::assertEqualsWithDelta(0.5, $touchpoints[0]->credit, 0.0001);
        self::assertEqualsWithDelta(0.5, $touchpoints[1]->credit, 0.0001);
    }
}
