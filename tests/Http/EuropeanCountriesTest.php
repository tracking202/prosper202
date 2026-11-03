<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\EuropeanCountries;

final class EuropeanCountriesTest extends TestCase
{
    public function testTheListIsTheEuTheEeaTheUkAndSwitzerland(): void
    {
        self::assertCount(32, array_unique(EuropeanCountries::CODES), '27 + 3 + 2');
        foreach (['DE', 'FR', 'IE', 'IS', 'LI', 'NO', 'GB', 'CH', 'de', ' gb '] as $code) {
            self::assertFalse(EuropeanCountries::isOutside($code), $code);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function unplaced(): iterable
    {
        foreach ([null, '', 'non', 'EU', 'AP', 'A1', 'A2', 'O1', 'XX', 'ZZ', 'USA', '1'] as $code) {
            yield var_export($code, true) => [$code];
        }
    }

    /** @dataProvider unplaced */
    public function testAVisitorNotPlacedInACountryMayBeEuropean(?string $code): void
    {
        self::assertFalse(EuropeanCountries::isOutside($code));
    }

    public function testAVisitorPlacedElsewhereIsOutside(): void
    {
        foreach (['US', 'BR', 'IN', 'RS', 'TR', 'ua'] as $code) {
            self::assertTrue(EuropeanCountries::isOutside($code), $code);
        }
    }
}
