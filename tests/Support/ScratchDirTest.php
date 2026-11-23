<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

final class ScratchDirTest extends TestCase
{
    private string $outside = '';

    protected function tearDown(): void
    {
        ScratchDir::remove($this->outside);
    }

    private static function tree(): string
    {
        $dir = sys_get_temp_dir() . '/p202-scratchdir-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir . '/sub/deeper', 0700, true));
        foreach (['a.txt', '.hidden', 'sess_1', 'sess_2', 'sub/b.txt', 'sub/deeper/.c'] as $file) {
            file_put_contents($dir . '/' . $file, 'x');
        }

        return $dir;
    }

    public function testRemoveTakesTheWholeTreeDotfilesIncluded(): void
    {
        $dir = self::tree();
        ScratchDir::remove($dir);
        self::assertFileDoesNotExist($dir);
    }

    public function testALinkIsRemovedAndNotFollowed(): void
    {
        $this->outside = self::tree();
        $dir = self::tree();
        self::assertTrue(symlink($this->outside, $dir . '/link'));
        ScratchDir::remove($dir);
        self::assertFileDoesNotExist($dir);
        self::assertFileExists($this->outside . '/sub/b.txt', 'what the link pointed at is untouched');
    }

    public function testADirectoryThatIsALinkIsNotEntered(): void
    {
        $this->outside = self::tree();
        $link = sys_get_temp_dir() . '/p202-scratchdir-link-' . bin2hex(random_bytes(6));
        self::assertTrue(symlink($this->outside, $link));
        try {
            ScratchDir::remove($link);
            self::assertFileExists($this->outside . '/a.txt');
        } finally {
            unlink($link);
        }
    }

    public function testNothingToCleanUpIsNotAnError(): void
    {
        ScratchDir::remove('');
        ScratchDir::clear('', '*');
        ScratchDir::remove(sys_get_temp_dir() . '/p202-scratchdir-never-made-' . bin2hex(random_bytes(6)));
        $this->addToAssertionCount(1);
    }

    public function testClearTakesOnlyWhatThePatternNames(): void
    {
        $dir = self::tree();
        try {
            ScratchDir::clear($dir, 'sess_*');
            self::assertFileDoesNotExist($dir . '/sess_1');
            self::assertFileDoesNotExist($dir . '/sess_2');
            self::assertFileExists($dir . '/a.txt');
            self::assertFileExists($dir . '/.hidden');
            ScratchDir::clear($dir, 'sub');
            self::assertFileDoesNotExist($dir . '/sub');
            self::assertFileExists($dir . '/a.txt');
        } finally {
            ScratchDir::remove($dir);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notTemp(): iterable
    {
        yield 'the root' => ['/'];
        yield 'the root by another spelling' => ['/./'];
        yield 'the temp directory itself' => [sys_get_temp_dir()];
        yield 'out of temp through ..' => [sys_get_temp_dir() . '/..'];
        yield 'this checkout' => [dirname(__DIR__, 2)];
    }

    /** @dataProvider notTemp */
    public function testAnythingOutsideTheTempDirectoryIsRefused(string $dir): void
    {
        $this->expectException(\LogicException::class);
        ScratchDir::remove($dir);
    }

    /** @dataProvider notTemp */
    public function testClearOutsideTheTempDirectoryIsRefused(string $dir): void
    {
        $this->expectException(\LogicException::class);
        ScratchDir::clear($dir, 'nothing-matches-this-*');
    }

    public function testAPatternIsANameNotAPath(): void
    {
        $dir = self::tree();
        try {
            foreach (['../*', 'sub/*', '..'] as $pattern) {
                try {
                    ScratchDir::clear($dir, $pattern);
                    self::fail('a path pattern is refused: ' . $pattern);
                } catch (\LogicException) {
                    self::assertFileExists($dir . '/sub/b.txt');
                }
            }
        } finally {
            ScratchDir::remove($dir);
        }
    }
}
