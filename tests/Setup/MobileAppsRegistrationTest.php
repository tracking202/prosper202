<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tracking202\Setup\MobileAppsController;

require_once dirname(__DIR__, 2) . '/tracking202/setup/MobileAppsController.php';

/**
 * What Setup › Mobile Apps derives instead of asking for, and what it refuses.
 *
 * The registration form has one field, so everything else — the App Store id,
 * the platform — is read out of whatever was pasted. These are the shapes a
 * user actually pastes, and the two ways the page must say no.
 */
final class MobileAppsRegistrationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int|null, 2: string, 3: string}>
     */
    public static function references(): array
    {
        return [
            'store link' => ['https://apps.apple.com/us/app/summit-run/id990077001', 990077001, 'ios', 'summit-run'],
            'store link with query' => ['https://apps.apple.com/us/app/summit-run/id990077001?mt=8', 990077001, 'ios', 'summit-run'],
            'store link, other region' => ['https://apps.apple.com/gb/app/summit-run/id990077001', 990077001, 'ios', 'summit-run'],
            'store link, no slug' => ['https://apps.apple.com/app/id990077001', 990077001, 'ios', ''],
            'bare id' => ['990077001', 990077001, 'ios', ''],
            'bare id with spaces' => ['  990077001  ', 990077001, 'ios', ''],
            'the URL\'s last segment on its own' => ['id990077001', 990077001, 'ios', ''],
            'last segment with spaces' => ['  id990077001  ', 990077001, 'ios', ''],
            'id inside a word is not an id' => ['covid19', null, 'ios', ''],
            'play link' => ['https://play.google.com/store/apps/details?id=com.example.app', 0, 'android', ''],
            'empty' => ['', null, 'ios', ''],
            'nonsense' => ['not-an-app', null, 'ios', ''],
            'negative' => ['-5', null, 'ios', ''],
            'zero' => ['0', null, 'ios', ''],
            'decimal' => ['990077001.5', null, 'ios', ''],
            'id too big for an int' => ['99999999999999999999999', null, 'ios', ''],
            'link to something else' => ['https://example.com/app/id123', 123, 'ios', ''],
        ];
    }

    /**
     * @dataProvider references
     */
    public function testWhatEachPastedReferenceIsReadAs(string $reference, ?int $appId, string $platform, string $slug): void
    {
        $parsed = MobileAppsController::parseStoreReference($reference);
        self::assertSame($appId, $parsed['app_id'], "app id read from: $reference");
        self::assertSame($platform, $parsed['platform'], "platform read from: $reference");
        self::assertSame($slug, $parsed['slug'], "slug read from: $reference");
    }

    public function testAnIdBeyondTheIntegerRangeIsRefusedRatherThanTruncated(): void
    {
        // (string)(int)'99999999999999999999999' is PHP_INT_MAX, which is a
        // different app. The parser must not hand that on as if it were the
        // number the user pasted.
        $parsed = MobileAppsController::parseStoreReference('99999999999999999999999');
        self::assertNull($parsed['app_id']);
    }

    public function testAndroidIsRefusedByNameAndIosIsAccepted(): void
    {
        AttributionAppsController::assertSupportedPlatform(['platform' => 'ios']);
        AttributionAppsController::assertSupportedPlatform(['platform' => 'iOS']);
        AttributionAppsController::assertSupportedPlatform([]);
        AttributionAppsController::assertSupportedPlatform(['platform' => null]);

        try {
            AttributionAppsController::assertSupportedPlatform(['platform' => 'android']);
            self::fail('android was accepted');
        } catch (ValidationException $e) {
            $message = $e->getFieldErrors()['platform'] ?? '';
            self::assertStringContainsString('Android', $message, 'the refusal names Android');
            self::assertStringContainsString('not supported yet', $message, 'and says it is a "not yet", not a typo');
        }
    }

    public function testAnUnknownPlatformListsWhatWorks(): void
    {
        $this->expectException(ValidationException::class);
        AttributionAppsController::assertSupportedPlatform(['platform' => 'windows']);
    }

    public function testAnUnknownPlatformDoesNotClaimAndroidIsTheProblem(): void
    {
        try {
            AttributionAppsController::assertSupportedPlatform(['platform' => 'windows']);
            self::fail('windows was accepted');
        } catch (ValidationException $e) {
            self::assertSame('Must be one of: ios', $e->getFieldErrors()['platform'] ?? '');
        }
    }

    public function testANonScalarPlatformIsRefusedRatherThanCastToAString(): void
    {
        $this->expectException(ValidationException::class);
        AttributionAppsController::assertSupportedPlatform(['platform' => ['ios']]);
    }

    public function testThePlatformFieldIsDeclaredWithIosAsItsDefault(): void
    {
        $reflection = new \ReflectionClass(AttributionAppsController::class);
        $method = $reflection->getMethod('fields');
        $method->setAccessible(true);
        $fields = $method->invoke($reflection->newInstanceWithoutConstructor());

        self::assertArrayHasKey('platform', $fields, 'the API exposes the platform the UI derives');
        self::assertSame('ios', $fields['platform']['default'] ?? null);
        // No 'allowed' list: it would answer before the guard that names Android.
        self::assertArrayNotHasKey('allowed', $fields['platform']);
    }
}
