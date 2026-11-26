<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * landing.php's split test: the shipped block, with the t202Settings and
 * first-party identity blocks before it, runs under node
 * (split-test-harness.js). The stored test and the served script are
 * LandingPageSplitTestIntegrationTest's.
 */
final class LandingSplitTestScriptTest extends TestCase
{
    public function testAVariantIsPickedKeptShownAndSentAsC4(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/split-test-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/tracking202/static/landing.php') . ' 2>&1';
        exec($command, $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('Split test harness passed', implode("\n", $lines));
    }
}
