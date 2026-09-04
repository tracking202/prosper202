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
