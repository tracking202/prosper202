<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * The cached reads keep an answer, when their caller names no time, as each
 * bootstrap's own copy kept it before there was one copy: on the click path
 * 3 minutes for a row and 1 for a list (a redirector's rules, so an edit
 * reaches its clicks within the minute), on the pages 5 for both. Which
 * bootstrap served a request is a constant, so each is asked in a process of
 * its own.
 */
final class CachedReadMinutesTest extends TestCase
{
    public function testTheClickPathKeepsARowThreeMinutesAndAListOne(): void
    {
        self::assertSame('3 1', self::minutes(true));
    }

    public function testThePagesKeepBothFiveMinutes(): void
    {
        self::assertSame('5 5', self::minutes(false));
    }

    private static function minutes(bool $clickPath): string
    {
        $code = ($clickPath ? 'define("P202_CLICK_PATH", true); ' : '')
            . 'require ' . var_export(dirname(__DIR__, 2) . '/202-config/functions-shared.php', true) . ';'
            . ' echo p202CachedReadMinutes(false), " ", p202CachedReadMinutes(true);';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return implode("\n", $output);
    }
}
