<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\PrivacyMode;

/**
 * The owner's privacy setting applied to one visitor. 'eu' used to read a
 * session key nothing set, so it never held back for anyone.
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

    /** @return iterable<string, array{mixed, bool}> getGeoData()'s is_european_union, may be in the EU */
    public static function geoAnswers(): iterable
    {
        yield 'in the EU' => [true, true];
        yield 'outside the EU' => [false, false];
        yield 'GeoIP cannot place the address' => ['Unknown', true];
        yield 'no GeoIP library' => [null, true];
    }

    /** @dataProvider geoAnswers */
    public function testOnlyAPositiveOutsideLiftsEuPrivacy(mixed $answer, bool $expected): void
    {
        self::assertSame($expected, PrivacyMode::mayBeInEu($answer));
    }
}
