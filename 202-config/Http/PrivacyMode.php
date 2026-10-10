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
 *  - 'eu' ("Enabled for European Traffic"): hold back for a visitor who may
 *    be in Europe (mayBeEuropean()).
 *
 * connect2.php's trackingEnabled() is this, with the visitor's GeoIP answer
 * (EuropeanVisitor); the app intakes apply it to what they store for their
 * sender (StoredVisitorIp::forAccount()).
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
     * Whether 'eu' holds back for an address GeoIP placed, from its record:
     * the country's European Union flag and the continent's code. Only a
     * place outside Europe that is not in the EU lifts privacy. An address
     * GeoIP cannot place at all never reaches this: EuropeanVisitor holds
     * back for it, as it does when there is no GeoIP library or database.
     *
     * So a European country outside the EU — Norway, Iceland, Switzerland,
     * Serbia, Russia — is held back, measured against the shipped GeoLite2
     * database. That is the setting's label ("European Traffic") and the
     * fail-safe direction: Norway and Iceland are in the EEA, where the GDPR
     * applies as it does in the EU, the UK and Switzerland have laws of the
     * same shape, and for the rest masking more costs a report's precision,
     * never a visitor's privacy. getGeoData() reached the same answer by
     * accident (`false == null` turned GeoIP's "not in the EU" into
     * "Unknown" unless the continent was known and not Europe); this says it
     * on purpose, so a tidier comparison there cannot change it.
     */
    public static function mayBeEuropean(bool $isInEuropeanUnion, ?string $continentCode): bool
    {
        return $isInEuropeanUnion || $continentCode === null || $continentCode === '' || $continentCode === 'EU';
    }
}
