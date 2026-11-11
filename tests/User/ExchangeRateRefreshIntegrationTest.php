<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ExchangeRateCache;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\ExchangeRates;

/**
 * The cron job's refresh of the kept exchange rates
 * (ExchangeRates::refreshKept(), which 202-cronjobs/index.php calls), with
 * the real request sent to a loopback stand-in for the service
 * (ExchangeRateStub): what it asks for, what it sends, what a service that
 * fails or answers nonsense leaves behind, how long a silent one can cost
 * the cron job, and that recording a postback asks the service nothing.
 *
 * @group integration
 */
final class ExchangeRateRefreshIntegrationTest extends TestCase
{
    private const USER = 1;

    private static ?\mysqli $db = null;
    private static ?ExchangeRateStub $stub = null;
    private Connection $conn;
    private string $log = '';
    private string $logWas = '';

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
        self::$db = $db;
        self::$stub = new ExchangeRateStub();
        putenv('P202_EXCHANGE_RATES_ORIGIN=' . self::$stub->origin);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('P202_EXCHANGE_RATES_ORIGIN');
        self::$stub?->stop();
        self::$stub = null;
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db || !self::$stub) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending',
            '202_dataengine', '202_exchange_rates', '202_users_pref'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::$db->query('INSERT INTO 202_users_pref SET user_id=' . self::USER . ", user_account_currency='USD'");
        self::$stub->forget();
        self::$stub->answers([]);
        $this->conn = new Connection(self::$db);
        $this->logWas = (string) ini_get('error_log');
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-rates-refresh');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->logWas);
            @unlink($this->log);
        }
    }

    /** A campaign paid in $currency, of the USD account. */
    private function campaign(int $id, string $currency): void
    {
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=" . self::USER . ", aff_network_id=1,
            aff_campaign_name='c$id', aff_campaign_url='http://x', aff_campaign_payout=10.00, aff_campaign_time=1,
            aff_campaign_currency='$currency', aff_campaign_foreign_payout=9.00, payout_mode='accumulate'");
    }

    private function keep(string $from, string $rate, int $age): void
    {
        self::$db->query("REPLACE INTO 202_exchange_rates SET from_currency='$from', to_currency='USD', rate=$rate, fetched_at=" . (time() - $age));
    }

    /** @return array<string, string> "FROM" => the kept rate into USD */
    private function kept(): array
    {
        return array_column(
            self::$db->query('SELECT from_currency, rate FROM 202_exchange_rates ORDER BY from_currency')->fetch_all(MYSQLI_ASSOC),
            'rate',
            'from_currency'
        );
    }

    /** @return list<string> the lines the run logged */
    private function logged(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public function testAPairACampaignNeedsIsAskedForOnceAndKeptAndTheRequestHoldsOnlyThePairAndTheAmount(): void
    {
        $this->campaign(7, 'EUR');
        $this->campaign(8, 'EUR');
        $this->campaign(9, 'USD');
        self::$stub->answers(['EUR>USD' => '1.1']);

        $result = ExchangeRates::refreshKept($this->conn, time());

        self::assertSame(['fetched' => 1, 'failed' => [], 'skipped' => 0], $result);
        self::assertSame(['EUR' => '1.1000000000'], $this->kept());
        self::assertSame([], $this->logged(), 'a refresh that worked logs nothing');

        $requests = self::$stub->requests();
        self::assertCount(1, $requests, 'one request for the pair, however many campaigns need it, and none for a campaign in the account\'s currency');
        self::assertSame(['POST', '/api/v2/get-foreign-payout', []], [$requests[0]['method'], $requests[0]['path'], $requests[0]['query']]);
        self::assertSame(
            ['currency' => 'EUR', 'payout_currency' => 'USD', 'payout' => ExchangeRateCache::REFERENCE],
            $requests[0]['fields'],
            'the two currencies and the reference amount, and nothing of the install or the account'
        );
        self::assertSame(
            ['accept', 'content-length', 'content-type', 'host'],
            array_keys($requests[0]['headers']),
            'no cookie, key, user agent or referer goes with it'
        );

        // Within the day it is not asked for again.
        self::$stub->forget();
        self::assertSame(['fetched' => 0, 'failed' => [], 'skipped' => 0], ExchangeRates::refreshKept($this->conn, time()));
        self::assertSame([], self::$stub->requests());
    }

    public function testAServiceThatFailsOrAnswersNonsenseKeepsTheRatesItHadAndIsLoggedOnce(): void
    {
        $answers = ['CAD' => 'down', 'CHF' => 'garbled', 'EUR' => 'moved', 'GBP' => 'zero', 'JPY' => 'huge', 'SEK' => 'text'];
        $id = 10;
        foreach ($answers as $currency => $answer) {
            $this->campaign($id++, $currency);
            $this->keep($currency, '1.2500000000', ExchangeRateCache::REFRESH_AFTER + 60);
        }
        $before = $this->kept();
        self::$stub->answers(array_combine(array_map(static fn (string $c): string => "$c>USD", array_keys($answers)), $answers));

        $result = ExchangeRates::refreshKept($this->conn, time());

        self::assertSame(0, $result['fetched']);
        // Each hour's run starts at another pair (ExchangeRateCache::refresh()), so the order is the hour's.
        $failed = $result['failed'];
        sort($failed);
        self::assertSame(['CAD>USD', 'CHF>USD', 'EUR>USD', 'GBP>USD', 'JPY>USD', 'SEK>USD'], $failed);
        self::assertSame($before, $this->kept(), 'each pair keeps the rate it had');
        self::assertSame(
            ['/api/v2/get-foreign-payout'],
            array_values(array_unique(array_column(self::$stub->requests(), 'path'))),
            'a redirect is not followed'
        );
        $lines = $this->logged();
        self::assertCount(1, $lines, 'one line for the run, not one for each pair');
        foreach ($failed as $pair) {
            self::assertStringContainsString($pair, $lines[0]);
        }
    }

    public function testARunStopsAskingWhenItsTimeIsUpAndThePairsItDidNotReachAreNotFailures(): void
    {
        foreach (['CAD', 'EUR', 'GBP'] as $i => $currency) {
            $this->campaign(20 + $i, $currency);
        }
        self::$stub->answers(['CAD>USD' => '0.7', 'EUR>USD' => '1.1', 'GBP>USD' => '1.3']);
        // The clock a silent service would leave: the first request takes the run's whole time.
        $ticks = [0.0, 0.0, (float) ExchangeRates::REFRESH_BUDGET_SECONDS];
        $clock = static function () use (&$ticks): float {
            return count($ticks) > 1 ? (float) array_shift($ticks) : $ticks[0];
        };

        $result = ExchangeRates::refreshKept($this->conn, time(), $clock);

        self::assertSame(['fetched' => 1, 'failed' => [], 'skipped' => 2], $result);
        self::assertCount(1, self::$stub->requests(), 'nothing is asked once the time is up');
        self::assertSame([], $this->logged());
        self::assertLessThanOrEqual(10, ExchangeRates::REFRESH_REQUEST_SECONDS, 'one request cannot hold the cron job long');
        self::assertLessThanOrEqual(30, ExchangeRates::REFRESH_BUDGET_SECONDS);
    }

    public function testRecordingAPostbackAsksTheServiceNothingAndARateAWeekOldIsNoRate(): void
    {
        $this->campaign(7, 'EUR');
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::$db->query("INSERT INTO $t SET click_id=100, user_id=" . self::USER . ', aff_campaign_id=7, click_payout=10, click_cpc=0, click_lead=0, click_time=1700000000');
        }
        self::$stub->answers(['EUR>USD' => '1.1']);
        $this->keep('EUR', '1.5000000000', ExchangeRateCache::MAX_AGE + 60);
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        try {
            $repo = new MysqlConversionRepository($this->conn);
            $stale = $repo->record(self::USER, ['click_id' => 100, 'source' => 'postback', 'transaction_id' => 'A', 'payout' => '20.00'], MatchMethod::CLICK_ID);
            $this->keep('EUR', '1.5000000000', 3600);
            $fresh = $repo->record(self::USER, ['click_id' => 100, 'source' => 'postback', 'transaction_id' => 'B', 'payout' => '20.00'], MatchMethod::CLICK_ID);
        } finally {
            self::$db->query("SET SESSION sql_mode=''");
        }

        self::assertSame([], self::$stub->requests(), 'neither postback asked the service, with a rate to be had from it');
        $rows = self::$db->query('SELECT transaction_id, click_payout, currency, stated_amount FROM 202_conversion_logs ORDER BY conv_id')->fetch_all();
        self::assertSame([['A', '20.00000', 'EUR', null], ['B', '30.00000', 'USD', '20.00000']], $rows, 'as sent under the old rate, converted under the kept one');
        self::assertTrue($stale['unconverted']);
        self::assertFalse($fresh['unconverted']);
    }

    public function testOnlyALoopbackOriginCanStandInForTheService(): void
    {
        $endpoint = new \ReflectionMethod(ExchangeRates::class, 'endpoint');
        $real = (new \ReflectionClassConstant(ExchangeRates::class, 'ENDPOINT'))->getValue();
        self::assertSame('https://my.tracking202.com/api/v2/get-foreign-payout', $real);
        self::assertSame(self::$stub->origin . '/api/v2/get-foreign-payout', $endpoint->invoke(null));

        $stub = (string) getenv('P202_EXCHANGE_RATES_ORIGIN');
        try {
            foreach (['https://rates.example.com', 'http://127.0.0.1.example.com', 'http://127.0.0.1:8080/other', 'ftp://127.0.0.1', 'http://user@127.0.0.1'] as $elsewhere) {
                putenv('P202_EXCHANGE_RATES_ORIGIN=' . $elsewhere);
                self::assertSame($real, $endpoint->invoke(null), $elsewhere . ' is not this machine');
            }
        } finally {
            putenv('P202_EXCHANGE_RATES_ORIGIN=' . $stub);
        }
        self::assertCount(5, $this->logged(), 'each ignored setting is said');
    }
}
