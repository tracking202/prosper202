<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of p202RecordLegacyConversion(), the writer behind px.php,
 * pb.php and cb202.php: the gate that decides whether a hit records, the
 * time-difference wording, and the input guard that runs before any database
 * work. The database path is exercised for real by tests/live/legacy-pixels.sh
 * against a running instance, because the thing under test there is the
 * wiring itself (CLAUDE.md error pattern #9).
 */
final class LegacyConversionRecordTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public function setDirtyHour($click_id) {}
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
    }

    // --- the gate --------------------------------------------------------

    public function testFreshClickRecordsWithoutTransactionId(): void
    {
        self::assertNull(p202LegacyConversionGate(false, ''));
    }

    public function testLeadClickWithoutTransactionIdIsBlocked(): void
    {
        // The one-conversion-per-click rule gpx.php applies: a retry and a
        // repeat are indistinguishable without an id, so the second hit stops.
        self::assertSame('already_lead', p202LegacyConversionGate(true, ''));
        self::assertSame('already_lead', p202LegacyConversionGate(true, '   '));
    }

    public function testLeadClickWithTransactionIdRecords(): void
    {
        // A transaction id lifts the gate: a new id is a repeat purchase and a
        // replayed id is de-duplicated by the writer, so recording is safe.
        self::assertNull(p202LegacyConversionGate(true, 'RCPT-1'));
    }

    // --- time difference ---------------------------------------------------

    public function testTimeDifferenceUsesTheLegacyWording(): void
    {
        $click = 1_700_000_000;
        self::assertSame('0 days, 0 hours, 0 min and 0 sec', p202TimeDifference($click, $click));
        self::assertSame('1 days, 2 hours, 3 min and 4 sec', p202TimeDifference($click, $click + 86400 + 7200 + 180 + 4));
    }

    public function testTimeDifferenceOfAClickWithNoTimeDoesNotThrow(): void
    {
        // A click_time of 0 (pre-existing rows can carry one) is a very long
        // gap, not an exception on the conversion path.
        self::assertStringEndsWith(' sec', p202TimeDifference(0, 1_700_000_000));
    }

    // --- input guard, before any database work ----------------------------

    public function testNonPositiveClickIdThrowsBeforeAnyQuery(): void
    {
        $db = new FakeLegacyMysqli();
        try {
            p202RecordLegacyConversion($db, 0, 1);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $db->prepares, 'No statement may be prepared for an invalid click id');
        }
    }
}

/**
 * Counts prepare() calls without connecting. Any real query would reach
 * parent::prepare() on an unconnected handle, so a count above zero is the
 * failure this fake exists to detect.
 */
class FakeLegacyMysqli extends \mysqli
{
    public int $prepares = 0;

    public function __construct()
    {
        // Skip parent constructor — no real connection.
    }

    public function prepare(string $query): \mysqli_stmt|false
    {
        $this->prepares++;
        return false;
    }
}
