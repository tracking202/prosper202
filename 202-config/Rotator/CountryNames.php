<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * The countries a redirector's country rule can name: ISO 3166-1 alpha-2
 * code => English name. A rule is matched by the code (CriterionMatcher), so
 * the name is for the reader; none holds a comma, which would split a
 * criterion's value.
 */
final class CountryNames
{
    public const BY_CODE = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua and Barbuda',
        'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica',
        'AR' => 'Argentina', 'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba',
        'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados',
        'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain',
        'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'Saint Barthélemy', 'BM' => 'Bermuda', 'BN' => 'Brunei',
        'BO' => 'Bolivia', 'BQ' => 'Bonaire', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan',
        'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada',
        'CC' => 'Cocos (Keeling) Islands', 'CD' => 'DR Congo', 'CF' => 'Central African Republic',
        'CG' => 'Congo Republic', 'CH' => 'Switzerland', 'CI' => 'Ivory Coast', 'CK' => 'Cook Islands',
        'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica',
        'CU' => 'Cuba', 'CV' => 'Cabo Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DE' => 'Germany', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt',
        'EH' => 'Western Sahara', 'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland',
        'FJ' => 'Fiji', 'FK' => 'Falkland Islands', 'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France',
        'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana',
        'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia',
        'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea', 'GR' => 'Greece',
        'GS' => 'South Georgia and the South Sandwich Islands', 'GT' => 'Guatemala', 'GU' => 'Guam',
        'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong', 'HM' => 'Heard and McDonald Islands',
        'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary', 'ID' => 'Indonesia',
        'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man', 'IN' => 'India',
        'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy',
        'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya',
        'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros',
        'KN' => 'St Kitts and Nevis', 'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait',
        'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'Saint Lucia',
        'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania',
        'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco',
        'MD' => 'Moldova', 'ME' => 'Montenegro', 'MF' => 'Saint Martin', 'MG' => 'Madagascar',
        'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolia',
        'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania',
        'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives', 'MW' => 'Malawi',
        'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NC' => 'New Caledonia',
        'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'The Netherlands',
        'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'New Zealand', 'OM' => 'Oman',
        'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea',
        'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon',
        'PN' => 'Pitcairn Islands', 'PR' => 'Puerto Rico', 'PS' => 'Palestine', 'PT' => 'Portugal', 'PW' => 'Palau',
        'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia',
        'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands', 'SC' => 'Seychelles',
        'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena', 'SI' => 'Slovenia',
        'SJ' => 'Svalbard and Jan Mayen', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino',
        'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan',
        'ST' => 'São Tomé and Príncipe', 'SV' => 'El Salvador', 'SX' => 'Sint Maarten', 'SY' => 'Syria',
        'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos Islands', 'TD' => 'Chad',
        'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan',
        'TK' => 'Tokelau', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga',
        'TR' => 'Türkiye', 'TT' => 'Trinidad and Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania',
        'UA' => 'Ukraine', 'UG' => 'Uganda', 'UM' => 'U.S. Outlying Islands', 'US' => 'United States',
        'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'St Vincent and Grenadines',
        'VE' => 'Venezuela', 'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands', 'VN' => 'Vietnam',
        'VU' => 'Vanuatu', 'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen',
        'YT' => 'Mayotte', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** What people type for a country that is neither its code nor its name here. */
    private const ALSO = [
        'UK' => 'GB', 'USA' => 'US', 'UNITED STATES OF AMERICA' => 'US', 'GREAT BRITAIN' => 'GB', 'BRITAIN' => 'GB',
        'ENGLAND' => 'GB', 'TURKEY' => 'TR', 'NETHERLANDS' => 'NL', 'HOLLAND' => 'NL', 'CZECH REPUBLIC' => 'CZ',
        'KOREA' => 'KR', 'UAE' => 'AE', 'RUSSIAN FEDERATION' => 'RU', 'VIET NAM' => 'VN', 'MACAU' => 'MO',
        'SWAZILAND' => 'SZ', 'BURMA' => 'MM', 'CAPE VERDE' => 'CV', 'EAST TIMOR' => 'TL',
    ];

    private function __construct()
    {
    }

    /** The code a typed country names: a code, a name here or a common other name, in any case; null for none. */
    public static function code(string $typed): ?string
    {
        $key = mb_strtoupper(trim($typed));
        if (isset(self::BY_CODE[$key])) {
            return $key;
        }
        if (isset(self::ALSO[$key])) {
            return self::ALSO[$key];
        }
        foreach (self::BY_CODE as $code => $name) {
            if (mb_strtoupper($name) === $key) {
                return $code;
            }
        }

        return null;
    }

    /** A country as a rule stores it: Name(CC). */
    public static function value(string $code): string
    {
        return self::BY_CODE[$code] . '(' . $code . ')';
    }

    /**
     * The countries whose name or code holds what was typed, as rule values,
     * a code's own country first.
     *
     * @return list<string>
     */
    public static function matching(string $typed, int $limit = 20): array
    {
        $needle = mb_strtoupper(trim($typed));
        if ($needle === '') {
            return [];
        }
        $found = [];
        $code = self::code($typed);
        if ($code !== null) {
            $found[$code] = self::value($code);
        }
        foreach (self::BY_CODE as $candidate => $name) {
            if (str_starts_with(mb_strtoupper($name), $needle)) {
                $found[$candidate] ??= self::value($candidate);
            }
        }
        foreach (self::BY_CODE as $candidate => $name) {
            if (str_contains(mb_strtoupper($name), $needle)) {
                $found[$candidate] ??= self::value($candidate);
            }
        }

        return array_slice(array_values($found), 0, $limit);
    }
}
