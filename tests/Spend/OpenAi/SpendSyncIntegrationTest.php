<?php

declare(strict_types=1);

namespace Tests\Spend\OpenAi;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Spend\OpenAi\InsightsClient;
use Prosper202\Spend\OpenAi\SpendConnection;
use Prosper202\Spend\OpenAi\SpendConnections;
use Prosper202\Spend\OpenAi\SpendSync;

/**
 * The ChatGPT Ads spend sync against a real server (CLAUDE.md #9), with
 * OpenAI's answers from tests/fixtures/openai-ads (shaped like its OpenAPI
 * spec; nothing leaves the process): off until turned on, 30 days on the
 * first run and 72 hours after, every page followed, each hour written
 * through the spend ledger, another currency refused by name, a 429 left
 * alone, and an unreadable row left out by name while the rest is kept.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 6401's rows.
 *
 * @group integration
 */
final class SpendSyncIntegrationTest extends TestCase
{
    private const USER = 6401;
    private const OTHER = 6402;
    private const CONNECTION = 64001;
    private const KEY = 'sk-adv-sync-test';
    private const NOW = 1794300000;

    private static ?\mysqli $db = null;

    /** @var list<string> */
    private array $urls = [];

    /** @var list<string> the conversion insights requests' bodies */
    private array $bodies = [];

    /** @var list<array<string, mixed>> */
    private array $conversionAnswers = [];

    /** @var list<array<string, mixed>> */
    private array $answers = [];

    /** @var array<int, SpendConnection> */
    private array $live = [];

    /** @var array<string, mixed>|null the page answered once $answers run out, for a pull that never ends */
    private ?array $repeat = null;

