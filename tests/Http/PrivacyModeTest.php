<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\PrivacyMode;

/**
 * The owner's privacy setting applied to one visitor. 'eu' used to read a
 * session key nothing set, so it never held back for anyone; it holds back
 * for every visitor GeoIP does not place outside Europe and the EU.
 */
final class PrivacyModeTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool, bool}> setting, visitor may be in the EU, tracked in full */
    public static function settings(): iterable
    {
        yield 'disabled' => ['disabled', true, true];
        yield 'all, outside the EU' => ['all', false, false];
        yield 'all, in the EU' => ['all', true, false];
        yield 'eu, outside the EU' => ['eu', false, true];
        yield 'eu, in the EU' => ['eu', true, false];
        yield 'an unrecognised value' => ['sometimes', true, true];
        yield 'no setting read (memcache miss)' => [false, true, true];
    }

    /** @dataProvider settings */
    public function testWhetherTheVisitorIsTrackedInFull(mixed $setting, bool $mayBeInEu, bool $expected): void
    {
        self::assertSame($expected, PrivacyMode::tracksInFull($setting, static fn (): bool => $mayBeInEu));
    }

    public function testTheGeoLookupIsAskedOnlyUnderEu(): void
    {
        $asked = 0;
        $lookup = static function () use (&$asked): bool {
            $asked++;
            return true;
        };
        PrivacyMode::tracksInFull('all', $lookup);
        PrivacyMode::tracksInFull('disabled', $lookup);
        self::assertSame(0, $asked);
        PrivacyMode::tracksInFull('eu', $lookup);
        self::assertSame(1, $asked);
    }

    /**
     * A placed address's record: the country's EU flag and the continent's
     * code. Only outside Europe and outside the EU lifts 'eu' privacy; an
     * address nobody can place never reaches this (EuropeanVisitorTest).
     *
     * @return iterable<string, array{bool, ?string, bool}>
     */
    public static function placedRecords(): iterable
    {
        yield 'an EU member (Germany)' => [true, 'EU', true];
        yield 'Europe outside the EU (Norway, Switzerland)' => [false, 'EU', true];
        yield 'the EU outside Europe (Reunion)' => [true, 'AF', true];
        yield 'outside Europe and the EU (United States)' => [false, 'NA', false];
        yield 'outside Europe and the EU (Japan)' => [false, 'AS', false];
        yield 'a country with no continent' => [false, null, true];
        yield 'an empty continent code' => [false, '', true];
    }

    /** @dataProvider placedRecords */
    public function testOnlyOutsideEuropeAndTheEuLiftsEuPrivacy(bool $inEu, ?string $continent, bool $expected): void
    {
        self::assertSame($expected, PrivacyMode::mayBeEuropean($inEu, $continent));
    }
}
