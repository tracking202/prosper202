<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * ReportsController against a mocked mysqli. These tests lived in
 * ControllerTest.php, where PHPUnit runs only the class named after the file,
 * so they never ran.
 */
final class ReportsControllerTest extends TestCase
{
    public function testDaypartReturnsTwentyFourRowsWithMetricsAndTimezone(): void
    {
        $db = $this->createMysqliMock([
            'SELECT user_timezone FROM 202_users' => ['user_timezone' => 'America/New_York'],
            'GROUP BY hour_of_day' => [
                [
                    'hour_of_day' => 3,
                    'total_clicks' => 10,
                    'total_click_throughs' => 8,
                    'total_leads' => 2,
                    'total_income' => 20.5,
                    'total_cost' => 7.5,
                    'total_net' => 13.0,
                    'epc' => 2.05,
                    'avg_cpc' => 0.75,
                    'conv_rate' => 25,
                    'roi' => 173.33,
                    'cpa' => 3.75,
                ],
            ],
        ]);

        $controller = new ReportsController($db, 1);
        $result = $controller->daypart([]);

        $this->assertSame('America/New_York', $result['timezone']);
        $this->assertCount(24, $result['data']);
        $this->assertSame(0, $result['data'][0]['hour_of_day']);
        $this->assertSame(23, $result['data'][23]['hour_of_day']);

        $row = $result['data'][3];
        foreach (['total_clicks', 'total_click_throughs', 'total_leads', 'total_income', 'total_cost', 'total_net', 'epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa'] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
    }

    public function testDaypartZeroFillsMissingHours(): void
    {
        $db = $this->createMysqliMock([
            'SELECT user_timezone FROM 202_users' => ['user_timezone' => 'UTC'],
            'GROUP BY hour_of_day' => [
                [
                    'hour_of_day' => 10,
                    'total_clicks' => 5,
                    'total_click_throughs' => 4,
                    'total_leads' => 1,
                    'total_income' => 8,
                    'total_cost' => 3,
                    'total_net' => 5,
                    'epc' => 1.6,
                    'avg_cpc' => 0.6,
                    'conv_rate' => 25,
                    'roi' => 166.67,
                    'cpa' => 3,
                ],
            ],
        ]);

        $controller = new ReportsController($db, 1);
        $result = $controller->daypart([]);

        $this->assertCount(24, $result['data']);
        $this->assertSame(0, $result['data'][9]['total_clicks']);
        $this->assertSame(5, $result['data'][10]['total_clicks']);
        $this->assertSame(0, $result['data'][11]['total_clicks']);
    }

    public function testDaypartSortsByMetricDescendingWithHourTieBreaker(): void
    {
        $db = $this->createMysqliMock([
            'SELECT user_timezone FROM 202_users' => ['user_timezone' => 'UTC'],
            'GROUP BY hour_of_day' => [
                ['hour_of_day' => 2, 'total_clicks' => 1, 'total_click_throughs' => 1, 'total_leads' => 1, 'total_income' => 4, 'total_cost' => 2, 'total_net' => 2, 'epc' => 4, 'avg_cpc' => 2, 'conv_rate' => 100, 'roi' => 100, 'cpa' => 2],
                ['hour_of_day' => 1, 'total_clicks' => 1, 'total_click_throughs' => 1, 'total_leads' => 1, 'total_income' => 4, 'total_cost' => 2, 'total_net' => 2, 'epc' => 4, 'avg_cpc' => 2, 'conv_rate' => 100, 'roi' => 100, 'cpa' => 2],
                ['hour_of_day' => 3, 'total_clicks' => 1, 'total_click_throughs' => 1, 'total_leads' => 1, 'total_income' => 3, 'total_cost' => 2, 'total_net' => 1, 'epc' => 3, 'avg_cpc' => 2, 'conv_rate' => 100, 'roi' => 50, 'cpa' => 2],
            ],
        ]);

        $controller = new ReportsController($db, 1);
        $result = $controller->daypart(['sort' => 'roi', 'sort_dir' => 'DESC']);

        $this->assertSame(1, $result['data'][0]['hour_of_day']);
        $this->assertSame(2, $result['data'][1]['hour_of_day']);
        $this->assertSame(3, $result['data'][2]['hour_of_day']);
    }

    public function testDaypartInvalidSortThrowsValidationException(): void
    {
        $db = $this->createMysqliMock();
        $controller = new ReportsController($db, 1);

        $this->expectException(ValidationException::class);
        $controller->daypart(['sort' => 'bad_field']);
    }

    public function testDaypartInvalidTimezoneFallsBackToUtc(): void
    {
        $db = $this->createMysqliMock([
            'SELECT user_timezone FROM 202_users' => ['user_timezone' => 'Invalid/Timezone'],
            'GROUP BY hour_of_day' => [],
        ]);

        $controller = new ReportsController($db, 1);
        $result = $controller->daypart([]);

        $this->assertSame('UTC', $result['timezone']);
    }

    public function testTimeseriesInvalidIntervalThrowsValidationException(): void
    {
        $db = $this->createMysqliMock();
        $controller = new ReportsController($db, 1);

        $this->expectException(ValidationException::class);
        $controller->timeseries(['interval' => 'bad']);
    }

    public function testTimeseriesIncludesComputedMetrics(): void
    {
        $db = $this->createMysqliMock([
            'GROUP BY period' => [
                [
                    'period' => '2026-02-15',
                    'total_clicks' => 100,
                    'total_click_throughs' => 80,
                    'total_leads' => 10,
                    'total_income' => 250,
                    'total_cost' => 100,
                    'total_net' => 150,
                    'epc' => 2.5,
                    'avg_cpc' => 1.0,
                    'conv_rate' => 12.5,
                    'roi' => 150,
                    'cpa' => 10,
                ],
            ],
        ]);

        $controller = new ReportsController($db, 1);
        $result = $controller->timeseries(['interval' => 'day']);

        $this->assertCount(1, $result['data']);
        foreach (['total_click_throughs', 'epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa'] as $field) {
            $this->assertArrayHasKey($field, $result['data'][0]);
        }
    }
}
