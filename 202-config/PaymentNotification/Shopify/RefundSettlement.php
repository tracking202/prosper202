<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use InvalidArgumentException;
use Prosper202\Conversion\Ledger\Amount;

/** Resolves a refund's transactions against terminal updates, regardless of delivery order. */
final class RefundSettlement
{
    /**
     * @param list<array{id: string, status: string, amount: string, currency: string}> $transactions
     * @param array<string, array{status: string, amount: string, currency: string}> $updates keyed by transaction id
     * @return array{pending: bool, amount: string, currency: ?string}
     */
    public static function resolve(array $transactions, array $updates): array
    {
        $seen = [];
        $units = 0;
        $currency = null;
        $pending = false;
        foreach ($transactions as $transaction) {
            $id = $transaction['id'];
            if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('Refund transaction ids must be unique positive Shopify ids.');
            }
            $seen[$id] = true;
            $status = $transaction['status'];
            $amount = $transaction['amount'];
            $code = strtoupper($transaction['currency']);
            if (preg_match('/^[A-Z]{3}$/D', $code) !== 1 || preg_match('/^\d{1,6}(?:\.\d{1,5})?$/D', $amount) !== 1) {
                throw new InvalidArgumentException('Refund transaction amount or currency is invalid.');
            }
            if (isset($updates[$id])) {
                $update = $updates[$id];
                if (Amount::toUnits($update['amount']) !== Amount::toUnits($amount)
                    || strtoupper($update['currency']) !== $code) {
                    throw new InvalidArgumentException('A transaction update does not match the refund amount and currency.');
                }
                if (in_array($status, ['success', 'failure', 'error'], true) && $status !== $update['status']) {
                    throw new InvalidArgumentException('A transaction has conflicting terminal statuses.');
                }
                $status = $update['status'];
            }
            if (!in_array($status, ['success', 'failure', 'error', 'pending', 'waiting'], true)) {
                throw new InvalidArgumentException('Refund transaction status is not supported.');
            }
            if ($status === 'pending' || $status === 'waiting') {
                $pending = true;
            } elseif ($status === 'success') {
                if ($currency !== null && $currency !== $code) {
                    throw new InvalidArgumentException('A refund has transactions in different currencies.');
                }
                $currency = $code;
                $units += Amount::toUnits($amount);
            }
        }

        return ['pending' => $pending, 'amount' => Amount::fromUnits($units), 'currency' => $currency];
    }
}
