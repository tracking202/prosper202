<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Prosper202\Update\SubidBatch;

/**
 * Update Subids reads `SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP` per line,
 * as 1.9.56 did, and names every line it cannot read rather than skipping it.
 */
final class SubidLinesTest extends TestCase
{
    private const NOW = 1793750400; // 2026-11-04 00:00:00 UTC

    /** @return list<array<string, mixed>> */
    private static function read(array $items, string $zone = 'UTC'): array
    {
        return SubidBatch::readLines($items, new \DateTimeZone($zone), self::NOW);
    }

    public function testABareSubidIsReadAsBefore(): void
    {
        self::assertSame(
            [['line' => 1, 'subid' => '940001', 'click_id' => 940001, 'status' => null]],
            self::read(['940001'])
        );
    }

    public function testEveryFieldIsRead(): void
    {
        $entry = self::read(['940001, 12.50, order-9, 2026-11-03 14:30:00'])[0];
        self::assertNull($entry['status']);
        self::assertSame(940001, $entry['click_id']);
        self::assertSame('12.50', $entry['payout']);
        self::assertSame('order-9', $entry['transaction_id']);
        self::assertSame(gmmktime(14, 30, 0, 11, 3, 2026), $entry['conv_time']);
    }

    public function testAnEmptyFieldIsSkipped(): void
    {
        $entry = self::read(['940001,,order-9'])[0];
        self::assertNull($entry['status']);
        self::assertArrayNotHasKey('payout', $entry);
        self::assertSame('order-9', $entry['transaction_id']);
        self::assertArrayNotHasKey('conv_time', $entry);
    }

