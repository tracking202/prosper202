<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * Tests for the thin parts of the static conversion helpers.
 *
 * p202RecordConversion is now a thin adapter over the canonical writer
 * Prosper202\Conversion\MysqlConversionRepository::record(): the transactional
 * lock + idempotency + insert behaviour is unit-tested in
 * tests/Conversion/MysqlConversionRepository*Test and exercised end-to-end
 * against a real database in tests/Conversion/ConversionIdempotencyIntegrationTest.
 * What remains here is the input guard and the pure transaction-id extraction.
 */
final class RecordConversionTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public function setDirtyHour($click_id) {}
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }

        if (!self::$loaded) {
            require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
            self::$loaded = true;
        }
    }

    // --- Input validation (guarded in the helper, before any DB work) ---

    public function testNonPositiveClickIdThrowsBeforeAnyDbWork(): void
    {
        $db = new FakeRecordMysqli();

        try {
            p202RecordConversion($db, ['click_id' => 0], '', false, '', '');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertSame([], $db->queries, 'No query/connection work should happen for an invalid click id');
        }
    }

    // --- p202ExtractTransactionId (pure) ---

    public function testExtractTransactionIdReadsCommonKeys(): void
    {
        self::assertSame('abc', p202ExtractTransactionId(['txid' => 'abc']));
        self::assertSame('def', p202ExtractTransactionId(['transaction_id' => 'def']));
        self::assertSame('ghi', p202ExtractTransactionId(['orderid' => 'ghi']));
        self::assertSame('123', p202ExtractTransactionId(['oid' => 123]));
    }

    public function testExtractTransactionIdPrefersTxidOverOtherKeys(): void
    {
        self::assertSame('first', p202ExtractTransactionId([
            'txid' => 'first',
            'transaction_id' => 'second',
            'orderid' => 'third',
        ]));
    }

    public function testExtractTransactionIdReturnsEmptyWhenAbsentOrBlank(): void
    {
        self::assertSame('', p202ExtractTransactionId([]));
        self::assertSame('', p202ExtractTransactionId(['txid' => '   ']));
        self::assertSame('', p202ExtractTransactionId(['unrelated' => 'x']));
    }

    public function testExtractTransactionIdTrimsWhitespace(): void
    {
        self::assertSame('xyz', p202ExtractTransactionId(['txid' => '  xyz  ']));
    }
}

/**
 * Minimal mysqli double: records any query() calls so the input-guard test can
 * assert that an invalid click id short-circuits before any DB interaction.
 */
class FakeRecordMysqli extends \mysqli
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct()
    {
        // Skip parent constructor — no real connection.
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        $this->queries[] = $query;
        return true;
    }

    public function close(): true
    {
        return true;
    }
}
