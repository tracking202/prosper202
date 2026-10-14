<?php

/**
 * The one normalizer for column cuts: bytes() for a column cut by bytes
 * (the c1-c4, UTM and variable values), chars() for one cut by characters
 * (keyword, location and device names, the site domain host).
 */

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;
use Prosper202\Repository\StoredText;

final class StoredTextTest extends TestCase
{
    /** @return array<string, array{string, int, string}> */
    public static function byteCuts(): array
    {
        return [
            'shorter than the column' => ['abc', 10, 'abc'],
            'exactly the column' => ['abcde', 5, 'abcde'],
            'past the column' => ['abcdef', 5, 'abcde'],
            'a two-byte character split' => ['abcdé', 5, 'abcd'],
            'a two-byte character whole' => ['abcé', 5, 'abcé'],
            'a three-byte character split after two' => ['abc€', 5, 'abc'],
            'a four-byte character split after three' => ['a😀', 4, 'a'],
            'a four-byte character whole' => ['a😀b', 5, 'a😀'],
            'quotes, a backslash and a NUL kept' => ["'\"\\\0\n", 5, "'\"\\\0\n"],
            'one byte' => ['é', 1, ''],
        ];
    }

    /** @dataProvider byteCuts */
    public function testBytesCutsOnAWholeCharacter(string $value, int $max, string $stored): void
    {
        $cut = StoredText::bytes($value, $max);

        self::assertSame($stored, $cut);
        self::assertLessThanOrEqual($max, strlen($cut));
        self::assertSame($cut, StoredText::bytes($cut, $max), 'a stored value is its own stored form');
    }

    /** @return array<string, array{string, int, string}> */
    public static function charCuts(): array
    {
        return [
            'shorter than the column' => ['abc', 10, 'abc'],
            'past the column' => ['abcdef', 3, 'abc'],
            'multi-byte characters count as one' => ['ééééé', 3, 'ééé'],
            'a four-byte character counts as one' => ['😀😀😀', 2, '😀😀'],
            'more bytes than characters, within the column' => ['éé', 2, 'éé'],
            'a byte that starts no character counts as one' => ["\xff" . 'abc', 2, "\xffa"],
            'a country code' => ['USAX', 3, 'USA'],
            'a NUL and a newline count as one each' => ["a\0\nb", 3, "a\0\n"],
        ];
    }

    /** @dataProvider charCuts */
    public function testCharsCutsToWholeCharacters(string $value, int $max, string $stored): void
    {
        $cut = StoredText::chars($value, $max);

        self::assertSame($stored, $cut);
        self::assertSame($cut, StoredText::chars($cut, $max), 'a stored value is its own stored form');
    }

    public function testTheTrackingDelegatesAreTheTwoCutsAtTheirColumns(): void
    {
        $value = str_repeat('x', 349) . 'é' . 'y';
        $keyword = str_repeat('é', 200);

        self::assertSame(StoredText::chars($value, 350), MysqlTrackingRepository::storedValue($value));
        self::assertSame(str_repeat('x', 349) . 'é', MysqlTrackingRepository::storedValue($value));
        self::assertSame(StoredText::chars($keyword, 150), MysqlTrackingRepository::storedKeyword($keyword));
        self::assertSame(str_repeat('é', 150), MysqlTrackingRepository::storedKeyword($keyword));
    }

    /** @return array<string, array{\Closure(): string}> */
    public static function impossibleWidths(): array
    {
        return [
            'bytes 0' => [static fn (): string => StoredText::bytes('abc', 0)],
            'bytes negative' => [static fn (): string => StoredText::bytes('abc', -1)],
            'chars 0' => [static fn (): string => StoredText::chars('abc', 0)],
            'chars negative' => [static fn (): string => StoredText::chars('abc', -1)],
        ];
    }

    /**
     * @dataProvider impossibleWidths
     * @param \Closure(): string $cut
     */
    public function testAWidthNoColumnHasIsRefused(\Closure $cut): void
    {
        $this->expectException(InvalidArgumentException::class);
        $cut();
    }

    /** A repeat count in the pattern would refuse a width past PCRE's limit. */
    public function testAWideCharacterCutRuns(): void
    {
        $value = str_repeat('é', 70001) . str_repeat('x', 10);

        self::assertSame(str_repeat('é', 70000), StoredText::chars($value, 70000));
    }
}
