<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\UnsavedClickRedirects;

final class UnsavedClickRedirectsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/p202-unsaved-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/202-config/temp', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink(UnsavedClickRedirects::path($this->root));
        @chmod($this->root . '/202-config/temp', 0777);
        @rmdir($this->root . '/202-config/temp');
        @rmdir($this->root . '/202-config');
        @rmdir($this->root);
    }

    public function testNoFileIsNoneCounted(): void
    {
        self::assertSame(
            ['state' => 'none', 'count' => 0, 'first_at' => null, 'last_at' => null],
            UnsavedClickRedirects::read($this->root)
        );
        self::assertNull(UnsavedClickRedirects::warning(UnsavedClickRedirects::read($this->root)));
    }

    public function testEachRedirectIsCountedWithTheFirstAndLastTimes(): void
    {
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 1_000));
        self::assertTrue(UnsavedClickRedirects::record($this->root . '/', 'lp.php', 2_000));
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'off.php', 3_000));

        self::assertSame(
            ['state' => 'counted', 'count' => 3, 'first_at' => 1_000, 'last_at' => 3_000],
            UnsavedClickRedirects::read($this->root)
        );
    }

    /** Twenty processes counting at once lose none of their counts. */
    public function testConcurrentRedirectsAreAllCounted(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'for ($i = 0; $i < 25; $i++) { \Prosper202\Click\UnsavedClickRedirects::record('
            . var_export($this->root, true) . ', "dl.php"); }';
        $processes = [];
        for ($i = 0; $i < 20; $i++) {
            $processes[] = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
        }
        foreach ($processes as $process) {
            self::assertSame(0, proc_close($process));
        }

        self::assertSame(500, UnsavedClickRedirects::read($this->root)['count']);
    }

    /** A file that cannot be read is not zero: it says so, and the next count starts again from its own time. */
    public function testAnUnreadableCountIsNeverZero(): void
    {
        file_put_contents(UnsavedClickRedirects::path($this->root), '{"count": "lots"}');

        $read = UnsavedClickRedirects::read($this->root);
        self::assertSame('unreadable', $read['state']);
        self::assertNull($read['count']);
        self::assertStringContainsString('cannot be read', (string) UnsavedClickRedirects::warning($read));

        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 5_000));
        self::assertSame(
            ['state' => 'counted', 'count' => 1, 'first_at' => 5_000, 'last_at' => 5_000],
            UnsavedClickRedirects::read($this->root)
        );
    }

    public function testAnEmptyFileIsUnreadable(): void
    {
        touch(UnsavedClickRedirects::path($this->root));

        self::assertSame('unreadable', UnsavedClickRedirects::read($this->root)['state']);
    }

    public function testACountThatCannotBeWrittenIsLoggedAndAnswersFalse(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes into a read-only directory');
        }
        chmod($this->root . '/202-config/temp', 0555);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202log');
        $previous = ini_set('error_log', $log);
        try {
            self::assertFalse(UnsavedClickRedirects::record($this->root, 'dl.php'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertStringContainsString('dl.php sent a visitor on without saving the click', $logged);
        self::assertSame('none', UnsavedClickRedirects::read($this->root)['state']);
    }

    public function testTheWarningHoldsForSevenDaysAfterTheLast(): void
    {
        $read = ['state' => 'counted', 'count' => 1234, 'first_at' => 86_400, 'last_at' => 172_800];
        $last = 172_800;

        $warning = (string) UnsavedClickRedirects::warning($read, $last + UnsavedClickRedirects::RECENT_SECONDS);
        self::assertStringContainsString('1,234 clicks were redirected without being saved since 1970-01-02 00:00', $warning);
        self::assertStringContainsString('the last at 1970-01-03 00:00 UTC', $warning);
        self::assertStringContainsString('the database refused the connection', $warning);
        self::assertNull(UnsavedClickRedirects::warning($read, $last + UnsavedClickRedirects::RECENT_SECONDS + 1));
        self::assertStringStartsWith(
            '1 click was redirected',
            (string) UnsavedClickRedirects::warning(['count' => 1] + $read, $last)
        );
    }
}
