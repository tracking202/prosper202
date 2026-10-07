<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

final class ServerStateStoreDefaultDirTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedGlobals = [];
    private string|false $savedEnv = false;
    /** @var string[] dirs created by the test, removed in tearDown */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedGlobals = [
            'dbname' => $GLOBALS['dbname'] ?? null,
            'dbhost' => $GLOBALS['dbhost'] ?? null,
        ];
        $this->savedEnv = getenv('P202_SERVER_STATE_DIR');
        putenv('P202_SERVER_STATE_DIR');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $name => $value) {
            if ($value === null) {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $value;
            }
        }
        if ($this->savedEnv !== false) {
            putenv('P202_SERVER_STATE_DIR=' . $this->savedEnv);
        }
        foreach ($this->createdDirs as $dir) {
            $this->removeDir($dir);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function testDefaultDirIsScopedByInstanceIdentity(): void
    {
        $GLOBALS['dbname'] = 'p202_state_test_' . bin2hex(random_bytes(4));
        $GLOBALS['dbhost'] = 'db.internal:3306';

        $store = new ServerStateStore();
        $this->createdDirs[] = $store->baseDir();

        $expectedSuffix = '-' . substr(sha1('db.internal:3306|' . $GLOBALS['dbname']), 0, 12);
        $this->assertStringEndsWith($expectedSuffix, $store->baseDir());

        // A different database on the same host gets a different directory —
        // no shared idempotency, staged changes, or rate limits.
        $GLOBALS['dbname'] = 'p202_state_test_' . bin2hex(random_bytes(4));
        $other = new ServerStateStore();
        $this->createdDirs[] = $other->baseDir();
        $this->assertNotSame($store->baseDir(), $other->baseDir());
    }

    /**
     * A rename that fails for a real reason (here the destination exists as
     * a file, so it can never be a state directory) must keep using the
     * legacy path, so in-flight staged changes and sync jobs stay reachable.
     */
    public function testAGenuinelyFailedAdoptionKeepsUsingTheLegacyDir(): void
    {
        $legacy = sys_get_temp_dir() . '/p202-api-v3-state';
        if (is_dir($legacy) || is_file($legacy)) {
            $this->markTestSkipped('a real legacy state dir is present on this host; not touching it');
        }
        $GLOBALS['dbname'] = 'p202_state_adopt_' . bin2hex(random_bytes(4));
        $GLOBALS['dbhost'] = 'adopt.host';
        $scoped = $legacy . '-' . substr(sha1($GLOBALS['dbhost'] . '|' . $GLOBALS['dbname']), 0, 12);

        mkdir($legacy, 0700, true);
        $this->createdDirs[] = $legacy;
        file_put_contents($scoped, 'not a directory');

        try {
            $store = new ServerStateStore();
            $this->assertSame($legacy, $store->baseDir());
        } finally {
            @unlink($scoped);
        }
    }

    /**
     * A process with no database identity (a test run, a script that loads
     * the configuration inside a function) wrote the legacy directory — the
     * one a new instance with no directory of its own adopts. So the next
     * instance installed on the host took that process's state as its own:
     * measured, its users were replayed the identity-less process's
     * idempotent create and listed its staged DELETE to apply. The fallback
     * has its own directory now, and a new instance finds nothing there.
     *
     * Run in a child PHP with its own TMPDIR: sys_get_temp_dir() is fixed
     * for a process, and this host's real temp dir may hold a legacy
     * directory the test must not adopt or delete.
     */
    public function testAProcessWithNoIdentityDoesNotWriteWhatTheNextNewInstanceAdopts(): void
    {
        $tmp = sys_get_temp_dir() . '/p202-state-tmpdir-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700, true);
        $this->createdDirs[] = $tmp;
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';' . <<<'PHP'
            use Api\V3\Support\ServerStateStore;
            $scope = ServerStateStore::idempotencyScopeForUser(1);
            $unscoped = new ServerStateStore();
            $unscoped->putIdempotent($scope, 'k-1', ['data' => ['id' => 999]], 'fp');
            $unscoped->stageWriteChange(1, ['change_id' => 'chg-1', 'method' => 'DELETE', 'path' => '/campaigns/1',
                'status' => 'staged', 'created_at_epoch' => time()]);
            $GLOBALS['dbhost'] = 'db.example:3306';
            $GLOBALS['dbname'] = 'brand_new';
            $fresh = new ServerStateStore();
            echo json_encode([
                'unscoped' => $unscoped->baseDir(),
                'fresh' => $fresh->baseDir(),
                'replay' => $fresh->lookupIdempotent($scope, 'k-1', 'fp')['state'],
                'staged' => array_column($fresh->listStagedChangesForUser(1), 'change_id'),
            ]);
            PHP;
        $env = getenv();
        unset($env['P202_SERVER_STATE_DIR']);
        $env['TMPDIR'] = $tmp;
        $command = [PHP_BINARY, '-d', 'error_log=' . $tmp . '/php.log', '-r', $code];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "the child answered: $out $err");

        self::assertSame('miss', $seen['replay'], 'the new instance replays nothing it did not record');
        self::assertSame([], $seen['staged'], 'and lists no staged change it was not given');
        self::assertSame($tmp . '/p202-api-v3-state-unscoped', $seen['unscoped'], 'the fallback is not adopted');
        self::assertDirectoryDoesNotExist($tmp . '/p202-api-v3-state', 'nothing wrote the legacy directory');
        self::assertStringStartsWith($tmp . '/p202-api-v3-state-', $seen['fresh']);
    }

    public function testExplicitEnvOverrideWins(): void
    {
        $GLOBALS['dbname'] = 'p202_state_test_env';
        $dir = sys_get_temp_dir() . '/p202-state-env-test-' . bin2hex(random_bytes(4));
        putenv('P202_SERVER_STATE_DIR=' . $dir);
        $this->createdDirs[] = $dir;

        $store = new ServerStateStore();
        $this->assertSame($dir, $store->baseDir());
    }
}
