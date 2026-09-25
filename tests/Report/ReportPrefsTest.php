<?php

declare(strict_types=1);

namespace Tests\Report;

use Tests\TestCase;

/**
 * Reading a report's filters from a request (202-config/functions-report-prefs.php).
 *
 * The v2 report pages keep their filters in the query string and apply them
 * to 202_users_pref, where every fragment, download and data-engine query
 * reads them. Two things decide whether that is safe, and both are pinned
 * here: a request writes only the columns it names (the classic form posted
 * every field, so its writer blanked whatever it was not sent), and a value
 * that does not parse is refused with a sentence rather than read as "all".
 *
 * The date reader is shared with the classic writer, set_user_prefs.php, so
 * the two shapes a report form submits — the classic calendar's mm/dd/yyyy
 * and the v2 picker's YYYY-MM-DD — are read by one function, tested once.
 */
final class ReportPrefsTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/functions-ui.php';
        require_once $root . '/202-config/functions-report-prefs.php';
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    // ── the date reader ───────────────────────────────────────────────

    /**
     * @return array<string, array{string, array{year: int, month: int, day: int}}>
     */
    public static function acceptedDates(): array
    {
        return [
            'the v2 picker, ISO' => ['2026-09-04', ['year' => 2026, 'month' => 9, 'day' => 4]],
            'the classic calendar' => ['09/04/2026', ['year' => 2026, 'month' => 9, 'day' => 4]],
            'the classic calendar, no leading zeros' => ['9/4/2026', ['year' => 2026, 'month' => 9, 'day' => 4]],
            'a classic preset, two-digit year' => ['09/04/26', ['year' => 2026, 'month' => 9, 'day' => 4]],
            'a two-digit year before 70 is this century' => ['12/31/69', ['year' => 2069, 'month' => 12, 'day' => 31]],
            'a two-digit year from 70 is the last' => ['01/01/70', ['year' => 1970, 'month' => 1, 'day' => 1]],
            'surrounding space, as the classic trim allowed' => ['  2026-02-28 ', ['year' => 2026, 'month' => 2, 'day' => 28]],
            'a leap day' => ['2028-02-29', ['year' => 2028, 'month' => 2, 'day' => 29]],
        ];
    }

    /**
     * @dataProvider acceptedDates
     * @param array{year: int, month: int, day: int} $expected
     */
    public function testBothShapesAReportFormSubmitsAreRead(string $input, array $expected): void
    {
        self::assertSame($expected, p202_report_parse_date($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedDates(): array
    {
        return [
            'empty' => [''],
            'a day that does not exist, ISO' => ['2026-02-30'],
            'a day that does not exist, classic' => ['02/30/2026'],
            'month thirteen' => ['13/01/2026'],
            'the European order in ISO clothing' => ['2026-31-12'],
            'day first with dots' => ['04.09.2026'],
            'a trailing time' => ['2026-09-04 10:00'],
            'trailing text the classic (int) cast swallowed' => ['09/04/2026abc'],
            'a missing part the classic reader silently skipped' => ['09//2026'],
            'three-digit year' => ['09/04/202'],
            'before the epoch' => ['1969-12-31'],
            'words' => ['yesterday'],
        ];
    }

    /**
     * @dataProvider refusedDates
     */
    public function testAnythingElseIsNotADate(string $input): void
    {
        self::assertNull(p202_report_parse_date($input), "'$input' is refused, not read as some other day");
    }

    public function testTheClassicWriterReadsDatesThroughTheSharedReader(): void
    {
        // set_user_prefs.php is a script, not a function, so this is the
        // structural half: it calls the shared reader for both bounds and no
        // longer splits on '/', which is what made it refuse YYYY-MM-DD.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/ajax/set_user_prefs.php');
        self::assertStringContainsString('functions-report-prefs.php', $source, 'the writer loads the shared reader');
        self::assertSame(1, substr_count($source, 'p202_report_parse_date('), 'one call, in the loop over both bounds');
        self::assertStringNotContainsString("explode('/'", $source, 'the slash-only parse is gone');
    }

    // ── the window ────────────────────────────────────────────────────

    public function testAPresetIsStoredByNameAndClearsTheCustomBounds(): void
    {
        $window = p202_report_range_columns('last7', null, null);
        self::assertSame([], $window['errors']);
        self::assertSame([
            'user_pref_time_predefined' => 'last7',
            'user_pref_time_from' => '',
            'user_pref_time_to' => '',
        ], $window['columns']);
    }

    public function testACustomWindowIsWholeDaysInTheCurrentTimezone(): void
    {
        $window = p202_report_range_columns(P202_RANGE_CUSTOM, '2026-09-04', '09/11/2026');
        self::assertSame([], $window['errors']);
        self::assertSame('', $window['columns']['user_pref_time_predefined'], 'an empty preset is how a custom window is stored');
        self::assertSame((string) mktime(0, 0, 0, 9, 4, 2026), $window['columns']['user_pref_time_from']);
        self::assertSame((string) mktime(23, 59, 59, 9, 11, 2026), $window['columns']['user_pref_time_to']);
        self::assertSame('2026-09-04 00:00:00', date('Y-m-d H:i:s', (int) $window['columns']['user_pref_time_from']), 'midnight where the user is');
    }

    public function testTheCustomValueIsTheOneThePickerUses(): void
    {
        self::assertSame('custom', P202_RANGE_CUSTOM);
    }

    public function testACustomWindowOfOneDayIsAllowed(): void
    {
        self::assertSame([], p202_report_range_columns(P202_RANGE_CUSTOM, '2026-09-04', '2026-09-04')['errors']);
    }

    /**
     * @return array<string, array{string, ?string, ?string, string}>
     */
    public static function refusedWindows(): array
    {
        // Providers run before setUp() loads the partials, so the custom
        // value is spelled out; testTheCustomValueIsTheOneThePickerUses pins it.
        return [
            'backwards' => ['custom', '2026-09-11', '2026-09-04', 'The start date is after the end date.'],
            'no end' => ['custom', '2026-09-04', '', 'A custom range needs a start and an end date.'],
            'no start' => ['custom', null, '2026-09-04', 'A custom range needs a start and an end date.'],
            'not a date' => ['custom', '2026-02-30', '2026-03-01', "The start date '2026-02-30' is not a date; use YYYY-MM-DD."],
            'an unknown preset' => ['last9000', null, null, "'last9000' is not a date range this report offers."],
            'the classic empty preset is not a range name' => ['', null, null, "'' is not a date range this report offers."],
        ];
    }

    /**
     * @dataProvider refusedWindows
     */
    public function testAWindowThatDoesNotParseIsRefused(string $range, ?string $from, ?string $to, string $sentence): void
    {
        $window = p202_report_range_columns($range, $from, $to);
        self::assertSame([], $window['columns'], 'nothing is written');
        self::assertSame(['range' => $sentence], $window['errors']);
    }

    // ── the request ───────────────────────────────────────────────────

    public function testOnlyTheNamesTheRequestCarriesAreWritten(): void
    {
        $read = p202_report_prefs_from_query(
            ['range' => 'today', 'user_pref_show' => 'real'],
            ['range', 'user_pref_show', 'user_cpc_or_cpv', 'ppc_network_id', 'keyword']
        );
        self::assertSame([], $read['errors']);
        self::assertSame([
            'user_pref_time_predefined' => 'today',
            'user_pref_time_from' => '',
            'user_pref_time_to' => '',
            'user_pref_show' => 'real',
        ], $read['columns'], 'a filter this page offers but the request did not send is left as stored');
    }

    public function testANameThePageDoesNotOfferIsIgnoredEvenWhenSent(): void
    {
        $read = p202_report_prefs_from_query(['keyword' => 'shoes', 'user_pref_show' => 'leads'], ['user_pref_show']);
        self::assertSame(['user_pref_show' => 'leads'], $read['columns'], 'the keyword filter is not this page\'s to change');
    }

    public function testAllIsStoredTheWayTheClassicWriterStoredIt(): void
    {
        $read = p202_report_prefs_from_query(
            ['ppc_network_id' => '', 'aff_campaign_id' => '', 'country_id' => '0', 'keyword' => '', 'method_of_promotion' => ''],
            ['ppc_network_id', 'aff_campaign_id', 'country_id', 'keyword', 'method_of_promotion']
        );
        self::assertSame([], $read['errors']);
        self::assertSame([
            'user_pref_ppc_network_id' => null,
            'user_pref_aff_campaign_id' => '',
            'user_pref_country_id' => '',
            'user_pref_keyword' => '',
            'user_pref_method_of_promotion' => '',
        ], $read['columns'], 'the traffic source as NULL, everything else as empty');
    }

    public function testEveryFilterWritesItsOwnColumn(): void
    {
        $query = [
            'ppc_network_id' => P202_REPORT_NO_TRAFFIC_SOURCE, 'ppc_account_id' => '4', 'aff_network_id' => '5',
            'aff_campaign_id' => '6', 'text_ad_id' => '7', 'landing_page_id' => '8', 'method_of_promotion' => 'landingpage',
            'country_id' => '9', 'region_id' => '10', 'isp_id' => '11', 'device_id' => '2', 'browser_id' => '12',
            'platform_id' => '13', 'subid' => '9007199254740993', 'ip' => '203.0.113.7', 'referer' => 'example.com',
            'keyword' => 'running shoes', 'user_pref_limit' => '100', 'user_pref_breakdown' => 'hour',
            'user_pref_show' => 'filtered_bot', 'user_cpc_or_cpv' => 'cpv', 'group_1' => '4', 'group_2' => '0',
        ];
        $read = p202_report_prefs_from_query($query, array_keys($query), [1, 4], '0');
        self::assertSame([], $read['errors']);
        $fields = p202_report_pref_fields();
        foreach ($query as $name => $value) {
            self::assertSame($value, $read['columns'][$fields[$name]['column']], "$name is written to {$fields[$name]['column']} as sent");
        }
        self::assertCount(count($query), $read['columns']);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>, string, string}>
     */
    public static function refusedValues(): array
    {
        return [
            'an id that is not a number' => [['aff_campaign_id' => '6 OR 1=1'], ['aff_campaign_id'], 'aff_campaign_id', "Campaign '6 OR 1=1' is not one this report can filter by."],
            'a negative id' => [['aff_campaign_id' => '-6'], ['aff_campaign_id'], 'aff_campaign_id', "Campaign '-6' is not one this report can filter by."],
            'a leading zero' => [['aff_campaign_id' => '06'], ['aff_campaign_id'], 'aff_campaign_id', "Campaign '06' is not one this report can filter by."],
            'an id its column cannot hold' => [['country_id' => '256'], ['country_id'], 'country_id', "Country '256' is not one this report can filter by."],
            'a subid past PHP_INT_MAX' => [['subid' => '9223372036854775808'], ['subid'], 'subid', "Subid '9223372036854775808' is not one this report can filter by."],
            'a clicks setting nobody offers' => [['user_pref_show' => 'bots'], ['user_pref_show'], 'user_pref_show', "Clicks 'bots' is not one of all, real, filtered, filtered_bot, leads."],
            'the classic plural method of promotion' => [['method_of_promotion' => 'landingpages'], ['method_of_promotion'], 'method_of_promotion', "Method of promotion 'landingpages' is not one of directlink, landingpage."],
            'a row count nobody offers' => [['user_pref_limit' => '1000'], ['user_pref_limit'], 'user_pref_limit', "Rows '1000' is not one of 10, 25, 50, 75, 100, 150, 200."],
            'a keyword longer than its column' => [['keyword' => str_repeat('k', 101)], ['keyword'], 'keyword', 'Keyword is longer than 100 characters.'],
            'an array where a value belongs' => [['keyword' => ['a', 'b']], ['keyword'], 'keyword', 'Keyword was sent more than once.'],
            'a grouping the report does not offer' => [['group_1' => '99'], ['group_1'], 'group_1', "Group by '99' is not a grouping this report offers."],
            'none as the first grouping' => [['group_1' => '0'], ['group_1'], 'group_1', "Group by '0' is not a grouping this report offers."],
        ];
    }

    /**
     * @dataProvider refusedValues
     * @param array<string, mixed> $query
     * @param list<string> $names
     */
    public function testAValueThatDoesNotParseIsRefusedAndNothingIsWritten(array $query, array $names, string $name, string $sentence): void
    {
        $query['user_pref_show'] ??= 'real';
        $names[] = 'user_pref_show';
        $read = p202_report_prefs_from_query($query, array_values(array_unique($names)), [1, 4], '0');
        self::assertSame($sentence, $read['errors'][$name] ?? null);
        self::assertSame([], $read['columns'], 'a request is applied whole or not at all: the valid Clicks value is not written either');
    }

    public function testAWindowErrorAlsoWithholdsTheRest(): void
    {
        $read = p202_report_prefs_from_query(
            ['range' => P202_RANGE_CUSTOM, 'from' => '2026-09-11', 'to' => '2026-09-04', 'user_pref_show' => 'real'],
            ['range', 'user_pref_show']
        );
        self::assertSame(['range' => 'The start date is after the end date.'], $read['errors']);
        self::assertSame([], $read['columns']);
        self::assertSame('2026-09-11', $read['values']['from'], 'the values come back for the form, so the reader can fix them');
    }

    public function testAnUnknownNameIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_report_prefs_from_query(['colour' => 'red'], ['colour']);
    }

    // ── showing what is stored ────────────────────────────────────────

    public function testStoredAllReadsAsEmptyAndEverythingElseAsStored(): void
    {
        $values = p202_report_prefs_values([
            'user_pref_ppc_network_id' => null,
            'user_pref_aff_campaign_id' => '0',
            'user_pref_country_id' => '',
            'user_pref_method_of_promotion' => '0',
            'user_pref_show' => 'leads',
            'user_pref_keyword' => 'shoes',
            'user_pref_group_1' => '4',
            'user_pref_subid' => '0',
        ]);
        self::assertSame('', $values['ppc_network_id']);
        self::assertSame('', $values['aff_campaign_id'], "the classic '0' for all");
        self::assertSame('', $values['country_id']);
        self::assertSame('', $values['method_of_promotion']);
        self::assertSame('', $values['subid']);
        self::assertSame('leads', $values['user_pref_show']);
        self::assertSame('shoes', $values['keyword']);
        self::assertSame('4', $values['group_1']);
        self::assertSame('', $values['user_pref_limit'], 'a column the row lacks is empty, and the control shows its default');
    }

    public function testAStoredValueNoControlOffersIsShownAsStored(): void
    {
        // The classic calendar stored 'landingpages', which no report
        // filter ever matched; showing it lets the filter bar say so.
        self::assertSame('landingpages', p202_report_prefs_values(['user_pref_method_of_promotion' => 'landingpages'])['method_of_promotion']);
    }

    // ── writing ───────────────────────────────────────────────────────

    public function testTheWriterRefusesAColumnThatIsNotAReportPreference(): void
    {
        $db = new \Prosper202\Database\Connection((new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'user_pass' is not a report preference column");
        p202_report_prefs_save($db, 1, ['user_pref_show' => 'real', 'user_pass' => 'x']);
    }

    public function testWritingNothingTouchesNothing(): void
    {
        $db = new \Prosper202\Database\Connection((new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor());
        p202_report_prefs_save($db, 1, []);
        self::assertTrue(true, 'no statement was prepared on an unconnected handle');
    }
}
