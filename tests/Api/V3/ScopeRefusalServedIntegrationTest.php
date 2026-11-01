<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Through api/v3/index.php itself, served by `php -S` over the scratch
 * database: a key refused for a scope it lacks is told which scope and what
 * grants it, as a plain key (make another key) or as an AI app's connection
 * (approve it again), and a refusal for the user's role is answered as it
 * was. The wording has unit tests (ScopeRefusalTest); this holds the
 * dispatcher to answering every scope refusal with it, from the key's own
 * row.
 *
 * @group integration
 */
final class ScopeRefusalServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3961;
    private const VIEWER = 3962;
    private const SOURCE = 3961;

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

        self::$state = sys_get_temp_dir() . '/p202-served-state-' . bin2hex(random_bytes(6));
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
        foreach ([self::USER, self::VIEWER] as $user) {
            self::q('DELETE FROM 202_api_keys WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_users_pref WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_users WHERE user_id = ' . $user);
        }
        self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id = ' . self::SOURCE);
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
        foreach ([self::USER => 1, self::VIEWER => 5] as $user => $role) {
            self::user($user);
            self::q('INSERT INTO 202_users_pref SET user_id = ' . $user);
            self::q("INSERT INTO 202_user_role (user_id, role_id) VALUES ($user, $role)");
        }
        self::q('INSERT INTO 202_ppc_networks SET ppc_network_id = ' . self::SOURCE . ', user_id = ' . self::USER
            . ", ppc_network_name = 'served source', ppc_network_time = " . time());
        $label = AiConnect::label('Claude');
        $keys = [
            'plain' => [self::USER, 'campaigns:read', null],
            'old_connection' => [self::USER, self::connectionScopeBefore('ppc-networks'), $label],
            'connection' => [self::USER, (string) AiConnect::scopeFor('write'), $label],
            // A text-ads:write a connection may or may not be given (#260): never this one's.
            'text_ads_connection' => [self::USER, self::connectionScopeBefore('text-ads'), $label],
            'viewer' => [self::VIEWER, null, null],
        ];
        foreach ($keys as $name => [$user, $scope, $keyLabel]) {
            $key = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES (' . $user . ', '
                . self::str($key) . ', ' . ($scope === null ? 'NULL' : self::str($scope)) . ', '
                . ($keyLabel === null ? 'NULL' : self::str($keyLabel)) . ', ' . time() . ')');
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

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private static function renameSource(string $key, string $query = ''): array
    {
        $path = '/ppc-networks/' . self::SOURCE . $query;

        return self::request('PUT', $path, self::$keys[$key], ['ppc_network_name' => 'renamed']);
    }

    /** @param array<string, mixed> $body */
    private function assertScopeRefusal(array $body, string $scope, string $source, string $raw): void
    {
        $context = $raw . $this->serverLog();
        self::assertTrue($body['error'] ?? null, $context);
        self::assertSame(403, $body['status'] ?? null, $context);
        self::assertSame('insufficient_scope', $body['code'] ?? null, $context);
        self::assertSame($scope, $body['required_scope'] ?? null, $context);
        self::assertSame($source, $body['key_source'] ?? null, $context);
        self::assertIsString($body['remedy'] ?? null, $context);
        self::assertStringStartsWith(
            "Insufficient API key scope for this operation: requires '$scope' (key has: ",
            (string) ($body['message'] ?? ''),
            $context
        );
        self::assertStringEndsWith(' ' . $body['remedy'], (string) $body['message'], $context);
    }

    public function testAPlainKeyIsToldToMakeAKeyWithTheScope(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::renameSource('plain');
        self::assertSame(403, $status, $raw . $this->serverLog());
        $this->assertScopeRefusal($body, 'ppc-networks:write', 'api_key', $raw);
        self::assertSame('campaigns:read', $body['key_scope']);
        self::assertArrayNotHasKey('connect_access', $body);
        self::assertStringContainsString("make a key whose scope includes 'ppc-networks:write'", $body['remedy']);
    }

    public function testAConnectionApprovedBeforeAnAreaWasAddedIsToldToConnectAgain(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::renameSource('old_connection');
        self::assertSame(403, $status, $raw . $this->serverLog());
        $this->assertScopeRefusal($body, 'ppc-networks:write', 'ai_connection', $raw);
        self::assertSame('write', $body['connect_access']);
        self::assertStringContainsString('Connect the AI app again and choose "Read and change"', $body['message']);

        // The access approved today covers it: the refusal was the old scope's.
        [$status, , $raw] = self::renameSource('connection');
        self::assertSame(200, $status, $raw . $this->serverLog());
    }

    public function testAConnectionStagingIsToldTheSameWithoutLosingItsWrites(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::renameSource('old_connection', '?staged=1');
        self::assertSame(403, $status, $raw . $this->serverLog());
        $this->assertScopeRefusal($body, 'ppc-networks:stage', 'ai_connection', $raw);
        self::assertSame('write', $body['connect_access']);
    }

    /**
     * text-ads is read by every connection; whether a connection approved
     * today may write it is AiConnect::WRITE_AREAS's to say (#260 adds it),
     * and the answer follows it: connect again, or no connection can.
     */
    public function testAWriteAreaAConnectionLacksIsAnsweredByWhatAConnectionMayHold(): void
    {
        self::requireScratch();
        $key = self::$keys['text_ads_connection'];
        [$status, $body, $raw] = self::request('POST', '/text-ads', $key, ['text_ad_name' => 'x']);
        self::assertSame(403, $status, $raw . $this->serverLog());
        $this->assertScopeRefusal($body, 'text-ads:write', 'ai_connection', $raw);
        $access = AiConnect::accessCovering('text-ads:write');
        self::assertSame($access, $body['connect_access']);
        $says = $access === null
            ? "No AI connection's key can hold 'text-ads:write'"
            : 'choose "' . AiConnect::ACCESS_LABELS[$access] . '"';
        self::assertStringContainsString($says, $body['remedy']);

        [$status, $body, $raw] = self::request('POST', '/users', self::$keys['connection'], ['user_name' => 'x']);
        self::assertSame(403, $status, $raw . $this->serverLog());
        $this->assertScopeRefusal($body, 'users:write', 'ai_connection', $raw);
        self::assertNull($body['connect_access']);
    }

    public function testARoleRefusalIsAnsweredAsBefore(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::request('GET', '/sync/status', self::$keys['viewer']);
        self::assertSame(403, $status, $raw . $this->serverLog());
        self::assertSame(['error', 'message', 'status'], array_keys($body), $raw);
    }
}
