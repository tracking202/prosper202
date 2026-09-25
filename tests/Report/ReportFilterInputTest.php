<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tracking202\Report\ReportFilterInput;
use Tracking202\Report\ReportPrefsStore;

/**
 * What an Analyze report reads from its query string before anything is
 * stored or queried (tracking202/Report/ReportFilterInput.php), and the one
 * date parser the v2 pages and the classic set_user_prefs.php now share.
 */
final class ReportFilterInputTest extends TestCase
{
    private const ALL = [
        'ppc_network_id', 'aff_campaign_id', 'user_pref_show', 'ppc_account_id', 'aff_network_id',
        'landing_page_id', 'text_ad_id', 'method_of_promotion', 'country_id', 'region_id', 'isp_id',
        'device_id', 'browser_id', 'platform_id', 'ip', 'keyword', 'referer', 'user_pref_limit', 'user_cpc_or_cpv',
    ];

    // ── Dates: both shapes a report has ever submitted ──────────────────

    public function testTheV2DateInputsIsoShapeIsADate(): void
    {
        self::assertSame([2026, 9, 25], ReportFilterInput::parseDate('2026-09-25'));
        self::assertSame([2026, 2, 3], ReportFilterInput::parseDate(' 2026-2-3 '));
    }

    public function testTheClassicCalendarsShapesAreStillDates(): void
    {
        self::assertSame([2026, 9, 25], ReportFilterInput::parseDate('09/25/2026'), 'mm/dd/yyyy, what the jQuery UI calendar posted');
        self::assertSame([2026, 9, 5], ReportFilterInput::parseDate('9/5/2026'));
        self::assertSame([2026, 9, 25], ReportFilterInput::parseDate('09/25/26'), 'mm/dd/yy, what its preset buttons wrote');
        self::assertSame([1999, 12, 31], ReportFilterInput::parseDate('12/31/99'), 'a two-digit year reads as mktime() read it');
    }

    public function testWhatIsNotADayIsRefusedRatherThanGuessed(): void
    {
        foreach (['', 'yesterday', '2026-02-30', '02/30/2026', '2026/09/25', '25/09/2026', '1969-12-31', '2026-09-25T00:00', '13/01/2026'] as $bad) {
            self::assertNull(ReportFilterInput::parseDate($bad), var_export($bad, true) . ' is not a date');
        }
    }

    public function testTheClassicPrefsEndpointReadsBothShapesThroughTheSameParser(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/ajax/set_user_prefs.php');
        self::assertStringContainsString('ReportFilterInput::parseDate($fromRaw)', $source);
        self::assertStringContainsString('ReportFilterInput::parseDate($toRaw)', $source);
        self::assertStringNotContainsString("explode('/'", $source, 'the mm/dd/yyyy-only splitter is gone');
    }

    // ── When the URL speaks ────────────────────────────────────────────

    public function testAUrlThatNamesNoFilterLeavesTheStoredOnesStanding(): void
    {
        $input = ReportFilterInput::fromQuery([], self::ALL);
        self::assertFalse($input->speaks);
        self::assertNull($input->window);

        $input = ReportFilterInput::fromQuery(['page' => '2', 'order' => 'sort_breakdown_clicks desc'], self::ALL, ['sort_breakdown_clicks desc']);
        self::assertFalse($input->speaks, 'a page or a sort alone is not a report');
        self::assertSame(2, $input->page);
        self::assertSame('sort_breakdown_clicks desc', $input->order);
    }

    public function testAUrlThatNamesOneFilterSpeaksForAllOfThem(): void
    {
        $input = ReportFilterInput::fromQuery(['keyword' => 'shoes'], self::ALL);
        self::assertTrue($input->speaks);
        self::assertSame('shoes', $input->values['keyword']);
        self::assertSame('', $input->values['ppc_network_id'], 'a filter the link does not name is not filtering');
        self::assertSame('all', $input->values['user_pref_show'], 'a display setting it does not name is its default');
        self::assertSame('50', $input->values['user_pref_limit']);
        self::assertNull($input->window, 'but the window has no "off", so the stored one stands');
        self::assertSame([], $input->errors);
    }

