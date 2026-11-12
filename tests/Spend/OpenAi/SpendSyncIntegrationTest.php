<?php

declare(strict_types=1);

namespace Tests\Spend\OpenAi;

use PHPUnit\Framework\TestCase;
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
            self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = 64401');
            self::q('DELETE FROM 202_custom_variables WHERE custom_variable_id = 644001');
            self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id = 644001');
        }
    }

    private function sync(int $now = self::NOW): SpendSync
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->urls[] = (string) $options[CURLOPT_URL];
            if (str_contains((string) $options[CURLOPT_URL], '/conversions/insights')) {
                $this->bodies[] = (string) ($options[CURLOPT_POSTFIELDS] ?? '');

                return (array_shift($this->conversionAnswers) ?? ['body' => '{"object":"list","data":[],"count":0}'])
                    + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => ''];
            }

            return (array_shift($this->answers) ?? ['body' => '{"object":"list","data":[],"count":0,"first_id":null,"last_id":null,"has_more":false}'])
                + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => ''];
        };
        $connections = new class ($this->live) implements SpendConnections {
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

        return new SpendSync(new Connection(self::$db), $connections, new InsightsClient(new WebhookSender($guard, $transport)), static fn (): int => $now);
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

        // One of this account's clicks carried ad_501 that day, and converted.
        self::q('INSERT INTO 202_ppc_networks SET ppc_network_id = 64401, user_id = ' . self::USER . ", ppc_network_name = 'ChatGPT Ads', ppc_network_time = 0");
        self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = 64401, ppc_network_id = 64401, name = 'ad_id', parameter = 'ad_id', placeholder = '{ad_id}'");
        self::q("INSERT INTO 202_custom_variables SET custom_variable_id = 644001, ppc_variable_id = 64401, variable = 'ad_501'");
        self::q("INSERT INTO 202_variable_sets2 SET variable_set_id = 644001, variables = '644001'");
        self::q('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ', click_id = 6440001, click_time = ' . ($hour + 60)
            . ", ppc_network_id = 64401, ppc_account_id = 1, aff_campaign_id = 1, landing_page_id = 0, keyword_id = 0,"
            . " variable_set_id = '644001', clicks = 1, click_out = 1, leads = 1, click_lead = 1, click_filtered = 0,"
            . ' click_bot = 0, payout = 10, income = 10, cost = 0.1');
        $read = (new \Prosper202\Spend\PlatformConversions(new Connection(self::$db)))->read(self::USER, $day, $day, null, 50);
        $byAd = array_column($read['rows'], null, 'ad_ref');
        self::assertSame([3, 2, 1, 129.5, 'USD'], [$byAd['ad_501']['platform_conversions'], $byAd['ad_501']['click_through'],
            $byAd['ad_501']['view_through'], $byAd['ad_501']['sales'], $byAd['ad_501']['sales_currency']]);
        self::assertNull($byAd['ad_502']['sales']);
        self::assertSame([1, 0], [$byAd['ad_501']['prosper202_conversions'], $byAd['ad_502']['prosper202_conversions']]);
        self::assertStringContainsString("conversion row(s) OpenAI sent were left out: row 2: ad ad_501: date is not a day", (string) $this->sync()->status(self::USER)[0]['last_note']);
    }
}
