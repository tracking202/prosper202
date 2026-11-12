<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\Redactor;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;

/**
 * Setup › Inbound Log, Conversion Logs' download and the cron health
 * reading, each run as an account in a process of its own
 * (fixtures/page-runner.php): "Record it now" behind its form token and the
 * Update permission, a search that covers only what the account may read,
 * a reversal asked about as a reversal, a CSV whose cells a spreadsheet
 * will not run, and a health answer that survives an unreadable queue.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class InboundLogPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990541;
    /** May open Setup, not Update. */
    private const READER = 990542;
    /** Manages users: sees every account's requests. */
    private const ADMIN = 990543;
    private const ROLE = 990541;
    private const READER_ROLE = 990542;
    private const ADMIN_ROLE = 990543;
    private const CLICK = 99054101;
    private const CAMPAIGN = 990591;
    private const ARRIVED = 1_793_000_000;
    private const LOG = 'tracking202/setup/inbound_log.php';
    private const FORMULA = '=HYPERLINK("https://demo.example/")';

    private static string $sessions = '';

    /** @var list<string>|null */
    private static ?array $versions = null;

    /** @var list<int> */
    private static array $addedPermissions = [];

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
        require_once $root . '/202-config/static-endpoint-helpers.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        $roles = [
            self::USER => [self::ROLE, ['access_to_setup_section', 'access_to_update_section', 'access_to_campaign_data']],
            self::READER => [self::READER_ROLE, ['access_to_setup_section']],
            self::ADMIN => [self::ADMIN_ROLE, ['access_to_setup_section', 'access_to_update_section', 'add_users']],
        ];
        foreach ($roles as $user => [$role, $permissions]) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
            self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner', install_hash = 'h$user' WHERE user_id = $user");
            self::row('202_roles', ['role_id' => $role, 'role_name' => 'inbound log test ' . $role]);
            foreach ($permissions as $name) {
                $grant = "permission_description = '$name'";
                $read = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $grant");
                $permission = $read instanceof \mysqli_result ? ($read->fetch_row()[0] ?? null) : null;
                if ($permission === null) {
                    $permission = self::insert("INSERT INTO 202_permissions SET $grant");
                    self::$addedPermissions[] = $permission;
                }
                self::row('202_role_permission', ['role_id' => $role, 'permission_id' => (int) $permission]);
            }
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
        }
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ", aff_network_id = 1, aff_campaign_name = 'Demo coffee store',"
            . " aff_campaign_url = 'https://demo-coffee.example/?sid=[[subid]]',"
            . ' aff_campaign_payout = 20, aff_campaign_time = 1, aff_campaign_foreign_payout = 20');
        self::$sessions = sys_get_temp_dir() . '/p202-uo-inbound-pages-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            foreach (self::$addedPermissions as $permission) {
                self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
            }
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
        $db = self::requireScratch();
        self::cleanRows();
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: self::CLICK,
            userId: self::USER,
            clickTime: self::ARRIVED - 600,
            affCampaignId: self::CAMPAIGN,
            clickPayout: '20.00',
            recordsCampaignRows: false,
        ));
    }

    private static function cleanRows(): void
    {
        $users = self::USER . ', ' . self::READER . ', ' . self::ADMIN;
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ')');
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks', '202_dataengine',
            '202_conversion_logs', '202_google'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
        self::q("DELETE FROM 202_notification_pending WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_destination_deliveries WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_destinations WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_inbound_log WHERE user_id IN (0, $users)");
        self::q("DELETE FROM 202_postback_auth WHERE user_id IN ($users)");
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . " = '' WHERE user_id IN ($users)");
    }

    private static function cleanUp(): void
    {
        self::cleanRows();
        foreach ([self::USER, self::READER, self::ADMIN] as $u) {
            foreach (['202_aff_campaigns', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
        foreach ([self::ROLE, self::READER_ROLE, self::ADMIN_ROLE] as $role) {
            self::q('DELETE FROM 202_role_permission WHERE role_id = ' . $role);
            self::q('DELETE FROM 202_roles WHERE role_id = ' . $role);
        }
    }

    /** A gpb postback refused for its token, as gpb.php leaves it; answers its id. */
    private static function refusedPostback(array $fields, int $user = self::USER): int
    {
        self::assertTrue((new InboundLog(self::requireScratch()))->append(new Entry(
            'gpb', 'GET', 403, 'unauthenticated', 'This account accepts only authenticated postbacks and pixels.', $user,
            self::CLICK, null, null, Redactor::fields($fields, 'gpb'), 'none', 'click_id',
        ), self::ARRIVED));

        return (int) self::$db->insert_id;
    }

    /** @return list<array<string, string>> */
    private static function conversions(): array
    {
        $read = self::$db->query('SELECT transaction_id, click_payout, conv_time FROM 202_conversion_logs WHERE click_id = ' . self::CLICK);

        return $read->fetch_all(MYSQLI_ASSOC);
    }

    public function testRecordItNowAsksForTheFormsTokenAndTheUpdateSection(): void
    {
        $id = self::refusedPostback(['subid' => (string) self::CLICK, 'amount' => '18.40', 'txid' => self::FORMULA]);

        $expired = self::decoded(self::page(self::LOG, ['inbound_id' => (string) $id, 'token' => 'not-the-token']));
        self::assertStringContainsString('That was not recorded: the page had expired.', $expired);
        $noToken = self::decoded(self::page(self::LOG, ['inbound_id' => (string) $id]));
        self::assertStringContainsString('That was not recorded: the page had expired.', $noToken);
        self::refusedPostback(['subid' => (string) self::CLICK], self::READER);
        $reader = self::decoded(self::page(self::LOG, ['inbound_id' => (string) $id, 'token' => 'SESSION'], self::READER));
        self::assertStringContainsString('Your role may not record conversions, so nothing was recorded.', $reader);
        self::assertStringNotContainsString('Record it now</button>', self::page(self::LOG, [], self::READER),
            'a role that may not record is not offered the button');
        self::assertSame([], self::conversions());

        $done = self::decoded(self::page(self::LOG, ['inbound_id' => (string) $id, 'token' => 'SESSION']));

        self::assertStringContainsString('Recorded as conversion', $done);
        self::assertSame([[ 'transaction_id' => self::FORMULA, 'click_payout' => '18.40000', 'conv_time' => (string) self::ARRIVED]],
            self::conversions());
        $again = self::decoded(self::page(self::LOG, ['inbound_id' => (string) $id, 'token' => 'SESSION']));
        self::assertStringContainsString('recorded from the log already', $again);
        self::assertCount(1, self::conversions());
    }

    public function testAReversalIsAskedAboutAsAReversal(): void
    {
        $reversal = self::refusedPostback(['subid' => (string) self::CLICK, 'status' => 'reversed', 'txid' => 'ORD-7']);

        // With no sale under ORD-7 the page says the reversal will be refused, which is what recording it does.
        $page = self::decoded(self::page(self::LOG));
        self::assertStringContainsString('a reversal of transaction ORD-7', $page);
        self::assertStringContainsString('No sale with transaction id ORD-7 is on file on this click, so it will be refused', $page);
        self::assertStringNotContainsString('Record this reversal now?', $page);

        // Once the sale is on file, it is asked as taking money off that sale.
        p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID,
            ['user_id' => self::USER, 'transaction_id' => 'ORD-7', 'conv_time' => self::ARRIVED - 60]);
        $page = self::decoded(self::page(self::LOG));
        self::assertStringContainsString('Record this reversal now? It takes money off a sale already recorded.', $page);
        self::assertStringContainsString('name="inbound_id" value="' . $reversal . '"', $page);
    }

    public function testTheQuestionNamesAnAdsDestinationTheClickIsTooOldFor(): void
    {
        // The click was made 100 days ago with a gclid, and the account sends conversions to Google Ads.
        $clicked = time() - 100 * 86400;
        foreach (['202_clicks', '202_clicks_spy', '202_click_latest_visits'] as $table) {
            self::q("UPDATE $table SET click_time = $clicked WHERE click_id = " . self::CLICK);
        }
        self::q('INSERT INTO 202_google SET click_id = ' . self::CLICK . ", gclid = 'Cj0KCQ-demo', utm_source_id = 0, utm_medium_id = 0,"
            . ' utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0');
        self::q('INSERT INTO 202_destinations SET user_id = ' . self::USER . ", type = 'google_ads', name = 'Search ads', enabled = 1,"
            . " settings = '{}', credentials = '', created_at = 1, updated_at = 1");
        self::refusedPostback(['subid' => (string) self::CLICK, 'txid' => 'ORD-9']);

        $page = self::decoded(self::page(self::LOG));

        self::assertStringContainsString('Google Ads takes a conversion up to 90 days after its click, and this click is older,'
            . ' so Search ads does not receive it.', $page);

        self::q('UPDATE 202_destinations SET enabled = 0 WHERE user_id = ' . self::USER);
        self::assertStringNotContainsString('Search ads does not receive', self::decoded(self::page(self::LOG)),
            'a switched-off destination is not named');
    }

    public function testASearchCoversOnlyWhatTheAccountMayRead(): void
    {
        // The account stores a Shopify secret, so it is shown the webhooks no secret verified, without their values.
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . " = 'demo-store-secret' WHERE user_id = " . self::USER);
        (new InboundLog(self::requireScratch()))->append(new Entry('shopify', 'POST', 401, 'unverified', 'No stored secret verifies it.',
            0, null, null, '5550001234', ['order_id' => '5550001234']), self::ARRIVED);
        $own = self::refusedPostback(['subid' => (string) self::CLICK, 'txid' => '5550001234']);
        $rows = static fn (string $html): int => substr_count($html, '<tr class="table-warning">');

        self::assertSame(2, $rows(self::page(self::LOG)), 'listed beside the account\'s own row');
        $search = self::page(self::LOG, [], self::USER, ['search' => '5550001234']);
        self::assertSame(1, $rows($search), 'a search reads only the account\'s own rows');
        self::assertStringContainsString('name="inbound_id" value="' . $own . '"', $search);
        // An admin's view is the admin's own rows and the ones no account was found for; its search covers that view.
        $admin = self::decoded(self::page(self::LOG, [], self::ADMIN, ['search' => '5550001234']));
        self::assertSame(1, $rows($admin));
        self::assertStringContainsString('Shopify webhook', $admin);
    }

    /** @return list<list<string>> the download's lines */
    private static function csv(array $constants = []): array
    {
        $csv = self::page('tracking202/logs/index.php', [], self::USER, ['range' => 'alltime', 'download' => 'csv'], $constants);
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => $l !== '')));
        self::assertSame('conversion_id', $lines[0][0] ?? null, substr($csv, 0, 300));

        return $lines;
    }

    public function testConversionLogsDownloadsCellsASpreadsheetWillNotRun(): void
    {
        // A campaign name and a visitor's user agent can be anything: each is written so a spreadsheet will not run it.
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_name = ' . self::str(self::FORMULA) . ' WHERE aff_campaign_id = ' . self::CAMPAIGN);
        try {
            p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID,
                ['user_id' => self::USER, 'transaction_id' => self::FORMULA, 'user_agent' => self::FORMULA, 'conv_time' => self::ARRIVED]);

            $lines = self::csv();

            self::assertCount(2, $lines, 'the header and the one conversion');
            $row = array_combine($lines[0], $lines[1]);
            self::assertSame(["'" . self::FORMULA, "'" . self::FORMULA, "'" . self::FORMULA, (string) self::CLICK],
                [$row['campaign'], $row['transaction_id'], $row['user_agent'], $row['subid']]);
        } finally {
            self::q("UPDATE 202_aff_campaigns SET aff_campaign_name = 'Demo coffee store' WHERE aff_campaign_id = " . self::CAMPAIGN);
        }

        $list = self::page('tracking202/logs/index.php', [], self::USER, ['range' => 'alltime']);
        self::assertStringContainsString('inbound_log.php?click_id=' . self::CLICK . '">its inbound requests</a>', $list);
    }

    public function testADownloadLongerThanItsCapSaysSo(): void
    {
        foreach (['ORD-1' => self::ARRIVED - 60, 'ORD-2' => self::ARRIVED] as $transaction => $at) {
            p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID,
                ['user_id' => self::USER, 'transaction_id' => $transaction, 'conv_time' => $at]);
        }

        $whole = self::csv();
        $cut = self::csv(['P202_CONVERSION_LOGS_CSV_MAX' => 1]);

        self::assertCount(3, $whole, 'the header and both conversions, and no note');
        self::assertCount(3, $cut, 'the header, the newest conversion, and the note');
        self::assertSame('ORD-2', array_combine($cut[0], $cut[1])['transaction_id']);
        self::assertSame(['This file holds the newest conversion of a longer list. Narrow the dates or a filter to download the rest.'],
            $cut[2]);
    }

    public function testHealthIsAnsweredWhenThePostbackQueueCannotBeRead(): void
    {
        self::q('RENAME TABLE 202_notification_pending TO 202_notification_pending_away');
        try {
            $answer = json_decode(self::page('202-cronjobs/health.php', [], self::ADMIN), true);
        } finally {
            self::q('RENAME TABLE 202_notification_pending_away TO 202_notification_pending');
        }

        self::assertIsArray($answer);
        self::assertArrayHasKey('status', $answer);
        self::assertArrayNotHasKey('error', $answer);
        self::assertArrayHasKey('trafficSourcePostbacks', $answer);
        self::assertNull($answer['trafficSourcePostbacks'], 'not known is null, never "nothing waiting"');
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $get
     */
    private static function page(string $page, array $post = [], int $user = self::USER, array $get = [], array $constants = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_CONSTANTS' => (string) json_encode((object) $constants),
            'P202_TEST_REPORT_USER' => (string) $user,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                '-d', 'log_errors=0', __DIR__ . '/fixtures/page-runner.php', $page,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning:', $err, $err);

        return $out;
    }

    private static function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