    public function testARangeAloneSpeaks(): void
    {
        $input = ReportFilterInput::fromQuery(['range' => 'last7'], self::ALL);
        self::assertTrue($input->speaks);
        self::assertSame(['range' => 'last7', 'from' => null, 'to' => null], $input->window);
    }

    public function testACustomWindowNeedsTwoDatesInOrder(): void
    {
        $ok = ReportFilterInput::fromQuery(['range' => 'custom', 'from' => '2026-09-01', 'to' => '09/10/2026'], self::ALL);
        self::assertSame(['range' => 'custom', 'from' => [2026, 9, 1], 'to' => [2026, 9, 10]], $ok->window, 'either shape, in either field');

        $reversed = ReportFilterInput::fromQuery(['range' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-01'], self::ALL);
        self::assertNull($reversed->window);
        self::assertSame('The start date is after the end date.', $reversed->errors['range']);

        $missing = ReportFilterInput::fromQuery(['range' => 'custom', 'from' => '2026-09-10'], self::ALL);
        self::assertArrayHasKey('range', $missing->errors);

        $datesOnly = ReportFilterInput::fromQuery(['from' => '2026-09-01', 'to' => '2026-09-02'], self::ALL);
        self::assertSame('custom', $datesOnly->window['range'] ?? null, 'two dates with no range mean a custom window, not nothing');

        $unknown = ReportFilterInput::fromQuery(['range' => 'last9000'], self::ALL);
        self::assertArrayHasKey('range', $unknown->errors);
    }

    /**
     * Dates with no range are a custom window, and are held to the same
     * checks: the reversed pair was stored as a window that ends before it
     * starts.
     */
    public function testDatesWithNoRangeAreCheckedAsACustomWindowIs(): void
    {
        $reversed = ReportFilterInput::fromQuery(['from' => '2026-09-10', 'to' => '2026-09-01'], self::ALL);
        self::assertNull($reversed->window, 'nothing to store');
        self::assertSame('The start date is after the end date.', $reversed->errors['range'] ?? null, 'and the field says why, in the range=custom sentence');
        self::assertTrue($reversed->speaks, 'the URL still speaks, so the refusal is shown rather than the stored report');

        $oneDay = ReportFilterInput::fromQuery(['from' => '2026-09-10', 'to' => '09/10/2026'], self::ALL);
        self::assertSame(['range' => 'custom', 'from' => [2026, 9, 10], 'to' => [2026, 9, 10]], $oneDay->window, 'one day, in either shape, is a window');

        $half = ReportFilterInput::fromQuery(['to' => '2026-09-01'], self::ALL);
        self::assertSame('Choose a start and an end date for a custom range.', $half->errors['range'] ?? null);
    }

    // ── Each field's rule ──────────────────────────────────────────────

    public function testAnIdIsAPositiveNumberAndZeroMeansNotFiltering(): void
    {
        $input = ReportFilterInput::fromQuery(['ppc_network_id' => '16777215', 'country_id' => '0', 'browser_id' => '12'], self::ALL);
        self::assertSame(ReportFilterInput::NO_TRAFFIC_SOURCE, $input->values['ppc_network_id'], '"No traffic source" is an id like any other');
        self::assertSame('', $input->values['country_id']);
        self::assertSame('12', $input->values['browser_id']);

        foreach (['-1', '1e3', '1.5', 'abc', ' 7 x', '01'] as $bad) {
            $refused = ReportFilterInput::fromQuery(['country_id' => $bad], self::ALL);
            self::assertArrayHasKey('country_id', $refused->errors, var_export($bad, true) . ' is refused');
            self::assertSame('', $refused->values['country_id']);
        }
    }

    public function testAChoiceMustBeOnItsList(): void
    {
        $input = ReportFilterInput::fromQuery(['user_pref_show' => 'leads', 'user_pref_limit' => '200', 'user_cpc_or_cpv' => 'cpv', 'method_of_promotion' => 'landingpage'], self::ALL);
        self::assertSame([], $input->errors);
        self::assertSame('landingpage', $input->values['method_of_promotion']);

        foreach (['user_pref_show' => 'everything', 'user_pref_limit' => '1000', 'user_cpc_or_cpv' => 'cpm', 'method_of_promotion' => 'landingpages'] as $field => $bad) {
            $refused = ReportFilterInput::fromQuery([$field => $bad], self::ALL);
            self::assertArrayHasKey($field, $refused->errors, "$field=$bad is refused");
        }
    }

    public function testFreeTextFitsItsColumnAndAnIpIsAnIp(): void
    {
        $input = ReportFilterInput::fromQuery(['ip' => '2001:db8::1', 'keyword' => 'running shoes'], self::ALL);
        self::assertSame([], $input->errors);

        $bad = ReportFilterInput::fromQuery(['ip' => '203.0.113.300', 'referer' => str_repeat('x', 101)], self::ALL);
        self::assertSame('203.0.113.300 is not an IP address.', $bad->errors['ip']);
        self::assertArrayHasKey('referer', $bad->errors, 'a value the varchar(100) column would truncate is refused, not cut');
    }

    public function testAnArrayWhereOneValueBelongsIsRefused(): void
    {
        $input = ReportFilterInput::fromQuery(['keyword' => ['a', 'b'], 'region' => ['x']], self::ALL);
        self::assertArrayHasKey('keyword', $input->errors);
        self::assertArrayHasKey('region_id', $input->errors);
    }

    public function testARegionOrIspMayBeTypedByNameAndTheIdWins(): void
    {
        $named = ReportFilterInput::fromQuery(['region' => 'California (US)', 'isp' => 'AT&T'], self::ALL);
        self::assertTrue($named->speaks, 'the typed-name fields speak like the others');
        self::assertSame(['region' => 'California (US)', 'isp' => 'AT&T'], $named->names);
        self::assertSame('', $named->values['region_id'], 'left for the store to resolve');

        $both = ReportFilterInput::fromQuery(['region' => 'Texas', 'region_id' => '4'], self::ALL);
        self::assertSame('4', $both->values['region_id']);
        self::assertSame([], $both->names, 'an id in the URL is not second-guessed by a name');

        $notOffered = ReportFilterInput::fromQuery(['region' => 'Texas'], ['keyword']);
        self::assertFalse($notOffered->speaks, 'a page without the region filter does not read it');
    }

    public function testAPageAndASortAreCheckedAndDroppedWithAWord(): void
    {
        $input = ReportFilterInput::fromQuery(['page' => '0', 'order' => 'clicks; DROP TABLE x'], self::ALL, ['sort_breakdown_clicks desc']);
        self::assertSame(1, $input->page);
        self::assertSame('', $input->order);
        self::assertArrayHasKey('page', $input->errors);
        self::assertArrayHasKey('order', $input->errors);
    }

    public function testAFieldTheFilterSetDoesNotStoreIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportFilterInput::fromQuery([], ['subid']);
    }

    // ── What the stored row shows ──────────────────────────────────────

    public function testTheStoredRowIsShownAsWhatItDoes(): void
    {
        $values = ReportPrefsStore::valuesFromRow([
            'user_pref_ppc_network_id' => null,
            'user_pref_country_id' => '0',
            'user_pref_region_id' => '7',
            'user_pref_show' => null,
            'user_pref_limit' => '25',
            'user_cpc_or_cpv' => 'cpv',
            // The classic calendar saved "Landing page" as this, which the
            // report filter does not recognise: it filtered nothing.
            'user_pref_method_of_promotion' => 'landingpages',
            'user_pref_keyword' => ' shoes ',
        ], ['ppc_network_id', 'country_id', 'region_id', 'user_pref_show', 'user_pref_limit', 'user_cpc_or_cpv', 'method_of_promotion', 'keyword']);

        self::assertSame([
            'ppc_network_id' => '',
            'country_id' => '',
            'region_id' => '7',
            'user_pref_show' => 'all',
            'user_pref_limit' => '25',
            'user_cpc_or_cpv' => 'cpv',
            'method_of_promotion' => '',
            'keyword' => 'shoes',
        ], $values);
    }
}
