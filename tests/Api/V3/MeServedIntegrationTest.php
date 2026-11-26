<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * GET /me through api/v3/index.php itself, served by `php -S` over the
 * scratch database, for the seeded roles: what each may see and do, its
 * account's zone and currency, and the install version a role that GET
 * /system/version refuses still reads.
 *
 * @group integration
 */
final class MeServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const SUPER = 4101;
    private const MANAGER = 4102;
    private const VIEWER = 4103;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    /** @var array<string, string> */
    private static array $keys = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null;

            return;
        }
        self::seed();

        self::$state = sys_get_temp_dir() . '/p202-me-served-' . bin2hex(random_bytes(6));
        mkdir(self::$state, 0700);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = self::$state;
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$state !== '') {
            exec('rm -rf ' . escapeshellarg(self::$state));
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::SUPER, self::MANAGER, self::VIEWER] as $user) {
            foreach (['202_api_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::user(self::SUPER);
        self::user(self::MANAGER, 'Europe/Berlin');
        self::user(self::VIEWER, 'Not/AZone');
        // One install, on which nobody holds a ClickServer key: not licensed.
        // (With no install to find an owner on, the gate cannot tell, and lets the CLI through.)
        self::q("UPDATE 202_users SET install_hash = 'me-served-install' WHERE user_id IN (" . self::SUPER . ', ' . self::MANAGER . ', ' . self::VIEWER . ')');
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::SUPER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::MANAGER . ", user_account_currency = 'EUR', user_pref_ad_settings = 'hide_all'");
        // The viewer has no preferences row: the columns' defaults, as the pages read it.
        foreach ([self::SUPER => 1, self::MANAGER => 3, self::VIEWER => 5] as $user => $role) {
            self::q("INSERT INTO 202_user_role (user_id, role_id) VALUES ($user, $role)");
        }
        foreach (['super' => [self::SUPER, null], 'manager' => [self::MANAGER, null], 'manager_read' => [self::MANAGER, 'read'],
            'viewer' => [self::VIEWER, null]] as $name => [$user, $scope]) {
            $key = bin2hex(random_bytes(32));
            self::q("INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES ($user, "
                . self::str($key) . ', ' . ($scope === null ? 'NULL' : self::str($scope)) . ', NULL, ' . time() . ')');
            self::$keys[$name] = $key;
        }
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private static function get(string $path, string $key): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $raw];
    }

    /** @return array<string, mixed> */
    private function me(string $key): array
    {
        [$status, $body, $raw] = self::get('/me', $key);
        self::assertSame(200, $status, $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log));

        return $body['data'];
    }

    public function testACampaignManagerSeesItsAccountItsMoneyAndWhatItMayEdit(): void
    {
        self::requireScratch();
        $me = $this->me(self::$keys['manager']);

        self::assertSame(self::MANAGER, $me['user']['user_id']);
        self::assertSame('rp' . self::MANAGER, $me['user']['user_name']);
        self::assertSame(['timezone' => 'Europe/Berlin', 'currency' => 'EUR'], $me['account']);
        self::assertSame([['role_id' => 3, 'role_name' => 'Campaign manager']], $me['roles']);
        self::assertSame(['access_to_campaign_data', 'access_to_setup_section', 'access_to_update_section', 'view_attribution_reports'], $me['permissions']);
        self::assertSame(['can_see_money', 'can_view_setup', 'can_edit_campaigns', 'can_use_update_section', 'can_view_attribution'],
            array_keys(array_filter($me['flags'])));
        self::assertSame('hide_all', $me['switches']['sponsored_ads']);
        self::assertSame(['method' => 'api_key', 'csrf_token' => null], $me['auth']);
        self::assertContains('staged_writes', $me['features']);
    }

    public function testAReadOnlyKeyOfTheSameUserMayNotEdit(): void
    {
        self::requireScratch();
        $me = $this->me(self::$keys['manager_read']);

        self::assertSame(['read'], $me['scopes']);
        self::assertSame(['can_see_money', 'can_view_setup', 'can_view_attribution'], array_keys(array_filter($me['flags'])));
    }

    public function testAViewerSeesNoMoneyAndReadsTheVersionTheSystemRouteRefusesIt(): void
    {
        self::requireScratch();
        $me = $this->me(self::$keys['viewer']);

        self::assertSame([], array_keys(array_filter($me['flags'])));
        self::assertSame([], $me['permissions']);
        self::assertSame(['timezone' => 'UTC', 'currency' => 'USD'], $me['account'], 'an unknown zone and no preferences row');
        self::assertSame('show_all', $me['switches']['sponsored_ads']);
        self::assertSame('not_licensed', $me['switches']['licence'], 'nobody on the install holds a ClickServer key');
        require_once dirname(__DIR__, 3) . '/202-config/version.php';
        self::assertSame(PROSPER202_VERSION, $me['install']['version']);
        self::assertSame('v3', $me['install']['api_version']);

        [$status] = self::get('/system/version', self::$keys['viewer']);
        self::assertSame(403, $status, 'the admin route still refuses the viewer');

        [$status, $body, $raw] = self::get('/system/version', self::$keys['super']);
        self::assertSame(200, $status, $raw);
        self::assertSame($body['data']['prosper202_version'], $me['install']['schema_version'], 'the same stored version the admin route reads');
    }

    public function testTheSuperUserHoldsEveryFlag(): void
    {
        self::requireScratch();
        $me = $this->me(self::$keys['super']);

        self::assertSame([], array_keys(array_filter($me['flags'], static fn (bool $on): bool => !$on)));
        self::assertTrue($me['flags']['is_admin']);
    }

    public function testNoKeyIsRefused(): void
    {
        self::requireScratch();
        [$status] = self::get('/me', 'not-a-key');
        self::assertSame(401, $status);
    }
}
