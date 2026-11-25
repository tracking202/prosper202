<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\PpcAccountsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;

/**
 * A traffic source repair and a write to the accounts naming the source are
 * taken one after the other (TrafficSourceAccountWrite): the write holds the
 * account and then the source, and the repair, which locks the source before
 * it reads the accounts, waits for it.
 *
 * TAKER has OWNER's source TAKEN (renamed into TAKER's account the old way),
 * which OWNER's account names, so the report says to move it back to OWNER;
 * TAKER also has a source of their own (OWN) with an account on it. Each
 * race is held here on a connection of its own, and the other side runs in
 * a child process (fixtures/traffic-source-repair-runner.php,
 * fixtures/traffic-source-account-write-runner.php, or Setup › Traffic
 * Sources through fixtures/page-runner.php), which acts once the server
 * shows it waiting:
 *
 * - an account move onto the source in flight: the repair waits, reads the
 *   moved account and is refused, since TAKER's accounts then name the
 *   source too (owner_has_accounts);
 * - a repair in flight (its lock and its move, held by hand): an account
 *   put on the source through the API or Setup waits, reads the source as
 *   OWNER's and is refused.
 *
 * Skips unless P202_TEST_DB_* names a scratch database. Needs a 202-config.php
 * for the Setup page (connect.php exits without one).
 *
 * @group integration
 */
final class TrafficSourceAccountWritesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990161;
    private const TAKER = 990162;
    private const ROLE = 990161;
    /** OWNER's, in TAKER's account since the rename. */
    private const TAKEN = 990161;
    /** TAKER's own. */
    private const OWN = 990162;
    /** OWNER's account on TAKEN, saved before the rename. */
    private const OWNERS_ACCOUNT = 990161;
    /** TAKER's account on OWN. */
    private const TAKERS_ACCOUNT = 990162;
    private const CREATED = 1_000_000_000;
    private const RENAMED = 1_100_000_000;
    private const CLOSED = 'http://127.0.0.1:9';

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    private string $log = '';
    private string $logWas = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-account-writes-' . getmypid();
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
        self::cleanUp();
        self::seed();
        // The repairs' log lines, out of the suite's output.
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-account-writes-log-');
        $this->logWas = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->logWas);
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
    }

    private static function cleanUp(): void
    {
        $found = self::$db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND COLUMN_NAME = 'user_id' AND TABLE_NAME LIKE '202\\_%'");
        $tables = array_column($found instanceof \mysqli_result ? $found->fetch_all() : [], 0);
        // The account row last: other rows name it by foreign key.
        usort($tables, static fn (string $a, string $b): int => ($a === '202_users') <=> ($b === '202_users'));
        foreach ($tables as $table) {
            self::q('DELETE FROM `' . $table . '` WHERE user_id IN (' . self::OWNER . ', ' . self::TAKER . ')');
        }
        self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id IN (' . self::TAKEN . ', ' . self::OWN . ')');
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        foreach ([self::OWNER, self::TAKER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        // A licence key on file (the session has already validated it), and
        // a role that may use Setup and remove a traffic source account.
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::TAKER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'account writes test']);
        foreach (['access_to_setup_section', 'remove_traffic_source_account'] as $name) {
            $found = self::$db->query(
                "SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'"
            );
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::TAKER, 'role_id' => self::ROLE]);

        self::row('202_ppc_networks', ['ppc_network_id' => self::TAKEN, 'user_id' => self::TAKER,
            'ppc_network_name' => 'Taken', 'ppc_network_time' => self::RENAMED]);
        self::row('202_ppc_networks', ['ppc_network_id' => self::OWN, 'user_id' => self::TAKER,
            'ppc_network_name' => 'Own', 'ppc_network_time' => self::CREATED]);
        self::row('202_ppc_accounts', ['ppc_account_id' => self::OWNERS_ACCOUNT, 'user_id' => self::OWNER,
            'ppc_network_id' => self::TAKEN, 'ppc_account_name' => 'Owner', 'ppc_account_time' => self::CREATED + 10]);
        self::row('202_ppc_accounts', ['ppc_account_id' => self::TAKERS_ACCOUNT, 'user_id' => self::TAKER,
            'ppc_network_id' => self::OWN, 'ppc_account_name' => 'Taker', 'ppc_account_time' => self::CREATED + 10]);
    }

    /**
     * The repair runs while TAKER's account move onto TAKEN is in flight,
     * held at the API's own write (PpcAccountsController::update(), joining
     * the transaction held here). The repair waits for the source, reads the
     * moved account once the move commits, and is refused: TAKER's accounts
     * name the source now, so it no longer goes back to OWNER by itself.
     */
    public function testARepairWaitsForAnAccountMoveAndReadsTheMovedAccount(): void
    {
        $holder = self::connect();
        $conn = new Connection($holder);
        $child = null;
        try {
            $conn->transaction(function () use ($holder, &$child): void {
                $moved = (new PpcAccountsController($holder, self::TAKER))
                    ->update(self::TAKERS_ACCOUNT, ['ppc_network_id' => self::TAKEN])['data'];
                self::assertSame(self::TAKEN, (int) $moved['ppc_network_id']);
                $child = self::waiting('traffic-source-repair-runner.php', (string) self::TAKEN, (string) self::OWNER);
            });
        } finally {
            $holder->close();
        }
        $out = self::finish($child);

        // Read under the repair's lock: TAKER's accounts name the source now.
        self::assertStringStartsWith('refused: ', $out);
        self::assertStringContainsString('ambiguous (owner_has_accounts)', $out);
        self::assertStringContainsString('Nothing was moved', $out);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN), 'the source was not moved from under the account');
        self::assertSame(self::TAKEN, self::sourceOf(self::TAKERS_ACCOUNT));
    }

    /**
     * With nothing in flight, the same repair moves the source back: what
     * refused it above was the account it waited for.
     */
    public function testWithNoAccountWriteInFlightTheRepairMovesTheSource(): void
    {
        [$proc, $pipes, $thread] = self::child(
            'traffic-source-repair-runner.php',
            (string) self::TAKEN,
            (string) self::OWNER
        );
        self::assertSame('exited', self::settle($proc, $thread));

        self::assertSame('ok', self::finish([$proc, $pipes]));
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
    }

    /**
     * An update that leaves the account on the source it is on is not asked
     * whether the source may take accounts: TAKER's own source, removed since,
     * still holds the account it held.
     */
    public function testAnUpdateThatKeepsTheAccountWhereItIsIsNotAsked(): void
    {
        self::$db->query('UPDATE 202_ppc_networks SET ppc_network_deleted = 1 WHERE ppc_network_id = ' . self::OWN);
        [$proc, $pipes, $thread] = self::child(
            'traffic-source-account-write-runner.php',
            (string) self::TAKER,
            (string) self::OWN,
            (string) self::TAKERS_ACCOUNT
        );
        self::assertSame('exited', self::settle($proc, $thread));

        self::assertSame('ok ' . self::TAKERS_ACCOUNT, self::finish([$proc, $pipes]));
        self::assertSame(self::OWN, self::sourceOf(self::TAKERS_ACCOUNT));
    }

    /**
     * A repair in flight, held here at its lock and its move: TAKER's
     * account moved onto the source, or a new one put on it, through the
     * API waits for the repair, reads the source as OWNER's once it commits,
     * and is refused. The ownership check before the lock read it as TAKER's.
     */
    public function testAnAccountPutOnTheSourceDuringARepairIsRefused(): void
    {
        foreach (['moved' => (string) self::TAKERS_ACCOUNT, 'new' => ''] as $what => $account) {
            self::cleanUp();
            self::seed();
            $out = self::duringARepair(
                static fn (): array => self::waiting(
                    'traffic-source-account-write-runner.php',
                    (string) self::TAKER,
                    (string) self::TAKEN,
                    $account
                )
            );

            self::assertStringStartsWith('refused: ', $out, $what);
            self::assertStringContainsString('traffic source ' . self::TAKEN . ' is not one of yours', $out, $what);
            self::assertSame(self::OWNER, self::ownerOf(self::TAKEN), $what);
            self::assertSame(
                [self::OWNERS_ACCOUNT],
                self::accountsOn(self::TAKEN),
                "$what: no account of TAKER's names OWNER's source"
            );
        }
    }

    /**
     * Setup › Traffic Sources adds, moves and removes an account through the
     * same locks, and an account added there while a repair is in flight
     * waits for it and is refused as the API's is.
     */
    public function testSetupAddsMovesAndRemovesAnAccountAndWaitsForARepair(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the page ran.');
        }
        $token = ['token' => 'p202pagerunnertoken'];
        self::page([], ['ppc_network_id' => (string) self::OWN, 'ppc_account_name' => 'Added on Setup'] + $token);
        $added = self::accountNamed('Added on Setup');
        self::assertSame(self::OWN, self::sourceOf($added), 'added on the source it was given');

        self::page(
            ['edit_ppc_account_id' => (string) $added],
            [
                'ppc_network_id' => (string) self::TAKEN,
                'ppc_account_name' => 'Added on Setup',
                'do_edit_ppc_account' => '1',
            ] + $token
        );
        self::assertSame(self::TAKEN, self::sourceOf($added), 'moved to the source it was given');

        self::page(
            ['delete_ppc_account_id' => (string) $added, 'delete_ppc_account_name' => 'Added on Setup'] + $token
        );
        self::assertSame('1', self::column($added, 'ppc_account_deleted'), 'removed');

        self::cleanUp();
        self::seed();
        $out = self::duringARepair(static function (): array {
            $proc = self::pageProcess([], [
                'ppc_network_id' => (string) self::TAKEN,
                'ppc_account_name' => 'Beside a repair',
                'token' => 'p202pagerunnertoken',
            ]);
            self::assertSame('waiting', self::settle($proc[0], null), 'the page waits for the repair');

            return $proc;
        });
        self::assertStringContainsString(
            'not authorized to add an account to another user',
            html_entity_decode($out, ENT_QUOTES)
        );
        self::assertNull(self::accountNamed('Beside a repair', false), 'nothing was added to OWNER\'s source');
    }

    /**
     * Run $start (a child that waits on a lock) while a repair of TAKEN to
     * OWNER is held at its lock and its move, as TrafficSourceOwners::repair()
     * takes them; commit, and answer the child's output.
     *
     * @param callable(): array{resource, array<int, resource>} $start
     */
    private static function duringARepair(callable $start): string
    {
        $holder = self::connect();
        $conn = new Connection($holder);
        $child = null;
        try {
            $conn->transaction(function () use ($conn, $start, &$child): void {
                $lock = $conn->prepareWrite(
                    'SELECT user_id FROM 202_users WHERE user_id IN (?, ?) ORDER BY user_id FOR UPDATE'
                );
                $conn->bind($lock, 'ii', [self::OWNER, self::TAKER]);
                $conn->fetchAll($lock);
                $source = $conn->prepareWrite(
                    'SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = ? FOR UPDATE'
                );
                $conn->bind($source, 'i', [self::TAKEN]);
                $conn->fetchAll($source);
                $move = $conn->prepareWrite('UPDATE 202_ppc_networks SET user_id = ? WHERE ppc_network_id = ?');
                $conn->bind($move, 'ii', [self::OWNER, self::TAKEN]);
                self::assertSame(1, $conn->executeUpdate($move));
                $child = $start();
            });
        } finally {
            $holder->close();
        }

        return self::finish($child);
    }

    private static function connect(): \mysqli
    {
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $db;
    }

    /** @return array{resource, array<int, resource>, string} a runner, its pipes and its connection id */
    private static function child(string $runner, string ...$args): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/' . $runner, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');

        return [$proc, $pipes, $thread];
    }

    /** @return array{resource, array<int, resource>} a runner, once the server shows it waiting on a lock */
    private static function waiting(string $runner, string ...$args): array
    {
        [$proc, $pipes, $thread] = self::child($runner, ...$args);
        if (self::settle($proc, $thread) !== 'waiting') {
            self::fail("$runner on connection $thread never waited on a lock: "
                . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        }

        return [$proc, $pipes];
    }

    /**
     * 'waiting' once the server shows the child's connection waiting on a
     * lock (any connection but this suite's, when $thread is null), 'exited'
     * once the child has ended.
     *
     * @param resource $proc
     */
    private static function settle($proc, ?string $thread): string
    {
        $which = $thread === null ? 'trx_mysql_thread_id <> CONNECTION_ID()' : "trx_mysql_thread_id = $thread";
        $deadline = microtime(true) + 15;
        do {
            if (!proc_get_status($proc)['running']) {
                return 'exited';
            }
            $waiting = self::$db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE $which"
                . " AND trx_state = 'LOCK WAIT'");
            self::assertInstanceOf(\mysqli_result::class, $waiting, self::$db->error);
            if ((int) $waiting->fetch_row()[0] > 0) {
                return 'waiting';
            }
            // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
            usleep(250000);
        } while (microtime(true) < $deadline);
        self::fail('The child neither ended nor waited on a lock');
    }

    /**
     * The child's output, once it has ended.
     *
     * @param array{resource, array<int, resource>}|null $child
     */
    private static function finish(?array $child): string
    {
        self::assertNotNull($child, 'the child was started');
        [$proc, $pipes] = $child;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the child could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('failed: ', $out, $err);

        return $out;
    }

    /**
     * Setup › Traffic Sources as TAKER, in a process of its own; answers once it has ended.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private static function page(array $get, array $post = []): string
    {
        $out = self::finish(self::pageProcess($get, $post));
        self::assertStringNotContainsStringIgnoringCase('database error', $out);

        return $out;
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @return array{resource, array<int, resource>}
     */
    private static function pageProcess(array $get, array $post): array
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::TAKER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
            // The hosted services sit behind a proxy on a closed port, so nothing leaves the machine.
            'https_proxy' => self::CLOSED, 'HTTPS_PROXY' => self::CLOSED, 'http_proxy' => self::CLOSED,
            'HTTP_PROXY' => self::CLOSED, 'all_proxy' => '', 'ALL_PROXY' => '', 'no_proxy' => '', 'NO_PROXY' => '',
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                __DIR__ . '/fixtures/page-runner.php', 'tracking202/setup/ppc_accounts.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    private static function ownerOf(int $sourceId): int
    {
        $found = self::$db->query("SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = $sourceId");
        self::assertInstanceOf(\mysqli_result::class, $found);

        return (int) ($found->fetch_row()[0] ?? 0);
    }

    private static function sourceOf(int $accountId): int
    {
        return (int) self::column($accountId, 'ppc_network_id');
    }

    private static function column(int $accountId, string $column): string
    {
        $found = self::$db->query("SELECT `$column` FROM 202_ppc_accounts WHERE ppc_account_id = $accountId");
        self::assertInstanceOf(\mysqli_result::class, $found);
        $row = $found->fetch_row();
        self::assertIsArray($row, "account $accountId has a row");

        return (string) $row[0];
    }

    /** @return list<int> */
    private static function accountsOn(int $sourceId): array
    {
        $found = self::$db->query("SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_network_id = $sourceId"
            . ' ORDER BY ppc_account_id');
        self::assertInstanceOf(\mysqli_result::class, $found);

        return array_map(static fn (array $row): int => (int) $row[0], $found->fetch_all());
    }

    private static function accountNamed(string $name, bool $required = true): ?int
    {
        $found = self::$db->query('SELECT ppc_account_id FROM 202_ppc_accounts WHERE user_id = ' . self::TAKER
            . ' AND ppc_account_name = ' . self::str($name));
        self::assertInstanceOf(\mysqli_result::class, $found);
        $rows = $found->fetch_all();
        if (!$required && $rows === []) {
            return null;
        }
        self::assertCount(1, $rows, "one account named $name");

        return (int) $rows[0][0];
    }
}
