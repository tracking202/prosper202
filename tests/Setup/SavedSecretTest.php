<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;
use Prosper202\Setup\SavedSecret;

final class SavedSecretTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function hints(): iterable
    {
        yield 'none' => [null, 'Not saved'];
        yield 'empty' => ['', 'Not saved'];
        yield 'blank' => ["  \t", 'Not saved'];
        yield 'not a string' => [12345678901234, 'Not saved'];
        yield 'eleven characters' => ['abcdefg1234', 'Saved'];
        yield 'twelve characters' => ['abcdefgh1234', 'Saved, ends in 1234'];
        yield 'a webhook' => ['https://hooks.slack.com/services/T0/B0/xyzw', 'Saved, ends in xyzw'];
        yield 'trimmed as the pill reads it' => ['  abcdefgh1234  ', 'Saved, ends in 1234'];
        yield 'counted in characters' => ['ééééééééwxyé', 'Saved, ends in wxyé'];
    }

    /** @dataProvider hints */
    public function testAHintShowsOnlyWhetherOneIsSavedAndHowALongOneEnds(mixed $stored, string $hint): void
    {
        self::assertSame($hint, SavedSecret::hint($stored));
        self::assertSame($hint !== 'Not saved', SavedSecret::isSaved($stored));
    }

    public function testTheDniListSharesTheRule(): void
    {
        self::assertSame('Not saved', SavedSecret::hintFrom(0, ''));
        self::assertSame('Saved', SavedSecret::hintFrom(11, 'abcd'));
        self::assertSame('Saved, ends in abcd', SavedSecret::hintFrom(12, 'abcd'));
    }

    public function testAnEmptyFieldKeepsTheSavedSecret(): void
    {
        self::assertTrue(SavedSecret::keeps(''));
        self::assertTrue(SavedSecret::keeps('   '));
        self::assertTrue(SavedSecret::keeps(null), 'a field not sent');
        self::assertTrue(SavedSecret::keeps(['x']), 'a list is no value, and changes nothing');
        self::assertFalse(SavedSecret::keeps('new-key'));
        self::assertFalse(SavedSecret::keeps('0'), 'a value, even one empty() calls empty');
    }

    public function testAPayKickstartKeyShowsAnEndingOnlyWhenLongEnough(): void
    {
        self::assertSame('', PayKickstartKeys::hint('pk-8char'), 'the shortest key it takes would show half of itself');
        self::assertSame('', PayKickstartKeys::hint('pk-11-chars'));
        self::assertSame("\u{2026}AB12", PayKickstartKeys::hint('secret--AB12'));
    }
}
