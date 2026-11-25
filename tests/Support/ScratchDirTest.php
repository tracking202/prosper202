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
    }

    /**
     * Each refusal is asked through clear() with a pattern nothing matches,
     * so a broken guard deletes nothing: remove() of the same paths would
     * delete them. A first draft also refused "this checkout", which is
     * not outside the temp directory when the checkout lives there, and
     * deleted it.
     *
     * @dataProvider notTemp
     */
    public function testAnythingOutsideTheTempDirectoryIsRefused(string $dir): void
    {
        $this->expectException(\LogicException::class);
        ScratchDir::clear($dir, 'p202-scratchdir-matches-nothing-' . bin2hex(random_bytes(8)));
    }

    /** remove() asks the same guard before it deletes anything. */
    public function testRemoveAsksTheGuardFirst(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/ScratchDir.php');
        self::assertMatchesRegularExpression(
            '/function remove\(string \$dir\): void\s*\{\s*\$root = self::owned\(\$dir\);\s*if \(\$root === null\) \{\s*return;\s*\}/',
            $src
        );
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
