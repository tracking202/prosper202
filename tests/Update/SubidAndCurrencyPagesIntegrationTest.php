<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;

/**
 * The pages, run as an account: Update › Subids takes a payout, transaction
 * id and time per line with a default payout and time zone, and names the
 * lines it refuses; Setup › Campaigns stores and shows a campaign's currency.
 *
 * A campaign saved in another currency asks a hosted service for the rate,
 * which no test here reaches: that path is CampaignCurrencyTest's, with the
 * rate handed in.
 *
 * @group integration
 */
final class SubidAndCurrencyPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990262;
    private const ROLE = 990262;
    private const CLICKS = [99026201, 99026202, 99026203];

    private static string $sessions = '';
    private static int $network = 0;
    private static int $campaign = 0;

    /** @var list<string>|null */
    private static ?array $versions = null;

    /** @var list<int> */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran. tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-' . getmypid() . '-subids';
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

    private static function cleanUp(): void
    {
        $u = self::USER;
        $clicks = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_dataengine', '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        $tables = ['202_conversion_logs', '202_revenue_events', '202_dirty_hours', '202_attribution_rollup_dirty_clicks',
            '202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        $u = self::USER;
        $t = time();
        self::user($u, 'America/New_York');
        self::row('202_users_pref', ['user_id' => $u, 'user_account_currency' => 'USD']);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'subids test']);
        foreach (['access_to_setup_section', 'access_to_update_section'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => $u, 'role_id' => self::ROLE]);

        self::$network = self::insert("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'My Category', aff_network_time = $t");
        // A campaign 1.56 saved in pounds: 10.00 GBP, 13.41 in the account's dollars.
        self::$campaign = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = " . self::$network
            . ", aff_campaign_name = 'Pound Offer', aff_campaign_url = 'https://shop.example/offer', aff_campaign_payout = 13.41,"
            . " aff_campaign_currency = 'GBP', aff_campaign_foreign_payout = 10.00, aff_campaign_time = $t");
        foreach (self::CLICKS as $click) {
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $u, aff_campaign_id = " . self::$campaign
                . ', ppc_account_id = 0, landing_page_id = 0, click_cpc = 0, click_payout = 13.41, click_lead = 0, click_time = ' . ($t - 86400 * 2));
        }
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private static function page(string $page, array $get = [], array $post = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', dirname(__DIR__) . '/Setup/fixtures/page-runner.php', $page],
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
        self::assertStringNotContainsString('Fatal error', $out . $err, "$page: $err");
        self::assertStringNotContainsString('Warning', $err, "$page: $err");

        return $out;
    }

    /** @return list<array{payout: float, tx: ?string, time: int}> */
    private static function rows(int $click): array
    {
        $r = self::$db->query("SELECT click_payout, transaction_id, conv_time FROM 202_conversion_logs WHERE click_id = $click AND deleted = 0 ORDER BY conv_id");

        return array_map(
            static fn (array $row): array => ['payout' => (float) $row['click_payout'], 'tx' => $row['transaction_id'], 'time' => (int) $row['conv_time']],
            $r->fetch_all(MYSQLI_ASSOC)
        );
    }

    public function testTheSubidsPageRecordsEachLinesFields(): void
    {
        [$a, $b] = self::CLICKS;
        $out = self::page('tracking202/update/subids.php', [], [
            'token' => 'SESSION',
            'subids' => "$a, 12.50, order-1, 11/02/2026 2:30:00 PM\r\n$b\r\n$b",
            'subid_payout' => '4.25',
            'subid_timezone' => 'Europe/London',
        ]);
        self::assertStringContainsString('2 subid(s) marked as converted', $out);
        self::assertStringContainsString('Repeats of an earlier line, recorded once: line 3', $out);
        $london = (new \DateTimeImmutable('2026-11-02 14:30:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        self::assertSame([['payout' => 12.5, 'tx' => 'order-1', 'time' => $london]], self::rows($a));
        self::assertSame(4.25, self::rows($b)[0]['payout'], 'the default payout');

        $again = self::page('tracking202/update/subids.php', [], ['token' => 'SESSION', 'subids' => "$a, 12.50, order-1", 'subid_timezone' => 'Europe/London']);
        self::assertStringContainsString('0 subid(s) marked', $again);
        self::assertStringContainsString('Already recorded, so left as they were: ' . $a . ' (order-1)', $again);

        $form = self::page('tracking202/update/subids.php');
        self::assertMatchesRegularExpression('#<option value="America/New_York" selected>America/New_York \(your account\)</option>#', $form, 'the account\'s zone is the default');
    }

    public function testTheSubidsPageNamesTheLinesItRefusesAndRecordsNone(): void
    {
        $c = self::CLICKS[2];
        $out = self::page('tracking202/update/subids.php', [], [
            'token' => 'SESSION',
            'subids' => "$c, 5\n$c, five, x\n$c,,,someday",
            'subid_timezone' => 'America/New_York',
        ]);
        self::assertStringContainsString('Nothing was recorded: 2 line(s) could not be read', $out);
        self::assertStringContainsString('Line 2 (' . $c . '): payout &quot;five&quot; is not a number', $out);
        self::assertStringContainsString('Line 3 (' . $c . '): time &quot;someday&quot; is not a date and time', $out);
        self::assertStringContainsString("$c, five, x", $out, 'the list is kept for another try');
        self::assertSame([], self::rows($c));

        $bad = self::page('tracking202/update/subids.php', [], ['token' => 'SESSION', 'subids' => "$c", 'subid_payout' => '1,5', 'subid_timezone' => 'America/New_York']);
        self::assertStringContainsString('Payout &quot;1,5&quot; is not a number', $bad);
        self::assertSame([], self::rows($c));
    }

    public function testTheCampaignsPageShowsAndKeepsACampaignsCurrency(): void
    {
        $id = self::$campaign;
        $edit = self::page('tracking202/setup/aff_campaigns.php', ['edit_aff_campaign_id' => (string) $id]);
        self::assertMatchesRegularExpression('#<option value="GBP" selected>GBP</option>#', $edit, 'its own currency');
        self::assertStringContainsString('id="aff_campaign_payout" name="aff_campaign_payout" value="10.00"', $edit, 'its own amount');
        self::assertStringContainsString('$13.41 (£10.00)', $edit, 'the list shows both');

        // Saved in the account's currency: no rate is asked for.
        self::page('tracking202/setup/aff_campaigns.php', ['edit_aff_campaign_id' => (string) $id], [
            'token' => 'SESSION', 'aff_campaign_id' => (string) $id, 'aff_network_id' => (string) self::$network,
            'aff_campaign_name' => 'Pound Offer', 'aff_campaign_url' => 'https://shop.example/offer',
            'aff_campaign_payout' => '15', 'aff_campaign_currency' => 'USD', 'payout_mode' => 'replace',
        ]);
        $row = self::$db->query("SELECT aff_campaign_currency, aff_campaign_payout, aff_campaign_foreign_payout FROM 202_aff_campaigns WHERE aff_campaign_id = $id")->fetch_assoc();
        self::assertSame(['aff_campaign_currency' => 'USD', 'aff_campaign_payout' => '15.00', 'aff_campaign_foreign_payout' => '0.00'], $row);

        $refused = self::page('tracking202/setup/aff_campaigns.php', ['edit_aff_campaign_id' => (string) $id], [
            'token' => 'SESSION', 'aff_campaign_id' => (string) $id, 'aff_network_id' => (string) self::$network,
            'aff_campaign_name' => 'Pound Offer', 'aff_campaign_url' => 'https://shop.example/offer',
            'aff_campaign_payout' => '15', 'aff_campaign_currency' => 'XYZ', 'payout_mode' => 'replace',
        ]);
        self::assertStringContainsString('Choose a currency from the list.', $refused);
    }
}
