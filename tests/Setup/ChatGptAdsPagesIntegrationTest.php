<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\ChatGptAdsSource;

/**
 * The pages, run as the account (fixtures/page-runner.php): Setup › Traffic
 * Sources' "Add ChatGPT Ads" makes one source however often it is pressed
 * and only with the session token, and Get Links shows the ChatGPT Ads pair
 * for a link on that source (and the Google pair for any other), the same
 * pair GET /trackers/{id}/url answers.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ChatGptAdsPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990381;
    private const ROLE = 990381;
    private const CATEGORY = 990381;
    private const CAMPAIGN = 990381;
    private const PAGE = 990381;
    private const PLAIN_SOURCE = 990381;
    private const PLAIN_ACCOUNT = 990381;

    private static string $sessions = '';

    /** @var list<int> */
    private static array $addedPermissions = [];

    /** @var list<string>|null */
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
        self::$sessions = sys_get_temp_dir() . '/p202-chatgpt-pages-' . getmypid();
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
        self::q("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = $u");
        foreach (['202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns', '202_landing_pages',
            '202_ppc_networks', '202_ppc_accounts', '202_trackers'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        $u = self::USER;
        $t = time();
        self::user($u);
        self::row('202_users_pref', ['user_id' => $u, 'user_tracking_domain' => 'https://track.example.com']);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'chatgpt pages test']);
        $setup = "permission_description = 'access_to_setup_section'";
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $setup");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET $setup");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => $u, 'role_id' => self::ROLE]);
        $mine = ['user_id' => $u];
        self::row('202_aff_networks', $mine + ['aff_network_id' => self::CATEGORY, 'aff_network_name' => 'Store', 'aff_network_time' => $t]);
        self::row('202_aff_campaigns', $mine + [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_network_id' => self::CATEGORY, 'aff_campaign_name' => 'Spring sale',
            'aff_campaign_url' => 'https://shop.example.com/checkout', 'aff_campaign_payout' => 1,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
        ]);
        self::row('202_landing_pages', $mine + [
            'landing_page_id' => self::PAGE, 'aff_campaign_id' => self::CAMPAIGN, 'landing_page_nickname' => 'Sale page',
            'landing_page_url' => 'https://shop.example.com/sale', 'landing_page_type' => 0, 'landing_page_time' => $t,
        ]);
        self::row('202_ppc_networks', $mine + ['ppc_network_id' => self::PLAIN_SOURCE, 'ppc_network_name' => 'Newsletter', 'ppc_network_time' => $t]);
        self::row('202_ppc_accounts', $mine + [
            'ppc_account_id' => self::PLAIN_ACCOUNT, 'ppc_network_id' => self::PLAIN_SOURCE, 'ppc_account_name' => 'weekly', 'ppc_account_time' => $t,
        ]);
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private static function page(string $page, array $get = [], array $post = []): string
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
            'P202_TEST_AJAX' => str_contains($page, '/ajax/') ? '1' : '0',
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

        return $out;
    }

    /** @return list<array<string, string>> the account's ChatGPT Ads sources */
    private static function chatGptSources(): array
    {
        $found = self::$db->query("SELECT ppc_network_id, ppc_network_name FROM 202_ppc_networks WHERE user_id = " . self::USER
            . " AND ppc_network_platform = 'chatgpt_ads'");
        self::assertInstanceOf(\mysqli_result::class, $found);

        return $found->fetch_all(MYSQLI_ASSOC);
    }

    /** The value of the code box labelled $label, or null. */
    private static function box(string $out, string $label): ?string
    {
        $found = preg_match('~<div class="form-label">' . preg_quote($label, '~') . '</div><div class="p202-code mb-3"><pre[^>]*>([^<]*)</pre>~', $out, $m);

        return $found === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }

    private function addChatGptAds(): array
    {
        self::page('tracking202/setup/ppc_accounts.php', [], ['token' => 'SESSION', 'add_chatgpt_ads' => '1']);
        $sources = self::chatGptSources();
        self::assertCount(1, $sources);
        $account = self::$db->query('SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_deleted = 0 AND ppc_network_id = ' . (int) $sources[0]['ppc_network_id'])->fetch_row();

        return ['source' => (int) $sources[0]['ppc_network_id'], 'account' => (int) $account[0]];
    }

    public function testAddChatGptAdsMakesOneSourceAndNeedsTheToken(): void
    {
        self::page('tracking202/setup/ppc_accounts.php', [], ['token' => 'wrong', 'add_chatgpt_ads' => '1']);
        self::assertSame([], self::chatGptSources(), 'nothing is made without the session token');

        $made = $this->addChatGptAds();
        $this->addChatGptAds();
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM 202_ppc_accounts WHERE ppc_network_id = ' . $made['source'])->fetch_row()[0]);
        self::assertSame(2, (int) self::$db->query('SELECT COUNT(*) FROM 202_ppc_network_variables WHERE deleted = 0 AND ppc_network_id = ' . $made['source'])->fetch_row()[0]);

        $out = self::page('tracking202/setup/ppc_accounts.php', ['chatgpt_exists' => '1']);
        self::assertStringContainsString('ChatGPT Ads is already set up, so nothing was added.', $out);
        self::assertStringContainsString('<button type="submit" class="btn btn-secondary">Add ChatGPT Ads</button>', $out);
        self::assertMatchesRegularExpression('~<span class="p202-list__name">ChatGPT Ads</span>\s*<span class="p202-pill p202-pill--accent"[^>]*>ChatGPT Ads</span>~', $out);
        self::assertDoesNotMatchRegularExpression('~<span class="p202-list__name">Newsletter</span>\s*<span class="p202-pill p202-pill--accent"~', $out, 'only the ChatGPT source is marked');
    }

    public function testGetLinksGivesThePairForADirectLinkOnTheSource(): void
    {
        $made = $this->addChatGptAds();
        $out = self::page('tracking202/ajax/generate_tracking_link.php', [], $this->link('directlink', $made));
        $public = (int) self::$db->query('SELECT MAX(tracker_id_public) FROM 202_trackers WHERE user_id = ' . self::USER)->fetch_row()[0];

        self::assertStringContainsString('<h3 class="h6">ChatGPT Ads</h3>', $out);
        self::assertSame("https://track.example.com/tracking202/redirect/dl.php?t202id=$public", self::box($out, 'Destination URL'));
        self::assertSame(ChatGptAdsSource::TEMPLATE, self::box($out, 'Tracking template'));
        self::assertSame(
            "https://track.example.com/tracking202/redirect/dl.php?t202id=$public&campaign_id={campaign_id}&ad_id={ad_id}&t202kw=",
            self::box($out, 'Tracking link'),
            'the full link is still there'
        );
        self::assertMatchesRegularExpression('~<details class="p202-disclosure"[^>]*>\s*<summary>Advanced .*?<div class="form-label">Tracking link</div>~s', $out, 'under Advanced');
        self::assertStringNotContainsString('Use this as the destination URL in your campaign', $out, "the other sources' advice is not shown");
        $this->assertTheApiGivesThePairShown($out);
    }

    public function testGetLinksGivesThePairForALandingPageOnTheSource(): void
    {
        $made = $this->addChatGptAds();
        $out = self::page('tracking202/ajax/generate_tracking_link.php', [], $this->link('landingpage', $made) + ['landing_page_id' => (string) self::PAGE]);
        $public = (int) self::$db->query('SELECT MAX(tracker_id_public) FROM 202_trackers WHERE user_id = ' . self::USER)->fetch_row()[0];

        self::assertSame('https://shop.example.com/sale', self::box($out, 'Destination URL'));
        self::assertSame(ChatGptAdsSource::TEMPLATE . "&t202id=$public&t202kw=", self::box($out, 'Tracking template'));
        self::assertStringNotContainsString('Google Ads (parallel tracking)', $out, 'the Google pair is not for this source');
        $this->assertTheApiGivesThePairShown($out);
    }

    public function testAnotherSourceGetsNoChatGptPair(): void
    {
        $this->addChatGptAds();
        $out = self::page('tracking202/ajax/generate_tracking_link.php', [], $this->link('landingpage', ['source' => self::PLAIN_SOURCE, 'account' => self::PLAIN_ACCOUNT]) + ['landing_page_id' => (string) self::PAGE]);

        self::assertStringNotContainsString('ChatGPT Ads', $out);
        self::assertNull(self::box($out, 'Tracking link'));
        self::assertStringContainsString('Google Ads (parallel tracking)', $out);
        self::assertStringStartsWith('https://shop.example.com/sale?t202id=', (string) self::box($out, 'Destination URL'));
    }

    /** @param array{source: int, account: int} $on */
    private function link(string $promotion, array $on): array
    {
        return [
            'token' => 'SESSION', 'tracker_type' => '0', 'aff_network_id' => (string) self::CATEGORY,
            'aff_campaign_id' => (string) self::CAMPAIGN, 'method_of_promotion' => $promotion, 'click_cloaking' => '0',
            'cost_type' => 'cpc', 'cpc_dollars' => '0', 'cpc_cents' => '10',
            'ppc_network_id' => (string) $on['source'], 'ppc_account_id' => (string) $on['account'],
        ];
    }

    private function assertTheApiGivesThePairShown(string $out): void
    {
        $tracker = (int) self::$db->query('SELECT MAX(tracker_id) FROM 202_trackers WHERE user_id = ' . self::USER)->fetch_row()[0];
        $api = (new \Api\V3\Controllers\TrackersController(self::$db, self::USER))->getTrackingUrl($tracker, [], [
            'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'DOCUMENT_ROOT' => dirname(__DIR__, 2),
        ])['data']['chatgpt_ads'];
        self::assertIsArray($api);
        self::assertSame(self::box($out, 'Destination URL'), $api['destination_url'], 'the API');
        self::assertSame(self::box($out, 'Tracking template'), $api['tracking_template'], 'the API');
    }
}
