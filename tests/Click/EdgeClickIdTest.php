<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickId;
use Prosper202\Click\EdgeClickIdRefused;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RecordedClicks;
use Prosper202\Database\Connection;

/**
 * Click ids from ClickId::EDGE_FIRST up are the hosted edge's, and the
 * install's allocator never hands one out: it refuses the id its counter
 * answered rather than record a click under an id the edge may have given
 * another click.
 */
final class EdgeClickIdTest extends TestCase
{
    protected function tearDown(): void
    {
        RecordedClicks::reset();
    }

    public function testIdsFromTenBillionUpAreTheEdges(): void
    {
        self::assertSame(10_000_000_000, ClickId::EDGE_FIRST);
        self::assertFalse(ClickId::isEdge(1));
        self::assertFalse(ClickId::isEdge(9_999_999_999));
        self::assertTrue(ClickId::isEdge(10_000_000_000));
        self::assertTrue(ClickId::isEdge(89_999_999_999));
        self::assertTrue(ClickId::isEdge(90_000_000_000), "the edge's fallback allocators");
        self::assertLessThan(2 ** 53, 99_999_999_999 * 100 + 99, 'a public id stays exact in a JSON number');
    }

    public function testTheAllocatorHandsOutTheLastIdBelowTheEdge(): void
    {
        $repo = new MysqlClickRepository(new Connection(self::counterAnswering(9_999_999_999)));

        self::assertSame(9_999_999_999, $repo->allocateClickId());
        self::assertTrue(RecordedClicks::has(9_999_999_999));
    }

    public function testTheAllocatorRefusesAnEdgeId(): void
    {
        $repo = new MysqlClickRepository(new Connection(self::counterAnswering(10_000_000_000)));

        try {
            $repo->allocateClickId();
            self::fail('an edge id was handed out');
        } catch (EdgeClickIdRefused $refused) {
            self::assertSame(10_000_000_000, $refused->clickId);
            self::assertStringContainsString('click id 10000000000', $refused->getMessage());
            self::assertStringContainsString('202_clicks_counter', $refused->getMessage());
        }
        self::assertFalse(RecordedClicks::has(10_000_000_000), 'a refused id is not one this request may sign');
    }

    /** A connection whose counter INSERT answers $id. */
    private static function counterAnswering(int $id): \mysqli
    {
        return new class ($id) extends \mysqli {
            public function __construct(private int $id)
            {
                // No server.
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query): object
            {
                TestCase::assertSame('INSERT INTO 202_clicks_counter SET click_id = DEFAULT', $query);

                return new class ($this->id) {
                    public int $insert_id = 0;
                    public string $error = '';
                    public int $errno = 0;

                    public function __construct(private int $id)
                    {
                    }

                    public function execute(): bool
                    {
                        $this->insert_id = $this->id;

                        return true;
                    }

                    public function close(): bool
                    {
                        return true;
                    }
                };
            }
        };
    }
}
