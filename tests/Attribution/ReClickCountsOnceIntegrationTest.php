<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionRollup;
use Prosper202\Attribution\JourneyBuilder;
use Prosper202\Attribution\ModelType;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * A redirector re-click gives a click a second 202_clicks row (and
 * 202_clicks_spy row), at the re-click's time, with the latest visit's
 * campaign; the first visit's traffic source and cost are copied onto it.
 * The multi-touch reports count that click once, at its latest visit: one
 * touch in a journey, its credits and assists in the latest visit's
 * dimension, its cost once, and in the click cohort at the latest visit's
 * time. The rollup sums the same.
 *
 * One person: click 10 on campaign 1, re-clicked 600 s later onto campaign
 * 2; then click 11 on campaign 3, which converts for $30. Linear credits
 * split it between the two clicks.
 *
 * @group integration
 */
final class ReClickCountsOnceIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private const BASE = 1_700_000_000;

    private const FIGURES = ['clicks', 'cost', 'attributed_conversions', 'attributed_revenue', 'assisted_conversions'];

    private int $linear = 0;
    private int $conv = 0;

    /** @param (callable(): void)|null $before more clicks, made before the conversion */
    private function scenario(?callable $before = null): void
    {
        foreach ([1, 2, 3] as $c) {
            $this->campaign($c);
        }
        self::fixture('INSERT INTO 202_ppc_accounts (ppc_account_id, user_id, ppc_network_id, ppc_account_name)'
            . " VALUES (5, 1, 1, 'Source A'), (6, 1, 1, 'Link source')");
        $this->click(10, 1, self::BASE, '0.40', 5);
        $this->visit(10, self::BASE, self::cookie('p'));
        $this->click(10, 2, self::BASE + 600, '0.40', 5);
        $this->click(11, 3, self::BASE + 1200, '0.10', 5);
        $this->visit(11, self::BASE + 1200, self::cookie('p'));
        $rows = (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE click_id IN (10, 11)');
        self::assertSame(3, $rows, 'click 10 has two visit rows');
        if ($before !== null) {
            $before();
        }
        $this->linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->conv = $this->convert(11, '30.00', 'T11', self::BASE + 1800);
        $this->work();
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    private function breakdown(
        string $groupBy,
        int $from,
        int $to,
        string $cohort = AttributionReports::COHORT_CONVERSION,
        bool $rollup = false
    ): array {
        return (new AttributionReports($this->conn, $rollup))
            ->breakdownAll(1, $this->linear, null, $this->defaultModelId(), $groupBy, $from, $to, $cohort);
    }

    /** @return array<string, array<string, mixed>> each row's figures, by key */
    private function rows(
        string $groupBy,
        int $from,
        int $to,
        string $cohort = AttributionReports::COHORT_CONVERSION
    ): array {
        $out = [];
        foreach ($this->breakdown($groupBy, $from, $to, $cohort)['rows'] as $r) {
            $out[(string) $r['key']] = array_intersect_key($r, array_flip(self::FIGURES));
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function figures(int $clicks, string $cost, string $credit, string $revenue, int $assists): array
    {
        return array_combine(self::FIGURES, [$clicks, $cost, $credit, $revenue, $assists]);
    }

    /**
     * @param list<string> $fields
     * @return list<list<mixed>>
     */
    private function touches(array $fields): array
    {
        $touches = (new AttributionReports($this->conn, false))->journey(1, $this->conv)['touches'];

        $keys = array_flip($fields);

        return array_map(
            static fn (array $t): array => array_values(array_intersect_key(array_merge($keys, $t), $keys)),
            $touches
        );
    }

    public function testTheJourneyHoldsTheReClickedClickOnce(): void
    {
        $this->scenario();

        self::assertSame([10, 11], self::journeyClicks($this->conv));
        $credits = array_map('strval', self::credits($this->conv, $this->linear));
        self::assertSame(['10' => '0.50000000', '11' => '0.50000000'], $credits);

        self::assertSame(
            [[0, 10, 2, 5, 'Source A'], [1, 11, 3, 5, 'Source A']],
            $this->touches(['position', 'click_id', 'campaign_id', 'ppc_account_id', 'ppc_account_name']),
            'each touch once, named by its click\'s latest visit'
        );
    }

    public function testAConvertingClickWithALaterVisitIsItsJourneysLastTouchOnly(): void
    {
        $this->scenario();

        // Click 10 converting through its re-click: the conversion carries
        // the latest visit's time, its visitor row the first visit's.
        $journey = (new JourneyBuilder($this->conn))->build(1, 10, self::BASE + 600, self::BASE + 900, 30);

        self::assertSame([10], array_map(static fn ($t): int => $t->clickId, $journey->touches));
    }

    /**
     * Click 20 was re-clicked twice in one second (a double-click on the
     * landing page's link): both visits are its latest, and it is one touch.
     * Click 21's latest visit was a bot's: it is no touch, as the reports
     * count no bot click.
     */
    public function testTwoLatestVisitsAreOneTouchAndALatestBotVisitIsNone(): void
    {
        $this->scenario(function (): void {
            $this->click(20, 1, self::BASE + 100);
            $this->visit(20, self::BASE + 100, self::cookie('p'));
            $this->click(20, 2, self::BASE + 400);
            $this->click(20, 2, self::BASE + 400);
            $this->click(21, 1, self::BASE + 200);
            $this->visit(21, self::BASE + 200, self::cookie('p'));
            $this->click(21, 2, self::BASE + 500, '0.10', 0, 0, 1);
        });

        self::assertSame([10, 20, 11], self::journeyClicks($this->conv));
        self::assertSame([[0, 10, 2], [1, 20, 2], [2, 11, 3]], $this->touches(['position', 'click_id', 'campaign_id']));
    }

    public function testTheBreakdownCountsTheClickOnceUnderItsLatestVisit(): void
    {
        $this->scenario();
        $window = [self::BASE - 3600, self::BASE + 7200];

        self::assertSame([
            '2' => self::figures(1, '0.40000', '0.50000000', '15.00000', 1),
            '3' => self::figures(1, '0.10000', '0.50000000', '15.00000', 0),
        ], $this->rows('campaign', ...$window), 'campaign 1, the first visit\'s, has nothing');
        self::assertSame(
            ['5' => self::figures(2, '0.50000', '1.00000000', '30.00000', 1)],
            $this->rows('traffic_source', ...$window)
        );
    }

    public function testTheClickCohortReadsTheLatestVisitsTime(): void
    {
        $this->scenario();
        $byClick = AttributionReports::COHORT_CLICK;
        $first = [self::BASE - 60, self::BASE + 60];
        $latest = [self::BASE + 500, self::BASE + 700];

        self::assertSame([], $this->rows('campaign', ...[...$first, $byClick]), 'nothing at the first visit\'s time');
        self::assertSame(
            ['conversions' => 0, 'attributed_conversions' => '0.00000000', 'attributed_revenue' => '0.00000'],
            $this->breakdown('campaign', ...[...$first, $byClick])['totals']
        );

        self::assertSame(
            ['2' => self::figures(1, '0.40000', '0.50000000', '15.00000', 1)],
            $this->rows('campaign', ...[...$latest, $byClick]),
            'the click, its credit and its assist at its latest visit'
        );
        $totals = $this->breakdown('campaign', ...[...$latest, $byClick])['totals'];
        self::assertSame(
            [1, '0.50000000', '15.00000'],
            [$totals['conversions'], $totals['attributed_conversions'], $totals['attributed_revenue']]
        );
    }

    public function testTheRollupSumsTheClickOnceToo(): void
    {
        $this->scenario();
        $now = self::BASE + 30 * 86400;
        $rollup = new AttributionRollup($this->conn, static fn (): int => $now);
        for ($i = 0; $i < 20; $i++) {
            if ($rollup->run(3600)->hoursBuilt === 0) {
                break;
            }
        }
        $built = (int) self::scalar('SELECT built_through_hour FROM 202_attribution_rollup_state WHERE user_id = 1');
        self::assertGreaterThan(intdiv(self::BASE + 1800, 3600), $built);

        $day = intdiv(self::BASE, 86400) * 86400;
        $window = [$day - 86400, $day + 2 * 86400 - 1];
        foreach (['campaign', 'traffic_source', 'landing_page'] as $dim) {
            $reports = new AttributionReports($this->conn, true);
            $rolled = $reports->breakdownAll(1, $this->linear, null, $this->defaultModelId(), $dim, ...$window);
            self::assertGreaterThan(0, $reports->lastServedHours(), "$dim: the rollup served the hours");
            self::assertSame($this->breakdown($dim, ...$window), $rolled, $dim);
        }
        $costRows = self::all(
            'SELECT dim_key, SUM(n) AS n FROM 202_attribution_rollup WHERE user_id = 1'
            . ' AND part = ' . AttributionRollup::PART_COST . ' AND grain = ' . AttributionRollup::GRAIN_HOUR
            . ' AND dim = ' . AttributionRollup::DIMENSION_CODES['campaign'] . ' GROUP BY dim_key ORDER BY dim_key'
        );
        self::assertSame(
            [['2', '1'], ['3', '1']],
            array_map(static fn (array $r): array => [(string) $r['dim_key'], (string) $r['n']], $costRows),
            'the summed clicks are the latest visits\''
        );
    }

    /**
     * A re-click written before the re-click copied the first visit's source
     * and cost carries the redirector link's: the click is counted once,
     * under what its latest row holds. Rows already written are not
     * rewritten, and a figure read from one row keeps the rollup's marks
     * (a row's own hour) sound.
     */
    public function testARowWrittenBeforeTheCopyCountsUnderTheLinksSource(): void
    {
        $this->scenario();
        $this->click(12, 1, self::BASE + 2000, '0.30', 5);
        $this->click(12, 2, self::BASE + 2300, '0.00', 6);
        $window = [self::BASE - 3600, self::BASE + 7200];

        $rows = $this->rows('traffic_source', ...$window);
        self::assertSame(
            [2, '0.50000'],
            [$rows['5']['clicks'], $rows['5']['cost']],
            'click 12 is not under its first visit\'s source'
        );
        self::assertSame([1, '0.00000'], [$rows['6']['clicks'], $rows['6']['cost']]);
    }
}
