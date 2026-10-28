<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * A database dropped, created again under its name and installed again is a
 * new install, and the API answers it as one. Through api/v3/index.php,
 * served by `php -S` over the scratch database the served tree's
 * 202-config.php names, twice: with the API state in the temp dir's default
 * directory (no P202_SERVER_STATE_DIR), and under an operator's
 * P202_SERVER_STATE_DIR (the Coolify stack's api_state volume).
 *
 * The directory was named by the database's host and name, so the new
 * install took the old one's as its own: measured on a live instance, every
 * create the old install had answered under an Idempotency-Key was answered
 * 201 again with the old install's record and created nothing, and the old
 * install's staged DELETE of campaign 1 was listed to the new install and,
 * applied, deleted the new install's campaign 1. The install's id names the
 * directory now (InstallId), minted by the installer. P202_SERVER_STATE_DIR
 * was used as named, for whichever install was there, which kept the same
 * bug wherever an operator chose the directory; the install's own directory
 * inside it is named by the id too.
 *
 * @group integration
 */
final class ReinstalledDatabaseStateServedIntegrationTest extends TestCase
{
    /** tests/live/guard.sh's P202_SCRATCH_RE: a name that reads as disposable. */
    private const SCRATCH_RE
        = '/(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)/';

    private const BODY = '{"aff_network_name":"Reinstalled network"}';
    private const KEY = 'reinstall-test-key-1';

    private static ?\mysqli $server = null;
    private static string $name = '';
    /** @var list<resource> */
    private static array $php = [];
    /** The server with the state in the temp dir's default directory. */
    private static string $defaultBase = '';
    /** The server with P202_SERVER_STATE_DIR set to $operatorDir. */
    private static string $operatorBase = '';
    private static string $operatorDir = '';
    private static string $tmp = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php')) {
            return; // no config for the served tree: skipped, as without a database
        }
        self::$name = (string) (getenv('P202_TEST_DB_NAME') ?: 'p202_integration_test');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$server = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            '',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );

        // The served processes' temp dir, so the default state directory is
        // made here and nowhere a real instance reads; the operator's
        // directory is here too.
        self::$tmp = sys_get_temp_dir() . '/p202-reinstall-' . bin2hex(random_bytes(6));
        mkdir(self::$tmp, 0700);
        self::$operatorDir = self::$tmp . '/api-v3-state';
        $env = getenv();
        unset($env['P202_SERVER_STATE_DIR']);
        $env['TMPDIR'] = self::$tmp;
        self::$defaultBase = self::serve($root, $env, self::$tmp . '/default-server.log');
        self::$operatorBase = self::serve(
            $root,
            ['P202_SERVER_STATE_DIR' => self::$operatorDir] + $env,
            self::$tmp . '/operator-server.log'
        );
    }

    /** Serve $root with `php -S` in the environment $env, logging to $log: its base URL. */
    private static function serve(string $root, array $env, string $log): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $socket = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($socket, strrpos($socket, ':') + 1);
        fclose($probe);
        self::$php[] = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        for ($i = 0; $i < 50; $i++) {
            $up = @fsockopen('127.0.0.1', $port);
            if ($up !== false) {
                fclose($up);
                break;
            }
            usleep(100000);
        }

        return "http://127.0.0.1:$port";
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$php as $php) {
            if (is_resource($php)) {
                proc_terminate($php);
                proc_close($php);
            }
        }
        self::$php = [];
        if (self::$tmp !== '') {
            exec('rm -rf ' . escapeshellarg(self::$tmp));
        }
        if (self::$server !== null) {
            self::$server->close();
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            self::markTestSkipped('No test database and served config (P202_TEST_DB_HOST, 202-config.php).');
        }
        self::assertMatchesRegularExpression(self::SCRATCH_RE, self::$name, 'the database dropped here is scratch');
    }

    /** Drop the database, create it again, and install into it: the API key of its first account. */
    private static function install(): string
    {
        self::$server->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
        self::$server->query('CREATE DATABASE `' . self::$name . '`');
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/install-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $key = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), "the install failed: $err");
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $key, $err);

        return $key;
    }

    /** @return array{0: int, 1: array<string, mixed>} status, decoded body */
    private static function request(
        string $base,
        string $method,
        string $path,
        string $key,
        ?string $body = null,
        ?string $idempotencyKey = null
    ): array {
        $ch = curl_init($base . '/api/v3' . $path);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($out, true);

        return [$status, is_array($decoded) ? $decoded : ['raw' => $out]];
    }

    /** The install id the installer stored, before any request could mint one. */
    private static function installId(): string
    {
        $rows = self::$server->query('SELECT secret_value FROM `' . self::$name . '`.`202_deployment_secrets`'
            . " WHERE secret_name = 'install_id'")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, 'the installer minted the install id');

        return (string) $rows[0]['secret_value'];
    }

    /** @return list<string> the names of the aff networks in the database as it is now */
    private static function networks(): array
    {
        $rows = self::$server->query('SELECT aff_network_name FROM `' . self::$name . '`.`202_aff_networks`'
            . ' ORDER BY aff_network_id');

        return array_column($rows->fetch_all(MYSQLI_ASSOC), 'aff_network_name');
    }

    /** @return list<string> the API state directories the default server made in the temp dir */
    private static function defaultStateDirs(): array
    {
        $dirs = glob(self::$tmp . '/p202-api-v3-state-*', GLOB_ONLYDIR);

        return $dirs === false ? [] : $dirs;
    }

    /** @return list<string> what the operator's directory holds at its top level */
    private static function operatorDirEntries(): array
    {
        $names = @scandir(self::$operatorDir);

        return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
    }

    /** What the server at $base logged. */
    private static function serverLog(string $base): string
    {
        $which = $base === self::$operatorBase ? 'operator' : 'default';

        return "\nserver log:\n" . (string) @file_get_contents(self::$tmp . '/' . $which . '-server.log');
    }

    public function testAReinstalledDatabaseMakesTheWritesItIsSentAndListsNoOldProposal(): void
    {
        $this->reinstallUnder(
            self::$defaultBase,
            self::defaultStateDirs(...),
            'is not used: it holds the state of an earlier install'
        );
    }

    /**
     * The same, under one P202_SERVER_STATE_DIR: each install has a
     * directory of its own inside it, install-<hash of its id>, and the
     * first's is left as it was.
     */
    public function testAReinstallUnderOneStateDirMakesTheWritesItIsSentAndListsNoOldProposal(): void
    {
        $firstId = $this->reinstallUnder(
            self::$operatorBase,
            self::operatorDirEntries(...),
            'is not used: it holds the state of another install'
        );

        $entries = self::operatorDirEntries();
        sort($entries);
        $expected = ['install-' . substr(sha1($firstId), 0, 12), 'install-' . substr(sha1(self::installId()), 0, 12)];
        sort($expected);
        self::assertSame($expected, $entries, 'each install\'s own directory, and nothing at the top level');
    }

    /**
     * Install, make a create under an Idempotency-Key and a staged DELETE
     * through $base, install again, and send them again: the create is made
     * and the old proposal is neither listed nor applied. $stateDirs lists
     * the directories the server keeps state in, and its log is to say
     * $logSays of the first's. The first install's id.
     *
     * @param \Closure(): list<string> $stateDirs
     */
    private function reinstallUnder(string $base, \Closure $stateDirs, string $logSays): string
    {
        $first = self::install();
        $firstId = self::installId();
        [$status, $created] = self::request($base, 'POST', '/aff-networks', $first, self::BODY, self::KEY);
        self::assertSame(201, $status, json_encode($created) . self::serverLog($base));
        self::assertArrayNotHasKey('idempotent_replay', $created);
        $id = (int) ($created['data']['aff_network_id'] ?? 0);
        self::assertGreaterThan(0, $id);
        [$status, $staged] = self::request($base, 'DELETE', '/aff-networks/' . $id . '?staged=1', $first);
        self::assertSame(202, $status, json_encode($staged) . self::serverLog($base));
        $changeId = (string) ($staged['data']['change_id'] ?? '');
        self::assertNotSame('', $changeId);
        $afterFirst = $stateDirs();

        $second = self::install();
        self::assertNotSame($firstId, self::installId(), 'the install again is another install');
        self::assertSame([], self::networks(), 'the database starts empty again');

        [$status, $again] = self::request($base, 'POST', '/aff-networks', $second, self::BODY, self::KEY);
        self::assertSame(201, $status, json_encode($again) . self::serverLog($base));
        self::assertArrayNotHasKey(
            'idempotent_replay',
            $again,
            'the first install\'s answer to this Idempotency-Key was replayed: ' . json_encode($again)
        );
        self::assertSame(['Reinstalled network'], self::networks(), 'the write was made');

        [$status, $listed] = self::request($base, 'GET', '/staged-changes', $second);
        self::assertSame(200, $status, json_encode($listed));
        self::assertSame([], $listed['data'] ?? null, 'the first install\'s staged DELETE is not listed');
        [$status] = self::request($base, 'POST', '/staged-changes/' . $changeId . '/apply', $second);
        self::assertSame(404, $status, 'nor can it be applied');
        self::assertSame(['Reinstalled network'], self::networks());

        self::assertCount(1, $afterFirst, 'the first install made its directory');
        self::assertCount(2, $stateDirs(), 'the second install made a directory of its own');
        self::assertStringContainsString($logSays, self::serverLog($base));

        [$status, $replayed] = self::request($base, 'POST', '/aff-networks', $second, self::BODY, self::KEY);
        self::assertSame(201, $status);
        self::assertTrue($replayed['idempotent_replay'] ?? false, 'within an install the key still replays');
        self::assertSame(['Reinstalled network'], self::networks());

        return $firstId;
    }
}
