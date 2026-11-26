<?php

declare(strict_types=1);

namespace Tests\Support;

use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Prosper202\Security\IntegrationSecretStore;

/**
 * A fixture's Shopify webhook secret, written where the code reads it: the
 * sealed integration secrets store (1.9.81 moved it out of 202_users_pref).
 * The value is stored as given, as a column written by hand once was, so a
 * secret that cannot be one stays one the endpoint refuses; null or "" is none.
 */
final class StoredShopifySecret
{
    public static function set(\mysqli $db, int $userId, ?string $secret): void
    {
        $store = new IntegrationSecretStore(new Connection($db));
        if ($secret === null || $secret === '') {
            $store->clear($userId, ShopifyWebhookSecret::NAME, null, 'api', time());

            return;
        }
        $store->put($userId, ShopifyWebhookSecret::NAME, $secret, 0, null, 'api', time());
    }

    /** The account's secret as stored, or null. */
    public static function of(\mysqli $db, int $userId): ?string
    {
        return (new IntegrationSecretStore(new Connection($db)))->open($userId, ShopifyWebhookSecret::NAME, time())?->current;
    }
}
