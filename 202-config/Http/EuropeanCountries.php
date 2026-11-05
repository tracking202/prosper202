<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The countries whose visitors' personal data the GDPR, or a law of the same
 * shape, governs: the EU's 27, the rest of the EEA (Iceland, Liechtenstein,
 * Norway), the United Kingdom and Switzerland, with the territories GeoIP
 * names apart (TERRITORIES), by ISO 3166-1 alpha-2 code as GeoIP records it
 * (202_locations_country.country_code).
 */
final class EuropeanCountries
{
    public const CODES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO',
        'GB', 'CH',
    ];

    /**
     * Places GeoIP records under codes of their own that the same laws reach,
     * or close enough to hold: the EU's outermost regions (RE, GP, MQ, GF,
     * YT, MF) and Åland (AX), and, to stay on the safe side, Gibraltar (GI),
     * the Crown Dependencies (IM, JE, GG) and Svalbard and Jan Mayen (SJ).
     */
    public const TERRITORIES = ['RE', 'GP', 'MQ', 'GF', 'YT', 'MF', 'AX', 'GI', 'IM', 'JE', 'GG', 'SJ'];

    /** Codes GeoIP gives places that are no one country (Europe, Asia/Pacific, a proxy, a satellite, other). */
    private const NO_COUNTRY = ['EU', 'AP', 'A1', 'A2', 'O1', 'XX', 'ZZ'];

    private function __construct()
    {
    }

    /**
     * Whether a visitor recorded in $code is placed outside these countries.
     * A code that names no country ('', 'non' for "Unknown country", a
     * region) is not: an unplaced visitor may be in Europe.
     */
    public static function isOutside(?string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
            return false;
        }

        return !in_array($code, self::CODES, true) && !in_array($code, self::TERRITORIES, true)
            && !in_array($code, self::NO_COUNTRY, true);
    }
}
