<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * An ad platform that could not be asked, as api/v3/index.php serves it: `php -S` over the scratch database, with
 * the install pointed at the stand-in Advertiser API (tests/fixtures/openai-ads/fake_openai_ads.py) told to answer
 * an outage, an answer its API does not document, or its rate limit. Each is the status, the Retry-After header
 * and the body the docs say (documentation/api/28-ad-connections.md), and nothing is stored.
 *
 * @group integration
 */
final class AdPlatformAnswersServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3971;
    private const AD_KEY = 'sk-ads-live-served-answers-01';

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
        self::$tls = sys_get_temp_dir() . '/p202-fake-openai-served-' . $unique;
        $command = ['python3', dirname(__DIR__, 2) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0',
            '--tls-dir', self::$tls, '--api-key', self::AD_KEY];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) !== 1) {
            return; // skipped in setUp: python3 and openssl are needed
        }
        self::$openAi = $process;
        self::$openAiOrigin = 'https://127.0.0.1:' . $m[1];

        self::$state = sys_get_temp_dir() . '/p202-served-state-' . $unique;
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
        self::q('DELETE FROM 202_ad_connections WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
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

    /** Every request the stand-in gets from now answers $status with $body, as it is. */
    private static function openAiAnswers(int $status, string $body): void
    {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json',
                'content' => (string) json_encode(['status' => $status, 'body' => $body])],
            'ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true],
        ]);
        self::assertNotFalse(file_get_contents(self::$openAiOrigin . '/control/answer', false, $context));
    }

    /**
     * A connect as a client sends it.
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string|null} status, decoded body, Retry-After (null: absent)
     */
    private static function connect(): array
    {
        $retryAfter = null;
        $ch = curl_init(self::$base . '/api/v3/ad-connections');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) json_encode(['api_key' => self::AD_KEY]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, strlen('Retry-After:')));
                }

                return strlen($line);
            }]);
        $answer = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($answer, true);
        self::assertIsArray($decoded, $answer . "\nserver log:\n" . (string) @file_get_contents(self::$log));

        return [$status, $decoded, $retryAfter];
    }

    private static function stored(): int
    {
        $found = self::$db->query('SELECT COUNT(*) FROM 202_ad_connections WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $found);

        return (int) $found->fetch_row()[0];
    }

    /**
     * The three answers in one test, the rate limit last: an install that met OpenAI's limit holds to it for the
     * minute after (this server's state directory is its own).
     */
    public function testEachUpstreamFailureIsServedAsTheDocsSay(): void
    {
        $cases = [
            'an outage' => [503, '{"error":{"message":"Service unavailable"}}', 503, 'unreachable', '60',
                'OpenAI could not be reached, so the key was not checked and nothing was saved'],
            'an answer its API does not document' => [200, '{"name":"Northwind Outfitters"}', 502, 'unreadable_answer',
                null, 'OpenAI\'s answer about the ad account cannot be used, so the key was not checked'],
            'its rate limit' => [429, '{"error":{"message":"Too many requests"}}', 429, 'rate_limited', '60',
                'OpenAI is limiting requests, so the key was not checked and nothing was saved'],
        ];
        foreach ($cases as $what => [$vendorStatus, $vendorBody, $status, $reason, $retryAfter, $said]) {
            self::openAiAnswers($vendorStatus, $vendorBody);
            [$got, $body, $header] = self::connect();
            self::assertSame($status, $got, $what . ': ' . json_encode($body));
            self::assertSame($retryAfter, $header, $what . ': the Retry-After header');
            $seconds = $retryAfter === null ? null : (int) $retryAfter;
            self::assertSame($seconds, $body['retry_after_seconds'] ?? null, $what);
            self::assertStringContainsString($said, (string) $body['message'], $what);
            self::assertSame(['upstream' => 'openai_ads', 'reason' => $reason], $body['details'] ?? null, $what);
            self::assertArrayNotHasKey('field_errors', $body, $what . ': not a refusal of what was sent');
            self::assertStringNotContainsString(self::AD_KEY, json_encode($body), $what);
            self::assertSame(0, self::stored(), $what . ': nothing stored');
        }
    }
}
