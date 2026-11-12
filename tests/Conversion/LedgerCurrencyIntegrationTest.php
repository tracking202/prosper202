<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\ConversionLog;
use Prosper202\Conversion\Ledger\ClickBreakdown;
use Prosper202\Conversion\Ledger\ExchangeRateCache;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\RevenueCurrencies;
use Prosper202\Conversion\Ledger\RowCurrency;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * Each ledger row keeps the currency its amount is in, against a real
 * MySQL/MariaDB: what the writer stamps on every path, what a postback on a
 * campaign paid in another currency records with and without a kept rate,
 * and that every reader answers the row's own currency after the account's
 * has changed.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class LedgerCurrencyIntegrationTest extends TestCase
{
    private const USER = 1;

    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;
    private Connection $conn;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending',
            '202_dataengine', '202_exchange_rates', '202_users_pref', '202_currency_changes'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->conn = new Connection(self::$db);
        $this->repo = new MysqlConversionRepository($this->conn);
        $this->account('USD');
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function account(string $currency, int $user = self::USER): void
    {
        self::fixture("INSERT INTO 202_users_pref SET user_id=$user, user_account_currency='$currency'
            ON DUPLICATE KEY UPDATE user_account_currency='$currency'");
    }

    /** A campaign; $paidIn another currency than the account's makes it one paid in that currency. */
    private function campaign(int $id, string $mode = 'accumulate', string $paidIn = ''): void
    {
        $foreign = $paidIn === '' ? '0' : '9.00';
        $currency = $paidIn === '' ? 'USD' : $paidIn;
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=" . self::USER . ", aff_network_id=1,
            aff_campaign_name='c$id', aff_campaign_url='http://x', aff_campaign_payout=10.00, aff_campaign_time=1,
            aff_campaign_currency='$currency', aff_campaign_foreign_payout=$foreign, payout_mode='$mode'");
    }

    private function click(int $id, int $campaign, string $payout = '10.00', int $lead = 0): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=" . self::USER . ", aff_campaign_id=$campaign,
                click_payout=$payout, click_cpc=0, click_lead=$lead, click_time=1700000000");
        }
    }

    private function rate(string $from, string $to, string $rate, int $age = 0): void
    {
        self::fixture("REPLACE INTO 202_exchange_rates SET from_currency='$from', to_currency='$to', rate=$rate, fetched_at=" . (time() - $age));
    }

    /** @return array<string, mixed> */
    private function row(int $convId): array
    {
        $row = self::$db->query("SELECT click_payout, currency, stated_amount, stated_currency, reverses_conv_id
            FROM 202_conversion_logs WHERE conv_id=$convId")->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    private function clickPayout(int $clickId): string
    {
        return (string) self::$db->query("SELECT click_payout FROM 202_clicks WHERE click_id=$clickId")->fetch_assoc()['click_payout'];
    }

    /** @return array<string, mixed> */
    private function record(int $clickId, array $data, string $source = 'postback'): array
    {
        return $this->repo->record(self::USER, ['click_id' => $clickId, 'source' => $source] + $data, MatchMethod::CLICK_ID);
    }

    public function testEveryRowIsStampedWithTheAccountsCurrencyWhenItIsWritten(): void
    {
        $this->campaign(7);
        $this->click(100, 7);

        $explicit = $this->record(100, ['transaction_id' => 'A', 'payout' => '12.50']);
        $default = $this->record(100, ['transaction_id' => 'B']);
        $reversal = $this->record(100, ['transaction_id' => 'A', 'reversal' => true]);

        foreach ([$explicit, $default, $reversal] as $result) {
            $row = $this->row((int) $result['convId']);
            self::assertSame('USD', $row['currency']);
            self::assertNull($row['stated_amount'], 'nothing was converted');
            self::assertNull($row['stated_currency']);
            self::assertSame('USD', $result['currency']);
            self::assertFalse($result['unconverted']);
        }
    }

    public function testARowWrittenUnderOneCurrencyReadsAsItAfterTheAccountMovesToAnother(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $before = (int) $this->record(100, ['transaction_id' => 'A', 'payout' => '12.50'])['convId'];

        $this->account('EUR');
        $after = (int) $this->record(101, ['transaction_id' => 'B', 'payout' => '8.00'])['convId'];

        self::assertSame('USD', $this->row($before)['currency'], 'the earlier row keeps the currency it was written in');
        self::assertSame('EUR', $this->row($after)['currency'], 'a new row is in the account\'s currency now');

        // The API's conversion, the click's breakdown and the conversion log each answer the row's own.
        $api = (new ConversionsController(self::$db, self::USER))->get($before)['data'];
        self::assertSame('USD', $api['currency']);
        self::assertSame(12.5, $api['click_payout']);
        self::assertNull($api['stated_amount']);

        $breakdown = (new ClickBreakdown($this->conn))->forClick(100, self::USER);
        self::assertSame('USD', $breakdown['rows'][0]['currency']);
        self::assertSame('USD', $breakdown['click']['value_currency']);
        self::assertFalse($breakdown['click']['mixed_currencies']);

        $log = (new ConversionLog($this->conn))->page(self::USER, ConversionLog::readQuery(['range' => 'alltime'], 'UTC', time())['filters'], 0);
        $byConv = array_column($log['rows'], 'currency', 'conv_id');
        self::assertSame([$before => 'USD', $after => 'EUR'], [$before => $byConv[$before], $after => $byConv[$after]]);

        // What a period's totals hold that is not in the account's currency, by currency.
        self::assertSame(
            [['currency' => 'USD', 'conversions' => 1, 'amount' => '12.50000']],
            RevenueCurrencies::others($this->conn, self::USER, 'EUR', null, null)
        );
        self::assertSame([], RevenueCurrencies::others($this->conn, self::USER, 'EUR', 1700000001, null), 'outside the period');
        self::assertStringContainsString(
            'USD 12.50 (1 conversion)',
            RevenueCurrencies::sentence(RevenueCurrencies::others($this->conn, self::USER, 'EUR', null, null), 'EUR')
        );

        $reports = new ReportsController(self::$db, self::USER);
        $summary = $reports->summary([]);
        self::assertSame('EUR', $summary['data']['currency']);
        self::assertSame([['currency' => 'USD', 'conversions' => 1, 'amount' => 12.5]], $summary['other_currencies']);
        $breakdown = $reports->breakdown(['breakdown' => 'campaign']);
        self::assertSame(['EUR', $summary['other_currencies']], [$breakdown['currency'], $breakdown['other_currencies']]);
        self::assertSame([], $reports->timeseries(['interval' => 'day', 'time_from' => '1700000001'])['other_currencies'], 'outside the report\'s period');
    }

    public function testAPostbackOnACampaignPaidInAnotherCurrencyIsConvertedAtTheKeptRateAndKeepsWhatWasSent(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);
        $this->rate('EUR', 'USD', '1.1000000000');

        $result = $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);

        $row = $this->row((int) $result['convId']);
        self::assertSame('22.00000', $row['click_payout'], '20.00 EUR at 1.10');
        self::assertSame('USD', $row['currency']);
        self::assertSame('20.00000', $row['stated_amount']);
        self::assertSame('EUR', $row['stated_currency']);
        self::assertFalse($result['unconverted']);
        self::assertSame('22.00000', $this->clickPayout(100));
        self::assertSame([], RevenueCurrencies::others($this->conn, self::USER, 'USD', null, null));

        $api = (new ConversionsController(self::$db, self::USER))->get((int) $result['convId'])['data'];
        self::assertSame(['USD', 20.0, 'EUR'], [$api['currency'], $api['stated_amount'], $api['stated_currency']]);
    }

    public function testWithNoKeptRateTheAmountIsRecordedAsSentInItsOwnCurrencyAndEveryReaderSaysSo(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);

        $none = $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);
        $this->rate('EUR', 'USD', '1.1000000000', ExchangeRateCache::MAX_AGE + 60);
        $stale = $this->record(100, ['transaction_id' => 'B', 'payout' => '5.00']);

        foreach ([[$none, '20.00000'], [$stale, '5.00000']] as [$result, $amount]) {
            $row = $this->row((int) $result['convId']);
            self::assertSame($amount, $row['click_payout'], 'as sent');
            self::assertSame('EUR', $row['currency'], 'in the campaign\'s currency');
            self::assertNull($row['stated_amount']);
            self::assertTrue($result['unconverted']);
        }
        self::assertSame(
            [['currency' => 'EUR', 'conversions' => 2, 'amount' => '25.00000']],
            RevenueCurrencies::others($this->conn, self::USER, 'USD', null, null)
        );
    }

    public function testBeforeTheUpgradeMakesTheRatesTableThePostbackIsStillRecordedAsSent(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);
        $definition = (string) self::$db->query('SHOW CREATE TABLE 202_exchange_rates')->fetch_row()[1];
        self::$db->query('DROP TABLE 202_exchange_rates');
        $log = (string) ini_get('error_log');
        $capture = (string) tempnam(sys_get_temp_dir(), 'p202-rates-log');
        ini_set('error_log', $capture);
        try {
            $result = $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);
        } finally {
            ini_set('error_log', $log);
            self::$db->query($definition);
        }

        self::assertSame(['20.00000', 'EUR', null], array_values(array_slice($this->row((int) $result['convId']), 0, 3)));
        self::assertStringContainsString('Run the upgrade', (string) file_get_contents($capture));
        unlink($capture);
    }

    public function testOnlyAPostbackOrPixelAmountIsReadInTheCampaignsCurrency(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);
        $this->rate('EUR', 'USD', '1.1000000000');

        $api = $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00'], 'api');
        $default = $this->record(100, ['transaction_id' => 'B']);
        $named = $this->record(100, ['transaction_id' => 'C', 'payout' => '7.00', 'currency' => 'gbp'], 'clickbank');

        self::assertSame(['20.00000', 'USD', null], array_values(array_slice($this->row((int) $api['convId']), 0, 3)), 'an API amount is the account\'s');
        self::assertSame(['10.00000', 'USD', null], array_values(array_slice($this->row((int) $default['convId']), 0, 3)), 'the campaign\'s payout is kept in the account\'s');
        self::assertSame(['7.00000', 'GBP', null], array_values(array_slice($this->row((int) $named['convId']), 0, 3)), 'a currency the caller names is recorded');
        self::assertTrue($named['unconverted']);
    }

    public function testACurrencyThatIsNotACodeIsRefusedAndNothingIsWritten(): void
    {
        $this->campaign(7);
        $this->click(100, 7);

        $refusal = null;
        try {
            $this->record(100, ['transaction_id' => 'A', 'payout' => '7.00', 'currency' => 'dollars'], 'clickbank');
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        }
        self::assertNotNull($refusal, 'a currency that is not a three-letter code was accepted');
        self::assertStringContainsString('three-letter code', $refusal);
        self::assertSame('0', (string) self::$db->query('SELECT COUNT(*) AS n FROM 202_conversion_logs')->fetch_assoc()['n']);
    }

    public function testARefundOfAConvertedSaleIsTakenBackAtTheSalesRateWhateverTodaysIs(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);
        $this->click(101, 7);
        $this->rate('EUR', 'USD', '1.1000000000');
        $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);
        $this->record(101, ['transaction_id' => 'B', 'payout' => '20.00']);

        // The rate moves, and then is gone: the refunds still net against what the sales recorded.
        $this->rate('EUR', 'USD', '1.5000000000');
        $full = $this->row((int) $this->record(100, ['transaction_id' => 'A', 'reversal' => true])['convId']);
        self::$db->query('TRUNCATE TABLE 202_exchange_rates');
        $half = $this->row((int) $this->record(101, ['transaction_id' => 'B', 'reversal' => true, 'payout' => '10.00'])['convId']);

        self::assertSame(['-22.00000', 'USD', '-20.00000', 'EUR'], array_values(array_slice($full, 0, 4)));
        self::assertSame(['-11.00000', 'USD', '-10.00000', 'EUR'], array_values(array_slice($half, 0, 4)), 'half of what was sent is half of what was recorded');
        self::assertSame('11.00000', $this->clickPayout(101));
    }

    public function testARefundOfAnUnconvertedSaleIsInTheSalesCurrency(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->click(100, 7);
        $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);
        $this->rate('EUR', 'USD', '1.1000000000');

        $refund = $this->row((int) $this->record(100, ['transaction_id' => 'A', 'reversal' => true, 'payout' => '5.00'])['convId']);

        self::assertSame(['-5.00000', 'EUR', null, null], array_values(array_slice($refund, 0, 4)), 'never converted against a sale that was not');
    }

    public function testAClickWhoseCountedRowsAreInTwoCurrenciesSaysSo(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->record(100, ['transaction_id' => 'A', 'payout' => '12.50']);
        $this->account('EUR');
        $this->record(100, ['transaction_id' => 'B', 'payout' => '8.00']);

        $click = (new ClickBreakdown($this->conn))->forClick(100, self::USER)['click'];

        self::assertTrue($click['mixed_currencies']);
        self::assertNull($click['value_currency'], 'a sum of two currencies is in neither');
    }

    public function testTheValueAPreLedgerClickCarriesTakesItsRowsCurrencyOrNoneWhenTheAccountsIsNotKnown(): void
    {
        $this->campaign(7);
        $this->click(100, 7, '12.00', 1);
        $this->click(101, 7, '12.00', 1);
        $this->click(102, 7, '12.00', 1);
        $old = static fn (int $click, string $currency): string => "INSERT INTO 202_conversion_logs SET click_id=$click, transaction_id='OLD-$click',
            campaign_id=7, click_payout=12, user_id=1, click_time=1, conv_time=1, source='postback', dedupe_key='tx:OLD-$click',
            superseded_reason='pre_ledger', currency='$currency', currency_basis=" . ($currency === '' ? 0 : 1);
        self::fixture($old(100, 'GBP'));
        $this->account('EUR');
        // Its currency and how that is known: from before the upgrade, so as the upgrade labelled it.
        $baseline = static fn (int $click): string => implode('/', self::$db->query(
            "SELECT currency, currency_basis FROM 202_conversion_logs WHERE click_id=$click AND source='legacy_baseline'"
        )->fetch_row());

        $this->record(100, ['transaction_id' => 'NEW']);
        self::assertSame('GBP/1', $baseline(100), 'its pre-ledger row\'s currency');

        $this->record(101, ['transaction_id' => 'NEW']);
        self::assertSame('EUR/1', $baseline(101), 'no row, and every row of the account has a currency: the account\'s');

        self::fixture($old(103, ''));
        $this->record(102, ['transaction_id' => 'NEW']);
        self::assertSame('/0', $baseline(102), 'the account holds rows with no currency recorded, so this value\'s is not known either');
    }

    /** An entry of the account's currency history (kind: change, owner or upgrade). */
    private function history(int $at, string $from, string $to, string $kind = 'change'): void
    {
        self::fixture("INSERT INTO 202_currency_changes SET user_id=" . self::USER . ", changed_at=$at, from_currency='$from', to_currency='$to', kind='$kind'");
    }

    /** @return array{0: string, 1: string} the conversion's currency and basis */
    private function currencyOf(int $convId): array
    {
        return self::$db->query("SELECT currency, currency_basis FROM 202_conversion_logs WHERE conv_id=$convId")->fetch_row();
    }

    public function testACampaignPaidByItsPostbacksAmountsStaysInItsCurrencyThroughAChangeAndIsConverted(): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=7, user_id=" . self::USER . ", aff_network_id=1,
            aff_campaign_name='store', aff_campaign_url='http://x', aff_campaign_payout=0, aff_campaign_time=1,
            aff_campaign_currency='USD', aff_campaign_foreign_payout=0, payout_mode='accumulate'");
        $this->click(100, 7, '0.00');
        $updates = \Prosper202\User\CurrencyChange::plan($this->conn, self::USER, 'EUR', 'USD', static fn (): array => ['exchange_payout' => 1]);
        $this->conn->transaction(function () use ($updates): void {
            $this->account('EUR');
            \Prosper202\User\CurrencyChange::apply($this->conn, $updates);
        });
        $this->rate('USD', 'EUR', '0.9000000000');

        $postback = $this->record(100, ['transaction_id' => 'A', 'payout' => '20.00']);

        self::assertSame(['18.00000', 'EUR', '20.00000'], array_values(array_slice($this->row((int) $postback['convId']), 0, 3)),
            'its network still reports in USD: converted, and what was sent is kept');
    }

    public function testAConversionThatTakesTheClicksPayoutIsInTheCurrencyOfTheClicksTime(): void
    {
        // The click (at 1700000000) carried 10.00 while the account counted in USD; it changed to EUR since.
        $this->campaign(7, 'replace');
        $this->click(100, 7, '10.00');
        $this->history(1700000500, 'USD', 'EUR');
        $this->account('EUR');

        $pixel = $this->record(100, ['transaction_id' => 'A']);
        self::assertSame(['USD', '0'], $this->currencyOf((int) $pixel['convId']), 'known from the change recorded after the click');

        // An amount stated now is in the account's currency now.
        $stated = $this->record(100, ['transaction_id' => 'B', 'payout' => '5.00'], 'api');
        self::assertSame(['EUR', '0'], $this->currencyOf((int) $stated['convId']));

        // One dated before the change (a replay, an import) is in the currency of its own time.
        $dated = $this->record(100, ['transaction_id' => 'C', 'payout' => '5.00', 'conv_time' => 1700000100], 'api');
        self::assertSame(['USD', '0'], $this->currencyOf((int) $dated['convId']));
    }

    public function testAClicksPayoutFromBeforeTheUpgradeIsAGuessTheOwnerCanCorrectByTheClicksTime(): void
    {
        // The history begins at the upgrade, in EUR; the click is older, so its payout's currency is not known.
        $this->campaign(7, 'replace');
        $this->click(100, 7, '10.00');
        $this->history(1700000900, 'EUR', 'EUR', 'upgrade');
        $this->account('EUR');

        $pixel = $this->record(100, ['transaction_id' => 'A']);
        self::assertSame(['EUR', (string) RowCurrency::BASIS_BY_CLICK], $this->currencyOf((int) $pixel['convId']));

        // The owner says the account counted in USD before 1700000500: the conversion, recorded now on that
        // click, is chosen by its click's time.
        self::assertSame(['stamped' => 1, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'USD', 1700000500));
        self::assertSame(['USD', (string) RowCurrency::BASIS_BY_CLICK], $this->currencyOf((int) $pixel['convId']));
        self::assertSame(['stamped' => 0, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'USD', 1700000500), 'said again, nothing changes');

        // And the statement is history now: the next conversion on that click takes it.
        $next = $this->record(100, ['transaction_id' => 'B']);
        self::assertSame(['USD', (string) RowCurrency::BASIS_BY_CLICK], $this->currencyOf((int) $next['convId']));
    }

    public function testTheOwnersLatestWordForADateWinsAndNeverSpeaksOverAKnownChange(): void
    {
        $this->campaign(7, 'replace');
        $this->click(100, 7, '10.00');
        $this->history(1700000900, 'EUR', 'EUR', 'upgrade');
        $this->account('EUR');

        RowCurrency::recordBefore($this->conn, self::USER, 'USD', 1700000500);
        RowCurrency::recordBefore($this->conn, self::USER, 'GBP', 1700000500);
        $next = $this->record(100, ['transaction_id' => 'A']);
        self::assertSame('GBP', $this->currencyOf((int) $next['convId'])[0], 'said again for the same date: the latest word');
        self::assertSame('1', (string) self::$db->query("SELECT COUNT(*) FROM 202_currency_changes WHERE kind = 'owner'")->fetch_row()[0]);

        // A change recorded at 1700000300 is known: a statement for a later date speaks only for before it.
        self::$db->query('TRUNCATE TABLE 202_currency_changes');
        $this->history(1700000300, 'USD', 'EUR');
        $this->click(101, 7, '10.00');
        self::fixture('UPDATE 202_clicks SET click_time = 1700000400 WHERE click_id = 101');
        RowCurrency::recordBefore($this->conn, self::USER, 'GBP', 1700000800);
        $after = $this->record(101, ['transaction_id' => 'B']);
        self::assertSame(['EUR', '0'], $this->currencyOf((int) $after['convId']), 'after the known change: EUR, known');
        $before = $this->record(100, ['transaction_id' => 'C']);
        self::assertSame(['USD', '0'], $this->currencyOf((int) $before['convId']), 'before it: what the change recorded');

        // A range wholly inside the known history says nothing, before it either.
        self::$db->query('TRUNCATE TABLE 202_currency_changes');
        $this->history(1700000500, 'EUR', 'EUR', 'upgrade');
        self::assertSame(['stamped' => 0, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'USD', 1700003000, 1700002000));
        self::assertSame('EUR', RowCurrency::inForceAt($this->conn, self::USER, 1700000000, true)[0]);
        // A wider statement replaces a narrower one inside it.
        RowCurrency::recordBefore($this->conn, self::USER, 'USD', 1700000400, 1700000100);
        RowCurrency::recordBefore($this->conn, self::USER, 'GBP', 1700000400);
        self::assertSame(['GBP', 'GBP'], [
            RowCurrency::inForceAt($this->conn, self::USER, 1700000000, true)[0],
            RowCurrency::inForceAt($this->conn, self::USER, 1700000200, true)[0],
        ]);
    }

    /**
     * Any run of statements, against a reference that paints each statement's
     * range [since, before) onto the time before the history's start: every
     * time reads the latest statement covering it, the start's currency where
     * none does, and the account's own from the start on. Overlapping, equal,
     * nested, touching and past-the-start ranges are all drawn.
     */
    public function testEveryRunOfStatementsReadsAsTheLatestWordForEachTime(): void
    {
        $start = 900;
        $grid = range(0, 1000, 50);
        mt_srand(20261112);
        for ($run = 0; $run < 300; $run++) {
            self::$db->query('TRUNCATE TABLE 202_currency_changes');
            $this->history($start, 'EUR', 'EUR', 'upgrade');
            $this->account('EUR');
            $expected = array_fill_keys($grid, 'EUR');
            $said = [];
            for ($n = mt_rand(1, 4); $n > 0; $n--) {
                $currency = ['USD', 'GBP', 'CAD'][mt_rand(0, 2)];
                $before = $grid[mt_rand(1, count($grid) - 1)];
                $since = mt_rand(0, 2) === 0 ? null : $grid[mt_rand(0, array_search($before, $grid, true) - 1)];
                RowCurrency::recordBefore($this->conn, self::USER, $currency, $before, $since);
                $said[] = ($since ?? '-') . '..' . $before . ' ' . $currency;
                foreach ($grid as $t) {
                    if (($since === null || $t >= $since) && $t < min($before, $start)) {
                        $expected[$t] = $currency;
                    }
                }
            }
            $read = [];
            foreach ($grid as $t) {
                $read[$t] = RowCurrency::inForceAt($this->conn, self::USER, $t, true)[0];
            }
            self::assertSame($expected, $read, 'run ' . $run . ': ' . implode('; ', $said));
            self::assertSame('0', (string) self::$db->query("SELECT COUNT(*) - COUNT(DISTINCT changed_at) FROM 202_currency_changes WHERE kind = 'owner'")
                ->fetch_row()[0], 'run ' . $run . ': one owner entry a time, however often it is said');
        }
    }

    public function testBeforeTheUpgradeMakesTheHistoryAConversionIsStillRecorded(): void
    {
        $this->campaign(7, 'replace');
        $this->click(100, 7, '10.00');
        self::$db->query('DROP TABLE 202_currency_changes');
        try {
            // A new handle: one that saw the ledger's columns is fine either way.
            $pixel = $this->record(100, ['transaction_id' => 'A']);

            self::assertSame(['USD', (string) RowCurrency::BASIS_BY_CLICK], $this->currencyOf((int) $pixel['convId']),
                'the account\'s currency, marked for the owner to correct, as the upgrade marks it');
        } finally {
            self::$db->query(\Prosper202\Database\Tables\UserTables::currencyChanges()->createStatement);
        }
    }

    public function testADeletedCampaignPaidInAnotherCurrencyKeepsItsRateFresh(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        self::fixture('UPDATE 202_aff_campaigns SET aff_campaign_deleted = 1 WHERE aff_campaign_id = 7');

        self::assertSame([['EUR', 'USD']], \Prosper202\Conversion\Ledger\ExchangeRateCache::duePairs($this->conn, time()),
            'its postbacks are converted too');
    }

    public function testTheOwnerSaysWhichCurrencyTheConversionsBeforeADateWereIn(): void
    {
        $w = RowCurrency::BASIS_WRITTEN;
        $u = RowCurrency::BASIS_UPGRADE;
        self::fixture("INSERT INTO 202_conversion_logs (conv_id, click_id, campaign_id, click_payout, user_id, click_time, conv_time, dedupe_key, currency, currency_basis, reverses_conv_id) VALUES
            (1, 1, 7, 5, 1, 1, 100, 'row:1', '', $w, NULL),
            (2, 2, 7, 5, 1, 1, 100, 'row:2', 'EUR', $u, NULL),
            (3, 3, 7, 5, 1, 1, 100, 'row:3', 'USD', $w, NULL),
            (4, 4, 7, 5, 2, 1, 100, 'row:4', '', $w, NULL),
            (5, 5, 7, 5, 1, 1, 500, 'row:5', 'EUR', $u, NULL),
            (6, 2, 7, -5, 1, 1, 600, 'row:6', 'EUR', $w, 2),
            (7, 7, 7, 5, 1, 1, 40, 'row:7', 'EUR', $u, NULL),
            (8, 7, 7, -5, 1, 1, 100, 'row:8', 'EUR', $u, 7)");
        $rows = static fn (): array => array_map(
            static fn (array $r): string => $r['currency'] . '/' . $r['currency_basis'],
            array_column(self::$db->query('SELECT conv_id, currency, currency_basis FROM 202_conversion_logs ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC), null, 'conv_id')
        );

        self::assertSame(1, RowCurrency::unrecorded($this->conn, self::USER));
        self::assertSame(['stamped' => 5, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'GBP', 300));
        self::assertSame(
            [1 => 'GBP/2', 2 => 'GBP/2', 3 => 'USD/0', 4 => '/0', 5 => 'EUR/1', 6 => 'GBP/0', 7 => 'GBP/2', 8 => 'GBP/1'],
            $rows(),
            'the account\'s rows before the date that nothing recorded a currency with (1, 2, 7), and the refunds of them'
            . ' (6, 8) whenever recorded; not a row written with its currency (3), another account\'s (4), or a later one (5)'
        );
        self::assertSame(['stamped' => 0, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'GBP', 300), 'said again, nothing changes');

        self::assertSame(['stamped' => 3, 'left' => 0], RowCurrency::recordBefore($this->conn, self::USER, 'EUR', 300, 50), 'a mistake is said again');
        self::assertSame(
            [1 => 'EUR/2', 2 => 'EUR/2', 3 => 'USD/0', 4 => '/0', 5 => 'EUR/1', 6 => 'EUR/0', 7 => 'GBP/2', 8 => 'GBP/1'],
            $rows(),
            'from the start date on; a refund in the range follows its sale before it (8), so nothing is left to do'
        );
    }

    public function testTheOwnersRangeMustBeACurrencyAndNotEmpty(): void
    {
        foreach ([['dollars', 300, null], ['', 300, null], ['GBP', 300, 300], ['GBP', 300, 400]] as [$currency, $before, $since]) {
            $refused = false;
            try {
                RowCurrency::recordBefore($this->conn, self::USER, $currency, $before, $since);
            } catch (\InvalidArgumentException) {
                $refused = true;
            }
            self::assertTrue($refused, $currency . ' ' . $before . ' ' . var_export($since, true));
        }
    }

    public function testTheCronRefreshAsksOnlyForThePairsCampaignsNeedThatAreADayOld(): void
    {
        $this->campaign(7, 'accumulate', 'EUR');
        $this->campaign(8, 'accumulate', 'GBP');
        $this->campaign(9);
        $this->rate('GBP', 'USD', '1.3000000000', 3600);
        $now = time();
        $asked = [];
        $convert = static function (string $from, string $to, string $amount) use (&$asked): ?string {
            $asked[] = "$from>$to:$amount";

            return '110000.00';
        };

        self::assertSame(['fetched' => 1, 'failed' => []], ExchangeRateCache::refresh($this->conn, $convert, $now));
        self::assertSame(['EUR>USD:' . ExchangeRateCache::REFERENCE], $asked, 'the fresh pair and the campaign in the account\'s currency are not asked for');
        self::assertSame('1.1000000000', ExchangeRateCache::rate($this->conn, 'EUR', 'USD', $now));

        // A pair the service does not answer keeps the rate it has.
        $this->rate('GBP', 'USD', '1.3000000000', ExchangeRateCache::REFRESH_AFTER + 60);
        $result = ExchangeRateCache::refresh($this->conn, static fn (): ?string => null, $now);
        self::assertSame(['fetched' => 0, 'failed' => ['GBP>USD']], $result);
        self::assertSame('1.3000000000', ExchangeRateCache::rate($this->conn, 'GBP', 'USD', $now));
        self::assertNull(ExchangeRateCache::rate($this->conn, 'GBP', 'USD', $now + ExchangeRateCache::MAX_AGE), 'a rate a week old is no rate');
    }

    public function testPairsThatNeverAnswerDoNotKeepTheOthersFromBeingAsked(): void
    {
        $this->campaign(7, 'accumulate', 'AUD');
        $this->campaign(8, 'accumulate', 'EUR');
        $this->campaign(9, 'accumulate', 'GBP');
        $starts = [];
        foreach ([3, 4, 5] as $hour) {
            $log = [];
            $result = ExchangeRateCache::refresh($this->conn, static function (string $from) use (&$log): ?string {
                $log[] = $from;

                return null;
            }, $hour * 3600 + 5, 2);
            self::assertCount(2, $result['failed'], 'at most the limit a run');
            $starts[] = $log;
        }
        self::assertSame(
            [['AUD', 'EUR'], ['EUR', 'GBP'], ['GBP', 'AUD']],
            $starts,
            'each hour starts at another pair, so every pair is asked within three hours'
        );

        // Under the limit too: a run its caller's time budget stops after the
        // first pair (ExchangeRates::refreshKept()) still reaches every pair in turn.
        $first = [];
        foreach ([3, 4, 5] as $hour) {
            $asked = 0;
            ExchangeRateCache::refresh($this->conn, static function (string $from) use (&$asked, &$first): ?string {
                if ($asked++ === 0) {
                    $first[] = $from;
                }

                return null;
            }, $hour * 3600 + 5, 10);
        }
        self::assertSame(['AUD', 'EUR', 'GBP'], $first, 'every pair is first within three hours, whatever the limit');
    }

    public function testBeforeTheUpgradeAddsTheColumnsARowIsStillWritten(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        self::$db->query('ALTER TABLE 202_conversion_logs DROP KEY user_currency_click_time, DROP COLUMN currency, DROP COLUMN stated_amount, DROP COLUMN stated_currency, DROP COLUMN currency_basis');
        try {
            // A new handle: one that saw the columns is not asked again.
            $db = mysqli_connect(
                (string) getenv('P202_TEST_DB_HOST'),
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
            $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
            $log = (string) ini_get('error_log');
            $capture = tempnam(sys_get_temp_dir(), 'p202-currency-log');
            ini_set('error_log', $capture);
            try {
                $result = (new MysqlConversionRepository(new Connection($db)))->record(
                    self::USER,
                    ['click_id' => 100, 'source' => 'postback', 'transaction_id' => 'A', 'payout' => '12.50'],
                    MatchMethod::CLICK_ID
                );
            } finally {
                ini_set('error_log', $log);
            }
            self::assertGreaterThan(0, $result['convId']);
            self::assertSame('', $result['currency'], 'not recorded: the upgrade stamps it');
            self::assertStringContainsString('Run the upgrade', (string) file_get_contents($capture));
            unlink($capture);
            self::assertSame('12.50000', $this->clickPayout(100));
            $db->close();
        } finally {
            self::$db->query("ALTER TABLE 202_conversion_logs ADD COLUMN currency char(3) NOT NULL DEFAULT '' AFTER match_method,
                ADD COLUMN stated_amount decimal(11,5) DEFAULT NULL AFTER currency, ADD COLUMN stated_currency char(3) DEFAULT NULL AFTER stated_amount,
                ADD COLUMN currency_basis tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER stated_currency,
                ADD KEY user_currency_click_time (user_id, currency, click_time)");
        }
    }
}