    public function testTheTimeIsReadInTheZoneGiven(): void
    {
        // 2:30 PM in New York on 3 Nov 2026 (EST, UTC-5) is 19:30 UTC.
        $entry = self::read(['940001,,,11/03/2026 2:30:00 PM'], 'America/New_York')[0];
        self::assertSame(gmmktime(19, 30, 0, 11, 3, 2026), $entry['conv_time']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function times(): iterable
    {
        yield 'us, 12-hour' => ['11/3/2026 2:30:05 pm', gmmktime(14, 30, 5, 11, 3, 2026)];
        yield 'us, two-digit year' => ['11/03/26 02:30 AM', gmmktime(2, 30, 0, 11, 3, 2026)];
        yield 'us, midnight' => ['11/03/2026 12:00:00 AM', gmmktime(0, 0, 0, 11, 3, 2026)];
        yield 'us, noon' => ['11/03/2026 12:00:00 PM', gmmktime(12, 0, 0, 11, 3, 2026)];
        yield 'us, date only' => ['11/03/2026', gmmktime(0, 0, 0, 11, 3, 2026)];
        yield 'iso with T' => ['2026-11-03T14:30', gmmktime(14, 30, 0, 11, 3, 2026)];
        yield 'unix timestamp' => ['1793700000', 1793700000];
    }

    /** @dataProvider times */
    public function testTheTimeFormatsRead(string $time, int $expected): void
    {
        self::assertSame($expected, SubidBatch::parseTime($time, new \DateTimeZone('UTC')));
    }

    /** @return iterable<string, array{string}> */
    public static function badTimes(): iterable
    {
        yield 'no such day' => ['02/30/2026'];
        yield 'hour 13 with pm' => ['11/03/2026 13:00 PM'];
        yield 'hour 24' => ['2026-11-03 24:00'];
        yield 'day first' => ['31/12/2026'];
        yield 'words' => ['yesterday'];
        yield 'relative' => ['+1 day'];
    }

    /** @dataProvider badTimes */
    public function testATimeThatIsNotADateIsRefused(string $time): void
    {
        self::assertNull(SubidBatch::parseTime($time, new \DateTimeZone('UTC')));
        $entry = self::read(['940001,,,' . $time])[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status']);
        self::assertStringContainsString('is not a date and time', $entry['error']);
    }

    public function testATimeInTheFutureIsRefused(): void
    {
        $entry = self::read(['940001,,,2026-11-05 00:00:00'])[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status']);
        self::assertStringContainsString('in the future', $entry['error']);
    }

    public function testATimeWithNoZoneIsRefused(): void
    {
        $entry = SubidBatch::readLines(['940001,,,2026-11-03'], null, self::NOW)[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status']);
        self::assertStringContainsString('no time zone', $entry['error']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPayouts(): iterable
    {
        yield 'words' => ['abc', 'is not a number'];
        yield 'currency sign' => ['$12.50', 'is not a number'];
        yield 'thousands separator' => ['1,250.00', 'thousands separator'];
        yield 'thousands separator, no decimals' => ['1,250', 'thousands separator'];
        yield 'decimal comma' => ['12,50', 'decimal comma'];
        yield 'decimal comma, one decimal' => ['12,5', 'decimal comma'];
        yield 'decimal comma, one-digit amount' => ['7,5', 'decimal comma'];
        yield 'decimal comma, four-digit amount' => ['1000,50', 'decimal comma'];
        yield 'decimal comma, zero cents' => ['2500,00', 'decimal comma'];
        yield 'dot thousands, comma decimals' => ['1.250,00', 'a dot for thousands'];
        yield 'exponent' => ['1e3', 'is not a number'];
        yield 'negative' => ['-5', 'is negative'];
        yield 'a million' => ['1000000', 'too large'];
        yield 'rounds to a million in the report' => ['999999.995', 'too large'];
        yield 'past the report column' => ['999999.99001', 'too large'];
        yield 'twenty digits' => ['99999999999999999999', 'too large'];
        yield 'six decimals' => ['1.123456', 'is not a number'];
    }

    /** @dataProvider badPayouts */
    public function testAPayoutThatCannotBeRecordedIsRefused(string $payout, string $why): void
    {
        $entry = self::read(['940001,' . $payout])[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status'], $payout);
        self::assertStringContainsString($why, $entry['error']);
    }

    public function testFiveFieldsAreRefused(): void
    {
        $entry = self::read(['940001, 1, tx, 2026-11-03, extra'])[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status']);
        self::assertStringContainsString('5 fields', $entry['error']);
    }

    public function testAnOverlongTransactionIdIsRefused(): void
    {
        $entry = self::read(['940001,,' . str_repeat('x', 256)])[0];
        self::assertSame(SubidBatch::INVALID_LINE, $entry['status']);
        self::assertStringContainsString('longer than 255', $entry['error']);
    }

    public function testASubidThatIsNotOneStaysNotASubid(): void
    {
        $entry = self::read(['abc, 5'])[0];
        self::assertSame(SubidBatch::NOT_A_SUBID, $entry['status']);
        self::assertSame('abc', $entry['subid']);
    }

    /**
     * A click with two sales is two lines with two transaction ids; the same
     * click and id twice is a repeat, as is a bare subid twice.
     */
    public function testARepeatIsTheSameClickAndTransactionId(): void
    {
        $entries = self::read(['940001, 5, A', '940001, 7, B', '940001, 5, A', '940001', '940001']);
        self::assertSame([null, null, SubidBatch::DUPLICATE_IN_LIST, null, SubidBatch::DUPLICATE_IN_LIST], array_column($entries, 'status'));
        self::assertSame(1, $entries[2]['first_line']);
        self::assertSame(4, $entries[4]['first_line']);
    }

    public function testTheDefaultPayoutIsHeldToTheSameRule(): void
    {
        self::assertNull(SubidBatch::payoutError('0'));
        self::assertNull(SubidBatch::payoutError('999999.99'));
        self::assertNull(SubidBatch::payoutError('999999.99000'));
        self::assertNull(SubidBatch::payoutError('0999999.98999'));
        self::assertNotNull(SubidBatch::payoutError('12,50'));
        self::assertNotNull(SubidBatch::payoutError(''));
    }

    /** Delete Subids reads bare subids only: a line with fields names no click to clear. */
    public function testTheBareReaderTakesNoFields(): void
    {
        self::assertSame(SubidBatch::NOT_A_SUBID, SubidBatch::read(['940001, 5'])[0]['status']);
        self::assertNull(SubidBatch::read(['940001'])[0]['status']);
    }

    /** A short numeric transaction id is still read after a payout written with its decimals. */
    public function testAPayoutWithItsDecimalsTakesAShortNumericTransactionId(): void
    {
        $entry = self::read(['940001, 12.00, 50'])[0];
        self::assertNull($entry['status']);
        self::assertSame('50', $entry['transaction_id']);
    }

    /** 2:30 AM on 8 March 2026 never happened in New York: the clocks went from 2:00 to 3:00. */
    public function testAWallTimeTheClocksSkippedIsRefused(): void
    {
        $zone = new \DateTimeZone('America/New_York');
        self::assertNull(SubidBatch::parseTime('03/08/2026 2:30:00 AM', $zone));
        self::assertSame(
            (new \DateTimeImmutable('2026-03-08 03:30:00', $zone))->getTimestamp(),
            SubidBatch::parseTime('03/08/2026 3:30:00 AM', $zone)
        );
    }
}
