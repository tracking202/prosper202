<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Repository\Mysql\ConversionHydrator;

final class ConversionHydratorTest extends TestCase
{
    public function testHydratesConversionWithOrderedJourney(): void
    {
        $hydrator = new ConversionHydrator();
        $rows = [[
            'conv_id' => 42,
            'click_id' => 2002,
            'user_id' => 7,
            'campaign_id' => 11,
            'ppc_account_id' => 3,
            'conv_time' => 1700000500,
            'click_time' => 1700000400,
            'click_payout' => 9.5,
            'click_cpc' => 1.25,
        ]];

        $journeys = [
            42 => [
                ['click_id' => 2000, 'click_time' => 1699999900],
                ['click_id' => 2002, 'click_time' => 1700000400],
            ],
        ];

        $records = $hydrator->hydrate($rows, $journeys);
        $this->assertCount(1, $records);

        $journey = $records[0]->getJourney();
        $this->assertCount(2, $journey);
        $this->assertSame(2000, $journey[0]->clickId);
        $this->assertSame(2002, $journey[1]->clickId);
    }

    public function testHydratesWithMissingJourneyFallsBackToConversionClick(): void
    {
        $hydrator = new ConversionHydrator();
        $rows = [[
            'conv_id' => 77,
            'click_id' => 3100,
            'user_id' => 9,
            'campaign_id' => 14,
            'ppc_account_id' => 5,
            'conv_time' => 1701000000,
            'click_time' => 1700999900,
            'click_payout' => 12.75,
            'click_cpc' => 2.15,
        ]];

        $records = $hydrator->hydrate($rows, []);
        $this->assertCount(1, $records);

        $journey = $records[0]->getJourney();
        $this->assertCount(1, $journey);
        $this->assertSame(3100, $journey[0]->clickId);
        $this->assertSame(1700999900, $journey[0]->clickTime);
    }
}
