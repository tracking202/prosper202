<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\UsersController;
use PHPUnit\Framework\TestCase;
use Tracking202\Setup\MobileAppsController;

require_once dirname(__DIR__, 2) . '/tracking202/setup/MobileAppsController.php';

/**
 * What Setup › Mobile Apps derives instead of asking for, and what it refuses.
 *
 * The registration form has one field, so everything else — the App Store id,
 * the platform — is read out of whatever was pasted. The reading is
 * AppIdentity's (tests/Apps/AppIdentityTest runs the shared vectors); this
 * page must not grow a second parser beside it, which is how the three split
 * copies the registry replaced came about.
 */
final class MobileAppsRegistrationTest extends TestCase
{
    public function testThePageReadsStoreLinksThroughAppIdentityAndHasNoParserOfItsOwn(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/tracking202/setup/MobileAppsController.php');
        self::assertStringContainsString('AppIdentity::fromStoreLink(', $source);
        self::assertFalse(method_exists(MobileAppsController::class, 'parseStoreReference'));
        self::assertFalse(method_exists(MobileAppsController::class, 'appStoreId'));
        // The shapes a hand-rolled App Store or Play parser is made of.
        self::assertStringNotContainsString("parse_url(", $source);
        self::assertSame(0, preg_match('/[\'"]~[^\'"]*id\(\\d/', $source), 'no App Store id pattern outside AppIdentity');
    }

    /**
     * The validator lives on UsersController, which owns preferences, because
     * Analyze > Mobile Apps prints the same amounts and must not resolve an
     * unreadable currency differently from this page.
     *
     * @dataProvider currencies
     */
    public function testAStoredCurrencyResolvesToACodeTheFormatterCanUse(mixed $stored, string $expected): void
    {
        self::assertSame($expected, UsersController::normalizeCurrency($stored));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function currencies(): array
    {
        return [
            'a plain code'          => ['EUR', 'EUR'],
            'lower case'            => ['eur', 'EUR'],
            'padded'                => ['  gbp  ', 'GBP'],
            'the column default'    => ['USD', 'USD'],
            // Everything below is a value the column should never hold. Each
            // resolves to USD rather than reaching dollar_format(), which
            // would print it verbatim in front of every amount on the page.
            'empty'                 => ['', 'USD'],
            'missing'               => [null, 'USD'],
            'two letters'           => ['US', 'USD'],
            'four letters'          => ['USDX', 'USD'],
            'digits'                => ['123', 'USD'],
            'punctuation'           => ['U$D', 'USD'],
            'not a string'          => [['EUR'], 'USD'],
            'a number'              => [840, 'USD'],
        ];
    }
}
