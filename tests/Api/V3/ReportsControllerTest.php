<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\DatabaseException;
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

    // ─── breakdown paging ───────────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function breakdownDimensions(): iterable
    {
        foreach (ReportsController::breakdownDimensions() as $dimension) {
            yield $dimension => [$dimension];
        }
    }

    /**
     * Rows tied on the sort column (many campaigns with 0 clicks) have no
     * defined order, so LIMIT/OFFSET pages could skip some and repeat others.
     * The dimension's id, which the query groups on, must break the tie.
     *
     * @dataProvider breakdownDimensions
     */
    public function testBreakdownBreaksSortTiesByIdForStablePaging(string $dimension): void
    {
        $sorts = [[[], 'total_clicks DESC'], [['sort' => 'roi', 'sort_dir' => 'asc'], 'roi ASC']];
        foreach ($sorts as [$sortParams, $expectedSort]) {
            $prepared = [];
            $db = $this->recordingDb([], $prepared);

            $params = ['breakdown' => $dimension, 'limit' => 500, 'offset' => 500] + $sortParams;
            (new ReportsController($db, 1))->breakdown($params);

            $sql = $this->onlyStatementContaining($prepared, 'LIMIT ? OFFSET ?');
            self::assertMatchesRegularExpression('/\bref\.(\w+) as id\b/', $sql);
            preg_match('/\bref\.(\w+) as id\b/', $sql, $id);
            self::assertStringContainsString("GROUP BY ref.{$id[1]},", $sql, 'the tie-breaker must be the grouped id');
            self::assertMatchesRegularExpression(
                '/ORDER BY ' . preg_quote($expectedSort, '/') . ', ref\.' . preg_quote($id[1], '/')
                    . ' ASC\s+LIMIT \? OFFSET \?$/',
                $sql,
                "breakdown=$dimension must order by the sort column, then its id"
            );
        }
    }

    // ─── timeseries truncation ──────────────────────────────────────

    public function testTimeseriesFlagsTruncationWhenMoreBucketsExistThanTheCap(): void
    {
        $prepared = [];
        $db = $this->recordingDb($this->periodRows(2001), $prepared);

        $result = (new ReportsController($db, 1))->timeseries(['interval' => 'hour']);

        self::assertStringContainsString('LIMIT 2001', $this->onlyStatementContaining($prepared, 'GROUP BY period'));
        self::assertCount(2000, $result['data']);
        self::assertTrue($result['truncated']);
        self::assertSame(2000, $result['limit']);
        self::assertSame('hour', $result['interval']);
        // Oldest first: the cut drops the newest bucket, never an earlier one.
        self::assertSame('p0000', $result['data'][0]['period']);
        self::assertSame('p1999', $result['data'][1999]['period']);
        self::assertSame(['data', 'interval', 'truncated', 'limit'], array_keys($result));
    }

    public function testTimeseriesReadsOnlyOneRowPastTheCap(): void
    {
        $prepared = [];
        $db = $this->recordingDb($this->periodRows(5000), $prepared);

        $result = (new ReportsController($db, 1))->timeseries(['interval' => 'day']);

        self::assertCount(2000, $result['data']);
        self::assertTrue($result['truncated']);
    }

    public function testTimeseriesAtExactlyTheCapIsNotTruncated(): void
    {
        $prepared = [];
        $db = $this->recordingDb($this->periodRows(2000), $prepared);

        $result = (new ReportsController($db, 1))->timeseries(['interval' => 'day']);

        self::assertCount(2000, $result['data']);
        self::assertFalse($result['truncated']);
        self::assertSame(2000, $result['limit']);
        self::assertSame('p1999', $result['data'][1999]['period']);
    }

    public function testTimeseriesBelowTheCapIsNotTruncated(): void
    {
        $prepared = [];
        $db = $this->recordingDb($this->periodRows(3), $prepared);

        $result = (new ReportsController($db, 1))->timeseries(['interval' => 'month']);

        self::assertCount(3, $result['data']);
        self::assertFalse($result['truncated']);
    }

    public function testTimeseriesFailedResultFetchIsAnErrorNotAnEmptySeries(): void
    {
        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)->disableOriginalConstructor()->getMock();
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);

        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willReturn($stmt);

        $this->expectException(DatabaseException::class);
        (new ReportsController($db, 1))->timeseries(['interval' => 'day']);
    }

    /**
     * A mysqli mock that records every prepared statement and, like the
     * server, returns no more rows than the statement's trailing LIMIT n.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $prepared
     */
    private function recordingDb(array $rows, array &$prepared): \mysqli
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($rows, &$prepared): \mysqli_stmt {
                $prepared[] = $sql;
                if (preg_match('/LIMIT (\d+)\s*$/', $sql, $m) === 1) {
                    $rows = array_slice($rows, 0, (int) $m[1]);
                }

                return $this->buildStmtMock($sql, ['SELECT' => $rows]);
            }
        );

        return $db;
    }

    /**
     * @param list<string> $prepared
     */
    private function onlyStatementContaining(array $prepared, string $needle): string
    {
        $matches = array_values(array_filter($prepared, static fn (string $sql): bool => str_contains($sql, $needle)));
        self::assertCount(1, $matches, "expected one statement containing '$needle'");

        return $matches[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function periodRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['period' => sprintf('p%04d', $i), 'total_clicks' => 1];
        }

        return $rows;
    }
}
