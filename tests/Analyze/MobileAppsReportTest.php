<?php

declare(strict_types=1);

namespace Tests\Analyze;

use PHPUnit\Framework\TestCase;
use Tracking202\Analyze\MobileAppsReportController;

/**
 * What Analyze › Mobile Apps derives from a query string before it asks the
 * API anything.
 *
 * Mostly the window. The page has two controls that both describe one — a
 * range picker and a pair of dates — and the whole of their interaction is
 * here. Every case is evaluated at one fixed instant, because "Last 30 Days"
 * is otherwise a different answer every day and a test of it would be a test
 * of the clock. The instant is deliberately awkward: 2026-03-01 00:30 UTC is
 * inside a UTC day that has only just begun, in a month whose predecessor has
 * 28 days, in a year whose 1 March is not 60 days from 1 January.
 *
 * The last two cases are about the groupings: that every one the toolbar
 * offers names the field its first column reads, and that every one is a
 * grouping the API will accept.
 */
final class MobileAppsReportTest extends TestCase
{
    /**
     * The page controllers are not autoloadable — composer maps
     * `Tracking202\` to `tracking202/`, and this one lives in `analyze/`, not
     * `Analyze/` — so the file is loaded here rather than at file scope,
     * which would make requiring this test file a side effect.
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/analyze/MobileAppsReportController.php';
    }

    /** 2026-03-01 00:30:00 UTC. */
    private const NOW = 1772325000;

    private const DAY = 86400;

    /** What a resolved window reads as, for a legible assertion. */
    private static function window(?string $range, string $from = '', string $to = ''): string
    {
        $w = MobileAppsReportController::resolveWindow($range, $from, $to, self::NOW);

        return $w['range'] . ' ' . gmdate('Y-m-d H:i:s', $w['from']) . ' .. ' . gmdate('Y-m-d H:i:s', $w['to']);
    }

