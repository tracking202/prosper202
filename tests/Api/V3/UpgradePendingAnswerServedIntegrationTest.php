<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;
use Prosper202\Database\Tables\ClickTables;

/**
 * Between deploying the files and running the upgrade, a read that names
 * what the upgrade makes fails (202_click_latest_visits, before the 1.9.79
 * rung). The API answered it 500 "Internal server error" and the legacy
 * reports 500 "Database error", while /system/health answered healthy, so
 * nothing said the upgrade was the remedy. Served by `php -S` over the
 * scratch database: while the schema is behind the code such a failure is a
 * 503 naming the upgrade; with the schema current the same failure claims
 * no cause, and a read that works is untouched. So does every 5xx answered
 * outside the dispatcher's last catch: the key's roles (an AuthException),
 * the Android install intake (pre-auth, its own catch), the Apple postback
 * receiver (its own entry point) and an offline conversion fetch URL
 * (pre-auth, its own catch).
 *
 * @group integration
 */
final class UpgradePendingAnswerServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3962;
    private const BEHIND = '1.9.55';
    /** The tables the failures below take away, each for one request. */
    private const BROKEN = ['202_roles', '202_app_registrations', '202_offline_conversion_feeds'];
    /** Stands for the seeded API key in a header a data provider writes. */
    private const KEY = '{key}';

    /** @var list<resource> */
    private static array $servers = [];
    /** @var array<string, string> base URL by mysqli report mode */
    private static array $base = [];
    private static string $log = '';
    private static string $prepend = '';
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
        // The scratch schema has no row; an install's has one.
        self::$stored = is_array($row) ? (string) $row[0] : null;
        if (self::$stored === null) {
            self::q('INSERT INTO 202_version (version) VALUES (' . self::str(PROSPER202_VERSION) . ')');
        }
        self::seed();

        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        // PHP's default report mode throws on a failed statement; with it
        // off, a failed prepare is a false each caller turns into its own
        // exception (Auth's is an AuthException), so both are served.
        self::$prepend = (string) tempnam(sys_get_temp_dir(), 'p202off');
        file_put_contents(self::$prepend, '<?php mysqli_report(MYSQLI_REPORT_OFF);');
        self::$base['strict'] = self::serve($root, []);
        self::$base['off'] = self::serve($root, ['-d', 'auto_prepend_file=' . self::$prepend]);
    }

    /** @param list<string> $ini */
    private static function serve(string $root, array $ini): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log, ...$ini,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($server);
        self::$servers[] = $server;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }

        return "http://127.0.0.1:$port";
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            foreach (self::BROKEN as $table) {
                $moved = self::$db->query("SHOW TABLES LIKE '{$table}\\_window'");
                if ($moved instanceof \mysqli_result && $moved->num_rows > 0) {
                    self::q("RENAME TABLE {$table}_window TO $table");
                }
            }
        }
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        self::$servers = [];
        foreach ([self::$log, self::$prepend] as $file) {
            if ($file !== '') {
                @unlink($file);
            }
        }
        if (self::$db !== null) {
            self::q(ClickTables::clickLatestVisits()->createStatement);
            self::q(self::$stored !== null
                ? 'UPDATE 202_version SET version = ' . self::str(self::$stored)
                : 'DELETE FROM 202_version');
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
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
        self::$key = bin2hex(random_bytes(32));
        self::q('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (' . self::USER . ', '
            . self::str(self::$key) . ', ' . time() . ')');
    }

    /** @return array{0: int, 1: array<string, mixed>} status, decoded body */
    private static function get(string $path, bool $bearer = true): array
    {
        [$status, $body] = self::request('GET', $path, $bearer ? ['Authorization: Bearer ' . self::$key] : []);

        return [$status, $body];
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded body, Retry-After
     */
    private static function request(
        string $method,
        string $path,
        array $headers,
        ?string $body = null,
        string $mode = 'strict'
    ): array {
        $retryAfter = '';
        $ch = curl_init(self::$base[$mode] . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, strlen('Retry-After:')));
                }

                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($answer, true);
        $log = (string) @file_get_contents(self::$log);
        self::assertIsArray($decoded, $method . ' ' . $path . ': ' . $answer . "\nserver log:\n" . $log);

        return [$status, $decoded, $retryAfter];
    }

    /**
     * Each request answers a 500 outside the dispatcher's last catch while
     * $table is away, under each report mode, with the message it gives when
     * the schema is current. With the mode off, the key's roles fail as an
     * AuthException; under PHP's default the failed statement throws, as
     * any other does.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: string, 6: string}>
     */
    public static function failuresOutsideTheDispatcher(): array
    {
        $postback = json_encode([
            'version' => '4.0', 'ad-network-id' => 'example123.skadnetwork', 'source-identifier' => '5239',
            'app-id' => 525463029, 'transaction-id' => '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', 'redownload' => false,
            'fidelity-type' => 1, 'did-win' => true, 'conversion-value' => 63, 'postback-sequence-index' => 0,
            'attribution-signature' => 'MEUCIQ==',
        ], JSON_THROW_ON_ERROR);
        $install = 'The install could not be recorded; retry later';
        $feed = 'The file could not be written; try again later';
        $cases = [
            'the key\'s roles' => ['202_roles', 'GET', '/api/v3/campaigns', ['Authorization: Bearer ' . self::KEY], '',
                ['strict' => 'Internal server error', 'off' => 'Authorization unavailable']],
            'the Android install intake' => ['202_app_registrations', 'POST', '/api/v3/apps/installs',
                ['X-P202-App-Token: ' . str_repeat('ab', 32), 'Content-Type: application/json'], '{}',
                ['strict' => $install, 'off' => $install]],
            'the Apple postback receiver' => [
                '202_app_registrations', 'POST', '/.well-known/skadnetwork/report-attribution/index.php',
                ['Content-Type: application/json'], $postback,
                ['strict' => 'Failed to store postback', 'off' => 'Failed to store postback']],
            'an offline conversion fetch URL' => [
                '202_offline_conversion_feeds', 'GET', '/api/v3/offline-conversions/' . str_repeat('k', 43) . '?file=conversions.csv',
                [], '', ['strict' => $feed, 'off' => $feed]],
        ];
        $out = [];
        foreach ($cases as $name => [$table, $method, $path, $headers, $body, $messages]) {
            foreach ($messages as $mode => $message) {
                $out["$name, report mode $mode"] = [$mode, $table, $method, $path, $headers, $body, $message];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private static function without(
        string $mode,
        string $table,
        string $method,
        string $path,
        array $headers,
        string $body
    ): array {
        self::assertContains($table, self::BROKEN);
        $headers = str_replace(self::KEY, self::$key, $headers);
        self::q("RENAME TABLE $table TO {$table}_window");
        try {
            return self::request($method, $path, $headers, $method === 'GET' ? null : $body, $mode);
        } finally {
            self::q("RENAME TABLE {$table}_window TO $table");
        }
    }

    private static function legacyReport(): array
    {
        $query = http_build_query([
            'apikey' => self::$key, 'type' => 'keywords', 'date_from' => '10/01/2026', 'date_to' => '10/31/2026',
        ]);

        return self::get('/api/v2/reports/?' . $query, false);
    }

    /** The files of this version over a schema without what its upgrade makes. */
    private static function deployedOver(string $version): void
    {
        self::q('DROP TABLE IF EXISTS 202_click_latest_visits');
        self::q('UPDATE 202_version SET version = ' . self::str($version));
    }

    public function testAFailureWhileTheUpgradeIsPendingNamesTheUpgrade(): void
    {
        self::requireScratch();
        self::deployedOver(self::BEHIND);
        $sentence = 'the database needs an upgrade: its schema is version ' . self::BEHIND
            . ' and this code is version ' . PROSPER202_VERSION
            . '. Open 202-config/upgrade.php on the site (signed in) to upgrade it.';

        [$status, $body] = self::get('/api/v3/ltv/breakdown?by=campaign');
        self::assertSame([503, $sentence], [$status, $body['message'] ?? null]);
        [$status, $body] = self::legacyReport();
        self::assertSame([503, $sentence], [$status, $body['msg'] ?? null]);

        [$status] = self::get('/api/v3/campaigns');
        self::assertSame(200, $status, 'a read the schema does not decide still answers');
    }

    /**
     * @dataProvider failuresOutsideTheDispatcher
     * @param list<string> $headers
     */
    public function testAFailureOutsideTheDispatcherNamesTheUpgradeToo(
        string $mode,
        string $table,
        string $method,
        string $path,
        array $headers,
        string $body,
        string $message
    ): void {
        self::requireScratch();
        self::deployedOver(self::BEHIND);
        $sentence = 'the database needs an upgrade: its schema is version ' . self::BEHIND
            . ' and this code is version ' . PROSPER202_VERSION
            . '. Open 202-config/upgrade.php on the site (signed in) to upgrade it.';

        [$status, $answer, $retryAfter] = self::without($mode, $table, $method, $path, $headers, $body);
        self::assertSame([503, $sentence], [$status, $answer['message'] ?? null]);
        if ($path === '/api/v3/apps/installs') {
            self::assertSame('300', $retryAfter, 'the SDK waits as for the intake\'s other 503s');
        }

        self::deployedOver(PROSPER202_VERSION);
        [$status, $answer] = self::without($mode, $table, $method, $path, $headers, $body);
        self::assertSame([500, $message], [$status, $answer['message'] ?? null]);
    }

    /** @return iterable<string, array{string}> */
    public static function schemasNoUpgradeMends(): iterable
    {
        require_once dirname(__DIR__, 3) . '/202-config/version.php';
        yield 'current' => [PROSPER202_VERSION];
        yield 'newer than the code (files rolled back), though smaller as text' => ['1.9.100'];
    }

    /** @dataProvider schemasNoUpgradeMends */
    public function testTheSameFailureWithTheSchemaCurrentClaimsNoCause(string $version): void
    {
        self::requireScratch();
        self::deployedOver($version);

        [$status, $body] = self::get('/api/v3/ltv/breakdown?by=campaign');
        self::assertSame([500, 'Internal server error'], [$status, $body['message'] ?? null]);
        [$status, $body] = self::legacyReport();
        self::assertSame([500, 'Database error'], [$status, $body['msg'] ?? null]);
    }
}
