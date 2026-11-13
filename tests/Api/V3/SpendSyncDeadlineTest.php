<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SpendSyncController;
use PHPUnit\Framework\TestCase;

/**
 * When a spend sync run asked for over the API stops reading: 30 seconds,
 * with PHP's time limit raised to leave a whole request and its write after
 * the last check, or what the limit leaves when it cannot be raised.
 */
final class SpendSyncDeadlineTest extends TestCase
{
    private string $limit = '0';

    protected function setUp(): void
    {
        $this->limit = (string) ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->limit);
    }

    private static function deadline(): int
    {
        return (new \ReflectionMethod(SpendSyncController::class, 'deadline'))->invoke(null);
    }

    public function testNoTimeLimitReadsThirtySecondsAndSetsNone(): void
    {
        set_time_limit(0);
        $before = time();
        self::assertEqualsWithDelta($before + 30, self::deadline(), 1);
        self::assertSame('0', ini_get('max_execution_time'), 'no limit is put where there was none');
    }

    public function testALimitTooShortIsRaisedToCoverTheLastRequest(): void
    {
        set_time_limit(30);
        $before = time();
        self::assertEqualsWithDelta($before + 30, self::deadline(), 1);
        self::assertSame('60', ini_get('max_execution_time'), '30 seconds, a 15-second request and 15 for its write');
    }

    public function testALongLimitIsLeftAsItIs(): void
    {
        set_time_limit(300);
        self::assertEqualsWithDelta(time() + 30, self::deadline(), 1);
        self::assertSame('300', ini_get('max_execution_time'));
    }

    /** @return iterable<string, array{int, int}> */
    public static function limitsThatCannotBeRaised(): iterable
    {
        yield 'room for ten seconds' => [40, 10];
        yield 'room for none: one page still goes' => [20, 1];
    }

    /** @dataProvider limitsThatCannotBeRaised */
    public function testALimitThatCannotBeRaisedLeavesARequestAndItsWriteAfterTheDeadline(int $limit, int $seconds): void
    {
        $root = dirname(__DIR__, 3);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' $t = time(); $d = (new ReflectionMethod(' . var_export(SpendSyncController::class, true) . ', "deadline"))->invoke(null);'
            . ' echo ($d - $t), " ", ini_get("max_execution_time");';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=set_time_limit -d max_execution_time=' . $limit
            . ' -r ' . escapeshellarg($code) . ' 2>&1');
        [$got, $kept] = explode(' ', trim($out)) + [1 => ''];
        self::assertContains((int) $got, [$seconds, $seconds + 1], $out);
        self::assertSame((string) $limit, $kept);
    }
}
