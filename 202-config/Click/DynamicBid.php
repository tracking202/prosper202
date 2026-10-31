<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The cost a link's t202b names for its click, for the accounts with dynamic
 * bids on. One reader for the four click scripts: three of them handed the
 * string to number_format() under strict_types, a TypeError for every bid
 * sent, so each such click was lost.
 */
final class DynamicBid
{
    /**
     * The largest bid both click tables hold: 202_clicks_spy.click_cpc is a
     * DECIMAL(4,2), which a strict sql_mode refuses 99.995 in (measured), and
     * the spy row is written with the click's.
     */
    private const MAX = 99.99;

    private function __construct()
    {
    }

    /**
     * The bid as 202_clicks stores it (five decimals), or null when the value
     * names none the columns hold: absent, a list, a number not written as
     * a plain decimal ('1e3', '-1', ' 2'), a token the traffic source left
     * unfilled ('{bid}'), or more than MAX. The click then costs its
     * tracker's CPC, as a value that was not numeric always did. A leading
     * '$' is the currency, not part of the number.
     */
    public static function fromRequest(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = ltrim($value, '$');
        $plain = preg_match('/\A(?:\d+(?:\.\d*)?|\.\d+)\z/', $value) === 1;
        $bid = (float) $value;
        if ($plain && is_finite($bid) && $bid <= self::MAX) {
            return number_format($bid, 5, '.', '');
        }
        // A number refused is said; an unfilled token ({bid}) never was.
        if ($plain || is_numeric($value)) {
            error_log('p202 click: the bid "' . addcslashes(substr($value, 0, 40), "\0..\37\\\"")
                . '" (t202b) is not a plain decimal from 0 to ' . self::MAX . '; the click costs its tracker\'s CPC');
        }

        return null;
    }
}
