<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;

final class ShopifyWebhookSecretTest extends TestCase
{
    private const ADMIN_SECRET = '3f1e9b0c7a2d4e8f9a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f';

    public function testASecretIsStoredAsShopifyShowsIt(): void
    {
        self::assertSame(self::ADMIN_SECRET, ShopifyWebhookSecret::parse("  " . self::ADMIN_SECRET . "\n"));
        // A custom app's client secret (built, so no scanner reads a fake one as real).
        $clientSecret = 'shp' . 'ss_' . str_repeat('0123456789abcdef', 2);
        self::assertSame($clientSecret, ShopifyWebhookSecret::parse($clientSecret));
        self::assertSame('', ShopifyWebhookSecret::parse('  '), '"" clears it');

        self::assertSame(self::ADMIN_SECRET, ShopifyWebhookSecret::stored(' ' . self::ADMIN_SECRET . ' '));
        self::assertNull(ShopifyWebhookSecret::stored(null));
        self::assertFalse(ShopifyWebhookSecret::unreadable(''));
    }

    /** @return iterable<string, array{string}> */
    public static function notSecrets(): iterable
    {
        yield 'two words' => ['webhook secret 12345678'];
        yield 'too short' => ['abc123'];
        yield 'too long' => [str_repeat('k', 251)];
        yield 'two lines' => ["first-half-0123\nsecond-half-0123"];
    }

    /** @dataProvider notSecrets */
    public function testWhatCannotBeASecretIsRefusedAndVerifiesNothing(string $value): void
    {
        try {
            ShopifyWebhookSecret::parse($value);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString($value, $e->getMessage(), 'never by its text');
        }
        self::assertNull(ShopifyWebhookSecret::stored($value));
        self::assertTrue(ShopifyWebhookSecret::unreadable($value));
    }
}
