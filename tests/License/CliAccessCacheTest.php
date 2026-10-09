<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\CliAccessCache;
use Prosper202\License\ShellAccessCache;

/**
 * The CLI licence verdict (paid) and the shell verdict (key valid) are cached
 * separately: a valid-but-unpaid key must never read as "CLI allowed".
 */
final class CliAccessCacheTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = 'test-key-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ShellAccessCache::invalidate($this->key);
        CliAccessCache::invalidate($this->key);
    }

    public function testShellResultIsNotReadAsCliResult(): void
    {
        ShellAccessCache::write($this->key, true);
        self::assertTrue(ShellAccessCache::read($this->key));
        self::assertNull(CliAccessCache::read($this->key));
    }

    public function testCliResultRoundTripsIndependently(): void
    {
        CliAccessCache::write($this->key, false);
        ShellAccessCache::write($this->key, true);
        self::assertFalse(CliAccessCache::read($this->key));
        self::assertTrue(ShellAccessCache::read($this->key));
    }

    public function testCliCacheLivesInItsOwnPrivateDirectory(): void
    {
        CliAccessCache::write($this->key, true);
        $dir = sys_get_temp_dir() . '/p202-cli-access';
        self::assertDirectoryExists($dir);
        self::assertSame(0700, fileperms($dir) & 0777);
    }
}
