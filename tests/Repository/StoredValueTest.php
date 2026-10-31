<?php

/**
 * A c1-c4, UTM or variable value is stored cut to its columns' 350
 * characters, and a keyword to its column's 150 characters (a VARCHAR counts
 * characters: cut at 350 bytes, 200 emoji kept 87); dl.php and record_simple.php send an offer URL's tokens through
 * the same cuts, so the URL carries what is stored.
 */

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

final class StoredValueTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function values(): array
    {
        return [
            'short' => ['O\'Brien', 'O\'Brien'],
            'exactly 350 characters' => [str_repeat('x', 350), str_repeat('x', 350)],
            'past 350 characters' => [str_repeat('x', 400), str_repeat('x', 350)],
            '200 four-byte characters, kept whole' => [str_repeat('😀', 200), str_repeat('😀', 200)],
            '350 four-byte characters, kept whole' => [str_repeat('😀', 350), str_repeat('😀', 350)],
            'past 350 four-byte characters' => [str_repeat('😀', 351), str_repeat('😀', 350)],
            'a two-byte character at the edge' => [str_repeat('x', 349) . 'é' . 'y', str_repeat('x', 349) . 'é'],
            'a three-byte character past the edge' => [str_repeat('x', 350) . '€', str_repeat('x', 350)],
        ];
    }

    /** @dataProvider values */
    public function testAValueIsCutToItsColumnOnAWholeCharacter(string $value, string $stored): void
    {
        $cut = MysqlTrackingRepository::storedValue($value);

        self::assertSame($stored, $cut);
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'), bin2hex($cut));
    }

    /** @return array<string, array{string, string}> */
    public static function keywords(): array
    {
        return [
            'short' => ['buy shoes', 'buy shoes'],
            'exactly 150 characters' => [str_repeat('k', 150), str_repeat('k', 150)],
            'past 150 characters' => [str_repeat('k', 200), str_repeat('k', 150)],
            'two-byte characters, counted as one each' => [str_repeat('é', 200), str_repeat('é', 150)],
            'a four-byte character at the edge' => [str_repeat('k', 149) . '😀b', str_repeat('k', 149) . '😀'],
            'a byte that starts no character, kept' => ["\xff" . str_repeat('k', 200), "\xff" . str_repeat('k', 149)],
        ];
    }

    /** @dataProvider keywords */
    public function testAKeywordIsCutToItsColumnsCharacters(string $keyword, string $stored): void
    {
        self::assertSame($stored, MysqlTrackingRepository::storedKeyword($keyword));
    }

    /** The stand-in stores as the repository does: a value and its stored form are one row. */
    public function testTheInMemoryRepositoryCutsTheSameWay(): void
    {
        $repo = new InMemoryTrackingRepository();
        $value = str_repeat('é', 350) . 'x';

        self::assertSame($repo->findOrCreateC1(str_repeat('é', 350)), $repo->findOrCreateC1($value));
        self::assertNotSame($repo->findOrCreateC1(str_repeat('é', 349) . 'x'), $repo->findOrCreateC1($value));
        self::assertSame(
            $repo->findOrCreateUtm(str_repeat('é', 350), 'utm_source'),
            $repo->findOrCreateUtm($value, 'utm_source')
        );
        self::assertSame(
            $repo->findOrCreateKeyword(str_repeat('k', 150)),
            $repo->findOrCreateKeyword(str_repeat('k', 151))
        );
    }
}
