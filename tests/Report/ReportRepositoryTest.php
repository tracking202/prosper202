<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\InMemoryReportRepository;
use Prosper202\Report\ReportQuery;

final class ReportRepositoryTest extends TestCase
{
    private function makeRepo(): InMemoryReportRepository
    {
        $repo = new InMemoryReportRepository();
        $repo->rows = [
            ['user_id' => 1, 'click_time' => 1709280000, 'clicks' => 100, 'click_out' => 80, 'leads' => 10, 'income' => 50.0, 'cost' => 20.0, 'aff_campaign_id' => 1, 'country_id' => 1],
            ['user_id' => 1, 'click_time' => 1709290000, 'clicks' => 200, 'click_out' => 150, 'leads' => 20, 'income' => 100.0, 'cost' => 40.0, 'aff_campaign_id' => 1, 'country_id' => 2],
            ['user_id' => 1, 'click_time' => 1709366400, 'clicks' => 50, 'click_out' => 30, 'leads' => 5, 'income' => 25.0, 'cost' => 10.0, 'aff_campaign_id' => 2, 'country_id' => 1],
            ['user_id' => 2, 'click_time' => 1709280000, 'clicks' => 500, 'click_out' => 400, 'leads' => 50, 'income' => 250.0, 'cost' => 100.0, 'aff_campaign_id' => 3, 'country_id' => 1],
        ];
        $repo->dimensions = [
            'campaign' => [1 => 'Campaign A', 2 => 'Campaign B', 3 => 'Campaign C'],
            'country' => [1 => 'United States', 2 => 'Canada'],
        ];

        return $repo;
    }

    // --- ReportQuery ---

    public function testReportQueryFiltersOnlyAllowedEntityFields(): void
    {
        $query = new ReportQuery(1, null, null, [
            'aff_campaign_id' => 5,
            'country_id' => 10,
            'evil_field' => 999,
        ]);

        self::assertSame(['aff_campaign_id' => 5, 'country_id' => 10], $query->entityFilters);
        self::assertArrayNotHasKey('evil_field', $query->entityFilters);
    }

    // --- Summary ---

    public function testSummaryAggregatesAllUserRows(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->summary($query);

        self::assertSame(350, $result['total_clicks']);
        self::assertSame(260, $result['total_click_throughs']);
        self::assertSame(35, $result['total_leads']);
        self::assertEqualsWithDelta(175.0, $result['total_income'], 0.01);
        self::assertEqualsWithDelta(70.0, $result['total_cost'], 0.01);
        self::assertEqualsWithDelta(105.0, $result['total_net'], 0.01);
    }

    public function testSummaryFiltersOtherUsers(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(2);

        $result = $repo->summary($query);

        self::assertSame(500, $result['total_clicks']);
        self::assertEqualsWithDelta(250.0, $result['total_income'], 0.01);
    }

    public function testSummaryWithTimeFilter(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1, 1709280000, 1709290000);

        $result = $repo->summary($query);

        self::assertSame(300, $result['total_clicks']); // first two rows
    }

    public function testSummaryWithEntityFilter(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1, null, null, ['aff_campaign_id' => 2]);

        $result = $repo->summary($query);

        self::assertSame(50, $result['total_clicks']);
    }

    public function testSummaryCalculatedMetrics(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->summary($query);

        // EPC = income / clicks = 175 / 350 = 0.5
        self::assertEqualsWithDelta(0.5, $result['epc'], 0.01);
        // avg_cpc = cost / clicks = 70 / 350 = 0.2
        self::assertEqualsWithDelta(0.2, $result['avg_cpc'], 0.01);
        // conv_rate = (leads / click_out) * 100 = (35 / 260) * 100 ≈ 13.46
        self::assertEqualsWithDelta(13.46, $result['conv_rate'], 0.01);
        // ROI = (net / cost) * 100 = (105 / 70) * 100 = 150
        self::assertEqualsWithDelta(150.0, $result['roi'], 0.01);
        // CPA = cost / leads = 70 / 35 = 2.0
        self::assertEqualsWithDelta(2.0, $result['cpa'], 0.01);
    }

    public function testSummaryZeroMetricsWhenNoData(): void
    {
        $repo = new InMemoryReportRepository();
        $query = new ReportQuery(999);

        $result = $repo->summary($query);

        self::assertSame(0, $result['total_clicks']);
        self::assertEqualsWithDelta(0.0, $result['epc'], 0.01);
        self::assertEqualsWithDelta(0.0, $result['roi'], 0.01);
    }

    // --- Breakdown ---

    public function testBreakdownGroupsByDimension(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->breakdown($query, 'campaign');

        self::assertCount(2, $result);
        // Default sort: total_clicks DESC
        self::assertSame(1, $result[0]['id']); // Campaign A: 300 clicks
        self::assertSame('Campaign A', $result[0]['name']);
        self::assertSame(300, $result[0]['total_clicks']);

        self::assertSame(2, $result[1]['id']); // Campaign B: 50 clicks
        self::assertSame('Campaign B', $result[1]['name']);
    }

    public function testBreakdownRespectsSort(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->breakdown($query, 'campaign', 'total_clicks', 'ASC');

        self::assertSame(2, $result[0]['id']); // Campaign B first (50 < 300)
    }

    public function testBreakdownRespectsPagination(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->breakdown($query, 'campaign', 'total_clicks', 'DESC', 1, 0);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
    }

    public function testBreakdownThrowsForInvalidType(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $this->expectException(\RuntimeException::class);
        $repo->breakdown($query, 'nonexistent');
    }

    // --- Timeseries ---

    public function testTimeseriesGroupsByDay(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->timeseries($query, 'day');

        self::assertCount(2, $result); // Two different days
        self::assertArrayHasKey('period', $result[0]);
        self::assertArrayHasKey('total_clicks', $result[0]);
    }

    public function testTimeseriesOrdersByPeriodAsc(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->timeseries($query, 'day');

        self::assertTrue($result[0]['period'] < $result[1]['period']);
    }

    public function testTimeseriesThrowsForInvalidInterval(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $this->expectException(\RuntimeException::class);
        $repo->timeseries($query, 'invalid');
    }

    // --- Daypart ---

    public function testDaypartGroupsByHour(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->daypart($query, 'UTC');

        self::assertNotEmpty($result);
        foreach ($result as $row) {
            self::assertArrayHasKey('hour_of_day', $row);
            self::assertGreaterThanOrEqual(0, $row['hour_of_day']);
            self::assertLessThanOrEqual(23, $row['hour_of_day']);
            self::assertArrayHasKey('total_clicks', $row);
        }
    }

    // --- Weekpart ---

    public function testWeekpartGroupsByDayOfWeek(): void
    {
        $repo = $this->makeRepo();
        $query = new ReportQuery(1);

        $result = $repo->weekpart($query, 'UTC');

        self::assertNotEmpty($result);
        foreach ($result as $row) {
            self::assertArrayHasKey('day_of_week', $row);
            self::assertGreaterThanOrEqual(0, $row['day_of_week']);
            self::assertLessThanOrEqual(6, $row['day_of_week']);
            self::assertArrayHasKey('total_clicks', $row);
        }
    }
}
