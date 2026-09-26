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
     * `Analyze/` — so the file is loaded on demand rather than at file scope,
     * which would make requiring this test file a side effect.
     *
     * Data providers run BEFORE setUpBeforeClass(), so one that reads a
     * constant off the controller has to load it itself; require_once makes
     * the second call free.
     */
    private static function loadController(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/analyze/MobileAppsReportController.php';
    }

    public static function setUpBeforeClass(): void
    {
        self::loadController();
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
            'last7'     => ['last7', 'last7 2026-02-22 00:00:00 .. 2026-03-01 23:59:59'],
            'last14'    => ['last14', 'last14 2026-02-15 00:00:00 .. 2026-03-01 23:59:59'],
            'last30'    => ['last30', 'last30 2026-01-30 00:00:00 .. 2026-03-01 23:59:59'],
            'last90'    => ['last90', 'last90 2025-12-01 00:00:00 .. 2026-03-01 23:59:59'],
            'thismonth' => ['thismonth', 'thismonth 2026-03-01 00:00:00 .. 2026-03-31 23:59:59'],
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
        // Compared whole. The first version str_replace'd 'custom' out of the
        // actual value before comparing, which made the one thing this test
        // exists to pin — the NAME the no-range case resolves under —
        // unobservable: a regression to 'custom' would have rendered the date
        // inputs live and submitted on a plain page load, and this would have
        // gone on passing.
        self::assertSame(self::window('last30'), self::window(null));
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
        self::assertSame('custom 2026-01-30 00:00:00 .. 2026-03-01 23:59:59', self::window('custom'));
    }

    public function testOnlyAStartMeansThirtyDaysEndingToday(): void
    {
        self::assertSame('custom 2026-02-20 00:00:00 .. 2026-03-01 23:59:59', self::window('custom', '2026-02-20'));
    }

    public function testOnlyAnEndMeansTheThirtyDaysBeforeIt(): void
    {
        self::assertSame('custom 2026-01-11 00:00:00 .. 2026-02-10 23:59:59', self::window('custom', '', '2026-02-10'));
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
        self::assertSame('2026-01-21', gmdate('Y-m-d', $resolved['from']));
    }

    public function testAnImpossibleDateIsNotRolledForward(): void
    {
        // gmmktime() would turn 2026-02-31 into 3 March. A date that does not
        // exist is a typo, and answering it with a different month's report
        // is worse than saying so — and saying the wrong thing about it
        // ("not in YYYY-MM-DD form", when the form is exactly right) sends
        // the reader hunting a problem they do not have.
        $resolved = MobileAppsReportController::resolveWindow('custom', '2026-02-31', '2026-02-20', self::NOW);
        self::assertSame(['There is no such date as 2026-02-31, so it was ignored.'], $resolved['notes']);
    }

    public function testAnUnknownRangeIsSaidOutLoud(): void
    {
        // The app and signature filters have always said when they dropped a
        // value; the range and the grouping silently swapped themselves for
        // the default, which answers a question nobody asked while looking
        // exactly like the answer to the one they did (error pattern #4).
        $resolved = MobileAppsReportController::resolveWindow('lastyear', '', '', self::NOW);
        self::assertSame('last30', $resolved['range']);
        self::assertSame(
            ['That range is not one this report offers, so it shows Last 30 Days.'],
            $resolved['notes']
        );
    }

    public function testAWellFormedWindowSaysNothing(): void
    {
        foreach ([['last7', '', ''], ['custom', '2026-02-01', '2026-02-28'], [null, '', '']] as [$r, $f, $t]) {
            self::assertSame([], MobileAppsReportController::resolveWindow($r, $f, $t, self::NOW)['notes']);
        }
    }

    public function testEveryGroupingNamesTheFieldItsFirstColumnComesFrom(): void
    {
        // The rendered table and the CSV both read groupKeys(). A grouping
        // offered in the toolbar with no entry there renders an empty first
        // column in both, which reads as "the report has no value for this"
        // rather than as a missing case.
        $keys = MobileAppsReportController::groupKeys();
        foreach (array_keys(MobileAppsReportController::GROUPINGS) as $grouping) {
            self::assertArrayHasKey($grouping, $keys, "the API names a field for $grouping");
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $keys[$grouping], "field for $grouping");
        }
    }

    public function testTheGroupKeysComeFromTheApiRatherThanACopy(): void
    {
        // The point of the accessor: a rename upstream must move this, not
        // leave a stale local table resolving to an empty first column.
        self::assertSame(
            \Api\V3\Controllers\AppPostbacksController::groupKeys(),
            MobileAppsReportController::groupKeys()
        );
    }

    /**
     * Presets grab_timeframe() has no counterpart for, so there is nothing to
     * compare them against. Last 90 Days is this report's own: a conversion
     * window runs to 35 days and postbacks trickle in behind it, which is a
     * question the click reports are never asked.
     *
     * testTheOnlyPresetsExemptFromTheCalendarAreOnesItDoesNotHave keeps this
     * honest — an entry added here to silence a real divergence fails there.
     */
    private const PRESETS_THE_CLICK_CALENDAR_LACKS = ['last90'];

    /**
     * Derived from RANGES rather than restated. A preset added to the toolbar
     * and forgotten here would simply go unchecked against the click
     * calendar, which is the divergence this whole comparison exists to
     * catch — and the list it was copied from is one file away.
     *
     * @return array<string, array{0: string}>
     */
    public static function calendarPresets(): array
    {
        self::loadController();
        $cases = [];
        foreach (array_keys(MobileAppsReportController::RANGES) as $range) {
            if (!in_array($range, self::PRESETS_THE_CLICK_CALENDAR_LACKS, true)) {
                $cases[$range] = [$range];
            }
        }

        return $cases;
    }

    /**
     * grab_timeframe() answers an unknown preset with *today* rather than
     * failing, so an exemption is indistinguishable from a preset it simply
     * disagrees about — which makes the exemption list a place a real
     * divergence could be parked. Each entry has to earn its place by
     * actually being unknown over there.
     */
    public function testTheOnlyPresetsExemptFromTheCalendarAreOnesItDoesNotHave(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-timeframe.php';
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            foreach (self::PRESETS_THE_CLICK_CALENDAR_LACKS as $range) {
                self::assertArrayHasKey(
                    $range,
                    MobileAppsReportController::RANGES,
                    "'$range' is exempted from a comparison for a preset this report does not offer"
                );
                do {
                    $day = gmdate('Y-m-d');
                    $window = grab_timeframe($range);
                    $today = grab_timeframe('today');
                } while ($day !== gmdate('Y-m-d'));
                self::assertSame(
                    [$today['from'], $today['to']],
                    [$window['from'], $window['to']],
                    "grab_timeframe() knows '$range' after all, so it must be compared rather than exempted"
                );
            }
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * @dataProvider calendarPresets
     */
    public function testEachPresetIsTheSameWindowTheClickCalendarMeans(string $range): void
    {
        // Executed against the other implementation, not reasoned about from
        // the labels. They were named identically and resolved differently —
        // 'Last 7 Days' was 7 days here and 8 on every click report, and
        // 'This Month' stopped at today instead of the end of the month — so
        // one label meant two windows and a reconciliation looked like
        // missing postbacks. Both are UTC here, which is the one difference
        // the page states outright.
        require_once dirname(__DIR__, 2) . '/202-config/functions-timeframe.php';
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            // One clock for both. grab_timeframe() reads the real one and
            // takes no argument, so this case is the one that cannot use the
            // fixed instant the rest of the file does; the day is re-read
            // afterwards so a run that straddles midnight retries rather than
            // reporting a difference that is only the calendar turning over.
            do {
                $day = gmdate('Y-m-d');
                $now = time();
                $calendar = grab_timeframe($range);
                $mine = MobileAppsReportController::resolveWindow($range, '', '', $now);
            } while ($day !== gmdate('Y-m-d'));
        } finally {
            date_default_timezone_set($previous);
        }
        self::assertSame(
            gmdate('Y-m-d H:i:s', $calendar['from']) . ' .. ' . gmdate('Y-m-d H:i:s', $calendar['to']),
            gmdate('Y-m-d H:i:s', $mine['from']) . ' .. ' . gmdate('Y-m-d H:i:s', $mine['to']),
            "the click calendar and this report must mean the same thing by '$range'"
        );
    }

    public function testNoTwoPresetsResolveToTheSameWindow(): void
    {
        // 'default' used to serve both last30 and "a name with no arm", so a
        // preset added to RANGES and nowhere else silently reported the
        // last-30-day window under its own name, and the guard tests above
        // (name kept, from < to, whole UTC days) all passed for it. The
        // default arm is gone — such a name now raises UnhandledMatchError on
        // the first page load — and this is the assertion that would have
        // caught the old behaviour: two labels, one window.
        $seen = [];
        self::assertNotSame([], MobileAppsReportController::RANGES, 'there are presets to check');
        foreach (array_keys(MobileAppsReportController::RANGES) as $range) {
            $window = MobileAppsReportController::resolveWindow($range, '', '', self::NOW);
            $key = $window['from'] . '..' . $window['to'];
            // The message is built from $seen only when there IS a clash;
            // PHPUnit evaluates it either way, and reading a key that is
            // absent is how the first version of this failed on itself.
            if (isset($seen[$key])) {
                self::fail("$range and {$seen[$key]} report the same window under different names");
            }
            $seen[$key] = $range;
        }
    }

    public function testTheGroupingsOfferedAreOnesTheApiWillAccept(): void
    {
        // Sent straight through as ?group_by=, so a name only this page knows
        // would be a pill that answers 422 and replaces the report with an
        // error. The API's own list is private, so read the sentence it puts
        // in the 422.
        $controller = new \ReflectionClass(\Api\V3\Controllers\AppPostbacksController::class);
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
