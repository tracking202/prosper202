<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * POST /ppc-networks/chatgpt-ads through api/v3/index.php itself, served by
 * `php -S` over the scratch database: 201 when it made the source, 200 when
 * it was already there; what it made is in the change feed
 * (GET /changes/ppc-networks and /changes/ppc-accounts), as a source and an
 * account made with POST are; and a key that may change traffic sources but
 * not their accounts is refused, since the action makes an account.
 *
 * @group integration
 */
final class ChatGptAdsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3971;

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
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        self::seed();

        self::$state = sys_get_temp_dir() . '/p202-chatgpt-served-' . bin2hex(random_bytes(6));
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
        $user = self::USER;
        self::q("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = $user");
        foreach (['202_api_keys', '202_user_role', '202_users_pref', '202_ppc_accounts', '202_ppc_networks', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = $user");
        }
    }

    /** A connection approved with "Read and change" before $area could be changed. */
    private static function connectionScopeBefore(string $area): string
    {
        return str_replace("$area:write", "$area:read", (string) AiConnect::scopeFor('write'));
    }

    private static function seed(): void
    {
        self::cleanUp();
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::user(self::USER);
        // An account with the Landing Page Optimizer on, whose dimension snapshot a new source changes.
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", lpo_status = 'active', lpo_bridge_config = '{}'");
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', 1)');
        foreach (['full' => null, 'sources_only' => 'ppc-networks:write'] as $name => $scope) {
            $key = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES (' . self::USER . ', '
                . self::str($key) . ', ' . ($scope === null ? 'NULL' : self::str($scope)) . ', NULL, ' . time() . ')');
            self::$keys[$name] = $key;
        }
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded body, raw body */
    private static function request(string $method, string $path, string $key, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $raw];
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    /** @return list<array<string, mixed>> the records the feed lists for $entity, made by self::USER */
    private function changes(string $entity): array
    {
        [$status, $body, $raw] = self::request('GET', '/changes/' . $entity . '?limit=500', self::$keys['full']);
        self::assertSame(200, $status, $raw . $this->serverLog());
        $mine = array_filter($body['data'] ?? [], static fn (array $item): bool => (int) ($item['actor_user_id'] ?? 0) === self::USER);

        return array_values(array_map(static fn (array $item): array => $item['record'] + ['operation' => $item['operation']], $mine));
    }

    public function testTheFirstCallMakesItTheSecondFindsItAndTheFeedListsWhatWasMade(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::request('POST', '/ppc-networks/chatgpt-ads', self::$keys['full']);
        self::assertSame(201, $status, $raw . $this->serverLog());
        $made = $body['data'];
        self::assertTrue($made['source_created']);

        [$status, $body, $raw] = self::request('POST', '/ppc-networks/chatgpt-ads', self::$keys['full']);
        self::assertSame(200, $status, $raw . $this->serverLog());
        self::assertFalse($body['data']['source_created']);
        self::assertSame($made['ppc_network_id'], $body['data']['ppc_network_id']);

        $sources = $this->changes('ppc-networks');
        self::assertCount(1, $sources, 'one create, for the one source made');
        self::assertSame(['create', (int) $made['ppc_network_id'], 'ChatGPT Ads', 'chatgpt_ads'], [
            $sources[0]['operation'], (int) $sources[0]['ppc_network_id'], $sources[0]['ppc_network_name'], $sources[0]['ppc_network_platform'],
        ]);
        $accounts = $this->changes('ppc-accounts');
        self::assertCount(1, $accounts);
        self::assertSame(['create', (int) $made['ppc_account_id'], (int) $made['ppc_network_id']], [
            $accounts[0]['operation'], (int) $accounts[0]['ppc_account_id'], (int) $accounts[0]['ppc_network_id'],
        ]);
        $config = json_decode((string) self::$db->query('SELECT lpo_bridge_config FROM 202_users_pref WHERE user_id = ' . self::USER)->fetch_row()[0], true);
        self::assertTrue($config['dims_dirty'] ?? null, 'the Landing Page Optimizer snapshot is marked for its next push');
    }

    public function testAKeyThatMayNotChangeAccountsIsRefusedAndNothingIsMade(): void
    {
        self::requireScratch();
        self::q("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = " . self::USER);
        self::q('DELETE FROM 202_ppc_accounts WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_ppc_networks WHERE user_id = ' . self::USER);
        [$status, $body, $raw] = self::request('POST', '/ppc-networks/chatgpt-ads', self::$keys['sources_only']);
        self::assertSame(403, $status, $raw . $this->serverLog());
        self::assertSame('ppc-accounts:write', $body['required_scope'] ?? null, $raw);
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_ppc_networks WHERE user_id = ' . self::USER)->fetch_row()[0]);
    }
}
