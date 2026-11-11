<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * The pages, run as a signed-in account (fixtures/page-runner.php):
 *
 * - Setup › Landing Pages saves a URL typed without its scheme with
 *   https:// and says so, lists each page with its code check, and the
 *   check's endpoint keeps what it found (a loopback URL, so the guard
 *   refuses the fetch and nothing leaves the machine);
 * - Visitors shows why a click was filtered, offers Not fraud on one
 *   filtered for its score, and the endpoint counts it again;
 * - Update Subids reads a payout pasted with the account currency's sign.
 *
 * @group integration
 */
final class ClicksAndSetupPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990471;
    private const OTHER = 990472;
    private const ROLE = 990471;
    private const CLICKS = [99047101, 99047102, 99047103, 99047104];

    private static string $sessions = '';
    private static int $ip = 0;

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
        self::q("INSERT INTO 202_version SET version = '" . PROSPER202_VERSION . "'");
        self::cleanUp();
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-' . getmypid() . '-clicks-setup';
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
                    self::q('INSERT INTO 202_version SET version = ' . self::str($version));
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
        $users = self::USER . ', ' . self::OTHER;
        $clicks = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_clicks_site', '202_dataengine',
            '202_click_latest_visits', '202_clicks_filter_reason', '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        foreach (['202_landing_page_checks', '202_landing_pages', '202_click_filter_entries', '202_click_filter_settings', '202_dirty_hours',
            '202_revenue_events', '202_aff_campaigns', '202_aff_networks', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
        if (self::$ip > 0) {
            self::q('DELETE FROM 202_ips WHERE ip_id = ' . self::$ip);
            self::$ip = 0;
        }
    }

    private static function seed(): void
    {
        $u = self::USER;
        self::user($u);
        self::user(self::OTHER);
        self::q("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD'");
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        self::q('INSERT INTO 202_roles SET role_id = ' . self::ROLE . ", role_name = 'clicks and setup test'");
        foreach (['access_to_setup_section', 'access_to_update_section', 'access_to_personal_settings'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::q('INSERT INTO 202_role_permission SET role_id = ' . self::ROLE . ', permission_id = ' . (int) $permission);
        }
        self::q("INSERT INTO 202_user_role SET user_id = $u, role_id = " . self::ROLE);
        self::$ip = self::insert("INSERT INTO 202_ips SET ip_address = '203.0.113.71'");
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
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __DIR__ . '/fixtures/page-runner.php', $page],
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

    private static function one(string $sql): ?string
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);
        $row = $result->fetch_row();

        return $row === null || $row[0] === null ? null : (string) $row[0];
    }

    public function testLandingPagesCompletesAUrlTypedWithoutItsSchemeAndListsTheCodeCheck(): void
    {
        $u = self::USER;
        self::page('tracking202/setup/landing_pages.php', [], [
            'token' => 'SESSION', 'landing_page_type' => '1', 'landing_page_nickname' => 'Spring sale',
            'landing_page_url' => ' lp.example.com/spring?c=[[c1]] ', 'leave_behind_page_url' => '',
        ]);
        self::assertSame('https://lp.example.com/spring?c=[[c1]]', self::one("SELECT landing_page_url FROM 202_landing_pages WHERE user_id = $u AND landing_page_nickname = 'Spring sale'"));
        $said = self::page('tracking202/setup/landing_pages.php', ['added' => '1', 'https' => '1']);
        self::assertStringContainsString('so it was saved with https://', $said);

        // A URL that is no bare host is still refused, and nothing is saved.
        $refused = self::page('tracking202/setup/landing_pages.php', [], [
            'token' => 'SESSION', 'landing_page_type' => '1', 'landing_page_nickname' => 'Summer sale', 'landing_page_url' => 'ftp://lp.example.com/x',
        ]);
        self::assertStringContainsString('must start with http:// or https://', $refused);
        self::assertSame('0', self::one("SELECT COUNT(*) FROM 202_landing_pages WHERE user_id = $u AND landing_page_nickname = 'Summer sale'"));

        // The list: a page with no answer asks for one; a page with one shows it.
        $loopback = self::insert("INSERT INTO 202_landing_pages SET user_id = $u, aff_campaign_id = 0, landing_page_nickname = 'Local page',"
            . " landing_page_url = 'http://127.0.0.1:9/lp', landing_page_id_public = 7123, landing_page_time = 0, landing_page_type = 1");
        $spring = (int) self::one("SELECT landing_page_id FROM 202_landing_pages WHERE user_id = $u AND landing_page_nickname = 'Spring sale'");
        self::q("INSERT INTO 202_landing_page_checks SET landing_page_id = $spring, user_id = $u, checked_url = 'https://lp.example.com/spring?c=[[c1]]',"
            . " check_status = 'absent', check_message = 'The code was not found. Paste the code above right above </body>.', checked_at = 1");
        $list = self::page('tracking202/setup/landing_pages.php');
        self::assertStringContainsString('class="p202-pill p202-pill--bad" data-p202-lp-check-pill="' . $spring . '" title="The code was not found. Paste the code from Get LP Code', $list);
        self::assertStringContainsString('class="p202-pill" data-p202-lp-check-pill="' . $loopback . '" data-p202-lp-check-due', $list);
        self::assertStringContainsString('data-p202-lp-check-now="' . $loopback . '"', $list);
        self::assertStringContainsString('data-p202-lp-check-url="', $list);

        // The pill's endpoint: the guard refuses a loopback page, and the answer is kept.
        $pill = self::page('tracking202/ajax/check_landing_code.php', [], ['token' => 'SESSION', 'landing_page_id' => (string) $loopback, 'as' => 'pill']);
        self::assertStringContainsString('data-p202-lp-check-pill="' . $loopback . '"', $pill);
        self::assertStringContainsString('>Could not be checked</span>', $pill);
        self::assertStringContainsString('loopback address', $pill);
        self::assertStringNotContainsString('data-p202-lp-check-due', $pill);
        self::assertSame('error', self::one("SELECT check_status FROM 202_landing_page_checks WHERE landing_page_id = $loopback AND user_id = $u"));
        self::assertStringNotContainsString('data-p202-lp-check-due', self::page('tracking202/setup/landing_pages.php'), 'nothing is left to ask for');

        // Another account's page is not checked, and a request without the token is refused.
        $theirs = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OTHER . ", aff_campaign_id = 0, landing_page_nickname = 'Theirs',"
            . " landing_page_url = 'http://127.0.0.1:9/theirs', landing_page_id_public = 7124, landing_page_time = 0, landing_page_type = 1");
        $notMine = self::page('tracking202/ajax/check_landing_code.php', [], ['token' => 'SESSION', 'landing_page_id' => (string) $theirs, 'as' => 'pill']);
        self::assertStringContainsString('>Not checked yet</span>', $notMine);
        self::assertStringNotContainsString('data-p202-lp-check-due', $notMine);
        self::assertStringContainsString('Invalid token', self::page('tracking202/ajax/check_landing_code.php', [], ['token' => 'wrong', 'landing_page_id' => (string) $loopback]));
        self::assertSame('0', self::one("SELECT COUNT(*) FROM 202_landing_page_checks WHERE landing_page_id = $theirs"));
    }

    public function testVisitorsSaysWhyAClickWasFilteredAndNotFraudCountsItAgain(): void
    {
        $u = self::USER;
        [$scored, $sameAddress, $ruled, $real] = self::CLICKS;
        $time = time() - 600;
        foreach (self::CLICKS as $click) {
            $filtered = $click === $real ? 0 : 1;
            foreach (['202_clicks', '202_clicks_spy'] as $table) {
                self::q("INSERT INTO $table SET click_id = $click, user_id = $u, aff_campaign_id = 0, landing_page_id = 0, ppc_account_id = 0,"
                    . " click_cpc = 0, click_payout = 0, click_lead = 0, click_filtered = $filtered, click_bot = 0, click_alp = 0, click_time = $time");
            }
            self::q("INSERT INTO 202_clicks_advance SET click_id = $click, text_ad_id = 0, keyword_id = 0, ip_id = " . self::$ip
                . ', country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0, isp_id = 0');
            ClickRollupSql::rollUpClick(new Connection(self::$db), $click);
        }
        self::q("INSERT INTO 202_clicks_filter_reason (click_id, filter_reason, entry_id) VALUES ($scored, 'fraud_score', 0), ($sameAddress, 'fraud_score', 0), ($ruled, 'repeat_visit', 0)");

        $spy = self::page('tracking202/ajax/click_history.php', ['spy' => '1']);
        self::assertSame(2, substr_count($spy, '>Filtered: fraud score</span>'), $spy === '' ? 'the fragment is empty' : '');
        self::assertSame(1, substr_count($spy, '>Filtered: repeat visit</span>'));
        self::assertSame(2, substr_count($spy, 'data-p202-not-fraud="'), 'only a click filtered for its score can be undone here');
        self::assertStringContainsString('data-p202-not-fraud="' . $scored . '" data-p202-not-fraud-url="', $spy);
        self::assertStringContainsString('ajax/click_not_fraud.php"', $spy);

        $refused = [
            [['click_id' => (string) $scored], 'token'],
            [['token' => 'SESSION', 'click_id' => (string) $ruled], 'not one of yours filtered for its fraud score'],
            [['token' => 'SESSION', 'click_id' => $scored . 'abc'], 'not a click'],
        ];
        foreach ($refused as [$post, $why]) {
            $answer = json_decode(self::page('tracking202/ajax/click_not_fraud.php', [], $post), true);
            self::assertFalse($answer['ok'] ?? null, json_encode($post));
            self::assertStringContainsString($why, $answer['message']);
        }
        self::assertSame('1', self::one("SELECT click_filtered FROM 202_dataengine WHERE click_id = $scored"), 'a refusal changes nothing');

        $answer = json_decode(self::page('tracking202/ajax/click_not_fraud.php', [], ['token' => 'SESSION', 'click_id' => (string) $scored]), true);
        self::assertTrue($answer['ok'] ?? null, json_encode($answer));
        self::assertStringContainsString('2 clicks from 203.0.113.71 count again.', $answer['message']);
        self::assertStringContainsString('always count', $answer['message']);
        foreach ([$scored, $sameAddress] as $click) {
            self::assertSame('0', self::one("SELECT MAX(click_filtered) FROM 202_clicks WHERE click_id = $click"));
            self::assertSame('0', self::one("SELECT MAX(click_filtered) FROM 202_clicks_spy WHERE click_id = $click"));
            self::assertSame('0', self::one("SELECT click_filtered FROM 202_dataengine WHERE click_id = $click"), 'the report row heard about it');
            self::assertNull(self::one("SELECT filter_reason FROM 202_clicks_filter_reason WHERE click_id = $click"));
        }
        self::assertSame('1', self::one("SELECT click_filtered FROM 202_clicks WHERE click_id = $ruled"), 'a click a rule filtered stays filtered');
        self::assertSame('count', self::one("SELECT action FROM 202_click_filter_entries WHERE user_id = $u"));

        $after = self::page('tracking202/ajax/click_history.php', ['spy' => '1']);
        self::assertSame(0, substr_count($after, 'data-p202-not-fraud="'));
        self::assertSame(0, substr_count($after, '>Filtered: fraud score</span>'));
    }

    public function testUpdateSubidsReadsAPayoutPastedWithTheAccountCurrencysSign(): void
    {
        $u = self::USER;
        $click = self::CLICKS[3];
        self::q("DELETE FROM 202_clicks WHERE click_id = $click");
        $network = self::insert("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'Store', aff_network_time = 0");
        $campaign = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = $network, aff_campaign_name = 'Spring sale',"
            . " aff_campaign_url = 'https://shop.example.com', aff_campaign_payout = 3, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $u, aff_campaign_id = $campaign, ppc_account_id = 0, landing_page_id = 0,"
            . ' click_cpc = 0, click_payout = 3, click_lead = 0, click_time = ' . (time() - 7200));

        $other = self::page('tracking202/update/subids.php', [], ['token' => 'SESSION', 'subids' => "$click, \u{20AC}12.50, order-1", 'subid_timezone' => 'UTC']);
        self::assertStringContainsString('Nothing was recorded: 1 line(s) could not be read', $other);
        self::assertStringContainsString('(USD)', $other);

        $out = self::page('tracking202/update/subids.php', [], [
            'token' => 'SESSION', 'subids' => "$click, \$12.50, order-1\n$click,,order-2", 'subid_payout' => '4.25 USD', 'subid_timezone' => 'UTC',
        ]);
        self::assertStringContainsString('2 subid(s) marked as converted', $out);
        self::assertStringContainsString('Payouts written with a currency sign were read as USD amounts: 4.25 USD as 4.25, $12.50 as 12.50.', $out);
        $rows = self::$db->query("SELECT click_payout, transaction_id FROM 202_conversion_logs WHERE click_id = $click AND deleted = 0 ORDER BY conv_id")->fetch_all(MYSQLI_NUM);
        self::assertSame([[12.5, 'order-1'], [4.25, 'order-2']], array_map(static fn (array $r): array => [(float) $r[0], $r[1]], $rows));
    }
}
