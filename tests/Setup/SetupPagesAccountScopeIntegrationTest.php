<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * The Setup and Get Links pages name a campaign, category, traffic source and
 * its account, landing page, text ad, redirector or network integration only
 * when it is the account's own (CLAUDE.md #27).
 *
 * A tracker names its campaign, landing page, text ad, traffic source
 * account and redirector by id; a category names its network integration, a
 * traffic source account its source and a campaign its category. Before
 * 229df10 nothing stopped one naming another account's record, and those
 * rows remain in installs. The pages joined each on its id alone, so Get
 * Links listed another account's landing page by name and built this
 * account's tracking link on that page's URL with that source's variables,
 * Campaigns showed another account's network integration, Update › CPC
 * filed this account's traffic source account under another account's
 * source, and the home page's Get Started card counted a source and a
 * category that are not this account's as done.
 *
 * Account A (USER) has a tracker naming B's (OTHER's) campaign, landing
 * page, text ad and traffic source account, a tracker on B's redirector, a
 * category on B's network integration, and a traffic source account and a
 * campaign filed under B's source and category. Each page runs as A in a
 * process of its own (fixtures/page-runner.php), as the web server runs it.
 *
 * Not here: Get Links' edit form reads its tracker id through filter_input(),
 * which a command-line run cannot supply, and the endpoints whose foreign
 * names went only into Slack notices (generate_tracking_link.php,
 * get_landing_code.php, ajax/rotator.php). tests/live/account-scoped-pages.sh
 * drives both over HTTP, with the notices captured.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class SetupPagesAccountScopeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990071;
    private const OTHER = 990072;
    /** A's own records that name OTHER's. */
    private const STRAY = 990073;
    private const LINK_ON_THEIRS = 99007119;
    private const LINK_ON_THEIR_REDIRECTOR = 99007129;
    private const ROLE = 990071;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var int|null the permission row this class added, if it added one */
    private static ?int $addedPermission = null;

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
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-' . getmypid();
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
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns', '202_ppc_networks', '202_ppc_accounts',
                '202_landing_pages', '202_text_ads', '202_rotators', '202_trackers', '202_dni_networks'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = ' . self::OTHER);
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        if (self::$addedPermission !== null) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . self::$addedPermission);
            self::$addedPermission = null;
        }
    }

    private static function seed(): void
    {
        $t = time();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        // A licence key on file (the session has already validated it).
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        // A role that may use Setup.
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = 'access_to_setup_section'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            self::$addedPermission = self::insert("INSERT INTO 202_permissions SET permission_description = 'access_to_setup_section'");
            $permission = self::$addedPermission;
        }
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'scope test']);
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);

        // OTHER's records.
        $o = self::OTHER;
        self::row('202_aff_networks', ['aff_network_id' => $o, 'user_id' => $o, 'aff_network_name' => 'Their Category', 'aff_network_time' => $t]);
        self::row('202_aff_campaigns', ['aff_campaign_id' => $o, 'user_id' => $o, 'aff_network_id' => $o, 'aff_campaign_name' => 'Their Offer',
            'aff_campaign_url' => 'https://their.example/offer', 'aff_campaign_payout' => 9, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
        self::row('202_ppc_networks', ['ppc_network_id' => $o, 'user_id' => $o, 'ppc_network_name' => 'Their Source', 'ppc_network_time' => $t]);
        self::row('202_ppc_accounts', ['ppc_account_id' => $o, 'user_id' => $o, 'ppc_network_id' => $o, 'ppc_account_name' => 'Their Account', 'ppc_account_time' => $t]);
        self::row('202_landing_pages', ['landing_page_id' => $o, 'user_id' => $o, 'aff_campaign_id' => $o, 'landing_page_nickname' => 'Their Page',
            'landing_page_url' => 'https://their.example/page', 'landing_page_time' => $t]);
        self::row('202_text_ads', ['text_ad_id' => $o, 'user_id' => $o, 'aff_campaign_id' => $o, 'landing_page_id' => 0, 'text_ad_name' => 'Their Ad',
            'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'u', 'text_ad_time' => $t]);
        self::row('202_rotators', ['id' => $o, 'public_id' => $o, 'user_id' => $o, 'name' => 'Their Rotator']);
        self::row('202_ppc_network_variables', ['ppc_variable_id' => $o, 'ppc_network_id' => $o, 'name' => 'Their Var', 'parameter' => 'theirvar', 'placeholder' => '{theirvar}']);
        self::row('202_dni_networks', ['id' => $o, 'user_id' => $o, 'networkId' => 'their', 'shortDescription' => 'Their DNI',
            'favIcon' => 'https://their.example/dni.ico', 'apiKey' => 'their-key', 'name' => 'Their DNI', 'type' => 'x', 'time' => $t, 'processed' => 1]);

        // A's records: a category on OTHER's network integration, and a
        // traffic source account and a campaign filed under OTHER's parents.
        $a = self::USER;
        $s = self::STRAY;
        self::row('202_aff_networks', ['aff_network_id' => $a, 'user_id' => $a, 'aff_network_name' => 'My Category', 'aff_network_time' => $t, 'dni_network_id' => $o]);
        self::row('202_ppc_accounts', ['ppc_account_id' => $s, 'user_id' => $a, 'ppc_network_id' => $o, 'ppc_account_name' => 'My Stray Account', 'ppc_account_time' => $t]);
        self::row('202_aff_campaigns', ['aff_campaign_id' => $s, 'user_id' => $a, 'aff_network_id' => $o, 'aff_campaign_name' => 'My Stray Offer',
            'aff_campaign_url' => 'https://mine.example/offer', 'aff_campaign_payout' => 9, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
        // A's links: one on OTHER's campaign, landing page, text ad and
        // account; one on OTHER's redirector.
        self::row('202_trackers', ['tracker_id' => $a, 'user_id' => $a, 'tracker_id_public' => self::LINK_ON_THEIRS, 'aff_campaign_id' => $o,
            'text_ad_id' => $o, 'ppc_account_id' => $o, 'landing_page_id' => $o, 'rotator_id' => 0, 'click_cpc' => 0.1, 'click_cloaking' => 0, 'tracker_time' => $t]);
        self::row('202_trackers', ['tracker_id' => $s, 'user_id' => $a, 'tracker_id_public' => self::LINK_ON_THEIR_REDIRECTOR, 'aff_campaign_id' => 0,
            'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0, 'rotator_id' => $o, 'click_cpc' => 0.1, 'click_cloaking' => 0, 'tracker_time' => $t]);
    }

    /**
     * What a page printed, run as A.
     *
     * @param array<string, string> $get
     */
    private static function page(string $page, array $get = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => '{}',
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
        self::assertStringNotContainsStringIgnoringCase('database error', $out, "$page: $err");

        return $out;
    }

    /** Another account's name, URL or setting anywhere in what a page printed. */
    private static function assertShowsNothingOfTheOtherAccount(string $out, string $page): void
    {
        preg_match_all('/Their [A-Z][A-Za-z]+|their\.example[^"<\s]*|theirvar/', $out, $m);
        self::assertSame([], array_values(array_unique($m[0])), "$page showed another account's records");
    }

    public function testGetLinksListsTheAccountsLinksNamingNothingOfAnotherAccount(): void
    {
        $out = self::page('tracking202/setup/get_trackers.php');
        self::assertStringContainsString((string) self::LINK_ON_THEIRS, $out, 'the link on another account\'s records is still listed');
        self::assertStringContainsString((string) self::LINK_ON_THEIR_REDIRECTOR, $out, 'and so is the one on another account\'s redirector');
        self::assertShowsNothingOfTheOtherAccount($out, 'Get Links');
        // Neither has a link to give: not one on another account's landing
        // page URL, and not a direct link it was never made as.
        self::assertStringNotContainsString('t202id=' . self::LINK_ON_THEIRS, $out, 'the link on another account\'s landing page offers no link to copy');
        self::assertStringNotContainsString('t202id=' . self::LINK_ON_THEIR_REDIRECTOR, $out, 'nor does the one on another account\'s redirector');
        self::assertStringContainsString('no landing page', $out);
        self::assertStringContainsString('no redirector', $out);
    }

    public function testCampaignsShowNoOtherAccountsNetworkIntegration(): void
    {
        $out = self::page('tracking202/setup/aff_campaigns.php');
        self::assertStringContainsString('My Category', $out);
        self::assertStringNotContainsString('data-dni-id', $out, 'the category offers no search of another account\'s network integration');
        self::assertStringNotContainsString('offers loading', $out, 'nor says its offers are loading');
        self::assertShowsNothingOfTheOtherAccount($out, 'Campaigns');
    }

    public function testUpdateCpcFilesNoAccountUnderAnotherAccountsSource(): void
    {
        $out = self::page('function:p202_update_traffic_lists');
        $lists = json_decode($out, true);
        self::assertIsArray($lists, $out);
        self::assertSame([], $lists['accounts'], 'an account under another account\'s source is left out, as one under a deleted source is: ' . $out);
        self::assertShowsNothingOfTheOtherAccount($out, 'Update › CPC');
    }

    public function testGetStartedCountsOnlyTheAccountsOwnSourcesAndCategories(): void
    {
        $out = self::page('202-account/index.php');
        self::assertStringContainsString('id="p202-getting-started"', $out, 'A has no source or category of its own: the Get Started card is still shown');
        self::assertMatchesRegularExpression('/Add a traffic source/', $out, 'with its first step not done');
    }
}
