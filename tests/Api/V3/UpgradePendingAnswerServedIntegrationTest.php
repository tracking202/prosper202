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
 * no cause, and a read that works is untouched.
 *
 * @group integration
 */
final class UpgradePendingAnswerServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 3962;
    private const BEHIND = '1.9.55';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
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
        // The scratch schema has no row; an install's has one.
        self::$stored = is_array($row) ? (string) $row[0] : null;
        if (self::$stored === null) {
            self::q('INSERT INTO 202_version (version) VALUES (' . self::str(PROSPER202_VERSION) . ')');
        }
        self::seed();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
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
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $bearer ? ['Authorization: Bearer ' . self::$key] : []]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        $log = (string) @file_get_contents(self::$log);
        self::assertIsArray($decoded, $path . ': ' . $body . "\nserver log:\n" . $log);

        return [$status, $decoded];
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

    public function testTheSameFailureWithTheSchemaCurrentClaimsNoCause(): void
    {
        self::requireScratch();
        self::deployedOver(PROSPER202_VERSION);

        [$status, $body] = self::get('/api/v3/ltv/breakdown?by=campaign');
        self::assertSame([500, 'Internal server error'], [$status, $body['message'] ?? null]);
        [$status, $body] = self::legacyReport();
        self::assertSame([500, 'Database error'], [$status, $body['msg'] ?? null]);
    }
}
