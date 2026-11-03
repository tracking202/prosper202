<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\Redactor;
use Prosper202\InboundLog\RequestLog;

/**
 * What the classic endpoints' log line says, from what the shared helpers
 * noted, and that every endpoint is watched.
 */
final class RequestLogTest extends TestCase
{
    private const ENDPOINTS = ['pb', 'gpb', 'px', 'gpx', 'upx'];

    private static function watch(array $fields = ['subid' => '9001']): RequestLog
    {
        $db = (new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor();

        return RequestLog::watch($db, 'pb', 'get', $fields);
    }

    protected function tearDown(): void
    {
        RequestLog::reset();
    }

    public function testAnAnswerNothingExplainedSaysNothingWasRecorded(): void
    {
        $entry = self::watch()->entry(200);

        self::assertSame('no_conversion', $entry->outcome);
        self::assertSame('Answered without recording a conversion.', $entry->reason);
        self::assertSame('GET', $entry->method);
        self::assertSame(0, $entry->userId);
    }

    public function testARecordedConversionNamesItsAccountClickAndRow(): void
    {
        $log = self::watch();
        $log->account('7');
        $log->conversionWritten(9001, 55, false, 'ORD-1001');
        $entry = $log->entry(200);

        self::assertSame(['recorded', 7, 9001, 55, 'ORD-1001'], [
            $entry->outcome, $entry->userId, $entry->clickId, $entry->conversionId, $entry->transactionId,
        ]);
    }

    public function testARefusalKeepsTheReasonAHelperNamed(): void
    {
        $log = self::watch();
        RequestLog::reset();
        $log->legacyResult('campaign_mismatch');
        $log->refuse(null, 'Unknown subid for this campaign', 404);
        $entry = $log->entry(404);

        self::assertSame('campaign_mismatch', $entry->outcome);
        self::assertSame('Unknown subid for this campaign', $entry->reason);

        RequestLog::reset();
        $failed = self::watch();
        $failed->conversionWritten(9001, 55, false, '');
        $failed->refuse(null, 'Failed to record conversion', 500);
        self::assertSame('failed', $failed->entry(500)->outcome);
    }

    public function testNoRowOnAClickThatIsOnFileIsNotCalledAnUnknownClick(): void
    {
        $missing = self::watch();
        $missing->conversionWritten(9001, 0, false, '', false);
        self::assertSame('unknown_click', $missing->entry(200)->outcome);

        RequestLog::reset();
        $stopped = self::watch();
        $stopped->conversionWritten(9001, 0, false, 'ORD-1');
        $entry = $stopped->entry(200);
        self::assertSame('not_recorded', $entry->outcome);
        self::assertSame('The click is on file, and no conversion was recorded.', $entry->reason);
    }

    public function testTheFieldsAreRedacted(): void
    {
        $entry = self::watch(['subid' => '9001', 'email' => 'pat@contoso.example'])->entry(200);

        self::assertSame(['subid' => '9001', 'email' => Redactor::REDACTED], $entry->fields);
    }

    public function testAWriteThatFailsDoesNotThrow(): void
    {
        $log = self::watch();
        $previous = ini_set('error_log', '/dev/null');
        try {
            $log->write();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertNull(RequestLog::current());
    }

    public function testEveryClassicEndpointIsWatched(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ENDPOINTS as $endpoint) {
            $source = (string) file_get_contents("$root/tracking202/static/$endpoint.php");
            self::assertStringContainsString("p202InboundLogWatch(\$db, '$endpoint');", $source, "$endpoint.php");
        }
        $helpers = (string) file_get_contents("$root/202-config/static-endpoint-helpers.php");
        self::assertMatchesRegularExpression(
            '/function p202RespondJsonError\(int \$code, string \$message\): never\s*\{\s*\\\\Prosper202\\\\InboundLog\\\\RequestLog::current\(\)\?->refuse/',
            $helpers,
            'every JSON refusal is noted before it is sent'
        );
    }
}
