<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsOAuth;

/**
 * tracking202/setup/destination-oauth.php as the web server runs it, signed
 * in (fixtures/page-runner.php): where Google's consent screen sends the
 * browser back after Connect Google Ads. GoogleAdsIntegrationTest covers
 * GoogleAdsOAuthCallback itself, Google's token endpoint included; this
 * covers the page's wiring: the signed-in user, the query and the sentence
 * left for Setup › Destinations. Neither case below reaches Google.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class DestinationOAuthPageIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990081;
    private const OTHER = 990082;
    private const ROLE = 990081;

    private static string $sessions = '';

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    private static int $destination = 0;

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
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'destinations oauth test']);
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = 'access_to_setup_section'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET permission_description = 'access_to_setup_section'");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        self::$destination = (int) self::store()->create(self::USER, GoogleAdsDestination::TYPE, 'Search', ['customer_id' => '123-456-7890', 'conversion_action' => '987654'], [
            'developer_token' => 'devTOKEN_abc123XYZ', 'oauth_client_id' => '1234-abcd.apps.googleusercontent.com', 'oauth_client_secret' => 'GOCSPX-clientSecret99',
        ], time())['destination_id'];
        self::$sessions = sys_get_temp_dir() . '/p202-oauth-page-runner-' . getmypid();
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
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_destination_deliveries', '202_destinations', '202_user_role', '202_users_pref', '202_users'] as $table) {
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

    private static function store(): DestinationStore
    {
        return new DestinationStore(new Connection(self::requireScratch()));
    }

    /**
     * The sentence the page left in the session for Setup › Destinations,
     * after running it as USER with $get.
     *
     * @param array<string, string> $get
     */
    private static function runCallbackPage(array $get): string
    {
        self::requireScratch();
        array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => '{}',
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __DIR__ . '/fixtures/page-runner.php', 'tracking202/setup/destination-oauth.php'],
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

        $sessions = glob(self::$sessions . '/sess_*') ?: [];
        self::assertCount(1, $sessions, 'the page wrote its session');

        return (string) file_get_contents($sessions[0]);
    }

    public function testAStateThisInstallDidNotSignIsRefusedAndConnectsNothing(): void
    {
        $session = self::runCallbackPage(['state' => 'v1.' . self::USER . '.' . self::$destination . '.' . (time() + 600) . '.00.forged', 'code' => '4/0code']);
        self::assertStringContainsString('p202_destination_oauth', $session);
        self::assertStringContainsString('did not sign', $session);
        self::assertStringContainsString('s:2:"ok";b:0', $session, 'the sentence says it failed');
        self::assertSame('needs_authorisation', self::store()->get(self::USER, self::$destination)['status']);
    }

    public function testTheSignedInUsersStateReachesTheCallback(): void
    {
        // Signed for this user and destination; Google's answer is a refusal, so nothing is traded.
        $state = GoogleAdsOAuth::state(self::USER, self::$destination, time(), self::store()->stateKey());
        $session = self::runCallbackPage(['state' => $state, 'error' => 'access_denied']);
        self::assertStringContainsString('access was not allowed', $session, 'the state verified for the session\'s user, and Google\'s answer was read');
        self::assertSame('needs_authorisation', self::store()->get(self::USER, self::$destination)['status']);

        // The same state for another user's session is refused before anything else.
        $theirs = GoogleAdsOAuth::state(self::OTHER, self::$destination, time(), self::store()->stateKey());
        self::assertStringContainsString('another user', self::runCallbackPage(['state' => $theirs, 'error' => 'access_denied']));
    }
}
