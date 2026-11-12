<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * An amount as an integer count of its currency's minor unit, by the ISO 4217
 * exponent (USD 89.00 is 8900, JPY 8900 is 8900, KWD 1.250 is 1250). A
 * currency not in the table is refused by name, never given a guessed
 * exponent (CLAUDE.md #4). Done on the decimal text, not a float: 0.29 * 100
 * is 28.999999999999996.
 */
final class MinorUnits
{
    /** Exponents other than 2, from ISO 4217 (list one, current codes). */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];

    /** Every other current ISO 4217 code, each with exponent 2. */
    private const TWO = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BMD', 'BND', 'BOB',
        'BOV', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHE', 'CHF', 'CHW', 'CNY', 'COP', 'COU', 'CRC',
        'CUP', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP',
        'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES', 'KGS', 'KHR', 'KPW',
        'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR',
        'MVR', 'MWK', 'MXN', 'MXV', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP',
        'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD',
        'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'USN',
        'UYU', 'UZS', 'VES', 'VED', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    private function __construct()
    {
    }

    /** The currency's exponent. */
    public static function exponent(string $currency): int
    {
        if (isset(self::EXPONENTS[$currency])) {
            return self::EXPONENTS[$currency];
        }
        if (in_array($currency, self::TWO, true)) {
            return 2;
        }
        throw new DestinationInputException('currency', 'not sent: the account\'s currency "' . $currency . '" is not an ISO 4217 code this destination knows the minor unit of');
    }

    /**
     * $amount (decimal text, at least zero) in minor units, rounded half up
     * at the currency's exponent.
     */
    public static function of(string $amount, string $currency): int
    {
        $exponent = self::exponent($currency);
        $amount = trim($amount);
        if (preg_match('/\A(\d{1,12})(?:\.(\d{1,12}))?\z/D', $amount, $m) !== 1) {
            throw new DestinationInputException('amount', 'not sent: the amount "' . $amount . '" is not a decimal of at least zero');
        }
        $fraction = str_pad($m[2] ?? '', $exponent + 1, '0');
        $units = (int) ($m[1] . substr($fraction, 0, $exponent));
        if ((int) $fraction[$exponent] >= 5) {
            $units++;
        }

        return $units;
    }
}
