<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\OfflineConversion\ConversionNames;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\LegacyGoogleFeedFetches;

/**
 * Setup › Offline Conversions and Setup › Destinations as the web server
 * runs them, signed in (fixtures/page-runner.php): the old Google Ads feed
 * a schedule still fetches is listed and moved over by its button, and a
 * Google Ads destination is added with three values and its account picked
 * by a form. No case here reaches Google: the picker's reads are
 * GoogleAdsAccountsTest's, and a page asks Google only for a destination
 * that is connected and has no account, which none here is.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class GoogleAdsSetupPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990181;
    private const OTHER = 990182;
    private const ROLE = 990181;
    private const CAMPAIGN = 990191;
    private const OTHERS_CAMPAIGN = 990192;
    private const CREDENTIALS = ['developer_token' => 'devTOKEN_abc123XYZ', 'oauth_client_id' => '1234-abcd.apps.googleusercontent.com', 'oauth_client_secret' => 'GOCSPX-clientSecret99'];

    private static string $sessions = '';

    /** @var list<int> the permission rows this class added */
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
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        // A licence key on file (the session has already validated it).
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'google ads setup pages test']);
        foreach (['access_to_setup_section', 'access_to_campaign_data'] as $description) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$description'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$description'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        foreach ([[self::CAMPAIGN, self::USER, 'Online store checkout'], [self::OTHERS_CAMPAIGN, self::OTHER, 'Demo request']] as [$id, $user, $name]) {
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, user_id = $user, aff_network_id = 0, aff_campaign_name = " . self::str($name)
                . ", aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 0, aff_campaign_time = 0, aff_campaign_foreign_payout = 0");
        }
        self::$sessions = sys_get_temp_dir() . '/p202-google-ads-pages-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_legacy_google_feed_fetches');
        foreach ([self::USER, self::OTHER] as $user) {
            $tables = ['202_destination_deliveries', '202_destinations', '202_offline_conversion_feeds',
                '202_offline_conversion_names', '202_aff_campaigns', '202_user_role', '202_users_pref', '202_users'];
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function conn(): Connection
    {
        return new Connection(self::requireScratch());
    }

    /**
     * What a page printed and the session it left, run as USER.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post a POST when not empty; token SESSION is the session's own
     * @return array{0: string, 1: string}
     */
    private static function page(string $page, array $get = [], array $post = []): array
    {
        self::requireScratch();
        array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
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
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning:', $err, $err);
        $sessions = glob(self::$sessions . '/sess_*') ?: [];
        self::assertCount(1, $sessions, 'the page wrote its session');

        return [$out, (string) file_get_contents($sessions[0])];
    }

    public function testAScheduleStillFetchingTheOldFeedIsListedAndMovedOverByItsButton(): void
    {
        $page = 'tracking202/setup/offline_conversions.php';
        $fetches = new LegacyGoogleFeedFetches(self::conn());
        // Nothing fetched: the page says nothing of an old feed.
        [$html] = self::page($page);
        self::assertStringContainsString('Conversion names', $html, 'the page rendered');
        self::assertStringNotContainsString('id="old-feed"', $html);

        $fetches->record(['campaign_id' => self::CAMPAIGN, 'conversion_name' => 'Purchase <b>'], time() - 7200);
        $fetches->record(['campaign_id' => self::OTHERS_CAMPAIGN, 'conversion_name' => 'Theirs'], time() - 60);
        $id = $fetches->forAccount(self::USER, time())[0]['fetch_id'];
        $theirs = $fetches->forAccount(self::OTHER, time())[0]['fetch_id'];

        [$html] = self::page($page);
        self::assertStringContainsString('id="old-feed"', $html);
        self::assertStringContainsString('Purchase &lt;b&gt;', $html, 'the old address\'s words are shown escaped');
        self::assertStringNotContainsString('Purchase <b>', $html);
        self::assertStringContainsString('Online store checkout', $html);
        self::assertStringContainsString('2 hours ago', $html);
        self::assertStringContainsString('name="action" value="move_legacy_feed"><input type="hidden" name="fetch_id" value="' . $id . '"', $html);
        self::assertStringNotContainsString('Theirs', $html, 'another account\'s schedule is not listed');

        // Without the session's token nothing moves; nor does another account's schedule.
        self::page($page, [], ['action' => 'move_legacy_feed', 'fetch_id' => (string) $id, 'token' => 'not-the-token']);
        [$html] = self::page($page, [], ['action' => 'move_legacy_feed', 'fetch_id' => (string) $theirs, 'token' => 'SESSION']);
        self::assertStringContainsString('no longer listed', $html);
        [$html] = self::page($page, [], ['action' => 'move_legacy_feed', 'fetch_id' => '12abc', 'token' => 'SESSION']);
        self::assertStringContainsString('no longer listed', $html);
        self::assertSame([], (new FeedStore(self::conn()))->list(self::USER));
        self::assertSame([], (new ConversionNames(self::conn()))->read(self::USER)[ConversionNames::GOOGLE]['campaigns']);

        // The button: the name is saved, the URL made and handed to the next page once.
        [$out, $session] = self::page($page, [], ['action' => 'move_legacy_feed', 'fetch_id' => (string) $id, 'token' => 'SESSION']);
        self::assertSame('', trim($out), 'the page redirected');
        $feeds = (new FeedStore(self::conn()))->list(self::USER);
        self::assertCount(1, $feeds);
        self::assertSame([Format::GOOGLE_UPLOADS, 90, 'Purchase <b>'], [$feeds[0]->format, $feeds[0]->days, $feeds[0]->conversionName]);
        self::assertSame([self::CAMPAIGN => 'Purchase <b>'], (new ConversionNames(self::conn()))->read(self::USER)[ConversionNames::GOOGLE]['campaigns']);
        self::assertMatchesRegularExpression('~p202_offline_feed_url.*api/v3/offline-conversions/[A-Za-z0-9_-]{43}\?file=conversions\.csv~s', $session);

        // Listed as moved from then on, with no button to move it again; hiding takes it off the page.
        [$html] = self::page($page);
        self::assertStringContainsString('moved: paste its URL into Google Ads', $html);
        self::assertStringNotContainsString('value="move_legacy_feed"', $html);
        self::page($page, [], ['action' => 'hide_legacy_feed', 'fetch_id' => (string) $id, 'token' => 'SESSION']);
        [$html] = self::page($page);
        self::assertStringNotContainsString('id="old-feed"', $html);
        self::assertSame('1', self::$db->query('SELECT COUNT(*) FROM 202_legacy_google_feed_fetches')->fetch_row()[0], 'the other account\'s schedule is still noted');
    }

    public function testAGoogleAdsDestinationIsAddedWithThreeValuesAndItsAccountIsPickedByAForm(): void
    {
        $page = 'tracking202/setup/destinations.php';
        $store = new DestinationStore(self::conn());

        // The add form asks for a name and the three credentials; the IDs sit under Advanced.
        [$html] = self::page($page);
        self::assertStringContainsString('Add and connect Google Ads', $html);
        self::assertMatchesRegularExpression('~<details class="p202-disclosure[^>]*>\s*<summary>Advanced.*name="gads_customer_id".*name="gads_conversion_action".*</details>~s', $html);

        [$out] = self::page($page, [], ['action' => 'create_google_ads', 'token' => 'SESSION', 'gads_name' => 'Search campaigns',
            'gads_developer_token' => self::CREDENTIALS['developer_token'], 'gads_oauth_client_id' => self::CREDENTIALS['oauth_client_id'],
            'gads_oauth_client_secret' => self::CREDENTIALS['oauth_client_secret'], 'gads_customer_id' => '', 'gads_login_customer_id' => '',
            'gads_conversion_action' => '', 'gads_ad_user_data' => '', 'gads_ad_personalization' => '']);
        self::assertSame('', trim($out), 'the page redirected: ' . substr($out, 0, 300));
        $made = $store->list(self::USER);
        self::assertCount(1, $made);
        $id = (int) $made[0]['destination_id'];
        self::assertSame('needs_authorisation', $made[0]['status']);
        self::assertSame(['campaign_ids' => [], 'send_user_data' => false, 'consent' => []], (array) $made[0]['settings']);

        // Not connected: nothing is picked from yet, and Google is not asked.
        [$html] = self::page($page);
        self::assertStringContainsString('not connected', $html);
        self::assertStringNotContainsString('name="gads_account"', $html);
        self::assertStringContainsString('conversion action not picked yet', $html);

        // The picker's form saves the account, then the action.
        self::page($page, [], ['action' => 'pick_google_ads', 'destination_id' => (string) $id, 'gads_account' => '1234567890:1112223333', 'token' => 'not-the-token']);
        self::assertArrayNotHasKey('customer_id', (array) $store->get(self::USER, $id)['settings'], 'refused without the session\'s token');
        [$html] = self::page($page, [], ['action' => 'pick_google_ads', 'destination_id' => (string) $id, 'gads_account' => 'customers/1234567890', 'token' => 'SESSION']);
        self::assertStringContainsString('Choose the Google Ads account from the list.', $html);
        [$out] = self::page($page, [], ['action' => 'pick_google_ads', 'destination_id' => (string) $id, 'gads_account' => '1234567890:1112223333',
            'gads_conversion_action' => '987654', 'token' => 'SESSION']);
        self::assertSame('', trim($out), 'the page redirected: ' . substr($out, 0, 300));
        self::assertSame(
            ['customer_id' => '1234567890', 'login_customer_id' => '1112223333', 'conversion_action' => '987654'],
            array_intersect_key((array) $store->get(self::USER, $id)['settings'], array_flip(['customer_id', 'login_customer_id', 'conversion_action']))
        );

        // Typed under Advanced, the IDs are taken as before.
        self::page($page, [], ['action' => 'create_google_ads', 'token' => 'SESSION', 'gads_name' => 'Typed',
            'gads_developer_token' => self::CREDENTIALS['developer_token'], 'gads_oauth_client_id' => self::CREDENTIALS['oauth_client_id'],
            'gads_oauth_client_secret' => self::CREDENTIALS['oauth_client_secret'], 'gads_customer_id' => '555-666-7777', 'gads_login_customer_id' => '',
            'gads_conversion_action' => '55', 'gads_ad_user_data' => '', 'gads_ad_personalization' => '']);
        $typed = $store->list(self::USER)[1];
        self::assertSame(['5556667777', '55'], [((array) $typed['settings'])['customer_id'], ((array) $typed['settings'])['conversion_action']]);

        // Connected and complete: the list offers the change, and reads Google only when it is followed.
        $store->authorise(self::USER, $id, '1//0refreshTokenValue-xyz', time());
        self::assertSame('ready', $store->get(self::USER, $id)['status']);
        [$html] = self::page($page);
        self::assertStringContainsString('?choose=' . $id . '#d' . $id, $html);
        self::assertStringNotContainsString('name="gads_account"', $html);
        self::assertSame(GoogleAdsDestination::TYPE, $store->get(self::USER, $id)['type']);
    }
}
