<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\EuropeanVisitor;

/**
 * Privacy 'eu' ("Enabled for European Traffic") against the GeoIP database
 * the install ships: who it holds back for.
 *
 * Measured before this was written down: the click path's getGeoData()
 * reported a European country outside the EU as "Unknown" (its
 * `$is_european_union == null` is true for GeoIP's `false`), and the
 * privacy check held back for "Unknown". Over 20,000 random addresses the
 * old decision and EuropeanVisitor's agree on every one; the countries held
 * back are the EU's, the EEA's (Norway, Iceland, Liechtenstein),
 * Switzerland's, the United Kingdom's, the rest of Europe's (Serbia, Russia,
 * Ukraine, …) and France's overseas departments. That is kept on purpose:
 * see PrivacyMode::mayBeEuropean().
 *
 * The cases that lift privacy need the GeoIP library (a full composer
 * install has it); without it every visitor is held back, which the
 * held-back cases assert too.
 */
final class EuropeanVisitorTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> address, held back */
    public static function addresses(): iterable
    {
        yield 'Germany (EU)' => ['193.99.144.80', true];
        yield 'Germany, IPv6' => ['2a00:1450:4001:80b::200e', true];
        yield 'Norway (EEA, outside the EU)' => ['129.240.2.6', true];
        yield 'Iceland (EEA, outside the EU)' => ['130.208.0.1', true];
        yield 'Switzerland' => ['129.132.0.1', true];
        yield 'the United Kingdom' => ['212.58.244.1', true];
        yield 'Serbia' => ['147.91.1.1', true];
        yield 'Russia' => ['77.88.55.88', true];
        yield 'a documentation address GeoIP cannot place' => ['192.0.2.21', true];
        yield 'a private address' => ['10.0.0.1', true];
        yield 'an IPv6 documentation address' => ['2001:db8::1', true];
        yield 'no address' => ['', true];
        yield 'not an address' => ['nope', true];
        yield 'the United States' => ['8.8.8.8', false];
        yield 'Japan' => ['133.11.0.1', false];
        yield 'Australia' => ['1.1.1.1', false];
    }

    /** @dataProvider addresses */
    public function testWhoEuPrivacyHoldsBackFor(string $address, bool $heldBack): void
    {
        if (!$heldBack && !class_exists(\GeoIp2\Database\Reader::class)) {
            self::markTestSkipped(
                'Placing an address outside Europe needs the GeoIP library (geoip2/geoip2), absent from this vendor/.'
            );
        }
        self::assertSame($heldBack, EuropeanVisitor::mayBe($address));
    }

    public function testADatabaseThatCannotBeOpenedHoldsBackForEveryone(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-eu-log');
        $previous = ini_set('error_log', (string) $log);
        try {
            self::assertTrue(EuropeanVisitor::mayBe('8.8.8.8', __DIR__ . '/no-such-database.mmdb'));
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink((string) $log);
        }
    }

    public function testItReadsTheDatabaseTheClickPathReads(): void
    {
        self::assertFileExists(EuropeanVisitor::DATABASE);
        self::assertSame(
            realpath(dirname(__DIR__, 2) . '/202-config/geo/GeoLite2-City.mmdb'),
            realpath(EuropeanVisitor::DATABASE)
        );
    }
}
