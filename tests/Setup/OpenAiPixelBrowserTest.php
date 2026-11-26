<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * landing.php's ChatGPT Ads pixel hook, run in node (openai-pixel-harness.js):
 * OpenAI's base code with its consent first, the browser reference posted
 * once the pixel's cookie is there, and a withdrawal that withdraws the
 * pixel's consent and asks the tracker to drop the reference.
 */
final class OpenAiPixelBrowserTest extends TestCase
{
    private const LANDING = __DIR__ . '/../../tracking202/static/landing.php';

    public function testTheHookLoadsThePixelAndPostsItsReference(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            // CI's runners carry node: a run there that cannot find it has not checked anything.
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $source = (string) file_get_contents(self::LANDING);
        $start = '// --- ChatGPT Ads pixel ---';
        $end = '// --- Bot202 Facebook Pixel Assistant ---';
        self::assertSame(1, substr_count($source, $start));
        self::assertSame(1, substr_count($source, $end));
        $from = (int) strpos($source, $start);
        $hookFile = (string) tempnam(sys_get_temp_dir(), 'p202-openai-hook');
        file_put_contents($hookFile, substr($source, $from, (int) strpos($source, $end, $from) - $from));

        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/openai-pixel-harness.js') . ' '
            . escapeshellarg($hookFile) . ' 2>&1';
        exec($command, $lines, $status);
        unlink($hookFile);

        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('ChatGPT Ads pixel harness passed', implode("\n", $lines));
    }

    /** The page's pattern and the server's (OpenAiBrowserRef::isValue()) agree on what a reference is. */
    public function testThePageSendsOnlyWhatTheServerKeeps(): void
    {
        $source = (string) file_get_contents(self::LANDING);
        self::assertSame(1, preg_match('#\[\\\\x21\\\\x23-\\\\x2B\\\\x2D-\\\\x3A\\\\x3C-\\\\x5B\\\\x5D-\\\\x7E\]\{1,255\}#', $source));
        foreach (["ob.ABC-123_x", str_repeat('a', 255), '!#$%&\'()*+-./09:<=>?@AZ[]^_`az{|}~'] as $kept) {
            self::assertTrue(\Prosper202\Click\OpenAiBrowserRef::isValue($kept), $kept);
        }
        foreach (['', str_repeat('a', 256), 'a b', 'a"b', 'a,b', 'a;b', 'a\\b', "a\nb", 'é'] as $refused) {
            self::assertFalse(\Prosper202\Click\OpenAiBrowserRef::isValue($refused), $refused);
        }
    }
}
