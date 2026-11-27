<?php

declare(strict_types=1);

namespace Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Lifecycle\UnknownState;
use Tests\Lifecycle\Fixtures\ParcelState;

final class MovesTest extends TestCase
{
    public function testAMoveTheTableHoldsIsMade(): void
    {
        self::assertTrue(ParcelState::Packed->canMoveTo(ParcelState::Sent));
        self::assertSame(ParcelState::Sent, ParcelState::Packed->moveTo(ParcelState::Sent));
        self::assertSame(ParcelState::Sent, ParcelState::Returned->moveTo(ParcelState::Sent), 'a loop back is a move');
    }

    /** @dataProvider illegalMoves */
    public function testAMoveTheTableDoesNotHoldIsRefusedNamingBoth(ParcelState $from, ParcelState $to): void
    {
        self::assertFalse($from->canMoveTo($to));
        $this->expectException(IllegalTransition::class);
        $this->expectExceptionMessage(ParcelState::class . ': ' . $from->value . ' may not become ' . $to->value . '.');
        $from->moveTo($to);
    }

    /** @return iterable<string, array{ParcelState, ParcelState}> */
    public static function illegalMoves(): iterable
    {
        yield 'a terminal state is not left' => [ParcelState::Delivered, ParcelState::Sent];
        yield 'the other terminal state is not left' => [ParcelState::Lost, ParcelState::Returned];
        yield 'a step is not skipped' => [ParcelState::Packed, ParcelState::Delivered];
        yield 'a state does not become itself' => [ParcelState::Sent, ParcelState::Sent];
        yield 'a move is not made backwards' => [ParcelState::Sent, ParcelState::Packed];
    }

    public function testTerminalStatesAreTheOnesNothingLeaves(): void
    {
        $terminal = array_values(array_filter(ParcelState::cases(), static fn (ParcelState $s): bool => $s->isTerminal()));
        self::assertSame([ParcelState::Delivered, ParcelState::Lost], $terminal);
    }

    public function testSourcesAreEveryStateThatMayMoveThere(): void
    {
        self::assertSame([ParcelState::Packed, ParcelState::Returned], ParcelState::sources(ParcelState::Sent));
        self::assertSame([ParcelState::Sent], ParcelState::sources(ParcelState::Lost));
        self::assertSame([], ParcelState::sources(ParcelState::Packed), 'nothing moves to the first state');
    }

    public function testTheWhereOfAnUpdateNamesItsFromStates(): void
    {
        self::assertSame(
            ['sql' => 'status IN (?, ?)', 'binds' => ['packed', 'returned'], 'types' => 'ss'],
            ParcelState::whereMayMoveTo(ParcelState::Sent, 'status')
        );
        self::assertSame('d.status IN (?)', ParcelState::whereMayMoveTo(ParcelState::Delivered, 'd.status')['sql']);
    }

    public function testAnUpdateToAStateNothingMovesToIsAMistakeNotAnEmptyMatch(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('may move to packed');
        ParcelState::whereMayMoveTo(ParcelState::Packed, 'status');
    }

    /** @dataProvider notColumns */
    public function testTheColumnIsANameAndNothingElse(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ParcelState::whereMayMoveTo(ParcelState::Sent, $column);
    }

    /** @return iterable<string, array{string}> */
    public static function notColumns(): iterable
    {
        yield 'empty' => [''];
        yield 'an expression' => ['status = 1 OR status'];
        yield 'a quote' => ["status'"];
        yield 'three parts' => ['a.b.c'];
        yield 'a trailing space' => ['status '];
    }

    public function testAStoredValueIsReadAsItsState(): void
    {
        self::assertSame(ParcelState::Lost, ParcelState::ofStored('lost', 'parcel 7'));
    }

    /** @dataProvider unknownValues */
    public function testAStoredValueNoCaseNamesIsRefusedWithItsRecord(mixed $value, string $shown): void
    {
        $this->expectException(UnknownState::class);
        $this->expectExceptionMessage('parcel 7 holds the state ' . $shown . ', which ' . ParcelState::class . ' does not name.');
        ParcelState::ofStored($value, 'parcel 7');
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unknownValues(): iterable
    {
        yield 'another word' => ['posted', '"posted"'];
        yield 'another case of letters' => ['Lost', '"Lost"'];
        yield 'empty' => ['', '""'];
        yield 'null' => [null, 'null'];
        yield 'a number' => [0, '"0"'];
        yield 'a list' => [['lost'], 'array'];
    }
}
