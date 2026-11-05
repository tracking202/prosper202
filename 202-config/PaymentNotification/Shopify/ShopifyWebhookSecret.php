<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use InvalidArgumentException;

/**
 * An account's Shopify webhook signing secret, in
 * 202_users_pref.shopify_webhook_secret: the key a store's admin webhooks are
 * signed with (Settings › Notifications › Webhooks, "Your webhooks will be
 * signed with …"), or a custom app's client secret. The page and
 * PUT /users/{id}/preferences write it through parse(), and the endpoint
 * reads it through stored(), so what is stored is what verifies.
 */
final class ShopifyWebhookSecret
{
    public const COLUMN = 'shopify_webhook_secret';

    public const MIN_LENGTH = 16;
    public const MAX_LENGTH = 250;

    private function __construct()
    {
    }

    /**
     * The secret a caller sent, trimmed; "" is none. Refused, never by its
     * text, when it cannot be one.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $raw): string
    {
        $secret = trim($raw);
        $problem = self::problem($secret);
        if ($secret !== '' && $problem !== null) {
            throw new InvalidArgumentException('The secret ' . $problem);
        }

        return $secret;
    }

    /** The stored secret as the endpoint verifies with it, or null when none is or it cannot be one. */
    public static function stored(?string $column): ?string
    {
        $secret = trim((string) $column);

        return $secret !== '' && self::problem($secret) === null ? $secret : null;
    }

    /** Whether a secret is stored that is not one (written by hand, cut short): it verifies nothing. */
    public static function unreadable(?string $column): bool
    {
        return trim((string) $column) !== '' && self::stored($column) === null;
    }

    private static function problem(string $secret): ?string
    {
        if (preg_match('/^[\x21-\x7E]+$/D', $secret) !== 1) {
            return 'has a space or a character that is not a letter, a digit or punctuation; paste it as Shopify'
                . ' shows it.';
        }
        $length = strlen($secret);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return 'is ' . $length . ' characters long; a Shopify webhook secret is ' . self::MIN_LENGTH . ' to '
                . self::MAX_LENGTH . '.';
        }

        return null;
    }
}
