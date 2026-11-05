<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

final class PurLinkBrowserTest extends TestCase
{
    public function testPreparationRetriesFallbackAndPageviewReplacement(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed; the PurLink script cannot be run');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/purlink-async-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-purlink.js') . ' 2>&1';
        exec($command, $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('PurLink asynchronous preparation harness passed', implode("\n", $lines));
    }

    public function testOrderedReportsRetryAndStopOnRevocation(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed; the PurLink script cannot be run');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/purlink-report-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-purlink.js') . ' 2>&1';
        exec($command, $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('PurLink ordered browser report harness passed', implode("\n", $lines));
    }

    public function testTemplatesFallbackFormsAndClickReports(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed; the PurLink script cannot be run');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/purlink-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-purlink.js') . ' 2>&1';
        exec($command, $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('PurLink browser harness passed', implode("\n", $lines));
    }
}
