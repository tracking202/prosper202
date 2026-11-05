<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Zaxaa;

/**
 * A ZPN's hash_key, as Zaxaa's published PHP sample computes it: the upper-case
 * MD5 of seller_id, the API signature, trans_receipt and trans_amount, joined.
 */
final class ZaxaaSignature
{
    /** The 202_users_pref column the account's Zaxaa API signature is stored in. */
    public const COLUMN = 'zaxaa_api_signature';

    private function __construct()
    {
    }

    public static function sign(string $sellerId, string $receipt, string $amount, string $signature): string
    {
        return strtoupper(md5($sellerId . $signature . $receipt . $amount));
    }

    /** The stored signature as the endpoint signs with it, or null when none is stored. */
    public static function stored(?string $column): ?string
    {
        $value = trim((string) $column);

        return $value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 ? null : $value;
    }
}
