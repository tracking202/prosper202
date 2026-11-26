<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;
use Prosper202\User\ShownSettings;

/**
 * Two saves of one settings form, made from one page view, give one save and
 * one refusal that reloads the page; neither change is dropped silently.
 *
 * The case is the PayKickstart key list on API Integrations: each save reads
 * the stored list, adds its key and writes the list back, so two saves that
 * both read the list before either wrote it would each write a list missing
 * the other's key. Both requests run as the web server runs them
 * (tests/Setup/fixtures/settings-page-runner.php). The test holds the
 * account's settings lock while both start, so that both are waiting on it
 * at once: the race the lock answers, not two saves that happen to run in
 * turn.
 *
 * @group integration
 */
final class SettingsSaveOverNewerVersionIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const CLOSED = 'http://127.0.0.1:9';
    private const USER = 990141;
    private const ROLE = 990141;
    private const PAGE = '202-account/api-integrations.php';
    private const SECRET = 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f';
    private const STALE = 'These settings were changed in another window or session after this page opened';
    private const BUSY = 'Another save of these settings is still running, so nothing was saved.';

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'shown settings test']);
        $name = 'access_to_api_integrations';
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-shown-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q("UPDATE 202_users_pref SET paykickstart_ipn_secret_keys = 'pk-before-0001'"
            . ' WHERE user_id = ' . self::USER);
    }

    private static function cleanUp(): void
    {
        foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q('DELETE FROM ' . $table . ' WHERE user_id = ' . self::USER);
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    /** @return list<string> the keys stored */
    private static function keys(): array
    {
        $found = self::$db->query(
            'SELECT paykickstart_ipn_secret_keys FROM 202_users_pref WHERE user_id = ' . self::USER
        );
        $row = $found instanceof \mysqli_result ? $found->fetch_row() : null;
        self::assertIsArray($row);

        return PayKickstartKeys::stored($row[0])['keys'];
    }

    /**
     * A run of the page, started and not waited for.
     *
     * @param array<string, mixed> $post
     * @return array{proc: resource, pipes: array<int, resource>}
     */
    private static function start(array $post, string $secret = self::SECRET): array
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => (string) json_encode((object) $post),
            'P202_TEST_SHOWN_SECRET' => $secret,
            // The hosted services sit behind a proxy on a closed port, so nothing leaves the machine.
            'https_proxy' => self::CLOSED, 'HTTPS_PROXY' => self::CLOSED, 'http_proxy' => self::CLOSED,
            'HTTP_PROXY' => self::CLOSED, 'all_proxy' => '', 'ALL_PROXY' => '', 'no_proxy' => '', 'NO_PROXY' => '',
        ] + getenv();
        $runner = $post === [] ? 'page-runner.php' : 'settings-page-runner.php';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/' . $runner, self::PAGE],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * What a started run printed, and for a POST its status and flashes.
     *
     * @param array{proc: resource, pipes: array<int, resource>} $run
     * @return array{out: string, status: int|null, flashes: list<array<string, mixed>>}
     */
    private static function finish(array $run): array
    {
        $out = (string) stream_get_contents($run['pipes'][1]);
        $err = (string) stream_get_contents($run['pipes'][2]);
        fclose($run['pipes'][1]);
        fclose($run['pipes'][2]);
        $code = proc_close($run['proc']);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        $status = null;
        $flashes = [];
        $at = strrpos($out, "\nP202-RUNNER-END ");
        if ($at !== false) {
            $end = json_decode(trim(substr($out, $at + strlen("\nP202-RUNNER-END "))), true, 8, JSON_THROW_ON_ERROR);
            $status = $end['status'];
            $flashes = array_values($end['flashes']);
        }

        return ['out' => $out, 'status' => $status, 'flashes' => $flashes];
    }

    /** The version the page renders for the PayKickstart form, opened under $secret. */
    private static function opened(string $secret = self::SECRET): string
    {
        $page = self::finish(self::start([], $secret));
        $carried = preg_match('/name="p202_shown\[paykickstart\]" value="([0-9a-f]{64})"/', $page['out'], $m);
        self::assertSame(1, $carried, 'the PayKickstart form carries the version of the keys it shows');

        return $m[1];
    }

    /** @return array<string, mixed> */
    private static function add(string $key, ?string $version): array
    {
        return ['token' => 'SESSION', 'paykickstart_key_action' => 'add', 'paykickstart_ipn_secret_key' => $key]
            + ($version === null ? [] : ['p202_shown' => ['paykickstart' => $version]]);
    }

    /** A connection of its own holding the account's settings lock. */
    private static function holdLock(): \mysqli
    {
        $holder = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $name = ShownSettings::lockName($holder, self::USER);
        $got = $holder->query("SELECT GET_LOCK('" . $holder->real_escape_string($name) . "', 0)");
        self::assertInstanceOf(\mysqli_result::class, $got);
        self::assertSame('1', (string) $got->fetch_row()[0], 'the test holds the settings lock');

        return $holder;
    }

    /** Wait until $count connections are waiting for a GET_LOCK. */
    private static function awaitWaiters(int $count): void
    {
        $deadline = microtime(true) + ShownSettings::LOCK_WAIT - 3;
        do {
            $found = self::$db->query(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE 'SELECT GET_LOCK(%'"
            );
            $waiting = $found instanceof \mysqli_result ? (int) $found->fetch_row()[0] : -1;
            if ($waiting >= $count) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        self::fail("$count saves were to wait for the settings lock; $waiting did");
    }

    public function testTwoSavesFromOnePageViewMakeOneSaveAndOneReload(): void
    {
        $version = self::opened();
        $holder = self::holdLock();
        $first = self::start(self::add('pk-first-window-0002', $version));
        $second = self::start(self::add('pk-second-window-0003', $version));
        try {
            self::awaitWaiters(2);
        } finally {
            $holder->query('DO RELEASE_ALL_LOCKS()');
            $holder->close();
        }
        $answers = [self::finish($first), self::finish($second)];

        $kinds = [];
        foreach ($answers as $answer) {
            self::assertSame(303, $answer['status'], $answer['out']);
            self::assertCount(1, $answer['flashes'], $answer['out']);
            $kinds[] = $answer['flashes'][0]['kind'] ?? null;
        }
        sort($kinds);
        self::assertSame(['ok', 'warn'], $kinds, 'one save, and one reload naming the other window');
        foreach ($answers as $answer) {
            if (($answer['flashes'][0]['kind'] ?? null) === 'warn') {
                self::assertStringContainsString(self::STALE, (string) $answer['flashes'][0]['text']);
            }
        }
        $keys = self::keys();
        self::assertCount(2, $keys, 'the key that was there and the one save that was made');
        self::assertSame('pk-before-0001', $keys[0]);
        self::assertContains($keys[1], ['pk-first-window-0002', 'pk-second-window-0003']);
    }

    public function testASaveAfterAnotherFromTheSamePageViewIsReloadedNotMade(): void
    {
        $version = self::opened();
        $saved = self::finish(self::start(self::add('pk-first-window-0002', $version)));
        self::assertSame('ok', $saved['flashes'][0]['kind'] ?? null, $saved['out']);

        $late = self::finish(self::start(self::add('pk-second-window-0003', $version)));
        self::assertSame('warn', $late['flashes'][0]['kind'] ?? null, $late['out']);
        self::assertSame(['pk-before-0001', 'pk-first-window-0002'], self::keys());

        $reopened = self::finish(self::start(self::add('pk-second-window-0003', self::opened())));
        self::assertSame('ok', $reopened['flashes'][0]['kind'] ?? null, 'made again from the reloaded page, it saves');
        self::assertSame(['pk-before-0001', 'pk-first-window-0002', 'pk-second-window-0003'], self::keys());
    }

    public function testASaveCarryingNoVersionOrAnotherSessionsIsRefused(): void
    {
        foreach ([null, self::opened(str_repeat('ab', 32)), str_repeat('0', 64)] as $version) {
            $answer = self::finish(self::start(self::add('pk-no-version-0004', $version)));
            self::assertSame('warn', $answer['flashes'][0]['kind'] ?? null, $answer['out']);
            self::assertStringContainsString(self::STALE, (string) ($answer['flashes'][0]['text'] ?? ''));
        }
        self::assertSame(['pk-before-0001'], self::keys(), 'nothing was saved');
    }

    public function testASaveThatWaitsPastTheLockSavesNothingAndSaysWhy(): void
    {
        $version = self::opened();
        $holder = self::holdLock();
        try {
            $answer = self::finish(self::start(self::add('pk-waited-0005', $version)));
        } finally {
            $holder->query('DO RELEASE_ALL_LOCKS()');
            $holder->close();
        }

        self::assertSame('bad', $answer['flashes'][0]['kind'] ?? null, $answer['out']);
        self::assertSame(self::BUSY . ' Try again in a moment.', $answer['flashes'][0]['text'] ?? null);
        self::assertSame(['pk-before-0001'], self::keys());
    }
}
