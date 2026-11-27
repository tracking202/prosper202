<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Lifecycle\UnknownState;
use Prosper202\Rotator\RuleStatus;

final class RuleStatusTest extends TestCase
{
    /** @dataProvider storedValues */
    public function testAStoredValueNamesOneStateOrNone(mixed $stored, ?RuleStatus $state): void
    {
        self::assertSame($state, RuleStatus::tryOfStored($stored));
        if ($state === null) {
            $this->expectException(UnknownState::class);
            $this->expectExceptionMessage('rule 7 holds the state');
        }
        self::assertSame($state, RuleStatus::ofStored($stored, 'rule 7'));
    }

    /** @return iterable<string, array{mixed, ?RuleStatus}> */
    public static function storedValues(): iterable
    {
        yield 'int 1' => [1, RuleStatus::Active];
        yield 'the digit 1, as a plain query reads it' => ['1', RuleStatus::Active];
        yield 'int 0' => [0, RuleStatus::Inactive];
        yield 'the digit 0' => ['0', RuleStatus::Inactive];
        yield 'NULL' => [null, RuleStatus::Unset];
        yield 'another int the column takes' => [2, null];
        yield 'a negative' => ['-1', null];
        yield 'empty' => ['', null];
        yield 'true' => [true, null];
        yield 'false' => [false, null];
        yield 'a float' => [1.0, null];
        yield 'a number with a tail' => ['1abc', null];
        yield 'padded' => [' 1', null];
        yield 'a word' => ['active', null];
        yield 'a list' => [[1], null];
    }

    public function testOnlyAnActiveRuleTakesVisitors(): void
    {
        self::assertSame(
            ['Active' => true, 'Inactive' => false, 'Unset' => false],
            array_combine(array_column(RuleStatus::cases(), 'name'), array_map(static fn (RuleStatus $s): bool => $s->matchesTraffic(), RuleStatus::cases()))
        );
        // A value that names no state takes none (CLAUDE.md #11).
        foreach ([1 => true, '1' => true, 0 => false, 2 => false] as $stored => $takes) {
            self::assertSame($takes, RuleStatus::storedMatchesTraffic($stored), var_export($stored, true));
        }
        foreach ([null, '', true, '1abc', 1.0, 'active'] as $stored) {
            self::assertFalse(RuleStatus::storedMatchesTraffic($stored), var_export($stored, true));
        }
        self::assertSame('ru.status = 1', RuleStatus::matchesTrafficSql('ru.status'));
        self::assertSame(['active', 'inactive', 'inactive'], array_map(static fn (RuleStatus $s): string => $s->word(), RuleStatus::cases()));
    }

    public function testTheConditionTakesAColumnNameOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleStatus::matchesTrafficSql('status = 1 OR 1');
    }

    public function testAStateIsStoredAsTheValueItIsReadFrom(): void
    {
        self::assertSame([1, 0, null], array_map(static fn (RuleStatus $s): ?int => $s->stored(), RuleStatus::cases()));
        foreach (RuleStatus::cases() as $state) {
            self::assertSame($state, RuleStatus::ofStored($state->stored(), 'a rule'));
        }
    }

    /** The whole table: a rule is switched on and off, and nothing is given no status. */
    public function testTheMovesAreTheSwitchAndNothingBecomesUnset(): void
    {
        $moves = [];
        foreach (RuleStatus::cases() as $from) {
            foreach (RuleStatus::cases() as $to) {
                if ($from->canMoveTo($to)) {
                    $moves[] = $from->name . '>' . $to->name;
                }
            }
        }
        self::assertSame(['Active>Inactive', 'Inactive>Active', 'Unset>Active', 'Unset>Inactive'], $moves);
        self::assertSame([RuleStatus::Active, RuleStatus::Inactive], RuleStatus::initial(), 'no rule is created with no status');
        self::assertSame(RuleStatus::Active, RuleStatus::Unset->moveTo(RuleStatus::Active));
    }

    /** @dataProvider illegalMoves */
    public function testAMoveTheTableDoesNotListIsRefused(RuleStatus $from, RuleStatus $to): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($from->name . ' may not become ' . $to->name);
        $from->moveTo($to);
    }

    /** @return iterable<string, array{RuleStatus, RuleStatus}> */
    public static function illegalMoves(): iterable
    {
        yield 'active to no status' => [RuleStatus::Active, RuleStatus::Unset];
        yield 'inactive to no status' => [RuleStatus::Inactive, RuleStatus::Unset];
        foreach (RuleStatus::cases() as $state) {
            yield $state->name . ' to itself' => [$state, $state];
        }
    }

    /** @dataProvider sentValues */
    public function testABodySendsZeroOrOneOrNothing(mixed $sent, ?RuleStatus $state, bool $refused = false): void
    {
        if ($refused) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($state, RuleStatus::ofSent($sent));
    }

    /** @return iterable<string, array{0: mixed, 1: ?RuleStatus, 2?: bool}> */
    public static function sentValues(): iterable
    {
        yield '1' => [1, RuleStatus::Active];
        yield 'the digit 1' => ['1', RuleStatus::Active];
        yield '0' => [0, RuleStatus::Inactive];
        yield 'the digit 0' => ['0', RuleStatus::Inactive];
        yield 'null sends none' => [null, null];
        yield 'empty sends none' => ['', null];
        yield '2' => [2, null, true];
        yield '1.5' => ['1.5', null, true];
        yield 'true' => [true, null, true];
        yield 'a word' => ['active', null, true];
        yield 'a list' => [[1], null, true];
    }
}
