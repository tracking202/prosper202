<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Report\ScratchReportDatabase;

/**
 * GET /reports/summary?compare=previous answers the window and the one
 * before it in one call, each what a summary of its own bounds answers; GET
 * /reports/timeseries?group_by= answers a series per group, each what the
 * series filtered to that group answers, and together the ungrouped one.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); writes
 * users 8150 and 8151.
 *
 * @group integration
 */
final class ReportCompareAndGroupIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 8150;
    private const OTHER = 8151;
    private const BULK = 8152;
    private const FIRST_CLICK = 81500001;
    private const ZONE = 'America/New_York';

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::user(self::USER, self::ZONE);
        self::user(self::OTHER);
        $campaign = static fn (int $user, string $name): int => self::insert('INSERT INTO 202_aff_campaigns SET'
            . " user_id = $user, aff_network_id = 0, aff_campaign_name = " . self::str($name) . ','
            . " aff_campaign_url = 'https://x.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0,"
            . ' aff_campaign_time = 0');
        $network = static fn (int $user, string $name): int => self::insert('INSERT INTO 202_ppc_networks SET'
            . " user_id = $user, ppc_network_name = " . self::str($name) . ', ppc_network_time = 0');
        self::$ids = [
            'a' => $campaign(self::USER, 'Alpha'),
            'b' => $campaign(self::USER, 'Beta'),
            'theirs' => $campaign(self::OTHER, 'Theirs'),
            'search' => $network(self::USER, 'Search'),
            'social' => $network(self::USER, 'Social'),
            'theirNet' => $network(self::OTHER, 'Their network'),
        ];
        $i = self::$ids;
        // 2026-03-02 .. 2026-03-11 in New York, across the change to summer
        // time on 03-08: [day, campaign, network, income, cost, lead].
        $clicks = [
            ['2026-03-02 10:00', $i['a'], $i['search'], '1.00000', '0.20000', 0],
            ['2026-03-03 11:00', $i['b'], $i['social'], '2.00000', '0.30000', 1],
            ['2026-03-04 23:30', $i['a'], 0, '0.50000', '0.10000', 0],
            ['2026-03-06 09:00', $i['a'], $i['search'], '3.00000', '0.40000', 1],
            ['2026-03-07 12:00', $i['b'], $i['search'], '4.00000', '0.50000', 0],
            ['2026-03-07 13:00', $i['theirs'], $i['theirNet'], '5.00000', '0.60000', 0],
            ['2026-03-08 03:30', $i['a'], $i['social'], '6.00000', '0.70000', 1],
            ['2026-03-09 00:30', $i['b'], 0, '7.00000', '0.80000', 0],
            ['2026-03-10 18:00', $i['a'], $i['search'], '8.00000', '0.90000', 1],
            ['2026-03-11 23:59', $i['b'], $i['social'], '9.00000', '1.00000', 0],
        ];
        $zone = new \DateTimeZone(self::ZONE);
        foreach ($clicks as $n => [$at, $aff, $ppc, $income, $cost, $lead]) {
            $time = (new \DateTimeImmutable($at, $zone))->getTimestamp();
            self::q('INSERT INTO 202_dataengine SET user_id = ' . self::USER
                . ', click_id = ' . (self::FIRST_CLICK + $n)
                . ", click_time = $time, aff_campaign_id = $aff, ppc_account_id = 0, ppc_network_id = $ppc,"
                . ' landing_page_id = 0, keyword_id = 0, ip_id = 0, click_referer_site_url_id = 0, device_id = 0,'
                . " click_filtered = 0, click_bot = 0, click_lead = $lead, clicks = 1, click_out = 1, leads = $lead,"
                . " payout = 0, income = $income, cost = $cost");
        }
        // The other account's own click, on a day the series covers.
        self::q('INSERT INTO 202_dataengine SET user_id = ' . self::OTHER . ', click_id = ' . (self::FIRST_CLICK + 50)
            . ', click_time = ' . (new \DateTimeImmutable('2026-03-07 12:00', $zone))->getTimestamp()
            . ", aff_campaign_id = {$i['theirs']}, ppc_account_id = 0, ppc_network_id = {$i['theirNet']},"
            . ' landing_page_id = 0, keyword_id = 0, ip_id = 0, click_referer_site_url_id = 0, device_id = 0,'
            . ' click_filtered = 0, click_bot = 0, click_lead = 0, clicks = 1, click_out = 1, leads = 0,'
            . ' payout = 0, income = 50, cost = 0');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER, self::BULK] as $u) {
            foreach (['202_dataengine', '202_aff_campaigns', '202_ppc_networks', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
    }

    private function reports(): ReportsController
    {
        return new ReportsController(self::$db, self::USER);
    }

    /** The other account's campaign reads as no campaign, as a breakdown's join reads it. */
    public function testEachCampaignsSeriesIsItsFilteredSeriesAndTheyAddUpToTheWhole(): void
    {
        $window = ['time_from' => '2026-03-02', 'time_to' => '2026-03-11'];
        $grouped = $this->reports()->timeseries($window + ['group_by' => 'campaign']);
        self::assertSame('campaign', $grouped['group_by']);
        self::assertFalse($grouped['truncated']);

        $series = [];
        foreach ($grouped['data'] as $row) {
            $series[$row['group_id'] ?? 'none'][] = $row;
        }
        self::assertSame([self::$ids['a'], self::$ids['b'], 'none'], array_keys($series));
        self::assertSame(['Alpha', 'Beta', null], [$series[self::$ids['a']][0]['group_name'],
            $series[self::$ids['b']][0]['group_name'], $series['none'][0]['group_name']]);
        foreach (['a', 'b'] as $name) {
            $id = self::$ids[$name];
            $alone = $this->reports()->timeseries($window + ['aff_campaign_id' => (string) $id])['data'];
            self::assertSame($alone, array_map(
                static fn (array $r): array => array_diff_key($r, ['group_id' => 1, 'group_name' => 1]),
                $series[$id]
            ), "campaign $name's series is the series filtered to it");
        }
        self::assertSame([['2026-03-07', 1, 5.0]], array_map(
            static fn (array $r): array => [$r['period'], $r['total_clicks'], $r['total_income']],
            $series['none']
        ), 'the click on another account\'s campaign is the null group, and the other account\'s click is not here');

        $this->assertSeriesAddUp($window, $grouped['data']);
    }

    public function testATrafficSourceSeriesKeepsTheClicksWithNoneAsTheNullGroup(): void
    {
        $window = ['time_from' => '2026-03-02', 'time_to' => '2026-03-11', 'interval' => 'week'];
        $grouped = $this->reports()->timeseries($window + ['group_by' => 'ppc_network']);
        foreach (['search', 'social'] as $name) {
            $id = self::$ids[$name];
            $alone = $this->reports()->timeseries($window + ['ppc_network_id' => (string) $id])['data'];
            $mine = array_values(array_filter($grouped['data'], static fn (array $r): bool => $r['group_id'] === $id));
            self::assertSame($alone, array_map(
                static fn (array $r): array => array_diff_key($r, ['group_id' => 1, 'group_name' => 1]),
                $mine
            ), "$name's weekly series is the series filtered to it");
        }
        $none = array_values(array_filter(
            $grouped['data'],
            static fn (array $r): bool => $r['group_id'] === null
        ));
        self::assertSame(
            3,
            array_sum(array_column($none, 'total_clicks')),
            'two with no network, one with another account\'s'
        );
        $this->assertSeriesAddUp($window, $grouped['data']);
    }

    /** @param list<array<string, mixed>> $grouped */
    private function assertSeriesAddUp(array $window, array $grouped): void
    {
        $whole = $this->reports()->timeseries($window)['data'];
        $sums = [];
        foreach ($grouped as $row) {
            $sums[$row['period']] ??= [0, 0, 0.0];
            $sums[$row['period']][0] += $row['total_clicks'];
            $sums[$row['period']][1] += $row['total_leads'];
            $sums[$row['period']][2] += $row['total_income'];
        }
        self::assertSame(
            array_map(static fn (array $r): array => [$r['period'], $r['total_clicks'], $r['total_leads']], $whole),
            array_map(static fn (string $p, array $s): array => [$p, $s[0], $s[1]], array_keys($sums), $sums)
        );
        foreach ($whole as $r) {
            self::assertEqualsWithDelta($r['total_income'], $sums[$r['period']][2], 1e-9, $r['period']);
        }
    }

    /** A cut that falls inside a period would serve some groups' last bucket and not others'. */
    public function testATruncatedGroupedSeriesEndsOnAWholePeriod(): void
    {
        self::user(self::BULK);
        $networks = array_map(static fn (int $n): int => self::insert('INSERT INTO 202_ppc_networks SET user_id = '
            . self::BULK . ", ppc_network_name = 'Bulk $n', ppc_network_time = 0"), [1, 2, 3]);
        $start = 1767225600; // 2026-01-01 00:00 UTC
        $rows = [];
        for ($hour = 0; $hour < 668; $hour++) {
            foreach ($networks as $n => $network) {
                $rows[] = '(' . self::BULK . ', ' . (82000000 + $hour * 3 + $n) . ', ' . ($start + 3600 * $hour)
                    . ", $network, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0)";
            }
        }
        self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_network_id, aff_campaign_id,'
            . ' ppc_account_id, landing_page_id, keyword_id, ip_id, click_referer_site_url_id, device_id,'
            . ' click_filtered, click_bot, click_lead, clicks, click_out, leads, payout, income, cost) VALUES '
            . implode(', ', $rows));
        try {
            $answer = (new ReportsController(self::$db, self::BULK))
                ->timeseries(['interval' => 'hour', 'group_by' => 'ppc_network']);
            self::assertTrue($answer['truncated']);
            self::assertCount(666 * 3, $answer['data'], 'the 667th hour, cut inside its groups, is left out whole');
            self::assertSame(
                array_fill(0, 666, 3),
                array_values(array_count_values(array_column($answer['data'], 'period')))
            );
        } finally {
            self::q('DELETE FROM 202_dataengine WHERE user_id = ' . self::BULK);
            self::q('DELETE FROM 202_ppc_networks WHERE user_id = ' . self::BULK);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::BULK);
        }
    }

    public function testCompareAnswersBothWindowsAsTheirOwnSummaries(): void
    {
        $answer = $this->reports()
            ->summary(['time_from' => '2026-03-07', 'time_to' => '2026-03-11', 'compare' => 'previous']);
        $zone = new \DateTimeZone(self::ZONE);
        $at = static fn (string $t): int => (new \DateTimeImmutable($t, $zone))->getTimestamp();
        self::assertSame(
            ['time_from' => $at('2026-03-07 00:00:00'), 'time_to' => $at('2026-03-11 23:59:59')],
            $answer['window']
        );
        self::assertSame(
            $at('2026-03-02 00:00:00'),
            $answer['previous']['time_from'],
            'five whole days before, though one is 23 hours'
        );
        self::assertSame($at('2026-03-06 23:59:59'), $answer['previous']['time_to']);

        $current = $this->reports()->summary(['time_from' => '2026-03-07', 'time_to' => '2026-03-11']);
        $previous = $this->reports()->summary(['time_from' => '2026-03-02', 'time_to' => '2026-03-06']);
        self::assertSame($current['data'], $answer['data']);
        self::assertSame($previous['data'], $answer['previous']['data']);
        self::assertSame([6, 4], [$answer['data']['total_clicks'], $previous['data']['total_clicks']]);
        self::assertSame([2, 2], [$answer['data']['total_leads'], $previous['data']['total_leads']]);
        self::assertSame($previous['other_currencies'], $answer['previous']['other_currencies']);

        $masked = \Api\V3\Support\CampaignFigures::mask($answer, \Api\V3\Support\CampaignFigures::REPORT);
        self::assertNull(
            $masked['previous']['data']['total_income'],
            'a viewer without campaign data sees neither window\'s money'
        );
        self::assertNull($masked['previous']['data']['total_clicks']);
        self::assertSame($answer['previous']['time_from'], $masked['previous']['time_from']);
    }

    public function testComparingANamedPeriodUsesOneClockForBoth(): void
    {
        $answer = $this->reports()->summary(['period' => 'yesterday', 'compare' => 'previous']);
        $zone = new \DateTimeZone(self::ZONE);
        $today = (new \DateTimeImmutable('now', $zone))->setTime(0, 0);
        self::assertSame($today->modify('-1 day')->getTimestamp(), $answer['window']['time_from']);
        self::assertSame($today->getTimestamp() - 1, $answer['window']['time_to']);
        self::assertSame($today->modify('-2 days')->getTimestamp(), $answer['previous']['time_from']);
        self::assertSame($answer['window']['time_from'] - 1, $answer['previous']['time_to']);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'an unknown comparison' => [['compare' => 'next', 'period' => 'last7'], 'compare'],
            'all time' => [['compare' => 'previous', 'period' => 'alltime'], 'compare'],
            'no window' => [['compare' => 'previous'], 'compare'],
            'one bound' => [['compare' => 'previous', 'time_from' => '2026-03-01'], 'compare'],
            'two windows' => [['compare' => 'previous', 'period' => 'last7', 'time_from' => '2026-03-01'], 'compare'],
        ];
    }

    /**
     * @dataProvider refusals
     * @param array<string, string> $params
     */
    public function testACompareWithNoPreviousWindowIsRefusedByName(array $params, string $field): void
    {
        try {
            $this->reports()->summary($params);
            self::fail('answered');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
    }

    public function testAGroupThatIsNotADimensionOfTheSeriesIsRefused(): void
    {
        try {
            $this->reports()->timeseries(['group_by' => 'keyword']);
            self::fail('answered');
        } catch (ValidationException $e) {
            self::assertStringContainsString('campaign', $e->getFieldErrors()['group_by']);
        }
    }
}
