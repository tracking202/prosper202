<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * The Meta Pixel events a landing page fires for the Bot202 Facebook Pixel
 * Assistant, run under node: landing.php's hook, as shipped, loads
 * 202-js/p202-fb-pixel-assistant.js, which fires init, PageView and
 * ViewContent, then the outbound-click event with the dynamic EPV once —
 * and nothing under refused consent (fb-pixel-assistant-harness.js).
 */
final class FacebookPixelAssistantBrowserTest extends TestCase
{
    private const LANDING = __DIR__ . '/../../tracking202/static/landing.php';
    private const MODULE = __DIR__ . '/../../202-js/p202-fb-pixel-assistant.js';

    public function testTheHookAndModuleFireThePixelsEvents(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            // CI's runners carry node: a run there that cannot find it has not checked anything.
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $source = (string) file_get_contents(self::LANDING);
        $start = '// --- Bot202 Facebook Pixel Assistant ---';
        $end = '// --- Tracking beacon ---';
        self::assertSame(1, substr_count($source, $start));
        self::assertSame(1, substr_count($source, $end));
        $from = (int) strpos($source, $start);
        $hookFile = (string) tempnam(sys_get_temp_dir(), 'p202-fbpa-hook');
        file_put_contents($hookFile, substr($source, $from, (int) strpos($source, $end, $from) - $from));

        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fb-pixel-assistant-harness.js') . ' '
            . escapeshellarg($hookFile) . ' ' . escapeshellarg(self::MODULE) . ' 2>&1';
        exec($command, $lines, $status);
        unlink($hookFile);

        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('Facebook Pixel Assistant harness passed', implode("\n", $lines));
    }
}
