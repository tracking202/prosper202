<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Journey;
use Prosper202\Attribution\JourneyBuilder;

/**
 * JourneyBuilder::latestTouches() merges the reads of a person's groups of
 * keys: the latest clicks that fit beside the converting click, oldest
 * first, whatever order the groups answered in.
 */
final class JourneyLatestTouchesTest extends TestCase
{
    public function testTheLatestThatFitAreKeptOldestFirst(): void
    {
        // Two groups' answers, each newest first, interleaved in time.
        $clicks = [];
        for ($i = 0; $i < Journey::MAX_TOUCHES; $i++) {
            $clicks[200 + 2 * $i] = 1000 + 2 * $i;
        }
        for ($i = 0; $i < Journey::MAX_TOUCHES; $i++) {
            $clicks[201 + 2 * $i] = 1001 + 2 * $i;
        }

        $latest = JourneyBuilder::latestTouches($clicks);

        self::assertTrue($latest['truncated']);
        $kept = range(200 + 2 * Journey::MAX_TOUCHES - (Journey::MAX_TOUCHES - 1), 200 + 2 * Journey::MAX_TOUCHES - 1);
        self::assertSame($kept, array_keys($latest['clicks']));
        self::assertSame(array_map(static fn (int $id): int => $id + 800, $kept), array_values($latest['clicks']));
    }

    public function testATieInTimeIsOrderedByClickId(): void
    {
        $latest = JourneyBuilder::latestTouches([9 => 50, 3 => 50, 7 => 40, 5 => 50]);

        self::assertFalse($latest['truncated']);
        self::assertSame([7 => 40, 3 => 50, 5 => 50, 9 => 50], $latest['clicks']);
    }

    public function testAsManyAsFitAreNotCut(): void
    {
        $clicks = [];
        for ($i = 1; $i < Journey::MAX_TOUCHES; $i++) {
            $clicks[$i] = $i;
        }

        $latest = JourneyBuilder::latestTouches($clicks);

        self::assertFalse($latest['truncated']);
        self::assertSame($clicks, $latest['clicks']);
        $oneMore = JourneyBuilder::latestTouches($clicks + [Journey::MAX_TOUCHES => 0]);
        self::assertTrue($oneMore['truncated']);
        self::assertSame($clicks, $oneMore['clicks'], 'the oldest is cut');
        self::assertSame(['clicks' => [], 'truncated' => false], JourneyBuilder::latestTouches([]));
    }
}
