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

    // --- p202ExtractItems (pure) ---

    public function testExtractItemsReturnsEmptyWithoutProductParams(): void
    {
        self::assertSame([], p202ExtractItems([]));
        self::assertSame([], p202ExtractItems(['qty' => 2, 'unit_price' => 5]), 'qty/price alone are not a product');
    }

    public function testExtractItemsBuildsSingleLineItem(): void
    {
        $items = p202ExtractItems([
            'product_id' => 'SHOP-9', 'sku' => 'WIDGET', 'product_name' => 'Blue Widget',
            'qty' => '2', 'unit_price' => '9.50',
        ]);
        self::assertCount(1, $items);
        self::assertSame('SHOP-9', $items[0]['external_product_id']);
        self::assertSame('WIDGET', $items[0]['sku']);
        self::assertSame('Blue Widget', $items[0]['name']);
        self::assertSame(2.0, $items[0]['quantity']);
        self::assertSame(9.5, $items[0]['unit_price']);
    }

    public function testExtractItemsDropsLineItemWithNonPositiveQuantity(): void
    {
        // insertLineItems() rejects a non-positive quantity inside the
        // conversion transaction, so a pixel carrying qty=0 (or negative) must
        // NOT reach the transactional writer — dropping the optional product
        // keeps the core conversion from being lost.
        self::assertSame([], p202ExtractItems(['sku' => 'WIDGET', 'qty' => '0']));
        self::assertSame([], p202ExtractItems(['sku' => 'WIDGET', 'qty' => '-3']));
        self::assertSame([], p202ExtractItems(['product_id' => 'P1', 'qty' => 0]));
    }

    public function testExtractItemsIgnoresNonNumericQuantity(): void
    {
        // A non-numeric qty is simply not set (insertLineItems defaults it to
        // 1) — the item still records; only an explicit non-positive number is
        // treated as malformed.
        $items = p202ExtractItems(['sku' => 'WIDGET', 'qty' => 'abc']);
        self::assertCount(1, $items);
        self::assertFalse(array_key_exists('quantity', $items[0]));
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
