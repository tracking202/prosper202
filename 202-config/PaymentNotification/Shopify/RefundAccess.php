<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

/**
 * Who may see and settle the Shopify refunds left to settle
 * (RefundsToSettle), on Setup › Inbound Log and through
 * /conversions/shopify-refunds: one rule, asked by the page and the API.
 *
 * Seeing: a role that opens Setup (the Inbound Log's own permission), or
 * the one that runs the Shopify connection on Account › API Integrations.
 * Recording or closing writes the sale's ledger, so a Setup role needs the
 * Update section too, as recording any conversion does; the integrations
 * role settles them on its own.
 *
 * $has answers whether the role holds a permission; it throws, never
 * answers false, when it cannot tell (CLAUDE.md #11).
 */
final class RefundAccess
{
    public const SETUP = 'access_to_setup_section';
    public const UPDATE = 'access_to_update_section';
    public const INTEGRATIONS = 'access_to_api_integrations';

    public const SEE_REFUSED = "This account's role has neither the '" . self::SETUP . "' permission nor the '"
        . self::INTEGRATIONS . "' permission, which the Shopify refunds left to settle need.";
    public const SETTLE_REFUSED = "This account's role may not record or close a Shopify refund: that needs the '"
        . self::INTEGRATIONS . "' permission (Account › API Integrations), or the '" . self::SETUP
        . "' and '" . self::UPDATE . "' permissions together.";

    /** @param callable(string): bool $has */
    public static function maySee(callable $has): bool
    {
        return $has(self::INTEGRATIONS) || $has(self::SETUP);
    }

    /** @param callable(string): bool $has */
    public static function maySettle(callable $has): bool
    {
        return $has(self::INTEGRATIONS) || ($has(self::SETUP) && $has(self::UPDATE));
    }
}
