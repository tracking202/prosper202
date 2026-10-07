<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The owner's privacy setting (user_pref_privacy) applied to one visitor:
 * whether the click path may track them in full — set its cookies, store
 * their address as it arrived — or must hold back (no cookies, the address
 * masked; StoredVisitorIp).
 *
 *  - 'disabled' (and anything unrecognised, as before): track in full.
 *  - 'all': hold back for every visitor.
 *  - 'eu': hold back for a visitor who may be in the European Union.
 *
 * connect2.php's trackingEnabled() is this, with the visitor's GeoIP answer.
 */
final class PrivacyMode
{
    private function __construct()
    {
    }

    /**
     * @param mixed $setting the stored preference
     * @param \Closure(): bool $visitorMayBeInEu asked only under 'eu'
     */
    public static function tracksInFull(mixed $setting, \Closure $visitorMayBeInEu): bool
    {
        if ($setting === 'all') {
            return false;
        }
        if ($setting === 'eu') {
            return !$visitorMayBeInEu();
        }

        return true;
    }

    /**
     * Whether 'eu' applies, from getGeoData()'s is_european_union: true,
     * false, or "Unknown" (an address GeoIP cannot place, and a European
     * country outside the EU). Only a positive false lifts privacy; null
     * means no GeoIP library, so nobody can be placed.
     */
    public static function mayBeInEu(mixed $isEuropeanUnion): bool
    {
        return $isEuropeanUnion !== false;
    }
}