    public function testTheInstantTheseCasesAreMeasuredFrom(): void
    {
        // If this drifts, every expectation below is about a different day.
        self::assertSame('2026-03-01 00:30:00', gmdate('Y-m-d H:i:s', self::NOW));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function presets(): array
    {
        return [
            'today'     => ['today', 'today 2026-03-01 00:00:00 .. 2026-03-01 23:59:59'],
            'yesterday' => ['yesterday', 'yesterday 2026-02-28 00:00:00 .. 2026-02-28 23:59:59'],
            'last7'     => ['last7', 'last7 2026-02-23 00:00:00 .. 2026-03-01 23:59:59'],
            'last14'    => ['last14', 'last14 2026-02-16 00:00:00 .. 2026-03-01 23:59:59'],
            'last30'    => ['last30', 'last30 2026-01-31 00:00:00 .. 2026-03-01 23:59:59'],
            'last90'    => ['last90', 'last90 2025-12-02 00:00:00 .. 2026-03-01 23:59:59'],
            'thismonth' => ['thismonth', 'thismonth 2026-03-01 00:00:00 .. 2026-03-01 23:59:59'],
            'lastmonth' => ['lastmonth', 'lastmonth 2026-02-01 00:00:00 .. 2026-02-28 23:59:59'],
        ];
    }

    /**
     * @dataProvider presets
     */
    public function testEachPresetIsWholeUtcDays(string $range, string $expected): void
    {
        self::assertSame($expected, self::window($range));
    }

    public function testEveryOfferedRangeHasAWindow(): void
    {
        // A label in the toolbar with no arm in the resolver would silently
        // report the default window under someone else's name.
        foreach (array_keys(MobileAppsReportController::RANGES) as $range) {
            $resolved = MobileAppsReportController::resolveWindow($range, '', '', self::NOW);
            self::assertSame($range, $resolved['range'], "range kept its name: $range");
            self::assertLessThan($resolved['to'], $resolved['from'], "range covers time: $range");
        }
    }

    public function testTheDefaultIsLastThirtyDays(): void
    {
        self::assertSame(self::window('last30'), str_replace(
            MobileAppsReportController::CUSTOM_RANGE,
            'last30',
            self::window(null)
        ));
    }

    public function testAnUnknownRangeFallsBackRatherThanErroring(): void
    {
        // A mistyped URL should show a report, not a stack trace.
        self::assertSame(self::window('last30'), self::window('nonsense'));
        self::assertSame(self::window('last30'), self::window(''));
    }

    public function testDatesOnTheirOwnAreReadAsACustomWindow(): void
    {
        self::assertSame(
            'custom 2026-02-10 00:00:00 .. 2026-02-12 23:59:59',
            self::window(null, '2026-02-10', '2026-02-12')
        );
    }

    public function testAPresetWinsOverDatesTheFormAlsoSent(): void
    {
        // The form's date inputs always hold the window being displayed, so a
        // browser that submits them must not turn every preset into a no-op.
        self::assertSame(
            self::window('today'),
            self::window('today', '2026-01-01', '2026-02-01')
        );
    }

    public function testACustomRangeWithNoDatesIsTheDefaultWindowUnderItsOwnName(): void
    {
        // What choosing Custom Date and applying does with JavaScript off:
        // the dates arrive empty because they were rendered disabled.
        self::assertSame('custom 2026-01-31 00:00:00 .. 2026-03-01 23:59:59', self::window('custom'));
    }

    public function testOnlyAStartMeansThirtyDaysEndingToday(): void
    {
        self::assertSame('custom 2026-02-20 00:00:00 .. 2026-03-01 23:59:59', self::window('custom', '2026-02-20'));
    }

    public function testOnlyAnEndMeansTheThirtyDaysBeforeIt(): void
    {
        self::assertSame('custom 2026-01-12 00:00:00 .. 2026-02-10 23:59:59', self::window('custom', '', '2026-02-10'));
    }

    public function testReversedDatesAreSwappedAndSaidOutLoud(): void
    {
        $resolved = MobileAppsReportController::resolveWindow('custom', '2026-02-12', '2026-02-10', self::NOW);
        self::assertSame('2026-02-10', gmdate('Y-m-d', $resolved['from']));
        self::assertSame('2026-02-12', gmdate('Y-m-d', $resolved['to']));
        self::assertSame(['The dates were the wrong way round, so they were swapped.'], $resolved['notes']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableDates(): array
    {
        return [
            'day first' => ['12-02-2026'],
            'slashes' => ['2026/02/12'],
            'no padding' => ['2026-2-1'],
            'a word' => ['yesterday'],
            'a timestamp' => ['1772325000'],
            'trailing text' => ['2026-02-12T00:00:00Z'],
        ];
    }

    /**
     * @dataProvider unreadableDates
     */
    public function testAnUnreadableDateIsReportedRatherThanGuessedAt(string $date): void
    {
        $resolved = MobileAppsReportController::resolveWindow('custom', $date, '2026-02-20', self::NOW);
        self::assertSame(
            ['A date was not in YYYY-MM-DD form, so it was ignored.'],
            $resolved['notes'],
            "silently accepted: $date"
        );
        // And the window it falls back to is the default one, not a window
        // built out of whatever the string happened to cast to.
        self::assertSame('2026-01-22', gmdate('Y-m-d', $resolved['from']));
    }

    public function testAnImpossibleDateIsNotRolledForward(): void
    {
        // gmmktime() would turn 2026-02-31 into 3 March. A date that does not
        // exist is a typo, and answering it with a different month's report
        // is worse than saying so.
        $resolved = MobileAppsReportController::resolveWindow('custom', '2026-02-31', '2026-02-20', self::NOW);
        self::assertSame(['A date was not in YYYY-MM-DD form, so it was ignored.'], $resolved['notes']);
    }

    public function testAWellFormedWindowSaysNothing(): void
    {
        foreach ([['last7', '', ''], ['custom', '2026-02-01', '2026-02-28'], [null, '', '']] as [$r, $f, $t]) {
            self::assertSame([], MobileAppsReportController::resolveWindow($r, $f, $t, self::NOW)['notes']);
        }
    }

    public function testEveryGroupingNamesTheFieldItsFirstColumnComesFrom(): void
    {
        // The rendered table and the CSV both read GROUP_KEYS. A grouping
        // offered in the toolbar with no entry there renders an empty first
        // column in both, which reads as "the report has no value for this"
        // rather than as a missing case.
        self::assertSame(
            array_keys(MobileAppsReportController::GROUPINGS),
            array_keys(MobileAppsReportController::GROUP_KEYS),
            'the groupings offered and the fields they read must be the same set, in the same order'
        );
        foreach (MobileAppsReportController::GROUP_KEYS as $grouping => $field) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $field, "field for $grouping");
        }
    }

    public function testTheGroupingsOfferedAreOnesTheApiWillAccept(): void
    {
        // Sent straight through as ?group_by=, so a name only this page knows
        // would be a pill that answers 422 and replaces the report with an
        // error. The API's own list is private, so read the sentence it puts
        // in the 422.
        $controller = new \ReflectionClass(\Api\V3\Controllers\AttributionPostbacksController::class);
        $modes = array_keys($controller->getConstant('GROUP_MODES'));
        foreach (array_keys(MobileAppsReportController::GROUPINGS) as $grouping) {
            self::assertContains($grouping, $modes, "the API accepts group_by=$grouping");
        }
    }

    public function testEveryWindowEndsOnTheLastSecondOfItsLastDay(): void
    {
        // The report groups by FLOOR(received_at / 86400), so a window that
        // stopped at midnight would drop the whole of its final day.
        $ranges = array_keys(MobileAppsReportController::RANGES);
        $ranges[] = MobileAppsReportController::CUSTOM_RANGE;
        foreach ($ranges as $range) {
            $resolved = MobileAppsReportController::resolveWindow($range, '', '', self::NOW);
            self::assertSame(0, $resolved['from'] % self::DAY, "starts at midnight UTC: $range");
            self::assertSame(self::DAY - 1, $resolved['to'] % self::DAY, "ends at 23:59:59 UTC: $range");
        }
    }
}
