<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\LtvAccess;
use PHPUnit\Framework\TestCase;

/**
 * GET /capabilities and PUT /users/{id}/preferences through api/v3/index.php
 * itself, served by `php -S` over the scratch database. principal.ltv says,
 * part by part, what the /ltv routes would answer this key's role and scope,
 * and the LTV › Settings values among the preferences are changed only by a
 * key that may change LTV's settings, while a GET body sent back whole still
 * passes for any role that may write its personal settings.
 *
 * @group integration
 */
final class LtvAccessServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    /** user id => the permissions of the one role it holds */
    private const USERS = [
        990581 => ['access_to_personal_settings'],
        990582 => ['access_to_personal_settings', 'access_to_campaign_data'],
        990583 => ['access_to_personal_settings', 'access_to_campaign_data', 'access_to_setup_section'],
        990584 => ['access_to_personal_settings', 'access_to_campaign_data', 'access_to_setup_section',
            'access_to_api_integrations'],
    ];
    private const ALL = 990584;
    private const NONE = 990581;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    /** @var array<string, string> name => key */
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
        self::cleanUp();
        $found = self::$db->query('SELECT 1 FROM 202_permissions'
            . " WHERE permission_description = 'access_to_api_integrations'");
        if (!$found instanceof \mysqli_result || $found->num_rows === 0) {
            (new \Prosper202\Database\DataSeeder(self::$db))->seedPermissions();
        }
        foreach (self::USERS as $user => $permissions) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD',"
                . ' user_ltv_customer_cparam = 1');
            self::q("INSERT INTO 202_roles SET role_id = $user, role_name = 'ltv access test $user'");
            self::q("INSERT INTO 202_role_permission (role_id, permission_id) SELECT $user, permission_id"
                . ' FROM 202_permissions WHERE permission_description IN ('
                . implode(', ', array_map(self::str(...), $permissions)) . ')');
            self::q("INSERT INTO 202_user_role (user_id, role_id) VALUES ($user, $user)");
            self::$keys[(string) $user] = self::key($user, '');
        }
        self::$keys['read'] = self::key(self::ALL, 'ltv:read');
        self::$keys['users only'] = self::key(self::ALL, 'users:write');

        self::$state = sys_get_temp_dir() . '/p202-ltv-access-served-' . bin2hex(random_bytes(6));
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

    private static function key(int $user, string $scope): string
    {
        $key = bin2hex(random_bytes(32));
        self::q("INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES ($user, "
            . self::str($key) . ', ' . self::str($scope) . ', ' . time() . ')');

        return $key;
    }

    private static function cleanUp(): void
    {
        $ids = implode(', ', array_keys(self::USERS));
        foreach (['202_api_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($ids)");
        }
        self::q("DELETE FROM 202_role_permission WHERE role_id IN ($ids)");
        self::q("DELETE FROM 202_roles WHERE role_id IN ($ids)");
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>, 2: string} status, body, what to show on failure
     */
    private static function call(string $method, string $path, string $key, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $options);
        $raw = (string) curl_exec($ch);
        $decoded = json_decode($raw, true);
        $context = "$method $path: $raw\nserver log:\n" . (string) @file_get_contents(self::$log);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_array($decoded) ? $decoded : [], $context];
    }

    private static function storedCparam(int $user): int
    {
        $found = self::requireScratch()
            ->query("SELECT user_ltv_customer_cparam FROM 202_users_pref WHERE user_id = $user");
        $row = $found instanceof \mysqli_result ? $found->fetch_row() : null;
        self::assertIsArray($row, "user $user has a preferences row");

        return (int) $row[0];
    }

    public function testCapabilitiesSaysWhichPartsTheKeyMayUse(): void
    {
        self::requireScratch();
        $expected = [
            '990581' => ['read' => false, 'settings_read' => false, 'crm_write' => false, 'incoming' => false,
                'settings_write' => false],
            '990582' => ['read' => true, 'settings_read' => false, 'crm_write' => false, 'incoming' => true,
                'settings_write' => false],
            '990583' => ['read' => true, 'settings_read' => false, 'crm_write' => true, 'incoming' => true,
                'settings_write' => false],
            '990584' => ['read' => true, 'settings_read' => true, 'crm_write' => true, 'incoming' => true,
                'settings_write' => true],
            'read' => ['read' => true, 'settings_read' => true, 'crm_write' => false, 'incoming' => false,
                'settings_write' => false],
        ];
        foreach ($expected as $name => $parts) {
            [$status, $body, $context] = self::call('GET', '/capabilities', self::$keys[$name]);
            self::assertSame(200, $status, $context);
            self::assertSame(array_keys(LtvAccess::PARTS), array_keys($parts), 'the cases name every part');
            self::assertSame($parts, $body['data']['principal']['ltv'] ?? null, "key $name: $context");
        }
    }

    public function testWhatCapabilitiesSaysIsWhatTheRoutesAnswer(): void
    {
        self::requireScratch();
        // One read route of each kind: a part said to be refused is refused.
        $routes = ['read' => '/ltv/customers', 'settings_read' => '/ltv/webhooks'];
        foreach (array_keys(self::USERS) as $user) {
            [, $body] = self::call('GET', '/capabilities', self::$keys[(string) $user]);
            foreach ($routes as $part => $path) {
                [$status, , $context] = self::call('GET', $path, self::$keys[(string) $user]);
                $may = $body['data']['principal']['ltv'][$part] ?? null;
                self::assertSame($may ? 200 : 403, $status, "user $user, $part: $context");
            }
        }
    }

    public function testAnLtvSettingsChangeAsksWhatLtvSettingsAsks(): void
    {
        self::requireScratch();
        $none = self::NONE;
        $mine = self::$keys[(string) $none];
        $change = ['user_ltv_customer_cparam' => 3];
        [$status, $body, $context] = self::call('PUT', "/users/$none/preferences", $mine, $change);
        self::assertSame(403, $status, $context);
        self::assertStringContainsString("'access_to_api_integrations' permission", $body['message'] ?? '', $context);
        self::assertSame(1, self::storedCparam($none), 'the refused change was not written');

        // Another preference still changes; the stored LTV value sent with it is no change.
        $body = ['user_ltv_customer_cparam' => 1, 'user_pref_limit' => 50];
        [$status, , $context] = self::call('PUT', "/users/$none/preferences", $mine, $body);
        self::assertSame(200, $status, $context);

        // The GET body sent back whole is no change to the LTV values either.
        [$status, $served, $context] = self::call('GET', "/users/$none/preferences", $mine);
        self::assertSame(200, $status, $context);
        $echo = $served['data'];
        $echo['user_pref_limit'] = 100;
        [$status, , $context] = self::call('PUT', "/users/$none/preferences", $mine, $echo);
        self::assertSame(200, $status, $context);

        $all = self::ALL;
        [$status, , $context] = self::call('PUT', "/users/$all/preferences", self::$keys['users only'], $change);
        self::assertSame(403, $status, 'a key without ltv:write: ' . $context);
        self::assertSame(1, self::storedCparam($all));

        [$status, , $context] = self::call('PUT', "/users/$all/preferences", self::$keys[(string) $all], $change);
        self::assertSame(200, $status, $context);
        self::assertSame(3, self::storedCparam($all));
    }
}
