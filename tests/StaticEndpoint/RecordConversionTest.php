<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use InvalidArgumentException;

/**
 * Tests for p202RecordConversion() and p202ExtractTransactionId() in
 * static-endpoint-helpers.php.
 *
 * p202RecordConversion is the shared transactional + idempotent conversion writer
 * used by the gpx/gpb/upx postback endpoints. Its job is to guarantee that a
 * retried/replayed postback can never double-count, and that a click is never
 * flagged converted without a matching audit row (no partial commits).
 */
final class RecordConversionTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DB', false)) {
            eval('class DB {
                private static $instance;
                public static function getInstance() {
                    if (!self::$instance) self::$instance = new self();
                    return self::$instance;
                }
                public function getConnection() { return new \mysqli(); }
            }');
        }

        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public array $dirtyHourCalls = [];
                public function setDirtyHour($click_id) { $this->dirtyHourCalls[] = $click_id; }
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }

        if (!self::$loaded) {
            require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
            self::$loaded = true;
        }
    }

    private function log(): array
    {
        return [
            'click_id'        => 100,
            'campaign_id'     => '7',
            'user_id'         => '1',
            'click_time'      => 1700000000,
            'conv_time'       => 1700000100,
            'time_difference' => '0 days, 0 hours, 1 min and 40 sec',
            'ip'              => '203.0.113.9',
            'pixel_type'      => 3,
            'user_agent'      => 'UnitTest/1.0',
        ];
    }

    // --- Idempotency / double-count protection ---

    public function testDuplicateTransactionReturnsExistingConversionWithoutInserting(): void
    {
        $db = new FakeRecordMysqli();
        $db->dupRow = ['conv_id' => '42'];

        $result = p202RecordConversion($db, $this->log(), '', false, '', 'ORDER-123');

        self::assertSame(42, $result['conv_id']);
        self::assertTrue($result['duplicate']);
        self::assertTrue($db->committed, 'A dedup hit still commits to release the lock');
        self::assertFalse($db->rolledBack);
        self::assertNull(
            $this->firstQueryContaining($db->queries, 'INSERT INTO 202_conversion_logs'),
            'A duplicate postback must NOT insert a second conversion row'
        );
    }

    public function testIdempotencyLookupIsSkippedWhenNoTransactionId(): void
    {
        $db = new FakeRecordMysqli();
        $db->dupRow = ['conv_id' => '42']; // would match, but must be ignored without a txid
        $db->insertReturns = false;        // stop before the insert_id read

        try {
            p202RecordConversion($db, $this->log(), '', false, '', '');
            self::fail('Expected RuntimeException from failing insert');
        } catch (RuntimeException $e) {
            // expected — we drove the insert to fail on purpose
        }

        self::assertNull(
            $this->firstQueryContaining($db->queries, 'SELECT conv_id'),
            'Without a transaction id there is no idempotency key, so no dedup lookup runs'
        );
    }

    // --- Atomicity / no partial commits ---

    public function testInsertFailureRollsBackInsteadOfCommitting(): void
    {
        $db = new FakeRecordMysqli();
        $db->insertReturns = false;

        $this->expectException(RuntimeException::class);
        try {
            p202RecordConversion($db, $this->log(), '', false, '', '');
        } finally {
            self::assertTrue($db->rolledBack, 'A failed conversion_logs insert must roll back the click update');
            self::assertFalse($db->committed, 'Nothing may be committed when the insert fails');
        }
    }

    public function testMissingSourceClickWritesNoOrphanConversion(): void
    {
        $db = new FakeRecordMysqli();
        $db->clickRow = null; // FOR UPDATE finds nothing

        $result = p202RecordConversion($db, $this->log(), '', false, '', 'ORDER-9');

        self::assertSame(0, $result['conv_id']);
        self::assertFalse($result['duplicate']);
        self::assertTrue($db->rolledBack);
        self::assertNull(
            $this->firstQueryContaining($db->queries, 'INSERT INTO 202_conversion_logs'),
            'No conversion row may be written for a click that no longer exists'
        );
    }

    // --- Locking ---

    public function testSourceClickIsLockedForUpdate(): void
    {
        $db = new FakeRecordMysqli();
        $db->insertReturns = false;

        try {
            p202RecordConversion($db, $this->log(), '', false, '', '');
        } catch (RuntimeException $e) {
            // ignore — we only care about the lock query below
        }

        $lock = $this->firstQueryContaining($db->queries, 'FOR UPDATE');
        self::assertNotNull($lock, 'The source click must be locked with SELECT ... FOR UPDATE');
        self::assertStringContainsString('202_clicks', $lock);
        self::assertStringContainsString('click_id = 100', $lock);
    }

    // --- transaction_id storage (NULL vs quoted) ---

    public function testEmptyTransactionIdIsStoredAsNull(): void
    {
        $db = new FakeRecordMysqli();
        $db->insertReturns = false;

        try {
            p202RecordConversion($db, $this->log(), '', false, '', '');
        } catch (RuntimeException $e) {
        }

        $insert = $this->firstQueryContaining($db->queries, 'INSERT INTO 202_conversion_logs');
        self::assertNotNull($insert);
        self::assertStringContainsString('transaction_id = NULL', $insert);
        self::assertStringNotContainsString("transaction_id = ''", $insert);
    }

    public function testTransactionIdIsEscapedInBothLookupAndInsert(): void
    {
        $db = new FakeRecordMysqli();
        $db->insertReturns = false;

        try {
            p202RecordConversion($db, $this->log(), '', false, '', "ord'1");
        } catch (RuntimeException $e) {
        }

        $lookup = $this->firstQueryContaining($db->queries, 'SELECT conv_id');
        self::assertNotNull($lookup);
        self::assertStringContainsString("transaction_id = 'ord\\'1'", $lookup);

        $insert = $this->firstQueryContaining($db->queries, 'INSERT INTO 202_conversion_logs');
        self::assertNotNull($insert);
        self::assertStringContainsString("transaction_id = 'ord\\'1'", $insert);
    }

    // --- Input validation ---

    public function testNonPositiveClickIdThrowsBeforeAnyDbWork(): void
    {
        $db = new FakeRecordMysqli();
        $log = $this->log();
        $log['click_id'] = 0;

        try {
            p202RecordConversion($db, $log, '', false, '', '');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertSame([], $db->queries, 'No query should run for an invalid click id');
            self::assertFalse($db->committed);
        }
    }

    // --- p202ExtractTransactionId ---

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

    // --- helpers ---

    private function firstQueryContaining(array $queries, string $needle): ?string
    {
        foreach ($queries as $q) {
            if (str_contains($q, $needle)) {
                return $q;
            }
        }
        return null;
    }
}

