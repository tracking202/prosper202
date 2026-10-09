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

    /**
     * @dataProvider malformed
     */
    public function testMalformedEntryIsUnknownNotADenial(string $content): void
    {
        CliAccessCache::write($this->key, true); // create the dir + a real entry
        $path = (new \ReflectionMethod(CliAccessCache::class, 'path'))->invoke(null, $this->key);
        self::assertIsString($path);
        file_put_contents($path, $content);
        self::assertNull(CliAccessCache::read($this->key));
        self::assertNull(CliAccessCache::readStale($this->key));
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'empty (interrupted write)' => [''],
            'garbage' => ['x'],
            'two digits' => ['10'],
            'leading space' => [' 1'],
            'trailing newline' => ["1\n"],
            'partial' => [' '],
        ];
    }

    public function testWriteLeavesNoTempFiles(): void
    {
        CliAccessCache::write($this->key, false);
        $dir = sys_get_temp_dir() . '/p202-cli-access';
        self::assertSame([], glob($dir . '/*.tmp') ?: []);
        self::assertFalse(CliAccessCache::read($this->key));
    }

    /**
     * Pins the shell cache's reads too (shared implementation): exact "1"/"0"
     * are verdicts; a corrupt entry is unknown, so /capabilities revalidates
     * the shell instead of denying it outright.
     */
    public function testShellCacheReadsAreStrictToo(): void
    {
        ShellAccessCache::write($this->key, true);
        self::assertTrue(ShellAccessCache::read($this->key));
        ShellAccessCache::write($this->key, false);
        self::assertFalse(ShellAccessCache::read($this->key));
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        file_put_contents($path, '');
        self::assertNull(ShellAccessCache::read($this->key));
    }

    public function testCliCacheLivesInItsOwnPrivateDirectory(): void
    {
        CliAccessCache::write($this->key, true);
        $dir = sys_get_temp_dir() . '/p202-cli-access';
        self::assertDirectoryExists($dir);
        self::assertSame(0700, fileperms($dir) & 0777);
    }
}
