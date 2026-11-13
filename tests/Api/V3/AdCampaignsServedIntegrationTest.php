<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * ChatGPT Ads connections and campaigns as api/v3/index.php serves them, to the keys an AI connection holds:
 * `php -S` over the scratch database, the install pointed at the stand-in Advertiser API
 * (tests/fixtures/openai-ads/fake_openai_ads.py). What a hosted assistant reads of a connection (never its key),
 * the key routes refused to it (AdConnectionKeyAccess), a check that needs write, a proposal that carries what it
 * would change, and a template preview that sends nothing.
 *
 * @group integration
 */
final class AdCampaignsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3972;
    private const AD_KEY = 'sk-ads-live-campaigns-served-01';

    /** @var resource|null */
    private static $server = null;
    /** @var resource|null */
    private static $openAi = null;
    private static string $base = '';
    private static string $openAiOrigin = '';
    private static string $tls = '';
    private static string $state = '';
    private static string $log = '';
    /** @var array<string, string> api key by access ('full', and AiConnect's read, propose, write) */
    private static array $keys = [];
    private static ?string $stored = null;
    private static int $connection = 0;

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
        self::$tls = sys_get_temp_dir() . '/p202-fake-openai-campaigns-' . $unique;
        $command = ['python3', dirname(__DIR__, 2) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0',
            '--tls-dir', self::$tls, '--api-key', self::AD_KEY];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) !== 1) {
            return;
        }
        self::$openAi = $process;
        self::$openAiOrigin = 'https://127.0.0.1:' . $m[1];

        self::$state = sys_get_temp_dir() . '/p202-campaigns-state-' . $unique;
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
        [$status, $body] = self::call('full', 'POST', '/ad-connections', ['api_key' => self::AD_KEY]);
        self::assertSame(201, $status, (string) json_encode($body) . "\n" . (string) @file_get_contents(self::$log));
        self::$connection = (int) $body['data']['connection_id'];
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
        self::control('reset');
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
        foreach (['full' => '*'] + array_combine(AiConnect::ACCESSES, array_map(static fn (string $a): string => (string) AiConnect::scopeFor($a), AiConnect::ACCESSES)) as $access => $scope) {
            self::$keys[$access] = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, created_at, scope) VALUES (' . self::USER . ', '
                . self::str(self::$keys[$access]) . ', ' . time() . ', ' . self::str($scope) . ')');
        }
    }

    /** @return array<string, mixed> */
    private static function control(string $what, string $method = 'POST'): array
    {
        $context = stream_context_create([
            'http' => ['method' => $method, 'header' => 'Content-Type: application/json', 'content' => $method === 'POST' ? '{}' : ''],
            'ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true],
        ]);
        $answer = file_get_contents(self::$openAiOrigin . '/control/' . $what, false, $context);
        self::assertNotFalse($answer);

        return (array) json_decode((string) $answer, true);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private static function call(string $access, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$keys[$access], 'Content-Type: application/json']]
            + ($body !== null ? [CURLOPT_POSTFIELDS => (string) json_encode((object) $body)] : []));
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log));

        return [$status, $decoded, $raw];
    }

    /** @return list<array<string, mixed>> */
    private static function sentToOpenAi(): array
    {
        return array_values(array_filter((array) self::control('requests', 'GET')['requests'], static fn (mixed $r): bool => is_array($r)));
    }

    /** A hosted assistant (an AI connection's read key) lists the connections: what it needs, never a key. */
    public function testAHostedAssistantListsTheConnectionsAndNoKey(): void
    {
        [$status, $body, $raw] = self::call('read', 'GET', '/ad-connections');
        self::assertSame(200, $status, $raw);
        $found = array_values(array_filter($body['data'], static fn (array $c): bool => $c['connection_id'] === self::$connection));
        self::assertCount(1, $found, $raw);
        self::assertSame(['Northwind Outfitters', 'USD', 'America/New_York', 'connected'],
            [$found[0]['account']['name'] ?? null, $found[0]['account']['currency'] ?? null, $found[0]['account']['timezone'] ?? null, $found[0]['status'] ?? null], $raw);
        self::assertStringNotContainsString(self::AD_KEY, $raw);
        self::assertStringNotContainsString('capi-', $raw, 'nor a Conversions API key');
        self::assertArrayNotHasKey('credentials', $found[0]);
    }

    /** An AI connection's key, even with write, never reaches the connection's key: connect, replace, delete, setup. */
    public function testTheKeyRoutesRefuseAnAiConnectionsKey(): void
    {
        $id = self::$connection;
        foreach ([['POST', '/ad-connections', ['api_key' => self::AD_KEY]], ['PATCH', "/ad-connections/$id", ['api_key' => self::AD_KEY]],
            ['DELETE', "/ad-connections/$id", null], ['POST', "/ad-connections/$id/setup", []]] as [$method, $path, $body]) {
            [$status, $answer, $raw] = self::call('write', $method, $path, $body);
            self::assertSame(403, $status, "$method $path: $raw");
            self::assertStringContainsString('full access or write scope', (string) $answer['message'], "$method $path");
        }
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM 202_ad_connections WHERE deleted_at IS NULL AND user_id = ' . self::USER)->fetch_row()[0]);
    }

    /** A check records OpenAI's answer on the connection: a read key may not make it. */
    public function testACheckNeedsWrite(): void
    {
        [$status, , $raw] = self::call('read', 'POST', '/ad-connections/' . self::$connection . '/check');
        self::assertSame(403, $status, $raw);
        [$status, , $raw] = self::call('write', 'POST', '/ad-connections/' . self::$connection . '/check');
        self::assertSame(200, $status, $raw);
    }

    /** A propose-only key proposes a pause; the proposal shows the campaign and what would change; nothing is sent. */
    public function testAProposedPauseCarriesWhatItWouldChange(): void
    {
        [$status, $body, $raw] = self::call('propose', 'POST', '/ad-connections/' . self::$connection . '/campaigns/cmpn_101/pause?staged=1', []);
        self::assertSame(202, $status, $raw);
        $preview = $body['data']['preview'] ?? $body['preview'] ?? null;
        self::assertIsArray($preview, 'the proposal carries a preview: ' . $raw);
        self::assertSame(['Spring sale', ['from' => 'active', 'to' => 'paused']], [$preview['record']['name'] ?? null, $preview['change']['status'] ?? null], $raw);
        self::assertSame([], array_values(array_filter(self::sentToOpenAi(), static fn (array $r): bool => $r['method'] === 'POST')), 'nothing sent to OpenAI');
    }

    /** A template preview reads and sends nothing; the push says what it replaced. */
    public function testATemplatePreviewSendsNothing(): void
    {
        $path = '/ad-connections/' . self::$connection . '/campaigns/cmpn_101/template';
        [$status, $body, $raw] = self::call('full', 'POST', $path . '?dry_run=1', ['template' => 'a={ad_id}']);
        self::assertSame(200, $status, $raw);
        self::assertSame(['from' => null, 'to' => 'a={ad_id}'], $body['data']['change']['query_string_template'] ?? null, $raw);
        self::assertSame([], array_values(array_filter(self::sentToOpenAi(), static fn (array $r): bool => $r['method'] === 'POST')), 'a preview sends nothing');

        [$status, $body, $raw] = self::call('full', 'POST', $path, ['template' => 'a={ad_id}']);
        self::assertSame(200, $status, $raw);
        self::assertArrayHasKey('query_string_template', $body['data']['previous'] ?? [], $raw);
        self::assertSame(['a={ad_id}', null], [$body['data']['record']['query_string_template'] ?? false, $body['data']['previous']['query_string_template']], $raw);
    }
}
