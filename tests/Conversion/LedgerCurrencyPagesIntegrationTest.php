<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * The pages show each conversion in the currency it recorded, say when a
 * period's revenue is in more than one, and say what changing the account's
 * currency does before it is saved: the conversion log, the click's
 * conversions panel, a report fragment and Personal settings, each run as
 * the web server runs it (tests/Auth/fixtures/session-user-runner.php) for
 * an account that counts in EUR and holds one conversion recorded in USD
 * and one with no currency recorded.
 *
 * @group integration
 */
final class LedgerCurrencyPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990871;
    private const ADMIN = 2;
    private const CLICK = 99087101;
    private const CAMPAIGN = 990871;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-session-currency-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        // Fixture rows leave out the columns the tracking code fills.
        self::q("SET SESSION sql_mode=''");
        $now = time();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'currency-pages',"
            . " user_email = 'currency-pages@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . " p202_customer_api_key = 'currency-pages-runner'");
        self::row('202_users_pref', ['user_id' => self::USER, 'user_account_currency' => 'EUR', 'user_pref_time_predefined' => 'alltime']);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ADMIN]);
        self::q("INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)"
            . " VALUES (SHA2('currency-pages-runner', 256), 1, UNIX_TIMESTAMP() - 3600)");
        // An accumulating campaign: both conversions count toward the click.
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ", aff_network_id = 1, aff_campaign_name = 'store', aff_campaign_url = 'http://x', aff_campaign_payout = 10,"
            . " aff_campaign_time = 1, aff_campaign_foreign_payout = 0, payout_mode = 'accumulate'");
        self::q('INSERT INTO 202_clicks SET click_id = ' . self::CLICK . ', user_id = ' . self::USER
            . ', aff_campaign_id = ' . self::CAMPAIGN . ", click_payout = 17.5, click_cpc = 0, click_lead = 1, click_time = $now");
        foreach ([['A', '12.50', 'USD'], ['B', '5.00', '']] as [$tx, $amount, $currency]) {
            self::q('INSERT INTO 202_conversion_logs SET click_id = ' . self::CLICK . ", transaction_id = '$tx', campaign_id = " . self::CAMPAIGN
                . ", click_payout = $amount, user_id = " . self::USER . ", click_time = $now, conv_time = $now, time_difference = '',"
                . " ip = '', pixel_type = 2, user_agent = '', source = 'postback', dedupe_key = 'tx:$tx', match_method = 'click_id',"
                . " currency = '$currency'");
        }
    }

    private static function cleanUp(): void
    {
        foreach (['202_user_role', '202_users_pref', '202_users', '202_conversion_logs', '202_clicks', '202_aff_campaigns', '202_destination_deliveries'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q("DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2('currency-pages-runner', 256)");
    }

    /**
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(string $page, array $env = []): array
    {
        $env += ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php', $page],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + getenv()
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not start the request:\n$stdout$stderr");
        $at = strrpos($stdout, "\n@@RESULT ");
        self::assertNotFalse($at, "the request left no result line:\n$stdout$stderr");

        return [
            'output' => substr($stdout, 0, $at) . $stderr,
            'result' => json_decode(substr($stdout, $at + strlen("\n@@RESULT ")), true, 8, JSON_THROW_ON_ERROR),
        ];
    }

    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    public function testTheConversionLogShowsEachAmountInItsOwnCurrency(): void
    {
        $page = self::request('tracking202/logs/index.php', ['P202_SU_GET' => '{"range":"alltime"}']);

        self::assertNull($page['result']['error'] ?? null, $page['output']);
        $text = self::text($page['output']);
        self::assertStringContainsString('$12.50', $text, 'the USD amount in USD, on an account that counts in EUR');
        self::assertStringContainsString('in USD, not converted to EUR', $text);
        self::assertStringContainsString('currency not recorded', $text);
        self::assertStringNotContainsString('€12.50', $text, 'never under the account\'s currency');
    }

    public function testTheClicksConversionsPanelShowsEachRowsCurrencyAndThatTheValueMixesThem(): void
    {
        $page = self::request('tracking202/ajax/click_conversions.php', ['P202_SU_GET' => '{"click_id":"' . self::CLICK . '"}']);

        self::assertNull($page['result']['error'] ?? null, $page['output']);
        $text = self::text($page['output']);
        self::assertStringContainsString('$12.50', $text);
        self::assertStringContainsString('5.00 (currency not recorded)', $text);
        self::assertStringContainsString('more than one currency', $text);
    }

    public function testAReportSaysHowMuchOfThePeriodIsInOtherCurrencies(): void
    {
        $page = self::request('tracking202/ajax/sort_breakdown.php');

        self::assertNull($page['result']['error'] ?? null, $page['output']);
        $text = self::text($page['output']);
        self::assertStringContainsString('Not all revenue in this period is in EUR', $text);
        self::assertStringContainsString('USD 12.50 (1 conversion)', $text);
        self::assertStringContainsString('5.00 with no currency recorded (1 conversion)', $text);

        // With every conversion in the account's currency, a report says nothing.
        self::q("UPDATE 202_conversion_logs SET currency = 'EUR' WHERE user_id = " . self::USER);
        $page = self::request('tracking202/ajax/sort_breakdown.php');
        self::assertStringNotContainsString('Not all revenue', self::text($page['output']));
    }

    /**
     * A download and a chart are read without the page around them, so each
     * says in itself what its money adds: every row of a report download
     * ends with the account's currency and what else the period holds, the
     * Group Overview download says it in its heading, and the chart in its
     * subtitle, on the Overview and when it is redrawn.
     */
    public function testADownloadAndAChartSayTheCurrenciesTheirMoneyAddsWithoutThePage(): void
    {
        $others = 'USD 12.50 (1 conversion); 5.00 with no currency recorded (1 conversion)';
        $now = time();
        self::q("INSERT INTO 202_keywords SET keyword_id = 99087, keyword = 'espresso beans'");
        self::q('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ', click_id = ' . self::CLICK . ", click_time = $now,"
            . ' aff_campaign_id = ' . self::CAMPAIGN . ', keyword_id = 99087, clicks = 1, click_out = 1, leads = 1, income = 17.5, cost = 0, payout = 10');
        try {
            $download = self::request('tracking202/analyze/keywords_download.php');
            self::assertNull($download['result']['error'] ?? null, $download['output']);
            $lines = array_values(array_filter(explode("\n", $download['output']), static fn (string $l): bool => str_contains($l, "\t")));
            self::assertStringEndsWith("\tROI\tCurrency\tOther currencies in period", $lines[0]);
            self::assertGreaterThanOrEqual(2, count($lines), 'the keyword\'s row is in the file');
            self::assertStringStartsWith('espresso beans' . "\t", $lines[1]);
            self::assertStringEndsWith("\tEUR\t" . $others, $lines[1], 'each row says what its money is in');

            $group = self::request('tracking202/overview/group_overview_download.php');
            self::assertNull($group['result']['error'] ?? null, $group['output']);
            self::assertStringContainsString(
                'Currency: EUR. Other currencies in this period, added as recorded and not converted: ' . $others,
                $group['output']
            );

            $note = 'Money lines add EUR and, unconverted: USD 12.50; 5.00 unrecorded';
            $overview = self::request('tracking202/ajax/account_overview.php');
            self::assertNull($overview['result']['error'] ?? null, $overview['output']);
            self::assertSame(1, preg_match('/data-p202-chart="([^"]*)"/', $overview['output'], $m), 'the Overview draws its chart');
            $config = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($note, $config['subtitle']['text']);

            $redrawn = self::request('tracking202/ajax/charts.php', ['P202_SU_POST' => '{"chart_time_range":"days","token":"SESSION"}', 'P202_SU_AJAX' => '1']);
            $json = json_decode(trim($redrawn['output']), true);
            self::assertIsArray($json, $redrawn['output']);
            self::assertSame($note, $json['subtitle']);

            // One currency: the columns are there, and say only the currency.
            self::q("UPDATE 202_conversion_logs SET currency = 'EUR' WHERE user_id = " . self::USER);
            $download = self::request('tracking202/analyze/keywords_download.php');
            $lines = array_values(array_filter(explode("\n", $download['output']), static fn (string $l): bool => str_contains($l, "\t")));
            self::assertStringEndsWith("\tEUR\t", $lines[1]);
            $overview = self::request('tracking202/ajax/account_overview.php');
            preg_match('/data-p202-chart="([^"]*)"/', $overview['output'], $m);
            self::assertSame('', json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true)['subtitle']['text']);
        } finally {
            self::q('DELETE FROM 202_dataengine WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_keywords WHERE keyword_id = 99087');
            self::q('DELETE FROM 202_charts WHERE user_id = ' . self::USER);
        }
    }

    public function testPersonalSettingsSaysWhatAChangeDoesBeforeItIsSavedAndRecordsACurrencyOnTheOwnersWord(): void
    {
        $page = self::request('202-account/account.php');
        self::assertNull($page['result']['error'] ?? null, $page['output']);
        $text = self::text($page['output']);
        self::assertStringContainsString('Conversions already recorded are not converted: each keeps the currency it was recorded in', $text);
        self::assertStringContainsString('1 earlier conversion has no currency recorded', $text);

        // What an ad platform was not sent for want of a currency waits on the owner's word too.
        self::q('INSERT INTO 202_destination_deliveries SET user_id = ' . self::USER . ", destination_id = 990871, conv_id = 1, kind = 'conversion',"
            . " status = 'withheld', attempts = 1, next_attempt_at = 0, last_error = "
            . self::str(\Prosper202\Destinations\DestinationQueue::NO_CURRENCY) . ', created_at = 1');

        $saved = self::request('202-account/account.php', [
            'P202_SU_POST' => '{"record_earlier_currency":"1","earlier_before":"2100-01-01","earlier_currency":"GBP","token":"SESSION"}',
        ]);
        self::assertNull($saved['result']['error'] ?? null, $saved['output']);
        $currencies = self::$db->query('SELECT transaction_id, currency FROM 202_conversion_logs WHERE user_id = ' . self::USER . ' ORDER BY transaction_id');
        self::assertInstanceOf(\mysqli_result::class, $currencies);
        self::assertSame([['A', 'USD'], ['B', 'GBP']], $currencies->fetch_all(), 'only the conversion that had none');
        $delivery = self::$db->query('SELECT status, attempts, last_error FROM 202_destination_deliveries WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $delivery);
        self::assertSame([['pending', '0', null]], $delivery->fetch_all(), 'and what was held back for it is queued again');

        // A request with no session token records nothing.
        self::q("UPDATE 202_conversion_logs SET currency = '' WHERE user_id = " . self::USER . " AND transaction_id = 'B'");
        self::request('202-account/account.php', ['P202_SU_POST' => '{"record_earlier_currency":"1","earlier_before":"2100-01-01","earlier_currency":"GBP"}']);
        $kept = self::$db->query('SELECT currency FROM 202_conversion_logs WHERE user_id = ' . self::USER . " AND transaction_id = 'B'");
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame([['']], $kept->fetch_all());

        $gone = self::request('202-account/account.php');
        self::assertStringContainsString('1 earlier conversion has no currency recorded', self::text($gone['output']));

        // A date that is no day records nothing, and says which field.
        $refused = self::request('202-account/account.php', [
            'P202_SU_POST' => '{"record_earlier_currency":"1","earlier_before":"2026-02-30","earlier_currency":"GBP","token":"SESSION"}',
        ]);
        self::assertStringContainsString('Enter the date of the change, as YYYY-MM-DD.', self::text($refused['output']));
        $still = self::$db->query('SELECT currency FROM 202_conversion_logs WHERE user_id = ' . self::USER . " AND transaction_id = 'B'");
        self::assertInstanceOf(\mysqli_result::class, $still);
        self::assertSame([['']], $still->fetch_all());

        // The account's changes of currency are listed, in its zone's dates.
        self::q('DELETE FROM 202_currency_changes WHERE user_id = ' . self::USER);
        self::q('INSERT INTO 202_currency_changes SET user_id = ' . self::USER . ", changed_at = 1767268800, from_currency = 'USD', to_currency = 'EUR'");
        try {
            $listed = self::text(self::request('202-account/account.php')['output']);
            self::assertStringContainsString('Changes of currency: 2026-01-01 from USD to EUR.', $listed);
        } finally {
            self::q('DELETE FROM 202_currency_changes WHERE user_id = ' . self::USER);
        }
    }

    public function testCampaignsPaidInAnotherCurrencyAreListedUntilTheOwnerSwitchesOrKeepsThem(): void
    {
        $campaign = static fn (int $id, string $name): string => 'INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . $id . ', user_id = ' . self::USER
            . ", aff_network_id = 1, aff_campaign_name = '$name', aff_campaign_url = 'https://shop.example', aff_campaign_payout = '9.20',"
            . " aff_campaign_currency = 'USD', aff_campaign_foreign_payout = '10.00', aff_campaign_time = 0, aff_campaign_currency_notice = 1";
        self::q($campaign(990901, 'Coffee <b>store</b>'));
        try {
            $page = self::request('202-account/account.php');
            self::assertNull($page['result']['error'] ?? null, $page['output']);
            self::assertStringContainsString('1 campaign is paid in another currency than your account\'s.', self::text($page['output']));
            self::assertStringContainsString('Coffee &lt;b&gt;store&lt;/b&gt; (USD)', $page['output'], 'the name is escaped');

            // No session token: nothing changes.
            self::request('202-account/account.php', ['P202_SU_POST' => '{"campaign_currency_notice":"switch"}']);
            $kept = self::$db->query('SELECT aff_campaign_currency, aff_campaign_foreign_payout, aff_campaign_currency_notice FROM 202_aff_campaigns WHERE aff_campaign_id = 990901');
            self::assertInstanceOf(\mysqli_result::class, $kept);
            self::assertSame([['USD', '10.00', '1']], $kept->fetch_all());

            $switched = self::request('202-account/account.php', ['P202_SU_POST' => '{"campaign_currency_notice":"switch","token":"SESSION"}']);
            self::assertNull($switched['result']['error'] ?? null, $switched['output']);
            $now = self::$db->query('SELECT aff_campaign_currency, aff_campaign_payout, aff_campaign_foreign_payout, aff_campaign_currency_notice FROM 202_aff_campaigns WHERE aff_campaign_id = 990901');
            self::assertInstanceOf(\mysqli_result::class, $now);
            self::assertSame([['EUR', '9.20', '0.00', '0']], $now->fetch_all(), 'paid in the account\'s currency, its payout as shown');
            self::assertStringNotContainsString('paid in another currency than your account', self::text(self::request('202-account/account.php')['output']));

            // Kept instead: the campaign stays as it is, and the notice goes.
            self::q($campaign(990902, 'Tea store'));
            self::request('202-account/account.php', ['P202_SU_POST' => '{"campaign_currency_notice":"keep","token":"SESSION"}']);
            $tea = self::$db->query('SELECT aff_campaign_currency, aff_campaign_foreign_payout, aff_campaign_currency_notice FROM 202_aff_campaigns WHERE aff_campaign_id = 990902');
            self::assertInstanceOf(\mysqli_result::class, $tea);
            self::assertSame([['USD', '10.00', '0']], $tea->fetch_all());
        } finally {
            self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN (990901, 990902)');
        }
    }
}
