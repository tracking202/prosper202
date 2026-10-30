<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

final class ReportRequestFailureTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function failures(): iterable
    {
        $statuses = [
            504 => 'The server did not respond in time.',
            502 => 'The server is temporarily unavailable.',
            503 => 'The server is temporarily unavailable.',
            401 => 'Your session may have expired.',
            403 => 'The request was not authorized.',
            500 => 'ask your administrator to check the server logs.',
            0 => 'Check your connection and try again.',
        ];
        foreach (['report', 'spy', 'conversions'] as $subject) {
            foreach ($statuses as $status => $guidance) {
                yield "$subject HTTP $status" => [$subject, $status, $guidance];
            }
        }
    }

    /** @dataProvider failures */
    public function testFailuresGiveGuidanceForTheirHttpStatus(string $subject, int $status, string $guidance): void
    {
        $result = $this->runScript($subject, $status);
        self::assertSame('false', $result['busy']);
        self::assertSame([$subject === 'conversions' ? '/conversions' : '/report'], $result['requests']);
        self::assertStringContainsString('role="alert"', $result['html']);
        self::assertStringContainsString($guidance, $result['html']);
        self::assertStringContainsString(
            $status === 0 ? 'no answer from the server' : "HTTP $status",
            $result['html']
        );
        if ($status !== 401) {
            self::assertStringNotContainsString('session', $result['html']);
        }
        if ($status === 504) {
            self::assertStringContainsString(
                $subject === 'conversions' ? 'Wait a moment and try again.' : 'Try a shorter date range.',
                $result['html']
            );
        }
        if ($subject === 'report') {
            self::assertSame('', $result['opacity']);
        }
    }

    public function testConversionsPreserveTheFragmentsOwnRefusal(): void
    {
        $refusal = '<div class="p202-flash">You cannot read this click.</div>';
        $result = $this->runScript('conversions', 403, $refusal);
        self::assertSame($refusal, $result['html']);
        self::assertSame('false', $result['busy']);
    }

    public function testSuccessfulReportStillRendersAndClearsTheLoadingState(): void
    {
        $result = $this->runScript('report', 200);
        self::assertSame('<p data-report-result>Report loaded</p>', $result['html']);
        self::assertSame('false', $result['busy']);
        self::assertSame('', $result['opacity']);
    }

    /** @return array{html: string, busy: string, opacity: ?string, requests: list<string>} */
    private function runScript(string $subject, int $status, string $responseText = ''): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node is required to exercise the report page script.');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/report-failure-harness.js')
            . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-overview.js')
            . ' ' . escapeshellarg(json_encode(compact('subject', 'status', 'responseText'), JSON_THROW_ON_ERROR))
            . ' 2>&1';
        exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);
        self::assertSame(0, $exitCode, $output);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
