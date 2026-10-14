<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * replaceTrackerPlaceholders() reads the click's row (a seven-table join)
 * only for a token that needs it. A URL asking for [[subid]], [[timestamp]],
 * [[random]] or [[referer]] alone, as most offer URLs do, is filled from the
 * click id and the request: off.php, lp.php, lpc.php, offrtr.php and rtr.php
 * call it on every redirect, and a read on each would be a query per click
 * for nothing. Run in a child process: the function's file is the click
 * path's bootstrap, so its body is lifted out and run beside stand-ins.
 */
final class TrackerPlaceholdersReadTheClickOnlyWhenAskedTest extends TestCase
{
    /** @return array{url: string, reads: int} */
    private static function placeholders(string $url, string $clickId): array
    {
        $runner = __DIR__ . '/fixtures/tracker-placeholders-runner.php';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
            . escapeshellarg($url) . ' ' . escapeshellarg($clickId) . ' 2>&1');
        $answer = json_decode((string) $output, true);
        self::assertIsArray($answer, (string) $output);

        return $answer;
    }

    public function testAUrlAskingOnlyForWhatTheRequestHoldsReadsNothing(): void
    {
        $answer = self::placeholders('https://offer.example/go?s=[[subid]]&t=[[timestamp]]&r=[[random]]', '4215');

        self::assertSame(0, $answer['reads']);
        self::assertMatchesRegularExpression(
            '~^https://offer\.example/go\?s=4215&t=[1-9][0-9]{9}&r=[0-9]{7}$~',
            $answer['url']
        );
    }

    public function testATokenFromTheClickReadsItOnce(): void
    {
        $answer = self::placeholders(
            'https://offer.example/go?s=[[subid]]&c1=[[c1]]&cpc=[[cpc]]&src=[[sourceid]]',
            '4215'
        );

        self::assertSame(1, $answer['reads']);
        self::assertSame('https://offer.example/go?s=4215&c1=from-the-row&cpc=0.37&src=6', $answer['url']);
    }

    public function testAUrlWithNoTokenReadsNothing(): void
    {
        $answer = self::placeholders('https://offer.example/go?s=1', '4215');

        self::assertSame(['url' => 'https://offer.example/go?s=1', 'reads' => 0], $answer);
    }
}
