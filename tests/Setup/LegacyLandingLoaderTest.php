<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\LandingPageCode;

/**
 * Paid 1.9.56 landing pages load tracking202/static/landing.js?lpip=N; the
 * file must ship and make the same landing.php call as the current loader.
 */
final class LegacyLandingLoaderTest extends TestCase
{
    private const LOADER = __DIR__ . '/../../tracking202/static/landing.js';

    public function testLegacyLoaderShips(): void
    {
        self::assertFileExists(self::LOADER);
    }

    public function testLegacyLoaderCallsLandingPhpAsTheCurrentLoaderDoes(): void
    {
        $legacy = (string) file_get_contents(self::LOADER);
        $current = LandingPageCode::loader('//t.example/', '42');

        self::assertStringContainsString('tracking202/static/landing.php?lpip=42&t202id=', $current);
        self::assertStringContainsString("'landing.php?lpip=' + encodeURIComponent(lpip) + '&t202id='", $legacy);
        // The same element id, so a page carrying both snippets loads once.
        self::assertStringContainsString('"upxif"', $current);
        self::assertStringContainsString("s.id = 'upxif'", $legacy);
        self::assertStringContainsString("d.getElementById('upxif')", $legacy);
        // The old snippet's own id, for browsers without currentScript.
        self::assertStringContainsString("d.getElementById('t202js')", $legacy);
    }
}
