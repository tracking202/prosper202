<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Apps\AppIdentity;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * AppIdentity against the shared vectors in
 * tests/fixtures/app-sdk-contract/app-identity.json: every store link, id
 * and package a user or an agent pastes, and every raw key a client sends.
 * The API, the Setup page and the CLI all resolve an app through this class
 * (the CLI by sending the link to the server), so these vectors are the
 * whole of "what does this link name".
 */
final class AppIdentityTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/app-sdk-contract/app-identity.json');
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<string, array{0: string, 1: array{platform: string, app_key: string, slug: string}|null}> */
    public static function storeLinks(): array
    {
        $cases = [];
        foreach (self::vectors()['store_links'] as $vector) {
            $cases[(string)$vector['name']] = [(string)$vector['input'], $vector['expect']];
        }
        return $cases;
    }

    /**
     * @dataProvider storeLinks
     * @param array{platform: string, app_key: string, slug: string}|null $expect
     */
    public function testWhatEachStoreLinkNames(string $input, ?array $expect): void
    {
        if ($expect === null) {
            try {
                $identity = AppIdentity::fromStoreLink($input);
                self::fail("'$input' was read as {$identity->platform} {$identity->appKey}; it must be refused");
            } catch (ValidationException $e) {
                // Refused by name, on the field the caller filled in.
                self::assertArrayHasKey('store_link', $e->getFieldErrors());
                self::assertNotSame('', $e->getFieldErrors()['store_link']);
            }
            return;
        }

        $identity = AppIdentity::fromStoreLink($input);
        self::assertSame($expect['platform'], $identity->platform, "platform read from '$input'");
        self::assertSame($expect['app_key'], $identity->appKey, "app_key read from '$input'");
        self::assertSame($expect['slug'], $identity->slug, "slug read from '$input'");
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: string|null, 3: string|null}> */
    public static function keys(): array
    {
        $cases = [];
        foreach (self::vectors()['keys'] as $vector) {
            $cases[(string)$vector['name']] = [
                $vector['platform'],
                $vector['app_key'],
                $vector['expect'],
                $vector['expect_platform'] ?? null,
            ];
        }
        return $cases;
    }

    /** @dataProvider keys */
    public function testWhatEachRawKeyResolvesTo(mixed $platform, mixed $appKey, ?string $expect, ?string $expectPlatform): void
    {
        if ($expect === null) {
            $this->expectException(ValidationException::class);
            AppIdentity::fromKey($platform, $appKey);
            return;
        }
        $identity = AppIdentity::fromKey($platform, $appKey);
        self::assertSame($expect, $identity->appKey);
        self::assertSame($expectPlatform ?? AppIdentity::normalizePlatform($platform), $identity->platform);
    }

    public function testTheVectorFileIsNotVacuous(): void
    {
        // A silent zero would make every data-provider test above pass while
        // asserting nothing.
        self::assertGreaterThanOrEqual(30, count(self::storeLinks()));
        self::assertGreaterThanOrEqual(15, count(self::keys()));
        $refused = array_filter(self::storeLinks(), static fn (array $case): bool => $case[1] === null);
        self::assertGreaterThanOrEqual(15, count($refused), 'the refusals are half the contract');
    }

    public function testAPayloadNamesItsAppOneWayOnly(): void
    {
        try {
            AppIdentity::fromPayload(['store_link' => '990077001', 'app_key' => '990077001']);
            self::fail('store_link and app_key together were accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('store_link', $e->getFieldErrors());
        }

        try {
            AppIdentity::fromPayload(['app_name' => 'x']);
            self::fail('a payload naming no app was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('app_key', $e->getFieldErrors());
        }

        try {
            AppIdentity::fromPayload(['store_link' => 'com.example.app', 'platform' => 'ios']);
            self::fail('a platform contradicting the link was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('android', $e->getFieldErrors()['platform'] ?? '');
        }

        $identity = AppIdentity::fromPayload(['store_link' => 'com.example.app', 'platform' => 'Android']);
        self::assertSame('android', $identity->platform);
    }

    public function testAnUnknownPlatformListsWhatWorks(): void
    {
        try {
            AppIdentity::fromKey('windows', '1');
            self::fail('windows was accepted');
        } catch (ValidationException $e) {
            self::assertSame('Must be one of: ios, android', $e->getFieldErrors()['platform'] ?? '');
        }
    }

    public function testOnlyAnIosIdentityHasAnAppStoreId(): void
    {
        self::assertSame(990077001, AppIdentity::fromStoreLink('990077001')->appleAppId());
        $this->expectException(\LogicException::class);
        AppIdentity::fromStoreLink('com.example.app')->appleAppId();
    }

    public function testAnAppleIdentityIsNeverBuiltFromZero(): void
    {
        // Postbacks may name app 0; no registration can, so the receiver
        // never asks the registry about it.
        $this->expectException(\InvalidArgumentException::class);
        AppIdentity::apple(0);
    }
}
