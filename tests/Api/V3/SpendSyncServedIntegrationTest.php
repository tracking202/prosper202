<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * POST /spend-ledger/sync/{id}/run as api/v3/index.php serves it: `php -S`
 * over the scratch database, the install pointed at the stand-in Advertiser
 * API (tests/fixtures/openai-ads/fake_openai_ads.py). Each way OpenAI can
 * fail is answered as the ad connections answer it
 * (AdPlatformAnswersServedIntegrationTest): its limit 429 with its Retry-After,
 * an outage 503 with Retry-After, an answer that cannot be used 502, a
 * refused key 422 naming the connection; and a new key ends the refused
 * key's back-off.
 *
 * @group integration
 */
final class SpendSyncServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3981;
    private const AD_KEY = 'sk-ads-live-served-spend-01';

    /** @var resource|null */
    private static $server = null;
    /** @var resource|null */
    private static $openAi = null;
    private static string $base = '';
    private static string $openAiOrigin = '';
    private static string $tls = '';
    private static string $state = '';
    private static string $log = '';
    private static string $key = '';
    private static ?string $stored = null;

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
        require_once $root . '/202-config/version.php';
        $version = self::$db->query('SELECT version FROM 202_version');
        self::assertInstanceOf(\mysqli_result::class, $version, self::$db->error);
        $row = $version->fetch_row();
        self::$stored = is_array($row) ? (string) $row[0] : null;
        if (self::$stored === null) {
            self::q('INSERT INTO 202_version (version) VALUES (' . self::str(PROSPER202_VERSION) . ')');
        }
        self::seed();

        $unique = getmypid() . '-' . bin2hex(random_bytes(3));
        self::$tls = sys_get_temp_dir() . '/p202-fake-openai-spend-' . $unique;
        $command = ['python3', dirname(__DIR__, 2) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0',
            '--tls-dir', self::$tls, '--api-key', self::AD_KEY];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) !== 1) {
            return; // skipped in setUp: python3 and openssl are needed
        }
        self::$openAi = $process;
        self::$openAiOrigin = 'https://127.0.0.1:' . $m[1];

        self::$state = sys_get_temp_dir() . '/p202-served-spend-state-' . $unique;
        mkdir(self::$state, 0700);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $env = [
            'P202_OPENAI_ADS_ENDPOINT' => self::$openAiOrigin,
            'P202_OPENAI_ADS_CA_FILE' => self::$tls . '/cert.pem',
            'P202_SERVER_STATE_DIR' => self::$state,
        ] + getenv();
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($server);
        self::$server = $server;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
        self::$base = "http://127.0.0.1:$port";
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$server, self::$openAi] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        self::$server = self::$openAi = null;
        foreach (['cert.pem', 'key.pem'] as $file) {
            @unlink(self::$tls . '/' . $file);
        }
        @rmdir(self::$tls);
        foreach (glob(self::$state . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$state);
        @unlink(self::$log);
        if (self::$db !== null) {
            self::q(self::$stored !== null
                ? 'UPDATE 202_version SET version = ' . self::str(self::$stored)
                : 'DELETE FROM 202_version');
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$openAi === null) {
            self::markTestSkipped('The fake Advertiser API did not start (python3 and openssl are needed).');
        }
    }

    private static function cleanUp(): void
    {
        foreach (['202_spend_ledger', '202_ad_spend_sync', '202_ad_platform_conversions', '202_ad_connections', '202_api_keys',
            '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_account_currency = 'USD'");
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', 1)');
        self::$key = bin2hex(random_bytes(32));
        self::q('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (' . self::USER . ', '
            . self::str(self::$key) . ', ' . time() . ')');
    }

    /** @param array<string, mixed> $body */
    private static function control(string $path, array $body): void
    {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => (string) json_encode($body)],
            'ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true],
        ]);
        self::assertNotFalse(file_get_contents(self::$openAiOrigin . $path, false, $context));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>, 2: string|null} status, decoded body, Retry-After (null: absent)
     */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $retryAfter = null;
        $ch = curl_init(self::$base . '/api/v3/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, strlen('Retry-After:')));
                }

                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode((object) $body));
        }
        $answer = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($answer, true);
        self::assertIsArray($decoded, $answer . "\nserver log:\n" . (string) @file_get_contents(self::$log));
        self::assertStringNotContainsString(self::AD_KEY, $answer);

        return [$status, $decoded, $retryAfter];
    }

    /** @return array<string, mixed> */
    private static function syncState(int $id): array
    {
        [$status, $body] = self::request('GET', 'spend-ledger/sync');
        self::assertSame(200, $status);
        foreach ($body['data'] as $state) {
            if ($state['connection_id'] === $id) {
                return $state;
            }
        }
        self::fail('connection ' . $id . ' is not listed');
    }

    /**
     * One connection through every answer, the rate limit last: an install
     * that met OpenAI's limit holds to it for the minute after.
     */
    public function testEachWayARunCanFailIsAnsweredAsTheAdConnectionsAnswerIt(): void
    {
        [$status, $made] = self::request('POST', 'ad-connections', ['api_key' => self::AD_KEY]);
        self::assertSame(201, $status, (string) json_encode($made));
        $id = (int) $made['data']['connection_id'];
        $run = 'spend-ledger/sync/' . $id . '/run';

        [$status, $body] = self::request('POST', 'spend-ledger/sync/' . ($id + 1000) . '/run', []);
        self::assertSame(404, $status, 'an unknown id');
        [$status, $body] = self::request('POST', $run, []);
        self::assertSame([422, ['enabled']], [$status, array_keys($body['field_errors'] ?? [])], 'a sync that is off');

        [$status] = self::request('PUT', 'spend-ledger/sync/' . $id, ['enabled' => true]);
        self::assertSame(200, $status);
        [$status, $body] = self::request('POST', $run, ['from' => 1]);
        self::assertSame([422, ['from']], [$status, array_keys($body['field_errors'] ?? [])], 'a body is refused');

        // An outage: 503 with OpenAI's Retry-After, nothing judged.
        self::control('/control/answer', ['status' => 503, 'body' => '{"error":{"message":"Service unavailable"}}', 'retry_after' => '120']);
        [$status, $body, $header] = self::request('POST', $run, []);
        self::assertSame([503, '120', 120], [$status, $header, $body['retry_after_seconds'] ?? null], (string) json_encode($body));
        self::assertSame(['upstream' => 'openai_ads', 'connection_id' => $id, 'rows_written' => 0, 'reason' => 'unreachable'], $body['details'] ?? null);
        self::assertStringNotContainsString('were written', (string) $body['message'], 'nothing was');
        self::assertArrayNotHasKey('field_errors', $body);
        self::assertStringContainsString('Spend sync run for connection ' . $id . ' failed: OpenAI answered 503', (string) $body['message']);

        // OpenAI's own 5xx without a Retry-After: 60 seconds.
        self::control('/control/answer', ['status' => 502, 'body' => 'Bad gateway']);
        [$status, , $header] = self::request('POST', $run, []);
        self::assertSame([503, '60'], [$status, $header]);

        // An answer that cannot be used: 502, no Retry-After.
        self::control('/control/answer', ['status' => 200, 'body' => '<html>maintenance</html>']);
        [$status, $body, $header] = self::request('POST', $run, []);
        self::assertSame([502, null], [$status, $header], (string) json_encode($body));
        self::assertSame('unreadable_answer', $body['details']['reason'] ?? null);

        // A request OpenAI refused (another 4xx): 422 naming it in OpenAI's words, not a retry.
        self::control('/control/answer', ['status' => 400, 'body' => '{"error":{"message":"time_ranges is invalid"}}']);
        [$status, $body, $header] = self::request('POST', $run, []);
        self::assertSame([422, null, ['request']], [$status, $header, array_keys($body['field_errors'] ?? [])], (string) json_encode($body));
        self::assertStringContainsString('OpenAI answered 400: time_ranges is invalid', (string) $body['field_errors']['request']);

        // A refused key: 422 naming the connection, and the hourly run waits.
        self::control('/control/answer', ['status' => 401, 'body' => '{"error":{"message":"Invalid API key"}}']);
        [$status, $body, $header] = self::request('POST', $run, []);
        self::assertSame([422, null], [$status, $header], (string) json_encode($body));
        self::assertSame(['api_key'], array_keys($body['field_errors'] ?? []));
        self::assertStringContainsString('connection ' . $id, (string) $body['message']);
        self::assertStringContainsString('PATCH /ad-connections/' . $id, (string) $body['field_errors']['api_key']);
        self::assertGreaterThan(time() + 3500, self::syncState($id)['retry_after'], 'left alone for an hour');
        [$status, $body] = self::request('GET', 'ad-connections/' . $id);
        self::assertSame([200, 'key_refused'], [$status, $body['data']['status'] ?? null], 'marked as its check marks it');

        // A new key ends that wait.
        self::control('/control/reset', []);
        [$status, $body] = self::request('PATCH', 'ad-connections/' . $id, ['api_key' => self::AD_KEY]);
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertNull(self::syncState($id)['retry_after']);
        self::assertSame('connected', self::request('GET', 'ad-connections/' . $id)[1]['data']['status'] ?? null);

        [$status, $body] = self::request('POST', $run, []);
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertSame([true, 12, null, true], [$body['data']['run']['complete'], $body['data']['run']['rows'], $body['data']['left_out'], $body['data']['complete']]);

        // OpenAI's limit: 429 with OpenAI's own wait; the hourly run's longer back-off is details.retry_after.
        self::control('/control/answer', ['status' => 429, 'body' => '{"error":{"message":"Too many requests"}}', 'retry_after' => '60']);
        [$status, $body, $header] = self::request('POST', $run, []);
        self::assertSame([429, '60', 60], [$status, $header, $body['retry_after_seconds'] ?? null], (string) json_encode($body));
        self::assertSame('rate_limited', $body['details']['reason'] ?? null);
        self::assertSame(self::syncState($id)['retry_after'], $body['details']['retry_after'] ?? null);
        self::assertGreaterThan(time() + 3500, $body['details']['retry_after']);

        // A run that wrote spend and then failed (its conversions met the limit) says what it wrote.
        self::control('/control/reset', []);
        self::control('/control/answer', ['status' => 429, 'body' => '{"error":{"message":"Too many requests"}}', 'path' => '/v1/conversions/insights']);
        [$status, $body] = self::request('POST', $run, []);
        self::assertSame(429, $status, (string) json_encode($body));
        self::assertSame(12, $body['details']['rows_written'] ?? null);
        self::assertStringContainsString('12 hour row(s) were written before it stopped, and they stand', (string) $body['message']);
        $state = self::syncState($id);
        self::assertFalse($state['complete']);
        self::assertNotNull($state['last_error']);
    }
}
