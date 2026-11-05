<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SetupCodeController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * The postback token and signing secret through api/v3/index.php itself,
 * served by `php -S` over the scratch database, with real API keys: a key
 * that may only read conversions is given neither credential (they record
 * and reverse conversions), nothing is minted for it, and its postback code
 * comes without the token; a key with conversions:write gets both. A
 * rotation sent again under its Idempotency-Key answers the first rotation
 * instead of making another, and the recorded answer keeps no credential.
 *
 * @group integration
 */
final class PostbackAuthServedIntegrationTest extends TestCase
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

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_postback_auth WHERE user_id = ' . self::USER);
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_postback_auth WHERE user_id = ' . self::USER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER);
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', 1)');
        foreach (['read' => 'read', 'conversions_read' => 'conversions:read', 'write' => 'conversions:write'] as $name => $scope) {
            $key = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES (' . self::USER . ', '
                . self::str($key) . ', ' . self::str($scope) . ', ' . time() . ')');
            self::$keys[$name] = $key;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded body, raw body
     */
    private static function request(string $method, string $path, string $key, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Authorization: Bearer ' . $key], $headers)]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $raw];
    }

    private function context(string $raw): string
    {
        return $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    private static function stored(): ?string
    {
        $row = self::$db->query('SELECT token_sealed FROM 202_postback_auth WHERE user_id = ' . self::USER)->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    public function testAKeyThatMayOnlyReadConversionsIsGivenNoCredential(): void
    {
        foreach (['read' => 'conversions:write', 'conversions_read' => 'conversions:write'] as $name => $scope) {
            [$status, $body, $raw] = self::request('GET', '/conversions/postback-auth', self::$keys[$name]);
            self::assertSame(403, $status, $name . ': ' . $this->context($raw));
            self::assertSame('insufficient_scope', $body['code'] ?? null, $raw);
            self::assertSame($scope, $body['required_scope'] ?? null, $raw);
            foreach ([['PUT', '/conversions/postback-auth', ['mode' => 'off']], ['POST', '/conversions/postback-auth/rotate', []]] as [$method, $path, $payload]) {
                [$status, , $raw] = self::request($method, $path, self::$keys[$name], $payload);
                self::assertSame(403, $status, "$name $method $path: $raw");
            }

            [$status, $body, $raw] = self::request('GET', '/conversions/postback-code?subid=%7Baff_sub%7D', self::$keys[$name]);
            self::assertSame(200, $status, $name . ': ' . $this->context($raw));
            self::assertNull($body['data']['postback_token'], $raw);
            self::assertSame(SetupCodeController::TOKEN_WITHHELD, $body['data']['postback_token_withheld']);
            self::assertStringNotContainsString('p202_token=', $raw);
            self::assertNull(self::stored(), "$name: nothing was minted");
        }

        // Once the account has credentials, they are still withheld.
        [$status, $body, $raw] = self::request('GET', '/conversions/postback-auth', self::$keys['write']);
        self::assertSame(200, $status, $this->context($raw));
        $token = (string) $body['data']['token'];
        $secret = (string) $body['data']['signing_secret'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $token);
        foreach (['read', 'conversions_read'] as $name) {
            [, , $raw] = self::request('GET', '/conversions/postback-code', self::$keys[$name]);
            self::assertStringNotContainsString($token, $raw, $name);
            self::assertStringNotContainsString($secret, $raw, $name);
        }
    }

    public function testAKeyThatMayWriteConversionsGetsTheTokenInItsUrls(): void
    {
        [$status, $body, $raw] = self::request('GET', '/conversions/postback-code?subid=%7Baff_sub%7D', self::$keys['write']);
        self::assertSame(200, $status, $this->context($raw));
        $token = (string) $body['data']['postback_token'];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $token, $raw);
        self::assertNull($body['data']['postback_token_withheld']);
        self::assertStringEndsWith('gpb.php?amount=&subid={aff_sub}&p202_token=' . $token, $body['data']['simple']['postback_url']);
        [$status, $body, $raw] = self::request('GET', '/conversions/postback-auth', self::$keys['write']);
        self::assertSame(200, $status, $this->context($raw));
        self::assertSame($token, $body['data']['token']);
    }

    public function testARotationSentAgainUnderItsIdempotencyKeyIsNotMadeTwice(): void
    {
        [, $start] = self::request('GET', '/conversions/postback-auth', self::$keys['write']);
        $original = (string) $start['data']['token'];
        $idempotency = ['Idempotency-Key: rotate-' . bin2hex(random_bytes(6))];
        $body = ['credential' => 'token', 'grace_hours' => 0];

        [$status, $first, $raw] = self::request('POST', '/conversions/postback-auth/rotate', self::$keys['write'], $body, $idempotency);
        self::assertSame(200, $status, $this->context($raw));
        $rotated = (string) $first['data']['token'];
        self::assertNotSame($original, $rotated);
        $sealed = self::stored();

        [$status, $again, $raw] = self::request('POST', '/conversions/postback-auth/rotate', self::$keys['write'], $body, $idempotency);
        self::assertSame(200, $status, $this->context($raw));
        self::assertTrue($again['idempotent_replay'] ?? null, $raw);
        self::assertSame($rotated, $again['data']['token'], 'the first rotation\'s token, not a second one');
        self::assertSame($first['data']['token_created_at'], $again['data']['token_created_at']);
        self::assertSame($sealed, self::stored(), 'nothing was written again');

        exec('grep -rl ' . escapeshellarg($rotated) . ' ' . escapeshellarg(self::$state) . ' 2>/dev/null', $found);
        self::assertSame([], $found, 'the recorded answer keeps no credential');

        [$status, $other, $raw] = self::request('POST', '/conversions/postback-auth/rotate', self::$keys['write'], $body,
            ['Idempotency-Key: rotate-' . bin2hex(random_bytes(6))]);
        self::assertSame(200, $status, $raw);
        self::assertNotSame($rotated, $other['data']['token'], 'a new key rotates again');
    }

    public function testARotationReplacesCredentialsWhoseKeyWasLost(): void
    {
        [, $start] = self::request('GET', '/conversions/postback-auth', self::$keys['write']);
        $key = (string) self::$db->query("SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = 'postback_token'")->fetch_row()[0];
        self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'postback_token'");
        try {
            [$status, $body, $raw] = self::request('POST', '/conversions/postback-auth/rotate', self::$keys['write'], ['grace_hours' => 24]);
            self::assertSame(200, $status, $this->context($raw));
            self::assertSame(['token', 'signing_secret'], $body['data']['recovered']['credentials'], $raw);
            self::assertTrue($body['data']['recovered']['key_recreated']);
            self::assertStringContainsString('stopped working', $body['data']['recovered']['message']);
            self::assertNull($body['data']['previous_token_valid_until'], 'an old token that cannot be read is not kept');
            self::assertNotSame($start['data']['signing_secret'], $body['data']['signing_secret']);
        } finally {
            self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'postback_token'");
            self::q("INSERT INTO 202_deployment_secrets SET secret_name = 'postback_token', secret_value = " . self::str($key)
                . ', created_at = 0');
        }
    }
}
