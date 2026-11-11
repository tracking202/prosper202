<?php

declare(strict_types=1);

namespace Tests\Analyze;

use PHPUnit\Framework\TestCase;
use Tracking202\Analyze\PublishersReport;

/**
 * Analyze › Publishers' rows and its download, from a groups answer shaped
 * as GET /reports/groups?by=publisher,publisher_link,campaign gives it
 * (PublisherReportIntegrationTest reads the real one).
 */
final class PublishersReportTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/analyze/PublishersReport.php';
    }

    /** @return array<string, int|float> */
    private static function metrics(int $clicks, int $leads = 0, float $income = 0.0, float $cost = 0.0): array
    {
        return [
            'total_clicks' => $clicks, 'total_click_throughs' => $clicks, 'total_leads' => $leads,
            'total_income' => $income, 'total_cost' => $cost, 'total_net' => $income - $cost,
            'conv_rate' => $clicks > 0 ? $leads / $clicks * 100 : 0.0,
            'roi' => $cost > 0 ? ($income - $cost) / $cost * 100 : 0.0,
        ];
    }

    /** @return array<string, mixed> */
    private static function groups(): array
    {
        $campaign = static fn (?int $id, ?string $name, array $m): array => ['id' => $id, 'name' => $name] + $m;
        $link = static fn (?int $id, array $children, array $m): array => ['id' => $id, 'name' => $id === null ? null : (string) $id, 'children' => $children] + $m;

        return [
            'data' => [
                ['id' => 7, 'name' => "=Dana\tReyes", 'children' => [
                    $link(4200000001, [$campaign(1, 'Running Shoes', self::metrics(2, 1, 12.5, 0.5))], self::metrics(2, 1, 12.5, 0.5)),
                    $link(null, [$campaign(null, null, self::metrics(1, 0, 0.0, 0.1))], self::metrics(1, 0, 0.0, 0.1)),
                ]] + self::metrics(3, 1, 12.5, 0.6),
                ['id' => null, 'name' => null, 'children' => []] + self::metrics(2, 0, 0.0, 0.5),
            ],
            'totals' => self::metrics(5, 1, 12.5, 1.1),
        ];
    }

    public function testEachPublisherHasARowPerLinkAndCampaign(): void
    {
        $report = PublishersReport::shape(self::groups());

        self::assertCount(1, $report['publishers']);
        self::assertSame(
            [['4200000001', 'Running Shoes', 2], [null, null, 1]],
            array_map(
                static fn (array $row): array => [$row['link'], $row['campaign'], $row['total_clicks']],
                $report['publishers'][0]['rows']
            )
        );
        self::assertSame(2, $report['uncredited']['total_clicks']);
        self::assertTrue($report['unrecorded_links'], 'a click that recorded no link is said so on the page');
        self::assertSame(5, $report['totals']['total_clicks']);
    }

    public function testTheDownloadIsOneTabSeparatedLinePerRow(): void
    {
        $lines = PublishersReport::exportLines(PublishersReport::shape(self::groups()));

        self::assertSame(
            "Publisher\tLink\tCampaign\tClicks\tClick Throughs\tLeads\tConv Rate\tIncome\tCost\tNet\tROI",
            $lines[0]
        );
        self::assertSame(
            "'=Dana Reyes\t4200000001\tRunning Shoes\t2\t2\t1\t50.00\t12.50\t0.50\t12.00\t2400.00",
            $lines[1],
            'a name cannot add a column or be read as a formula'
        );
        self::assertSame("'=Dana Reyes\tLink not recorded\tNo campaign\t1\t1\t0\t0.00\t0.00\t0.10\t-0.10\t-100.00", $lines[2]);
        self::assertSame("No publisher\t\t\t2\t2\t0\t0.00\t0.00\t0.50\t-0.50\t-100.00", $lines[3]);
        self::assertCount(4, $lines);
    }

    public function testNoPublisherClicksIsAnEmptyReportNotAnError(): void
    {
        $report = PublishersReport::shape(['data' => [], 'totals' => self::metrics(0)]);

        self::assertSame([], $report['publishers']);
        self::assertNull($report['uncredited']);
        self::assertCount(1, PublishersReport::exportLines($report));
    }
}
