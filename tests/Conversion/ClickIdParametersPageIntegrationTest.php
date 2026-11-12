<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Personal settings › Advanced › Match conversions by ad click id: the page
 * stores the parameter names as the postback reads them, refuses what is not
 * a list of names under the field with nothing saved, shows the stored value
 * back, and leaves it alone when a form does not send the field.
 *
 * Runs the page as the web server does (tests/Auth/fixtures/session-user-runner.php).
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ClickIdParametersPageIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990727;
    private const PAGE = '202-account/account.php';
    private const ADMIN = 2;
    private const LICENCE = 'click-id-params-runner';

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
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-session-clickid-' . getmypid();
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
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'clickid-params',"
            . " user_email = 'owner@northwind.example', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . ' p202_customer_api_key = ' . self::str(self::LICENCE));
        self::row('202_users_pref', ['user_id' => self::USER, 'analytics_geo_is_eu' => 0]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ADMIN]);
        self::q('INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)'
            . ' VALUES (SHA2(' . self::str(self::LICENCE) . ', 256), 1, UNIX_TIMESTAMP() - 3600)');
    }

    private static function cleanUp(): void
    {
        foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2(' . self::str(self::LICENCE) . ', 256)');
    }

    /**
     * @param array<string, string> $post the profile form's fields, over the ones every save sends
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function page(?array $post = null): array
    {
        $env = ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        if ($post !== null) {
            $env['P202_SU_POST'] = json_encode($post + [
                'update_profile' => '1', 'token' => 'SESSION', 'user_email' => 'owner@northwind.example',
                'user_timezone' => 'UTC', 'user_keyword_searched_or_bidded' => 'searched', 'user_referer' => 'browser',
                'user_bid' => '0', 'user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => '0',
                'cloak_referer' => 'origin', 'user_pref_ad_settings' => 'show_all',
            ], JSON_THROW_ON_ERROR);
        }
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr',
                dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php', self::PAGE,
            ],
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
        $result = json_decode(substr($stdout, $at + strlen("\n@@RESULT ")), true, 8, JSON_THROW_ON_ERROR);
        self::assertNull($result['uncaught'], $stdout . $stderr);

        return ['output' => substr($stdout, 0, $at) . $stderr, 'result' => $result];
    }

    private static function stored(): string
    {
        $result = self::$db->query(
            'SELECT user_pref_click_id_params FROM 202_users_pref WHERE user_id = ' . self::USER
        );
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_row()[0];
    }

    /** The field as the page drew it. */
    private static function field(string $html): string
    {
        $found = preg_match('/<input[^>]*name="user_pref_click_id_params"[^>]*>/', $html, $m);
        self::assertSame(1, $found, 'the page has the field');

        return $m[0];
    }

    public function testThePageStoresTheNamesAndShowsThemBack(): void
    {
        self::page(['user_pref_click_id_params' => ' Aff_Sub3, sub5 ']);
        self::assertSame('aff_sub3,sub5', self::stored());

        $shown = self::page()['output'];
        self::assertStringContainsString('value="aff_sub3,sub5"', self::field($shown));
        self::assertStringContainsString('Match conversions by ad click id', $shown);

        self::page(['user_pref_click_id_params' => '']);
        self::assertSame('', self::stored(), 'an empty box lists none');
    }

    public function testWhatIsNotAListOfNamesIsRefusedUnderTheFieldAndNothingIsSaved(): void
    {
        self::page(['user_pref_click_id_params' => 'aff_sub3']);
        $refusals = ['gclid' => 'is read without being listed', '{aff_sub3}' => 'is not a parameter name',
            'subid' => 'already means something else'];
        foreach ($refusals as $bad => $why) {
            $refused = self::page(['user_pref_click_id_params' => $bad, 'user_timezone' => 'Europe/Paris'])['output'];
            self::assertStringContainsString($why, $refused, $bad);
            self::assertStringContainsString('is-invalid', self::field($refused), 'the field is marked');
            $landsOn = '/p202-flash__body[^>]*>\s*Your settings were not saved\. Match conversions by ad click id,'
                . ' under Advanced: [^<]*' . preg_quote($why, '/') . '/';
            self::assertSame(1, preg_match($landsOn, $refused), 'the message the page lands on says which box and why');
            $typed = 'value="' . htmlspecialchars($bad, ENT_QUOTES) . '"';
            self::assertStringContainsString($typed, self::field($refused), 'what was typed is kept');
            self::assertSame('aff_sub3', self::stored(), $bad);
        }
        $zone = self::$db->query('SELECT user_timezone FROM 202_users WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $zone);
        self::assertSame('UTC', $zone->fetch_row()[0], 'the rest of a refused form is not saved either');
    }

    public function testAFormThatDoesNotSendTheFieldLeavesItAsItWas(): void
    {
        self::page(['user_pref_click_id_params' => 'aff_sub3']);
        self::page([]);

        self::assertSame('aff_sub3', self::stored());
    }
}
