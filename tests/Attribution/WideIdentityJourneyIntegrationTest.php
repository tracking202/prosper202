<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Journey;
use Prosper202\Attribution\JourneyBuilder;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * A person's keys are read in groups (JourneyBuilder::KEYS_PER_STATEMENT),
 * and the journey is the same one a single read of every key would answer:
 * the latest touches of them all, in time and then click id order, cut
 * where a single read cut it.
 *
 * @group integration
 */
final class WideIdentityJourneyIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private const CANONICAL = 5_000_000;
    private const BASE = 1_700_000_000;
    private const KEYS = 3 * JourneyBuilder::KEYS_PER_STATEMENT + 1;
    private const CONVERTING = 900;

    public function testTheLatestTouchesOfEveryGroupAreTheJourneyAndItIsCutWhereOneReadCutIt(): void
    {
        $this->person();
        // MAX_TOUCHES earlier clicks, one second apart, dealt round the four
        // groups so each group holds some of the latest; the last two share
        // a second, which the click id orders.
        $expected = [];
        for ($i = 0; $i < Journey::MAX_TOUCHES; $i++) {
            $key = $i % 4 === 3 ? self::KEYS - 1 : ($i % 4) * JourneyBuilder::KEYS_PER_STATEMENT + $i;
            $time = self::BASE + min($i, Journey::MAX_TOUCHES - 2);
            $this->clickOn(100 + $i, $key, $time);
            $expected[] = 100 + $i;
        }
        $this->clickOn(self::CONVERTING, 0, self::BASE + 1000);

        $journey = $this->build();

        self::assertTrue($journey->truncated, 'one earlier click more than fits');
        self::assertSame(
            [...array_slice($expected, 1), self::CONVERTING],
            self::clickIds($journey),
            'the oldest earlier click is the one cut'
        );
        self::assertSame(range(0, Journey::MAX_TOUCHES - 1), array_column($journey->touches, 'position'));

        self::fixture('DELETE FROM 202_clicks_visitor WHERE click_id = 100');
        $journey = $this->build();
        self::assertFalse($journey->truncated, 'as many earlier clicks as fit');
        self::assertSame([...array_slice($expected, 1), self::CONVERTING], self::clickIds($journey));
    }

    public function testClicksOutsideTheWindowOrAfterTheConvertingClickAreNotTouches(): void
    {
        $this->person();
        $this->clickOn(100, self::KEYS - 1, self::BASE - 31 * 86400);
        $this->clickOn(101, self::KEYS - 1, self::BASE + 10);
        $this->clickOn(102, 2 * JourneyBuilder::KEYS_PER_STATEMENT, self::BASE + 20);
        $this->clickOn(103, 1, self::BASE + 2000);
        $this->clickOn(self::CONVERTING, 0, self::BASE + 1000);

        $journey = $this->build();

        self::assertFalse($journey->truncated);
        self::assertSame([101, 102, self::CONVERTING], self::clickIds($journey));
    }

    /** @return list<int> */
    private static function clickIds(Journey $journey): array
    {
        return array_map(static fn ($t): int => $t->clickId, $journey->touches);
    }

    private function build(): Journey
    {
        return (new JourneyBuilder($this->conn))
            ->build(1, self::CONVERTING, self::BASE + 1000, self::BASE + 1100, 30);
    }

    /** One person of KEYS visitor keys: CANONICAL and its aliases CANONICAL + 1 on. */
    private function person(): void
    {
        $rows = ['(' . self::CANONICAL . ', 1, NULL, 1)'];
        for ($k = 1; $k < self::KEYS; $k++) {
            $rows[] = '(' . (self::CANONICAL + $k) . ', 1, ' . self::CANONICAL . ', 1)';
        }
        self::fixture('INSERT INTO 202_identity_visitors (visitor_key, user_id, alias_of, created_at) VALUES '
            . implode(', ', $rows));
        $this->campaign(1);
    }

    private function clickOn(int $clickId, int $offset, int $time): void
    {
        $this->click($clickId, 1, $time);
        self::fixture('INSERT INTO 202_clicks_visitor (click_id, user_id, visitor_key, click_time) VALUES ('
            . $clickId . ', 1, ' . (self::CANONICAL + $offset) . ', ' . $time . ')');
    }
}
