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

    // --- click ids from untrusted input -------------------------------------

    /**
     * @dataProvider acceptedClickIds
     */
    public function testParseClickIdAcceptsExactPositiveIntegers(mixed $input, int $expected): void
    {
        self::assertSame($expected, p202ParseClickId($input));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function acceptedClickIds(): iterable
    {
        yield 'digits' => ['910000001', 910000001];
        yield 'one' => ['1', 1];
        yield 'an int' => [42, 42];
        yield 'bigint-sized' => ['9223372036854775807', PHP_INT_MAX];
    }

    /**
     * Every shape is_numeric() would have let through and the int cast would
     * then have turned into a DIFFERENT click, plus the plainly invalid ones.
     *
     * @dataProvider refusedClickIds
     */
    public function testParseClickIdRefusesAnythingElse(mixed $input): void
    {
        self::assertNull(p202ParseClickId($input));
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedClickIds(): iterable
    {
        yield 'fraction (would become 123)' => ['123.9'];
        yield 'exponent (would become 1000)' => ['1e3'];
        yield 'leading space' => [' 42'];
        yield 'trailing newline' => ["42\n"];
        yield 'plus sign' => ['+42'];
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'leading zero' => ['042'];
        yield 'hex' => ['0x1A'];
        yield 'beyond bigint (would saturate)' => ['99999999999999999999'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'float' => [12.0];
        yield 'bool' => [true];
        yield 'array' => [['1']];
    }

    // --- the stored client address ------------------------------------------

    /**
     * @dataProvider clientIps
     * @param array<string,mixed> $server
     */
    public function testClientIpIsOneValidAddressOrNothing(array $server, string $expected): void
    {
        $ip = p202ClientIp($server);
        self::assertSame($expected, $ip);
        self::assertLessThanOrEqual(45, strlen($ip), 'must fit 202_conversion_logs.ip varchar(45)');
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function clientIps(): iterable
    {
        yield 'no proxy' => [['REMOTE_ADDR' => '203.0.113.9'], '203.0.113.9'];
        yield 'one hop' => [['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'REMOTE_ADDR' => '10.0.0.1'], '198.51.100.7'];
        yield 'a chain keeps the leftmost hop' => [
            ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2, 10.0.0.3', 'REMOTE_ADDR' => '10.0.0.1'],
            '198.51.100.7',
        ];
        yield 'an IPv6 chain longer than the column' => [
            ['HTTP_X_FORWARDED_FOR' => '2001:db8:85a3:8d3:1319:8a2e:370:7348, 2001:db8:85a3:8d3:1319:8a2e:370:7349, 2001:db8::1', 'REMOTE_ADDR' => '10.0.0.1'],
            '2001:db8:85a3:8d3:1319:8a2e:370:7348',
        ];
        yield 'garbage header falls back to the peer' => [
            ['HTTP_X_FORWARDED_FOR' => str_repeat('x', 200), 'REMOTE_ADDR' => '10.0.0.1'],
            '10.0.0.1',
        ];
        yield 'an unknown hop falls back to the peer' => [
            ['HTTP_X_FORWARDED_FOR' => 'unknown, 198.51.100.7', 'REMOTE_ADDR' => '10.0.0.1'],
            '10.0.0.1',
        ];
        yield 'a port suffix is not an address' => [
            ['HTTP_X_FORWARDED_FOR' => '198.51.100.7:8080', 'REMOTE_ADDR' => '10.0.0.1'],
            '10.0.0.1',
        ];
        yield 'nothing usable' => [['HTTP_X_FORWARDED_FOR' => 'nope', 'REMOTE_ADDR' => 'also nope'], ''];
        yield 'nothing at all' => [[], ''];
        yield 'whitespace around the peer address is trimmed' => [['HTTP_X_FORWARDED_FOR' => '', 'REMOTE_ADDR' => '  203.0.113.9 '], '203.0.113.9'];
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
