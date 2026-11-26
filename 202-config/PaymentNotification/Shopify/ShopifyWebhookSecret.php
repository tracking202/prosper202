<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use InvalidArgumentException;
use Prosper202\Database\Connection;
use Prosper202\Security\IntegrationSecretStore;

/**
 * An account's Shopify webhook signing secret: the key a store's admin
 * webhooks are signed with (Settings › Notifications › Webhooks, "Your
 * webhooks will be signed with …"), or a custom app's client secret. Kept
 * sealed in the integration secrets store under NAME (the 1.9.81 upgrade
 * moved it there from 202_users_pref) and never served back. The page and
 * PUT /users/{id}/preferences write it through parse(), and the endpoint
 * reads it through stored(), so what is stored is what verifies. One
 * replaced on the page with the overlap kept keeps verifying until it ends.
 */
final class ShopifyWebhookSecret
{
    /** The secret's name in the store, and the field the page and the preferences API take it in. */
    public const NAME = 'shopify_webhook_secret';

    /** How long the page keeps the replaced secret verifying when asked to. */
    public const OVERLAP_SECONDS = 7 * 86400;

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
    public static function stored(?string $value): ?string
    {
        $secret = trim((string) $value);

        return $secret !== '' && self::problem($secret) === null ? $secret : null;
    }

    /**
     * Whether the account stores a secret a webhook can verify with. Such an
     * account is shown the Shopify webhooks no secret verified, in the page
     * and the API alike: one may be its own.
     *
     * @throws \Prosper202\Security\UnreadableSecret when its secret is stored and cannot be opened
     */
    public static function accountStores(Connection $conn, int $account): bool
    {
        $secret = (new IntegrationSecretStore($conn))->open($account, self::NAME, time());
        foreach ($secret?->accepted() ?? [] as $value) {
            if (self::stored($value) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Whether a secret is stored that is not one (moved from a column written by hand, cut short): it verifies nothing. */
    public static function unreadable(?string $value): bool
    {
        return trim((string) $value) !== '' && self::stored($value) === null;
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
