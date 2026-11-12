<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRef;
use Prosper202\Click\PurLinkSubid;
use Prosper202\Conversion\UnfilledPlaceholder;

/**
 * Which SubID values read as "the network did not fill this in": one macro
 * and nothing else. A value that could name a click never does.
 */
final class UnfilledPlaceholderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unfilled(): iterable
    {
        $macros = [
            '[[subid]]', '[[SubID]]', ' [[subid]] ', '%5B%5Bsubid%5D%5D', '%5b%5bsubid%5d%5d',
            '{aff_sub}', '{{conversion.value}}', '${subid}', '[subid]', '[=SID=]', '#s2#', '%subid1%', '<subid>',
            '%7Baff_sub%7D', '{aff-sub.2}',
        ];
        foreach ($macros as $value) {
            yield $value => [$value];
        }
    }

    /** @dataProvider unfilled */
    public function testAMacroAloneIsUnfilled(string $value): void
    {
        self::assertTrue(UnfilledPlaceholder::isUnfilled($value));
        self::assertTrue(UnfilledPlaceholder::isMissing($value));
    }

    /** @return iterable<string, array{string}> */
    public static function values(): iterable
    {
        $values = [
            '123', '12abc', '123.0', '0', '123-456', '99071501-12-0123456789ab', 'xxC1xx', 'subid', '[[subid]]123',
            '123[[subid]]', '[[subid]] 5', '[[]]', '{}', '##', '%%', '[[sub id]]', '{a}{b}', '[[subid]', '{subid',
            '{1}', '#12#', '[[subid]][[subid]]', '-', 'null', 'undefined',
        ];
        foreach ($values as $value) {
            yield $value => [$value];
        }
    }

    /** @dataProvider values */
    public function testAnythingElseIsAValue(string $value): void
    {
        self::assertFalse(UnfilledPlaceholder::isUnfilled($value));
        self::assertFalse(UnfilledPlaceholder::isMissing($value));
    }

    public function testAbsentAndBlankAreMissingAndAListIsAValue(): void
    {
        self::assertTrue(UnfilledPlaceholder::isMissing(null));
        self::assertTrue(UnfilledPlaceholder::isMissing(''));
        self::assertTrue(UnfilledPlaceholder::isMissing("  \t"));
        self::assertFalse(UnfilledPlaceholder::isMissing(['[[subid]]']));
        self::assertFalse(UnfilledPlaceholder::isMissing(0));
    }

    public function testNoClickReferenceIsEverUnfilled(): void
    {
        foreach (['1', '9223372036854775807', '77-12', '77-12-0123456789ab'] as $subid) {
            self::assertTrue(
                ClickRef::parse($subid) !== null || PurLinkSubid::legacy($subid) !== null,
                "$subid is a click reference"
            );
            self::assertFalse(UnfilledPlaceholder::isMissing($subid), $subid);
        }
    }
}
