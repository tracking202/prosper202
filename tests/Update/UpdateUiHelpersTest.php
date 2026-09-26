<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;

/**
 * The pure helpers behind the Update pages on the v2 shell
 * (tracking202/update/_includes/update_ui.php): reading the Update CPC form,
 * the clause that both counts and updates its clicks, and the revenue
 * upload's column guess.
 *
 * The CPC reader is the one that decides how wide a write is, so it is held
 * to error pattern #11: a value that cannot be read never becomes the widest
 * reading ("every campaign"), it is refused under its field. And #18: the
 * CPC is checked as the string the browser sent, before anything casts it,
 * so a value the column cannot hold is refused rather than truncated.
 */
final class UpdateUiHelpersTest extends TestCase
{
    private string $timezone;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 2) . '/tracking202/update/_includes/update_ui.php';
    }

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return array<string, string> */
    private static function form(array $overrides = []): array
    {
        return array_merge(['from' => '2021-03-10', 'to' => '2021-03-11', 'cpc' => '0.25'], $overrides);
    }

    public function testTheCommonCaseReadsAsTheAccountsDays(): void
    {
        $parsed = p202_update_cpc_parse(self::form());
        self::assertSame([], $parsed['errors']);
        $values = $parsed['values'];
        self::assertSame(mktime(0, 0, 0, 3, 10, 2021), $values['from_time'], 'the first day starts at midnight in the account zone');
        self::assertSame(mktime(23, 59, 59, 3, 11, 2021), $values['to_time'], 'the last day ends at 23:59:59');
        self::assertSame('0.25000', $values['cpc'], 'normalised as the column stores it');
        foreach (['aff_network_id', 'aff_campaign_id', 'ppc_network_id', 'ppc_account_id', 'landing_page_id', 'text_ad_id'] as $field) {
            self::assertSame(0, $values[$field], "$field left out means every one");
        }
        self::assertSame('', $values['method_of_promotion']);
    }

    public function testTheClassicDateSpellingStillReads(): void
    {
        $parsed = p202_update_cpc_parse(self::form(['from' => '03/10/2021', 'to' => '3/11/21']));
        self::assertSame([], $parsed['errors']);
        self::assertSame(mktime(0, 0, 0, 3, 10, 2021), $parsed['values']['from_time']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function refusals(): iterable
    {
        yield 'no first day' => [['from' => ''], 'from', 'Enter the first day to update.'];
        yield 'not a date' => [['to' => '2021-02-30'], 'to', "'2021-02-30' is not a date. Use the date picker, or type it as YYYY-MM-DD."];
        yield 'backwards window' => [['from' => '2021-03-12'], 'to', 'The last day is before the first day.'];
        yield 'no CPC' => [['cpc' => ''], 'cpc', 'Enter the CPC these clicks cost, for example 0.25.'];
        yield 'a word' => [['cpc' => 'abc'], 'cpc', "'abc' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125."];
        yield 'negative' => [['cpc' => '-1'], 'cpc', "'-1' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125."];
        yield 'six decimals' => [['cpc' => '0.000001'], 'cpc', "'0.000001' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125."];
        yield 'exponent' => [['cpc' => '1e1'], 'cpc', "'1e1' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125."];
        yield 'over the column' => [['cpc' => '100'], 'cpc', 'A CPC can be at most $99.99999.'];
        yield 'a campaign that is not a number' => [['aff_campaign_id' => '12x'], 'aff_campaign_id', 'Choose a campaign from the list.'];
        yield 'a negative id' => [['ppc_account_id' => '-3'], 'ppc_account_id', 'Choose a traffic source account from the list.'];
        yield 'an array for an id' => [['text_ad_id' => ['1']], 'text_ad_id', 'Choose a text ad from the list.'];
        yield 'an id past the column' => [['landing_page_id' => '99999999999'], 'landing_page_id', 'Choose a landing page from the list.'];
        yield 'an unknown method' => [['method_of_promotion' => 'both'], 'method_of_promotion', 'Choose direct links, landing pages, or both.'];
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $overrides
     */
    public function testAValueThatCannotBeReadIsRefusedUnderItsField(array $overrides, string $field, string $sentence): void
    {
        $parsed = p202_update_cpc_parse(self::form($overrides));
        self::assertSame($sentence, $parsed['errors'][$field] ?? null);
    }

    public function testAnUnreadableIdNeverWidensTheWrite(): void
    {
        // The value is refused; what the reader would have put in its place
        // must not be what "every campaign" means to a caller that forgot
        // to look at the errors — so the errors are what decide.
        $parsed = p202_update_cpc_parse(self::form(['aff_campaign_id' => 'abc']));
        self::assertArrayHasKey('aff_campaign_id', $parsed['errors']);
    }

    public function testTheScopeBindsOneValuePerPlaceholder(): void
    {
        $values = p202_update_cpc_parse(self::form([
            'aff_network_id' => '3', 'aff_campaign_id' => '4', 'ppc_network_id' => '5', 'ppc_account_id' => '6',
            'landing_page_id' => '7', 'text_ad_id' => '8', 'method_of_promotion' => 'landingpage',
        ]))['values'];
        $scope = p202_update_cpc_scope($values, 42);
        self::assertSame(substr_count($scope['where'], '?'), strlen($scope['types']), 'one type per placeholder (error pattern #7)');
        self::assertCount(strlen($scope['types']), $scope['params']);
        self::assertSame([42, $values['from_time'], $values['to_time'], 3, 4, 8, 7, 5, 6], $scope['params']);
        self::assertStringContainsString('202_clicks.user_id = ?', $scope['where'], 'the account is always the first condition');
        self::assertStringContainsString('202_clicks_site.click_landing_site_url_id != 0', $scope['where']);
    }

    public function testEveryIsNoCondition(): void
    {
        $scope = p202_update_cpc_scope(p202_update_cpc_parse(self::form())['values'], 42);
        self::assertSame('iii', $scope['types'], 'only the account and the window');
        $direct = p202_update_cpc_scope(p202_update_cpc_parse(self::form(['method_of_promotion' => 'directlink']))['values'], 42);
        self::assertStringContainsString('202_clicks_site.click_landing_site_url_id = 0', $direct['where']);
    }

    public function testTheConfirmIsBoundedByTheHighestClickTheCheckSaw(): void
    {
        $values = p202_update_cpc_parse(self::form(['aff_campaign_id' => '4']))['values'];
        $scope = p202_update_cpc_scope($values, 42, 940006);
        self::assertStringContainsString('202_clicks.click_id <= ?', $scope['where']);
        self::assertSame(substr_count($scope['where'], '?'), strlen($scope['types']), 'one type per placeholder');
        self::assertSame([42, $values['from_time'], $values['to_time'], 4, 940006], $scope['params']);
        $check = p202_update_cpc_scope($values, 42)['where'];
        self::assertStringNotContainsString('click_id <=', $check, 'the check counts every click that matches now');
    }

    public function testTheConfirmCarriesWhatWasChecked(): void
    {
        $read = p202_update_cpc_snapshot(['expect_clicks' => '4', 'through_click_id' => '940006']);
        self::assertSame(['count' => 4, 'through' => 940006], $read);
        $none = p202_update_cpc_snapshot(['expect_clicks' => '0', 'through_click_id' => '0']);
        self::assertSame(['count' => 0, 'through' => 0], $none);
    }

    /**
     * A confirm that cannot say what it confirmed is refused and checked
     * again; it never reads as "no limit" (error pattern #11) — a form from
     * before the check carried these, a tampered one, or one cut short.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableSnapshots(): array
    {
        return [
            'neither' => [[]],
            'no count' => [['through_click_id' => '9']],
            'no boundary' => [['expect_clicks' => '4']],
            'empty count' => [['expect_clicks' => '', 'through_click_id' => '9']],
            'negative' => [['expect_clicks' => '-1', 'through_click_id' => '9']],
            'fraction' => [['expect_clicks' => '4', 'through_click_id' => '9.5']],
            'exponent' => [['expect_clicks' => '4', 'through_click_id' => '1e20']],
            'too long' => [['expect_clicks' => '4', 'through_click_id' => '99999999999999999999']],
            'array' => [['expect_clicks' => ['4'], 'through_click_id' => '9']],
            'padded' => [['expect_clicks' => ' 4', 'through_click_id' => '9']],
        ];
    }

    /**
     * @dataProvider unreadableSnapshots
     * @param array<string, mixed> $in
     */
    public function testAConfirmThatCannotSayWhatItConfirmedIsRefused(array $in): void
    {
        self::assertNull(p202_update_cpc_snapshot($in));
    }

    public function testTheColumnGuessReadsPlainHeaders(): void
    {
        self::assertSame(['subid' => 0, 'amount' => 2], p202_update_guess_columns(['Sub ID', 'Order', 'Commission']));
        self::assertSame(['subid' => 1, 'amount' => 0], p202_update_guess_columns(['Payout', 'subid']));
        self::assertSame(['subid' => 2, 'amount' => 3], p202_update_guess_columns(['Date', 'Offer', 'aff_sub', 'Revenue']));
        self::assertSame(['subid' => null, 'amount' => null], p202_update_guess_columns(['a', 'b']), 'nothing is guessed at random');
        self::assertSame(['subid' => 0, 'amount' => null], p202_update_guess_columns(['click_id_payout']), 'one column is not both');
    }

    public function testListsAndLines(): void
    {
        self::assertSame(['1', '2', '3'], p202_update_lines(" 1\r\n\n2\r3 \n"));
        self::assertSame([], p202_update_lines("  \n\t\n"));
        self::assertSame('a, b', p202_update_list_sentence(['a', 'b']));
        self::assertSame('a, b and 3 more', p202_update_list_sentence(['a', 'b', 'c', 'd', 'e'], 2));
    }

    public function testMoneyKeepsFractionsOfACent(): void
    {
        self::assertSame('$1.75', p202_update_money('1.75000'));
        self::assertSame('$0.00125', p202_update_money('0.00125'));
        self::assertSame('$3.00', p202_update_money('3'));
        self::assertSame('-$2.50', p202_update_money('-2.5'));
        self::assertSame('$0.50', p202_update_money('.5'));
    }

    public function testTheHeaderIsTheKitsAndEscapes(): void
    {
        $html = p202_update_header('bi-x', 'A <b>', 'd & e');
        self::assertStringContainsString('<h1 class="p202-page-header__title">A &lt;b&gt;</h1>', $html);
        self::assertStringContainsString('<p class="p202-page-header__desc">d &amp; e</p>', $html);
    }
}
