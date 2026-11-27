<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\DefaultModel;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelStatus;
use Prosper202\Attribution\ModelType;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;

/**
 * GET /attribution/reports/breakdown through api/v3/index.php itself, served
 * by `php -S` over the scratch database, for an account whose default model
 * the engine marked invalid: with no model_id the answer is 409 naming the
 * model and the reason, and a breakdown that names an active model still
 * answers 200.
 *
 * @group integration
 */
final class InvalidDefaultBreakdownServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 4131;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    private static string $key = '';
    private static int $default = 0;
    private static int $linear = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        self::seed();

        self::$state = sys_get_temp_dir() . '/p202-invalid-default-served-' . bin2hex(random_bytes(6));
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
        if (self::$state !== '' && str_starts_with(self::$state, sys_get_temp_dir() . '/p202-invalid-default-served-')) {
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
        foreach (['202_attribution_models', '202_api_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
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
        self::$key = bin2hex(random_bytes(32));
        self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES (' . self::USER . ', '
            . self::str(self::$key) . ', NULL, NULL, ' . time() . ')');

        $conn = new Connection(self::requireScratch());
        $models = new ModelRepository($conn);
        self::$default = DefaultModel::ensureFor($conn, self::USER);
        self::$linear = $models->insert(self::USER, 'Linear', 'linear', ModelType::LINEAR, [], 30, ModelStatus::Active, false);
        // The stored definition stops validating, and the engine's own read marks the model.
        self::q("UPDATE 202_attribution_models SET weighting_config = '{\"half_life\": 3}' WHERE model_id = " . self::$default);
        $models->activeModels(self::USER);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private static function get(string $path): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key]]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $raw];
    }

    public function testABreakdownThatLeansOnTheInvalidDefaultAnswers409NamingIt(): void
    {
        self::requireScratch();
        $stored = self::$db->query('SELECT status, is_default FROM 202_attribution_models WHERE model_id = ' . self::$default)->fetch_row();
        self::assertSame(['invalid', '1'], $stored, 'the engine marked the default invalid and it is still the default');

        [$status, $body, $raw] = self::get('/attribution/reports/breakdown?group_by=campaign');

        self::assertSame(409, $status, $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log));
        self::assertTrue($body['error'] ?? null, $raw);
        self::assertStringContainsString('The default attribution model (' . self::$default . ') is invalid', (string) $body['message'], $raw);
        self::assertStringContainsString('half_life', (string) $body['message'], 'the reason is given');
        self::assertStringContainsString('name a model with model_id', (string) $body['message'], 'and what to do meanwhile');
        self::assertSame(['model_id' => self::$default, 'status' => 'invalid'], $body['details'] ?? null, $raw);
    }

    public function testABreakdownThatNamesAnActiveModelStillAnswers(): void
    {
        self::requireScratch();

        [$status, $body, $raw] = self::get('/attribution/reports/breakdown?group_by=campaign&model_id=' . self::$linear);

        self::assertSame(200, $status, $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log));
        self::assertSame([], $body['data'] ?? null, $raw);
    }

    public function testNamingTheInvalidDefaultItselfIsRefusedAsAnyModelWithNoCredits(): void
    {
        self::requireScratch();

        [$status, $body, $raw] = self::get('/attribution/reports/breakdown?group_by=campaign&model_id=' . self::$default);

        self::assertSame(409, $status, $raw);
        self::assertStringContainsString('Model ' . self::$default . ' is invalid and has no credits', (string) ($body['message'] ?? ''), $raw);
    }
}
