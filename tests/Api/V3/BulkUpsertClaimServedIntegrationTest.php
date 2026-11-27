<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Through api/v3/index.php, served by `php -S` with more than one worker over
 * the scratch database: a bulk-upsert retried while the first request is
 * still writing is answered 409 idempotency_in_flight and stores nothing, and
 * the same request afterwards replays the first one's answer. The first
 * request is held at its insert by a table lock, so the retry really does
 * arrive while it runs (BulkUpsertClaimsItsKeyIntegrationTest has the rule;
 * this holds the router and two processes to it).
 *
 * @group integration
 */
final class BulkUpsertClaimServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3952;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    private static string $key = '';

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
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER);
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', 1)');
        self::$key = bin2hex(random_bytes(32));
        self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES (' . self::USER . ', '
            . self::str(self::$key) . ", 'write', " . time() . ')');

        self::$state = sys_get_temp_dir() . '/p202-served-state-' . bin2hex(random_bytes(6));
        mkdir(self::$state, 0700);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = self::$state;
        $env['PHP_CLI_SERVER_WORKERS'] = '3';
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
            self::$db->query('UNLOCK TABLES');
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_aff_networks WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
    }

    public function testARetryWhileTheFirstRequestWritesIsAnswered409AndStoresNothing(): void
    {
        self::requireScratch();
        $idempotencyKey = 'served-claim-' . bin2hex(random_bytes(6));
        $body = (string) json_encode(['rows' => [['aff_network_name' => 'served-claim']]]);

        // The first request stops at its INSERT, its claim already taken.
        self::q('LOCK TABLES 202_aff_networks WRITE');
        $first = self::handle($idempotencyKey, $body);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $first);
        $claimed = false;
        for ($i = 0; $i < 100 && !$claimed; $i++) {
            curl_multi_exec($multi, $running);
            self::assertSame(1, $running, 'the first request ended while the table was locked' . $this->serverLog());
            $claimed = self::claimFiles() !== [];
            usleep(50000);
        }
        self::assertTrue($claimed, 'the first request never claimed its key' . $this->serverLog());

        $retry = self::handle($idempotencyKey, $body);
        $answer = (string) curl_exec($retry);
        self::assertSame(409, curl_getinfo($retry, CURLINFO_RESPONSE_CODE), $answer . $this->serverLog());
        self::assertSame('idempotency_in_flight', json_decode($answer, true)['code'] ?? null, $answer);
        curl_multi_exec($multi, $running);
        self::assertSame(1, $running, 'the first request was not running when the retry was answered');

        self::q('UNLOCK TABLES');
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.2);
        } while ($running > 0);
        $stored = (string) curl_multi_getcontent($first);
        self::assertSame(200, curl_getinfo($first, CURLINFO_RESPONSE_CODE), $stored . $this->serverLog());
        self::assertSame(1, json_decode($stored, true)['summary']['created'] ?? null, $stored);

        $again = self::handle($idempotencyKey, $body);
        $replayed = json_decode((string) curl_exec($again), true);
        self::assertTrue($replayed['idempotent_replay'] ?? null, json_encode($replayed) . $this->serverLog());
        self::assertSame(json_decode($stored, true)['data'], $replayed['data']);

        $count = self::requireScratch()->query(
            "SELECT COUNT(*) FROM 202_aff_networks WHERE aff_network_name = 'served-claim' AND user_id = " . self::USER
        );
        self::assertInstanceOf(\mysqli_result::class, $count);
        self::assertSame('1', (string) $count->fetch_row()[0], 'the batch was stored once');
    }

    /** @return list<string> */
    private static function claimFiles(): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree(self::$state));
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getPath(), '/idempotency') && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return \CurlHandle */
    private static function handle(string $idempotencyKey, string $body)
    {
        $ch = curl_init(self::$base . '/api/v3/aff-networks/bulk-upsert');
        self::assertInstanceOf(\CurlHandle::class, $ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'Authorization: Bearer ' . self::$key,
                'Idempotency-Key: ' . $idempotencyKey,
            ],
        ]);

        return $ch;
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }
}
