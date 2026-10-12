<?php

declare(strict_types=1);

namespace Prosper202\Integration;

use Prosper202\Conversion\Ledger\Amount;

/**
 * Zaxaa's Payment Notification (ZPN).
 *
 * hash_key covers seller_id, the API signature, trans_receipt and
 * trans_amount only. trans_type, trans_testmode, the products and their
 * custom values (the click ids) are not signed, so a copy of one genuine
 * notification can be replayed with those changed; nothing here can tell.
 */
final class ZaxaaZpn
{
    /** A one-time sale, a subscription's first payment, a rebill. */
    public const CREDIT = ['SALE', 'FIRST_BILL', 'REBILL'];

    public const REVERSE = ['REFUND'];

    /**
     * MD5 of seller_id . API signature . trans_receipt . trans_amount, upper
     * case, as the raw posted strings.
     *
     * @param array<array-key, mixed> $post
     */
    public static function verify(array $post, string $signature): bool
    {
        foreach (['seller_id', 'trans_receipt', 'trans_amount', 'hash_key'] as $field) {
            if (!isset($post[$field]) || !is_string($post[$field])) {
                return false;
            }
        }
        if ($signature === '' || $post['hash_key'] === '') {
            return false;
        }
        $expected = strtoupper(md5($post['seller_id'] . $signature . $post['trans_receipt'] . $post['trans_amount']));

        return hash_equals($expected, strtoupper($post['hash_key']));
    }

    /** A decimal amount as Zaxaa writes it ("47.00"), or null. */
    public static function amount(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return preg_match('/^\d{1,6}(\.\d{1,5})?$/D', $raw) === 1 ? $raw : null;
    }

    /**
     * The products a notification names, each with its custom value (where
     * the order link's ?custom= arrives: the click id) and the transaction id
     * it is recorded under: the receipt and the product's number, since one
     * receipt can pay for several products.
     *
     * With $amounts, each product's amount too: its own, or for a lone
     * product without one the signed trans_amount; they may not add up to
     * more than trans_amount, the one amount the hash covers.
     *
     * @param array<array-key, mixed> $post
     * @return list<array{custom: string, transaction_id: string, amount: string|null}>
     * @throws \InvalidArgumentException naming what is malformed
     */
    public static function products(array $post, bool $amounts): array
    {
        $receipt = is_string($post['trans_receipt'] ?? null) ? trim($post['trans_receipt']) : '';
        if ($receipt === '') {
            throw new \InvalidArgumentException('trans_receipt is empty');
        }
        $products = $post['products'] ?? null;
        if (!is_array($products) || $products === []) {
            throw new \InvalidArgumentException('products is missing');
        }
        $total = null;
        if ($amounts) {
            $total = self::amount($post['trans_amount'] ?? null);
            if ($total === null) {
                throw new \InvalidArgumentException('trans_amount is not an amount');
            }
        }

        $out = [];
        $sum = 0;
        foreach (array_values($products) as $i => $product) {
            if (!is_array($product)) {
                throw new \InvalidArgumentException("products[$i] is not a product");
            }
            $number = is_string($product['prod_number'] ?? null) ? trim($product['prod_number']) : '';
            $amount = null;
            if ($amounts) {
                if (isset($product['amount']) && $product['amount'] !== '') {
                    $amount = self::amount($product['amount']);
                    if ($amount === null) {
                        throw new \InvalidArgumentException("products[$i][amount] is not an amount");
                    }
                } elseif (count($products) === 1) {
                    $amount = $total;
                } else {
                    throw new \InvalidArgumentException("products[$i] has no amount");
                }
                $sum += Amount::toUnits($amount);
            }
            $out[] = [
                'custom' => is_string($product['custom'] ?? null) ? trim($product['custom']) : '',
                'transaction_id' => $receipt . ':' . ($number !== '' ? $number : (string) $i),
                'amount' => $amount,
            ];
        }
        if ($total !== null && $sum > Amount::toUnits($total)) {
            throw new \InvalidArgumentException('the products\' amounts add up to more than trans_amount');
        }

        return $out;
    }
}
