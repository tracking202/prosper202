<?php

declare(strict_types=1);

namespace Tests\Report\Json;

use PHPUnit\Framework\TestCase;
use Tracking202\Report\Json\FlatReportPayloadBuilder;

/**
 * Tests for the pagination block of the JSON report payload.
 *
 * The report SQL runs its LIMIT with the client's raw offset before this metadata is
 * built, so the metadata must describe the page that was actually queried. An earlier
 * version clamped the reported offset, which meant an out-of-range request returned an
 * empty row set described as the last page.
 */
final class FlatReportPaginationTest extends TestCase
{
    public function testDescribesAMiddlePage(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 2, '');

        self::assertSame(250, $p['totalRows']);
        self::assertSame(50, $p['limit']);
        self::assertSame(5, $p['pageCount']);
        self::assertSame(2, $p['currentOffset']);
        self::assertSame(3, $p['currentPageNumber']);
        self::assertFalse($p['outOfRange']);
        self::assertTrue($p['hasPreviousPage']);
        self::assertSame(1, $p['previousOffset']);
        self::assertTrue($p['hasNextPage']);
        self::assertSame(3, $p['nextOffset']);
    }

    public function testFirstPageHasNoPrevious(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 0, '');

        self::assertFalse($p['hasPreviousPage']);
        self::assertSame(0, $p['previousOffset']);
        self::assertTrue($p['hasNextPage']);
    }

    public function testLastPageHasNoNext(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 4, '');

        self::assertSame(5, $p['pageCount']);
        self::assertFalse($p['outOfRange']);
        self::assertFalse($p['hasNextPage']);
        self::assertSame(4, $p['nextOffset'], 'next is a no-op on the last page');
        self::assertTrue($p['hasPreviousPage']);
    }

    /**
     * The regression this class exists for: offset past the end must not be described as
     * the last page. The rows are empty, and saying otherwise makes the client render an
     * empty table labelled "page 5 of 5".
     */
    public function testOutOfRangeOffsetIsReportedHonestly(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 999, '');

        self::assertSame(5, $p['pageCount']);
        self::assertTrue($p['outOfRange']);
        self::assertSame(999, $p['currentOffset'], 'must report the offset the query actually used');
        self::assertFalse($p['hasNextPage']);
    }

    /**
     * ...and the client must be able to get back to real data from there.
     */
    public function testOutOfRangeOffsetPointsPreviousAtTheLastRealPage(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 999, '');

        self::assertTrue($p['hasPreviousPage']);
        self::assertSame(4, $p['previousOffset'], 'previous should recover to the last page that has rows');
    }

    public function testEmptyReportIsASinglePage(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(0, 50, 0, '');

        self::assertSame(0, $p['totalRows']);
        self::assertSame(1, $p['pageCount']);
        self::assertFalse($p['outOfRange']);
        self::assertFalse($p['hasNextPage']);
        self::assertFalse($p['hasPreviousPage']);
    }

    /**
     * A missing/zero user_pref_limit must not divide by zero.
     */
    public function testZeroLimitFallsBackToASinglePage(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(120, 0, 0, '');

        self::assertSame(120, $p['limit'], 'a zero limit degrades to one page holding every row');
        self::assertSame(1, $p['pageCount']);
        self::assertFalse($p['hasNextPage']);
    }

    public function testOrderTokenIsEchoedBackForPaginationLinks(): void
    {
        $p = FlatReportPayloadBuilder::paginationFor(250, 50, 1, 'sort_breakdown_epc desc');

        self::assertSame('sort_breakdown_epc desc', $p['orderToken']);
    }
}
