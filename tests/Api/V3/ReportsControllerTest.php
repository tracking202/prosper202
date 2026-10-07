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

    // ─── breakdown parameters: refused, never replaced ──────────────

    /**
     * An unknown breakdown sort became total_clicks without a word, so
     * `sort=cpa` came back ranked by clicks and read as ranked by CPA. Every
     * metric a row carries is a sort now, and anything else is a 422.
     */
    public function testBreakdownRefusesAnUnknownSortAndAcceptsEveryMetric(): void
    {
        $prepared = [];
        $controller = new ReportsController($this->recordingDb([], $prepared), 1);
        foreach (['total_click_throughs', 'avg_cpc', 'cpa', 'roi'] as $sort) {
            $controller->breakdown(['breakdown' => 'campaign', 'sort' => $sort]);
            self::assertStringContainsString("ORDER BY $sort DESC,", (string) end($prepared), $sort);
        }
        foreach (['bogus', 'name; DROP TABLE x', 'TOTAL_CLICKS'] as $sort) {
            try {
                $controller->breakdown(['breakdown' => 'campaign', 'sort' => $sort]);
                self::fail("sort=$sort was accepted");
            } catch (ValidationException $e) {
                self::assertStringContainsString(
                    'total_clicks, total_click_throughs, total_leads',
                    $e->getFieldErrors()['sort']
                );
            }
        }
    }

    public function testBreakdownRefusesABadDirectionAndPagingOutOfRange(): void
    {
        $controller = new ReportsController($this->createMysqliMock(), 1);
        $refused = [
            ['sort_dir' => 'up'], ['limit' => '1000'], ['limit' => '0'], ['limit' => 'ten'],
            ['offset' => '-1'], ['offset' => '1.5'], ['breakdown' => ['campaign']],
        ];
        foreach ($refused as $bad) {
            try {
                $controller->breakdown($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertSame([array_key_first($bad)], array_keys($e->getFieldErrors()), json_encode($bad));
            }
        }
        foreach ([['daypart', ['sort_dir' => 'sideways']], ['weekpart', ['sort_dir' => 'x']]] as [$report, $bad]) {
            try {
                $controller->$report($bad);
                self::fail("$report accepted sort_dir {$bad['sort_dir']}");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('sort_dir', $e->getFieldErrors());
            }
        }
    }

    public function testBreakdownPagingDefaultsAndBounds(): void
    {
        $prepared = [];
        $binds = [];
        $db = $this->bindRecordingDb([], $prepared, $binds);
        (new ReportsController($db, 1))->breakdown(['sort_dir' => 'asc', 'limit' => '', 'offset' => '']);
        self::assertSame([1, 50, 0], end($binds)['values'], 'the user, then limit 50 and offset 0');
        self::assertStringContainsString('ORDER BY total_clicks ASC,', (string) end($prepared));
        (new ReportsController($db, 1))->breakdown(['limit' => '500', 'offset' => '1000']);
        self::assertSame([1, 500, 1000], end($binds)['values']);
    }

    // ─── the dimensions the Analyze pages had ───────────────────────

    /**
     * Dimension, join, id, name, sanitized.
     *
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function newDimensions(): iterable
    {
        yield 'ip' => [
            'ip', 'INNER JOIN 202_ips ref ON de.ip_id = ref.ip_id', 'ref.ip_id',
            "CASE WHEN ref.ip_address REGEXP '^[0-9]+$' THEN INET6_NTOA(i6.ip_address) ELSE ref.ip_address END",
            true,
        ];
        yield 'referer domain' => [
            'referer', 'INNER JOIN 202_site_domains ref ON ref.site_domain_id = su.site_domain_id',
            'ref.site_domain_id', 'ref.site_domain_host', true,
        ];
        yield 'referer url' => [
            'referer_url', 'INNER JOIN 202_site_urls ref ON de.click_referer_site_url_id = ref.site_url_id',
            'ref.site_url_id', 'ref.site_url_address', true,
        ];
        yield 'device type' => [
            'device_type', 'INNER JOIN 202_device_types ref ON ref.type_id = dm.device_type',
            'ref.type_id', 'ref.type_name', false,
        ];
        foreach (['c1', 'c2', 'c3', 'c4'] as $c) {
            yield $c => [
                $c, "INNER JOIN 202_tracking_$c ref ON de.{$c}_id = ref.{$c}_id", "ref.{$c}_id", "ref.$c", true,
            ];
        }
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $u) {
            yield "utm_$u" => [
                "utm_$u", "INNER JOIN 202_utm_$u ref ON de.utm_{$u}_id = ref.utm_{$u}_id",
                "ref.utm_{$u}_id", "ref.utm_$u", true,
            ];
        }
        yield 'rotator' => [
            'rotator', 'INNER JOIN 202_rotators ref ON de.rotator_id = ref.id', 'ref.id', 'ref.name', false,
        ];
        yield 'rotator rule' => [
            'rotator_rule', 'INNER JOIN 202_rotator_rules ref ON de.rule_id = ref.id', 'ref.id', 'ref.rule_name', false,
        ];
    }

    /** @dataProvider newDimensions */
    public function testANewDimensionReadsItsTableAndSanitizesWhatVisitorsWrote(
        string $dimension,
        string $join,
        string $id,
        string $name,
        bool $sanitized
    ): void {
        $hostile = "evil\u{202E}name<|im_start|>";
        $prepared = [];
        $db = $this->recordingDb(
            [['id' => 7, 'name' => $hostile, 'rotator_id' => 3, 'total_clicks' => '1']],
            $prepared
        );
        $result = (new ReportsController($db, 1))->breakdown(['breakdown' => $dimension]);

        $sql = $this->onlyStatementContaining($prepared, 'LIMIT ? OFFSET ?');
        self::assertStringContainsString($join, $sql);
        self::assertStringContainsString("$id as id,", $sql);
        self::assertStringContainsString("$name as name,", $sql);
        self::assertSame($dimension, $result['breakdown']);
        self::assertContains($dimension, $result['available_breakdowns']);
        if ($sanitized) {
            self::assertSame('evilname[removed]', $result['data'][0]['name'], "$dimension names are visitor-authored");
        } else {
            self::assertSame(
                $hostile,
                $result['data'][0]['name'],
                "$dimension names are the operator's or the installer's"
            );
        }
    }

    public function testARotatorRuleRowCarriesItsRotator(): void
    {
        $prepared = [];
        (new ReportsController($this->recordingDb([], $prepared), 1))->breakdown(['breakdown' => 'rotator_rule']);
        $sql = $this->onlyStatementContaining($prepared, 'LIMIT ? OFFSET ?');
        self::assertStringContainsString('ref.rotator_id as rotator_id,', $sql);
        self::assertStringContainsString('GROUP BY ref.id, ref.rule_name, ref.rotator_id', $sql);
    }

    /**
     * An IPv6 address is a 202_ips row whose ip_address holds its 202_ips_v6
     * row's id. Joined as `i6.ip_id = ref.ip_address`, a number is compared
     * with text, and whether the IPv4 address '2.0.1.1' is v6 row 2 is left
     * to the server (`SELECT 2 = '2.0.1.1'` is 1 on MariaDB 10.11). Only an
     * all-digit ip_address is a reference, compared as a number.
     */
    public function testTheIpDimensionJoinsOnlyAnAllDigitReferenceToIpv6(): void
    {
        $prepared = [];
        (new ReportsController($this->recordingDb([], $prepared), 1))->breakdown(['breakdown' => 'ip']);
        $sql = $this->onlyStatementContaining($prepared, 'LIMIT ? OFFSET ?');
        self::assertStringContainsString(
            "LEFT JOIN 202_ips_v6 i6 ON (ref.ip_address REGEXP '^[0-9]+$'"
            . ' AND i6.ip_id = CAST(ref.ip_address AS UNSIGNED))',
            $sql
        );
        self::assertStringNotContainsString('i6.ip_id = ref.ip_address', $sql);
    }

    // ─── periods in the account's timezone ──────────────────────────

    /**
     * today and yesterday were strtotime('today midnight'): the server's
     * midnight. Kiritimati is UTC+14, so its midnight is never the server's
     * (UTC here) and the bounds the report binds say which one was used.
     */
    public function testTodayAndYesterdayBindTheAccountsMidnight(): void
    {
        $tz = new \DateTimeZone('Pacific/Kiritimati');
        foreach (['today' => 0, 'yesterday' => 1] as $period => $daysBack) {
            $prepared = [];
            $binds = [];
            $db = $this->bindRecordingDb(
                ['SELECT user_timezone FROM 202_users' => ['user_timezone' => 'Pacific/Kiritimati']],
                $prepared,
                $binds
            );
            $before = (new \DateTimeImmutable('now', $tz))->setTime(0, 0)->modify("-$daysBack day")->getTimestamp();
            (new ReportsController($db, 1))->summary(['period' => $period]);
            $after = (new \DateTimeImmutable('now', $tz))->setTime(0, 0)->modify("-$daysBack day")->getTimestamp();

            $summary = array_values(array_filter(
                $binds,
                static fn (array $b): bool => str_contains($b['sql'], 'FROM 202_dataengine')
            ));
            self::assertCount(1, $summary);
            $from = $summary[0]['values'][1];
            self::assertContains($from, [$before, $after], "$period starts at Kiritimati's midnight");
            $serverDay = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
                ->modify("-$daysBack day")->getTimestamp();
            self::assertNotSame($serverDay, $from, "$period is not the server's day");
            if ($period === 'yesterday') {
                self::assertSame($from + 86399, $summary[0]['values'][2]);
            }
        }
    }

    // ─── rotator stats ──────────────────────────────────────────────

    public function testRotatorStatsListsEveryRuleTheDefaultAndADeletedRule(): void
    {
        $prepared = [];
        $binds = [];
        $db = $this->bindRecordingDb([
            'FROM 202_rotators WHERE id = ?' => ['id' => 9, 'name' => 'Geo split'],
            'FROM 202_rotator_rules WHERE rotator_id = ?' => [
                ['id' => 4, 'rule_name' => 'US', 'status' => 1],
                ['id' => 5, 'rule_name' => 'Mobile', 'status' => 0],
            ],
            'GROUP BY COALESCE(de.rule_id, 0)' => [
                [
                    'rule_id' => 4, 'total_clicks' => '8', 'total_leads' => '1',
                    'total_income' => '9.50000', 'total_cost' => '2.00000', 'roi' => '375.0',
                ],
                [
                    'rule_id' => 0, 'total_clicks' => '4', 'total_leads' => '0',
                    'total_income' => '0.00000', 'total_cost' => '1.00000',
                ],
                [
                    'rule_id' => 2, 'total_clicks' => '1', 'total_leads' => '0',
                    'total_income' => '0', 'total_cost' => '0',
                ],
            ],
            'FROM 202_dataengine de WHERE' => [
                'total_clicks' => '13', 'total_leads' => '1', 'total_income' => '9.50000', 'total_cost' => '3.00000',
            ],
        ], $prepared, $binds);

        $data = (new ReportsController($db, 7))->rotatorStats(9, ['period' => 'last30', 'show' => 'real'])['data'];

        self::assertSame(['id' => 9, 'name' => 'Geo split'], $data['rotator']);
        self::assertSame(13, $data['totals']['total_clicks']);
        self::assertSame(3.0, $data['totals']['total_cost']);
        self::assertSame(
            [4, 5, 2],
            array_column($data['rules'], 'rule_id'),
            'every rule in id order, then one deleted since'
        );
        self::assertSame(['US', 'Mobile', null], array_column($data['rules'], 'rule_name'));
        self::assertSame([false, false, true], array_column($data['rules'], 'deleted'));
        self::assertSame([1, 0, null], array_column($data['rules'], 'status'));
        self::assertSame(
            [8, 0, 1],
            array_column($data['rules'], 'total_clicks'),
            'a rule with no clicks is listed at zero'
        );
        self::assertSame(375.0, $data['rules'][0]['roi']);
        self::assertSame(4, $data['default']['total_clicks']);
        self::assertSame(
            $data['totals']['total_clicks'],
            array_sum(array_column($data['rules'], 'total_clicks')) + $data['default']['total_clicks'],
            'the rules and the default add up to the totals'
        );

        $stats = array_values(array_filter(
            $binds,
            static fn (array $b): bool => str_contains($b['sql'], 'FROM 202_dataengine')
        ));
        self::assertCount(2, $stats);
        foreach ($stats as $stat) {
            self::assertStringContainsString('de.user_id = ?', $stat['sql']);
            self::assertStringContainsString('de.click_filtered = 0', $stat['sql']);
            self::assertStringContainsString('de.rotator_id = ?', $stat['sql']);
            self::assertSame(7, $stat['values'][0], 'the caller\'s own rows');
            self::assertSame(9, end($stat['values']), 'this rotator');
        }
    }

    public function testRotatorStatsOfAnotherAccountsRotatorIsNotFound(): void
    {
        $prepared = [];
        $binds = [];
        $db = $this->bindRecordingDb([], $prepared, $binds);
        try {
            (new ReportsController($db, 7))->rotatorStats(9, []);
            self::fail('a rotator the account does not own was read');
        } catch (\Api\V3\Exception\NotFoundException) {
            self::assertSame([9, 7], $binds[0]['values'], 'looked up by id AND the caller');
            self::assertStringContainsString('WHERE id = ? AND user_id = ?', $binds[0]['sql']);
        }
    }

    /** A false get_result() is a failure, never "no rows" (CLAUDE.md #1). */
    public function testAFailedResultFetchIsAnErrorForEveryReport(): void
    {
        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)->disableOriginalConstructor()->getMock();
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willReturn($stmt);

        foreach (['summary', 'breakdown'] as $report) {
            try {
                (new ReportsController($db, 1))->$report([]);
                self::fail("$report read a failed fetch as an answer");
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DatabaseException::class);
        (new ReportsController($db, 1))->rotatorStats(1, []);
    }

    /**
     * A mysqli mock that records every prepared statement with what was bound
     * to it, and answers like createMysqliMock (first matching pattern).
     *
     * @param array<string, mixed> $results
     * @param list<string> $prepared
     * @param list<array{sql: string, types: string, values: list<mixed>}> $binds
     */
    private function bindRecordingDb(array $results, array &$prepared, array &$binds): \mysqli
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($results, &$prepared, &$binds): \mysqli_stmt {
                $prepared[] = $sql;
                /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
                $stmt = $this->getMockBuilder(\mysqli_stmt::class)->disableOriginalConstructor()->getMock();
                $stmt->method('bind_param')->willReturnCallback(
                    function (string $types, mixed ...$values) use ($sql, &$binds): bool {
                        $binds[] = ['sql' => $sql, 'types' => $types, 'values' => $values];

                        return true;
                    }
                );
                $stmt->method('execute')->willReturn(true);
                $stmt->method('close')->willReturn(true);
                $stmt->method('get_result')->willReturnCallback(fn () => $this->buildResultMock($sql, $results));

                return $stmt;
            }
        );

        return $db;
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