/**
 * Fake mysqli_result returning canned rows; only fetch_assoc()/free() are used.
 */
class FakeRecordResult extends \mysqli_result
{
    /** @var list<array<string,mixed>> */
    private array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    #[\ReturnTypeWillChange]
    public function fetch_assoc(): array|false|null
    {
        return array_shift($this->rows) ?? null;
    }

    #[\ReturnTypeWillChange]
    public function free(): void
    {
    }
}

/**
 * Fake mysqli that records queries and serves canned results for the lock and
 * idempotency SELECTs, so p202RecordConversion can be exercised without a DB.
 * The success path (which reads the read-only $db->insert_id) is covered by the
 * integration suite; these unit tests drive the dedup / failure / lock paths.
 */
class FakeRecordMysqli extends \mysqli
{
    /** @var list<string> */
    public array $queries = [];
    public bool $committed = false;
    public bool $rolledBack = false;
    public bool $insertReturns = true;

    /** @var array<string,mixed>|null Row returned by the FOR UPDATE lock query. */
    public ?array $clickRow = ['click_id' => '100'];
    /** @var array<string,mixed>|null Row returned by the idempotency lookup. */
    public ?array $dupRow = null;

    public function __construct()
    {
        // Skip parent constructor — no real connection.
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->committed = true;
        return true;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rolledBack = true;
        return true;
    }

    public function real_escape_string(string $string): string
    {
        return addslashes($string);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        $this->queries[] = $query;

        if (str_contains($query, 'FOR UPDATE')) {
            return new FakeRecordResult($this->clickRow !== null ? [$this->clickRow] : []);
        }
        if (str_contains($query, 'SELECT conv_id')) {
            return new FakeRecordResult($this->dupRow !== null ? [$this->dupRow] : []);
        }
        if (str_starts_with(ltrim($query), 'INSERT')) {
            return $this->insertReturns;
        }

        // UPDATE 202_clicks / 202_clicks_spy from p202ApplyConversionUpdate
        return true;
    }

    public function close(): true
    {
        return true;
    }
}
