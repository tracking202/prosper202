<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Ltv\MysqlCustomerRepository;

/**
 * A customer id as the identity graph and the operator's signature see it.
 *
 * The type is the LTV alias vocabulary (email_md5, email_sha256, esp_id,
 * merchant_id, subid, custom; none given means custom), so an id links the
 * same person the LTV ledger records. The canonical form is
 * "<type>:<value>": the type is inside it, so `abc` as a merchant id and
 * `abc` as an ESP id are two people, and a signature over one cannot be
 * replayed as the other. The vocabulary contains no colon, so the first
 * colon always ends the type and the form is injective (CLAUDE.md #17).
 * Email digests are hex and fold to lower case, as the LTV ledger folds
 * them; every other type is compared exactly.
 *
 * The signature (cust_sig) is HMAC-SHA256(the account's linking key, the
 * canonical form), hex, computed by the operator's own server. It is what
 * makes the id a proof rather than a claim.
 */
final class CustomerId
{
    private function __construct()
    {
    }

    /**
     * The canonical form, or null for an id that is not one: empty, an
     * unknown type, or an email digest that is not a digest.
     */
    public static function canonical(string $id, ?string $type = null): ?string
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            $type = 'custom';
        }
        if (!in_array($type, MysqlCustomerRepository::ALIAS_TYPES, true)) {
            return null;
        }
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if ($type === 'email_md5' || $type === 'email_sha256') {
            $id = strtolower($id);
            if (preg_match('/^[0-9a-f]{' . ($type === 'email_md5' ? 32 : 64) . '}$/D', $id) !== 1) {
                return null;
            }
        }

        return $type . ':' . $id;
    }

    public static function sign(string $linkKeyHex, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, self::key($linkKeyHex));
    }

    /**
     * Whether $signature is the operator's signature of this canonical id.
     * Constant time; an empty id or a signature that is not 64 hex
     * characters is never valid.
     */
    public static function verify(string $linkKeyHex, string $canonical, string $signature): bool
    {
        $signature = strtolower(trim($signature));
        if ($canonical === '' || preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            return false;
        }

        return hash_equals(self::sign($linkKeyHex, $canonical), $signature);
    }

    private static function key(string $hex): string
    {
        // Checked before decoding: hex2bin() warns on odd-length input
        // rather than returning false.
        if (preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \InvalidArgumentException('the linking key is not 32 bytes of hex');
        }

        return (string) hex2bin($hex);
    }
}