    /** @var string|null a key whose every request throws, as a fault below the client would */
    private ?string $throwFor = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
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
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'ss$u', user_email = 'ss$u@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = 'UTC', user_time_register = 0,"
                . " install_hash = '', user_hash = ''");
            self::q("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD'");
        }
        $this->live = [self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_demo', 'USD', self::KEY)];
        $this->urls = [];
        $this->answers = [];
        $this->bodies = [];
        $this->conversionAnswers = [];
        $this->repeat = null;
        $this->throwFor = null;
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_spend_ledger', '202_ad_spend_sync', '202_ad_platform_conversions', '202_dataengine', '202_ppc_networks', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
            self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id IN (64401, 64402)');
            self::q('DELETE FROM 202_custom_variables WHERE custom_variable_id IN (644001, 644002)');
            self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id IN (644001, 644002)');
        }
    }

    private function sync(int $now = self::NOW): SpendSync
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = function (array $options): array {
            if ($this->throwFor !== null && in_array('Authorization: Bearer ' . $this->throwFor, $options[CURLOPT_HTTPHEADER], true)) {
                throw new \RuntimeException('the transport broke');
            }
            $this->urls[] = (string) $options[CURLOPT_URL];
            if (str_contains((string) $options[CURLOPT_URL], '/conversions/insights')) {
                $this->bodies[] = (string) ($options[CURLOPT_POSTFIELDS] ?? '');

                return (array_shift($this->conversionAnswers) ?? ['body' => '{"object":"list","data":[],"count":0}'])
                    + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => ''];
            }

            return (array_shift($this->answers) ?? $this->repeat ?? ['body' => '{"object":"list","data":[],"count":0,"first_id":null,"last_id":null,"has_more":false}'])
                + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => ''];
        };
        $connections = $this->connections($this->live);

        return new SpendSync(new Connection(self::$db), $connections, new InsightsClient(new AdvertiserApi(new WebhookSender($guard, $transport))), static fn (): int => $now);
    }

    /** @param array<int, SpendConnection> $live */
    private function connections(array $live): SpendConnections
    {
        return new class ($live) implements SpendConnections {
            /** @param array<int, SpendConnection> $live */
            public function __construct(private array $live)
            {
            }

            public function find(int $userId, int $connectionId): ?SpendConnection
            {
                $c = $this->live[$connectionId] ?? null;

                return $c !== null && $c->userId === $userId ? $c : null;
            }

            public function ofAccount(int $userId): array
            {
                $out = [];
                foreach ($this->live as $id => $c) {
                    if ($c->userId === $userId) {
                        $out[$id] = $c->accountRef;
                    }
                }

                return $out;
            }
        };
    }

    /** @return array{start: int, end: int} */
    private static function window(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $range = json_decode($query['time_ranges'][0], true);

        return ['start' => $range['start'], 'end' => $range['end']];
    }

    private function pages(int $hour): void
    {
        $this->answers[] = ['body' => InsightsClientTest::fixture('insights-hourly-page-1.json', $hour)];
        $this->answers[] = ['body' => InsightsClientTest::fixture('insights-hourly-page-2.json', $hour)];
    }

    /** @return array<string, array<string, string>> ad@hour => row */
    private static function ledger(): array
    {
        $out = [];
        $result = self::$db->query('SELECT ad_ref, hour_start, campaign_ref, ad_group_ref, impressions, platform_clicks, spend, currency, account_ref'
            . ' FROM 202_spend_ledger WHERE user_id = ' . self::USER . ' ORDER BY hour_start, ad_ref');
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $out[$row['ad_ref'] . '@' . $row['hour_start']] = $row;
        }

        return $out;
    }

    public function testNothingIsPulledUntilTheSyncIsTurnedOn(): void
    {
        self::assertSame(['synced' => 0, 'failed' => 0, 'rows' => 0], $this->sync()->runDue(self::NOW + 60));
        self::assertSame([], $this->urls);
        self::assertFalse($this->sync()->status(self::USER)[0]['enabled']);

        // Turned on and off again: still nothing.
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->sync()->setEnabled(self::USER, self::CONNECTION, false);
        self::assertSame(['synced' => 0, 'failed' => 0, 'rows' => 0], $this->sync()->runDue(self::NOW + 60));
        self::assertSame([], $this->urls);
    }

    public function testTheFirstRunReadsThirtyDaysAndLaterRunsTheTrailingSeventyTwoHours(): void
    {
        $hour = self::NOW - self::NOW % 3600 - 7200;
        self::assertTrue($this->sync()->setEnabled(self::USER, self::CONNECTION, true));
        $this->pages($hour);
        $done = $this->sync()->runDue(self::NOW + 60);

        self::assertSame(1, $done['synced'], (string) json_encode($this->sync()->status(self::USER)));
        $pages = array_values(array_filter($this->urls, static fn (string $u): bool => str_contains($u, '/ad_account/insights')));
        self::assertCount(2, $pages, 'both pages read');
        $this->urls = $pages;
        self::assertSame(['start' => self::NOW - self::NOW % 3600 - 30 * 86400, 'end' => self::NOW], self::window($this->urls[0]));
        self::assertStringContainsString('after=' . rawurlencode("start=$hour:end=" . ($hour + 3600) . ':entity_id=ad_502'), $this->urls[1]);

        $ledger = self::ledger();
        // ad_503's spend has seven decimals: left out, by name; the rest kept.
        self::assertSame(["ad_501@$hour", "ad_502@$hour", 'ad_501@' . ($hour + 3600)], array_keys($ledger));
        self::assertSame(['11.250000', '700', '22', 'cmpn_101', 'adgrp_201', 'adacct_demo', 'USD'], [
            $ledger["ad_501@$hour"]['spend'], $ledger["ad_501@$hour"]['impressions'], $ledger["ad_501@$hour"]['platform_clicks'],
            $ledger["ad_501@$hour"]['campaign_ref'], $ledger["ad_501@$hour"]['ad_group_ref'], $ledger["ad_501@$hour"]['account_ref'],
            $ledger["ad_501@$hour"]['currency'],
        ]);
        $state = $this->sync()->status(self::USER)[0];
        self::assertSame(self::NOW, $state['backfilled_at']);
        self::assertSame(3, $state['last_rows']);
        self::assertNull($state['last_error']);
        self::assertStringContainsString('1 row(s) OpenAI sent were left out: ad "ad_503"', (string) $state['last_note']);
        self::assertStringContainsString('spend must have at most six decimals', (string) $state['last_note']);

        // The next hour: 72 hours back, and a settled hour replaces its row.
        $this->urls = [];
        $later = self::NOW + 3600;
        $this->answers[] = ['body' => str_replace('"spend": 11.25', '"spend": 12.5', InsightsClientTest::fixture('insights-hourly-page-1.json', $hour))];
        $this->answers[] = ['body' => InsightsClientTest::fixture('insights-hourly-page-2.json', $hour)];
        $this->sync($later)->runDue($later + 60);
        self::assertSame(['start' => $later - $later % 3600 - 72 * 3600, 'end' => $later], self::window($this->urls[0]));
        $ledger = self::ledger();
        self::assertCount(3, $ledger, 'replaced, not added');
        self::assertSame('12.500000', $ledger["ad_501@$hour"]['spend']);
    }

    public function testAnAdAccountInAnotherCurrencyIsRefusedByNameAndNothingIsPulled(): void
    {
        $this->live = [self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_demo', 'EUR', self::KEY)];
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $done = $this->sync()->runDue(self::NOW + 60);

        self::assertSame(1, $done['failed']);
        self::assertSame([], $this->urls, 'nothing asked of OpenAI');
        self::assertSame([], self::ledger());
        $state = $this->sync()->status(self::USER)[0];
        self::assertStringContainsString('billed in EUR and this Prosper202 account records money in USD', (string) $state['last_error']);
        self::assertNull($state['backfilled_at']);
    }

    public function testA429LeavesTheConnectionAloneForAnHourThenTwo(): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->answers[] = ['status' => 429, 'body' => '{}'];
        $this->sync()->runDue(self::NOW + 60);
        $state = $this->sync()->status(self::USER)[0];
        self::assertSame(self::NOW + 3600, $state['retry_after']);
        self::assertStringContainsString('429', (string) $state['last_error']);

        $this->urls = [];
        self::assertSame(0, $this->sync(self::NOW + 1800)->runDue(self::NOW + 1860)['synced'] + count($this->urls), 'left alone');

        $this->answers[] = ['status' => 429, 'body' => '{}'];
        $this->sync(self::NOW + 3600)->runDue(self::NOW + 3660);
        self::assertSame(self::NOW + 3600 + 7200, $this->sync()->status(self::USER)[0]['retry_after']);

        // A run that gets through clears the back-off.
        $this->sync(self::NOW + 3600 + 7200)->runDue(self::NOW + 3600 + 7260);
        $state = $this->sync()->status(self::USER)[0];
        self::assertNull($state['retry_after']);
        self::assertNull($state['last_error']);
    }

    public function testAConnectionThatIsGoneTurnsItsSyncOff(): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->live = [];
        // Another account's connection by the same id is not this sync's.
        $this->live[self::CONNECTION] = new SpendConnection(self::CONNECTION, self::OTHER, 'adacct_other', 'USD', 'sk-other');
        self::assertSame(1, $this->sync()->runDue(self::NOW + 60)['failed']);
        self::assertSame([], $this->urls);
        $row = self::$db->query('SELECT enabled, last_error FROM 202_ad_spend_sync WHERE connection_id = ' . self::CONNECTION)->fetch_assoc();
        self::assertSame('0', $row['enabled']);
        self::assertStringContainsString('was removed', (string) $row['last_error']);
        self::assertFalse($this->sync()->setEnabled(self::USER, self::CONNECTION, true), 'not this account\'s to turn on');
    }

    public function testPlatformConversionsAreReadPerAdAndDayBesideProsper202s(): void
    {
        $hour = self::NOW - self::NOW % 3600 - 7200;
        $day = gmdate('Y-m-d', $hour);
        $this->live = [self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_demo', 'USD', self::KEY, 'UTC')];
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->pages($hour);
        $this->conversionAnswers[] = ['status' => 413, 'body' => '{}'];
        $this->conversionAnswers[] = ['body' => str_replace('@D0@', $day, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/openai-ads/conversions-daily.json'))];
        $this->sync()->runDue(self::NOW + 60);

        // Asked daily, per ad, over whole UTC days; a 413 asks again for fewer ads.
        self::assertCount(3, $this->bodies);
        $first = json_decode($this->bodies[0], true);
        self::assertSame(['ad', 'daily', true], [$first['aggregation_level'], $first['time_granularity'], $first['group_by_entity']]);
        self::assertSame(['ad_501', 'ad_502'], $first['entity_ids']);
        $range = json_decode($first['time_ranges'][0], true);
        self::assertSame(0, $range['start'] % 86400);
        self::assertSame(['ad_501'], json_decode($this->bodies[1], true)['entity_ids'], 'halved after the 413');

        self::assertSame(['openai_ads'], array_column(self::$db->query('SELECT DISTINCT platform FROM 202_spend_ledger WHERE user_id = ' . self::USER)->fetch_all(MYSQLI_ASSOC), 'platform'));

        // A click of the ChatGPT Ads source and one of another source both
        // carried ad_501 that day and converted: only the first is OpenAI's
        // ad (LedgerCost::SOURCE_PLATFORMS), as for its spend.
        foreach ([[64401, 'chatgpt_ads', 644001, 6440001], [64402, '', 644002, 6440002]] as [$network, $mark, $set, $click]) {
            self::q("INSERT INTO 202_ppc_networks SET ppc_network_id = $network, user_id = " . self::USER . ", ppc_network_name = 'Source $network',"
                . " ppc_network_platform = '$mark', ppc_network_time = 0");
            self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = $network, ppc_network_id = $network, name = 'ad_id', parameter = 'ad_id', placeholder = '{ad_id}'");
            self::q("INSERT INTO 202_custom_variables SET custom_variable_id = $set, ppc_variable_id = $network, variable = 'ad_501'");
            self::q("INSERT INTO 202_variable_sets2 SET variable_set_id = $set, variables = '$set'");
            self::q('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ", click_id = $click, click_time = " . ($hour + 60)
                . ", ppc_network_id = $network, ppc_account_id = 1, aff_campaign_id = 1, landing_page_id = 0, keyword_id = 0,"
                . " variable_set_id = '$set', clicks = 1, click_out = 1, leads = 1, click_lead = 1, click_filtered = 0,"
                . ' click_bot = 0, payout = 10, income = 10, cost = 0.1');
        }
        $read = (new \Prosper202\Spend\PlatformConversions(new Connection(self::$db)))->read(self::USER, $day, $day, null, 50);
        $byAd = array_column($read['rows'], null, 'ad_ref');
        self::assertSame([3, 2, 1, 129.5, 'USD'], [$byAd['ad_501']['platform_conversions'], $byAd['ad_501']['click_through'],
            $byAd['ad_501']['view_through'], $byAd['ad_501']['sales'], $byAd['ad_501']['sales_currency']]);
        self::assertNull($byAd['ad_502']['sales']);
        self::assertSame([1, 0], [$byAd['ad_501']['prosper202_conversions'], $byAd['ad_502']['prosper202_conversions']]);
        self::assertStringContainsString("conversion row(s) OpenAI sent were left out: row 2: ad ad_501: date is not a day", (string) $this->sync()->status(self::USER)[0]['last_note']);
    }

    public function testSalesWithMoreDecimalsThanTheColumnAreRoundedAndTheRowKept(): void
    {
        $hour = self::NOW - self::NOW % 3600 - 7200;
        $day = gmdate('Y-m-d', $hour);
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->pages($hour);
        $this->conversionAnswers[] = ['body' => (string) json_encode(['object' => 'list', 'data' => [
            ['entity_id' => 'ad_501', 'date' => $day, 'conversions' => 4, 'click_through_conversions' => 3, 'view_through_conversions' => 1,
                'order_created_attributed_sales' => '12.3456789', 'order_created_attributed_sales_currency' => 'USD'],
            ['entity_id' => 'ad_502', 'date' => $day, 'conversions' => 1, 'order_created_attributed_sales' => '0.0000005',
                'order_created_attributed_sales_currency' => 'USD'],
        ]])];
        $this->sync()->runDue(self::NOW + 60);

        $stored = self::$db->query('SELECT ad_ref, conversions, click_through, sales FROM 202_ad_platform_conversions WHERE user_id = ' . self::USER . ' ORDER BY ad_ref')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['ad_ref' => 'ad_501', 'conversions' => '4', 'click_through' => '3', 'sales' => '12.345679'],
            ['ad_ref' => 'ad_502', 'conversions' => '1', 'click_through' => null, 'sales' => '0.000001']], $stored);
        self::assertStringNotContainsString('conversion row', (string) $this->sync()->status(self::USER)[0]['last_note']);
    }

    public function testTheConversionsWindowEndsAtTheLastMidnightNotInTheFuture(): void
    {
        $zone = 'America/New_York';
        $this->live = [self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_demo', 'USD', self::KEY, $zone)];
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->pages(self::NOW - self::NOW % 3600 - 7200);
        $this->sync()->runDue(self::NOW + 60);

        $range = json_decode(json_decode($this->bodies[0], true)['time_ranges'][0], true);
        $midnight = (new \DateTimeImmutable('@' . self::NOW))->setTimezone(new \DateTimeZone($zone))->setTime(0, 0)->getTimestamp();
        self::assertSame($midnight, $range['end'], 'the last local midnight, a whole day and not after now');
        self::assertLessThanOrEqual(self::NOW, $range['end']);
        self::assertSame('00:00:00', (new \DateTimeImmutable('@' . $range['start']))->setTimezone(new \DateTimeZone($zone))->format('H:i:s'), 'whole days');
    }

    public function testRetryAfterLongerThanTheBackOffIsWaitedOut(): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->answers[] = ['status' => 429, 'body' => '{}', 'retry_after' => '10800'];
        $this->sync()->runDue(self::NOW + 60);
        self::assertSame(self::NOW + 10800, $this->sync()->status(self::USER)[0]['retry_after'], 'Retry-After, over the first hour');

        $later = self::NOW + 10800;
        $this->answers[] = ['status' => 429, 'body' => '{}', 'retry_after' => gmdate('D, d M Y H:i:s \\G\\M\\T', $later + 60)];
        $this->sync($later)->runDue($later + 60);
        self::assertSame($later + 7200, $this->sync()->status(self::USER)[0]['retry_after'], 'the back-off, over a shorter Retry-After');
    }

    public function testOneConnectionsFaultIsRecordedOnItAndTheNextStillRuns(): void
    {
        $this->live = [
            self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_broken', 'USD', 'sk-adv-broken-key'),
            self::CONNECTION + 1 => new SpendConnection(self::CONNECTION + 1, self::USER, 'adacct_demo', 'USD', self::KEY),
        ];
        $this->throwFor = 'sk-adv-broken-key';
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->sync()->setEnabled(self::USER, self::CONNECTION + 1, true);
        $this->pages(self::NOW - self::NOW % 3600 - 7200);

        self::assertSame(['synced' => 1, 'failed' => 1, 'rows' => 3], $this->sync()->runDue(self::NOW + 60));
        $states = array_column($this->sync()->status(self::USER), null, 'connection_id');
        self::assertSame('the run stopped: RuntimeException: the transport broke', $states[self::CONNECTION]['last_error']);
        self::assertSame(self::NOW, $states[self::CONNECTION]['last_run_at']);
        self::assertNull($states[self::CONNECTION + 1]['last_error']);
        self::assertCount(3, self::ledger());
    }

    public function testACursorOpenAiAlreadyGaveStopsThePull(): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->repeat = ['body' => InsightsClientTest::fixture('insights-hourly-page-1.json', self::NOW - self::NOW % 3600 - 7200)];
        self::assertSame(1, $this->sync()->runDue(self::NOW + 60)['failed']);
        self::assertStringContainsString('handed back a cursor it had already given', (string) $this->sync()->status(self::USER)[0]['last_error']);
        self::assertCount(2, array_filter($this->urls, static fn (string $u): bool => str_contains($u, '/ad_account/insights')), 'stopped at the first repeat');
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function conversionFailuresThatFailTheRun(): iterable
    {
        yield 'rate limited' => [429, 'OpenAI answered 429', true];
        yield 'key refused' => [401, 'refused the Advertiser API key', false];
    }

    /** @dataProvider conversionFailuresThatFailTheRun */
    public function testAConversions429OrRefusedKeyFailsTheRunAsTheSpendPullWould(int $status, string $said, bool $waits): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->pages(self::NOW - self::NOW % 3600 - 7200);
        $this->conversionAnswers[] = ['status' => $status, 'body' => '{}'];
        self::assertSame(1, $this->sync()->runDue(self::NOW + 60)['failed']);
        $state = $this->sync()->status(self::USER)[0];
        self::assertStringContainsString($said, (string) $state['last_error']);
        self::assertStringNotContainsString('conversions not read', (string) $state['last_note']);
        self::assertSame($waits ? self::NOW + 3600 : null, $state['retry_after']);
    }

    public function testConversionsOpenAiRefusesAreANoteAndTheSpendStands(): void
    {
        $this->sync()->setEnabled(self::USER, self::CONNECTION, true);
        $this->pages(self::NOW - self::NOW % 3600 - 7200);
        $this->conversionAnswers[] = ['status' => 400, 'body' => (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/openai-ads/error-invalid-request.json')];
        self::assertSame(1, $this->sync()->runDue(self::NOW + 60)['synced']);
        $state = $this->sync()->status(self::USER)[0];
        self::assertNull($state['last_error']);
        self::assertStringContainsString('conversions not read: OpenAI answered 400: time_ranges must not be in the future', (string) $state['last_note']);
        self::assertCount(3, self::ledger());
    }
    /**
     * The sync the cron runs (SpendSync's own client, its limits in the
     * install's state store) against the stand-in P202_OPENAI_ADS_ENDPOINT
     * names: both of its requests reach the stand-in, which refuses a bound
     * in the future and a part day as OpenAI documents.
     */
    public function testTheCronsSyncReachesTheEndpointTheEnvironmentNames(): void
    {
        $tls = sys_get_temp_dir() . '/p202-fake-openai-sync-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $state = $tls . '-state';
        $process = proc_open(['python3', dirname(__DIR__, 2) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0', '--tls-dir', $tls, '--api-key', self::KEY],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) !== 1) {
            self::markTestSkipped('The fake Advertiser API did not start (python3 and openssl are needed).');
        }
        $origin = 'https://127.0.0.1:' . $m[1];
        $before = [AdvertiserApi::ENV_ENDPOINT => getenv(AdvertiserApi::ENV_ENDPOINT), AdvertiserApi::ENV_CA_FILE => getenv(AdvertiserApi::ENV_CA_FILE),
            'P202_SERVER_STATE_DIR' => getenv('P202_SERVER_STATE_DIR')];
        putenv(AdvertiserApi::ENV_ENDPOINT . '=' . $origin);
        putenv(AdvertiserApi::ENV_CA_FILE . '=' . $tls . '/cert.pem');
        putenv('P202_SERVER_STATE_DIR=' . $state);
        try {
            $this->live = [self::CONNECTION => new SpendConnection(self::CONNECTION, self::USER, 'adacct_123', 'USD', self::KEY, 'America/New_York')];
            $sync = new SpendSync(new Connection(self::$db), $this->connections($this->live));
            $sync->setEnabled(self::USER, self::CONNECTION, true);
            $done = $sync->runDue(time() + 60);

            $status = $sync->status(self::USER)[0];
            self::assertSame(1, $done['synced'], (string) json_encode($status));
            self::assertNull($status['last_note'], 'the conversions were read: no bound in the future, whole days');
            self::assertSame(12, $done['rows'], 'six hours of two ads');
            $seen = json_decode((string) file_get_contents($origin . '/control/requests', false, stream_context_create(['ssl' => ['cafile' => $tls . '/cert.pem']])), true);
            self::assertSame(['GET /v1/ad_account/insights', 'POST /v1/conversions/insights'],
                array_values(array_unique(array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $seen['requests']))));
            $sales = self::$db->query('SELECT DISTINCT sales FROM 202_ad_platform_conversions WHERE user_id = ' . self::USER)->fetch_all(MYSQLI_ASSOC);
            self::assertSame([['sales' => '12.345679']], $sales);

            // An endpoint off this machine is refused by name and nothing is sent anywhere.
            putenv(AdvertiserApi::ENV_ENDPOINT . '=https://api.ads.openai.com.example');
            $other = new SpendSync(new Connection(self::$db), $this->connections($this->live));
            self::assertSame(1, $other->run($this->live[self::CONNECTION])['error'] === null ? 0 : 1);
            self::assertStringStartsWith('not sent: ' . AdvertiserApi::ENV_ENDPOINT . ' must be', (string) $other->status(self::USER)[0]['last_error']);
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            proc_terminate($process);
            proc_close($process);
            exec('rm -rf ' . escapeshellarg($tls) . ' ' . escapeshellarg($state));
        }
    }
}
