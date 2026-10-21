<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Through api/v3/index.php itself, served by `php -S` over the scratch
 * database: a source past its budget of failed connect exchanges is answered
 * 429 with Retry-After before the exchange runs, and a proposal is discarded
 * only by the key that staged it or a key that may write its area. The rules
 * have unit tests (AiConnectFailureBudgetTest, StagedChangesControllerTest);
 * this holds the router to delivering them.
 *
 * @group integration
 */
final class ConnectAndProposalsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3951;
    private const CAMPAIGN = 3951;

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
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER);
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', 1)');
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ', aff_campaign_id_public = ' . (self::CAMPAIGN + 100000) . ", aff_network_id = 0,"
            . " aff_campaign_name = 'served campaign', aff_campaign_url = 'https://offer.example/',"
            . ' aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = ' . time());
        $propose = (string) AiConnect::scopeFor('propose');
        foreach (['a' => $propose, 'b' => $propose, 'w' => 'write'] as $name => $scope) {
            $key = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES (' . self::USER . ', '
                . self::str($key) . ', ' . self::str($scope) . ', ' . time() . ')');
            self::$keys[$name] = $key;
        }
    }

    /** @return array{0: int, 1: string, 2: string} status, headers, body */
    private static function request(string $method, string $path, ?string $key = null, ?string $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $headers = ['Content-Type: application/json'];
        if ($key !== null) {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    private static function junkExchange(): array
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return self::request('POST', '/connect/token', null, (string) json_encode([
            'code' => $code, 'code_verifier' => str_repeat('v', 50),
        ]));
    }

    public function testASourcePastItsFailureBudgetIsAnswered429WithRetryAfter(): void
    {
        self::requireScratch();
        for ($i = 1; $i <= 30; $i++) {
            [$status, , $body] = self::junkExchange();
            self::assertSame(400, $status, "exchange $i: $body" . $this->serverLog());
        }
        [$status, $headers, $body] = self::junkExchange();
        self::assertSame(429, $status, $body . $this->serverLog());
        self::assertMatchesRegularExpression('~^Retry-After: [1-9][0-9]*\r?$~mi', $headers);
    }

    private static function stage(): string
    {
        [$status, , $body] = self::request(
            'PUT',
            '/campaigns/' . self::CAMPAIGN . '?staged=1',
            self::$keys['a'],
            (string) json_encode(['aff_campaign_name' => 'proposed ' . bin2hex(random_bytes(3))])
        );
        self::assertContains($status, [200, 201, 202], 'staged: ' . $body);
        $id = json_decode($body, true)['data']['change_id'] ?? null;
        self::assertIsString($id, $body);

        return $id;
    }

    public function testAProposalIsDiscardedOnlyByItsKeyOrAWriter(): void
    {
        self::requireScratch();
        $first = self::stage();
        [$status, , $body] = self::request('POST', "/staged-changes/$first/discard", self::$keys['b']);
        self::assertSame(403, $status, 'another propose key: ' . $body . $this->serverLog());
        [$status, , $body] = self::request('POST', "/staged-changes/$first/discard", self::$keys['a']);
        self::assertSame(200, $status, 'the key that staged it: ' . $body . $this->serverLog());

        $second = self::stage();
        [$status, , $body] = self::request('POST', "/staged-changes/$second/discard", self::$keys['w']);
        self::assertSame(200, $status, 'a write key: ' . $body . $this->serverLog());

        $third = self::stage();
        [$status, , $body] = self::request('POST', "/staged-changes/$third/apply", self::$keys['a']);
        self::assertSame(403, $status, 'a propose key applying its own proposal: ' . $body . $this->serverLog());
    }
}
