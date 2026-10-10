<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

/**
 * Whether a request carrying a key that already recorded a ledger event is
 * the same request -- a retry -- or a different one sent under the same key.
 *
 * The idempotency key of POST /ltv/revenue (and the key or transaction id of
 * a subscription's renewal or refund) locates the event and nothing compared
 * the request to it, so a key reused for a different sale answered 200
 * `duplicate: true` with the first event and recorded nothing: measured, a
 * 99.50 one_time on another customer under a 10.00 purchase's key was
 * dropped without a word (CLAUDE.md #15, the discriminator that must be
 * compared, not looked up). The ledger row is the only record of the
 * request, so the comparison is of what the row (and its line items) holds:
 *
 *  - compared whenever the request states it, and when it does not, the
 *    value its absence always means (so "left out" is compared too):
 *    event_type (purchase), amount, external_ref and transaction_id (none),
 *    the customer it names, and items (none) -- each stored exactly as sent;
 *  - compared only when stated: currency and occurred_at, whose absence means
 *    the account's currency and the time of the request, which a retry
 *    cannot be held to;
 *  - never compared: customer_crm, which only describes a customer the write
 *    creates and is ignored for one that exists (as every replay's does), and
 *    a line item's catalog `price`, which updates the product, not the event,
 *    and may have changed since.
 *
 * Amounts and quantities are compared as the ledger stores them, through
 * MysqlCustomerRepository::ledgerDecimals(), so a retry of the same number
 * always matches and a different number matches only when the columns could
 * not tell them apart.
 */
final class RevenueReplay
{
    public function __construct(private MysqlCustomerRepository $customers)
    {
    }

    /**
     * How the request differs from the event its key recorded: field => what
     * the event holds and what was sent. [] when it is the same request.
     *
     * @param array{event_id: int, customer_id: int, event_type: string, amount: string, currency: string,
     *              occurred_at: int, external_ref: ?string, transaction_id: ?string} $event
     *        MysqlCustomerRepository::findEventByIdempotencyKey()'s row
     * @param array<string, mixed> $sent what the request states, normalized as the write would store it:
     *        event_type, amount (signed), currency, occurred_at, external_ref, transaction_id,
     *        customer (['id' => int] or ['ref' => string, 'type' => ?string]), items (list).
     *        A key left out is not compared.
     * @return array<string, string>
     */
    public function differences(int $userId, array $event, array $sent): array
    {
        $differences = [];
        if (array_key_exists('event_type', $sent) && $sent['event_type'] !== $event['event_type']) {
            $differences['event_type'] = self::recordedSent($event['event_type'], (string) $sent['event_type']);
        }
        if (array_key_exists('amount', $sent)) {
            $amount = $this->customers->ledgerDecimals((float) $sent['amount'])['amount'];
            if ($amount !== $event['amount']) {
                $differences['amount'] = self::recordedSent($event['amount'], $amount);
            }
        }
        $currency = array_key_exists('currency', $sent) ? strtoupper((string) $sent['currency']) : null;
        if ($currency !== null && $currency !== strtoupper($event['currency'])) {
            $differences['currency'] = self::recordedSent($event['currency'], (string) $sent['currency']);
        }
        if (array_key_exists('occurred_at', $sent) && (int) $sent['occurred_at'] !== $event['occurred_at']) {
            $differences['occurred_at'] = self::recordedSent(
                (string) $event['occurred_at'],
                (string) $sent['occurred_at']
            );
        }
        foreach (['external_ref', 'transaction_id'] as $reference) {
            if (array_key_exists($reference, $sent) && $sent[$reference] !== $event[$reference]) {
                $differences[$reference] = self::recordedSent(
                    self::quoted($event[$reference]),
                    self::quoted(is_string($sent[$reference]) ? $sent[$reference] : null)
                );
            }
        }
        if (array_key_exists('customer', $sent) && is_array($sent['customer'])) {
            $customer = $this->customerDifference($userId, $event['customer_id'], $sent['customer']);
            if ($customer !== null) {
                $differences['customer'] = $customer;
            }
        }
        if (array_key_exists('items', $sent) && is_array($sent['items'])) {
            $signed = (float) ($sent['amount'] ?? $event['amount']);
            $items = $this->itemsDifference($userId, $event['event_id'], array_values($sent['items']), $signed);
            if ($items !== null) {
                $differences['items'] = $items;
            }
        }

        return $differences;
    }

    /**
     * How the customer a request names differs from the one an event
     * recorded (merges followed on both sides), or null when it is the same
     * customer. A name that resolves to no customer of the account differs.
     *
     * @param array{id?: int, ref?: string, type?: ?string} $customer
     */
    public function customerDifference(int $userId, ?int $recordedCustomerId, array $customer): ?string
    {
        $recorded = $recordedCustomerId !== null && $recordedCustomerId > 0
            ? $this->customers->followMergePointer($recordedCustomerId)
            : null;
        if (isset($customer['id'])) {
            $id = (int) $customer['id'];
            $named = $this->customers->customerBelongsToUser($id, $userId)
                ? $this->customers->followMergePointer($id)
                : null;
            $what = 'customer_id ' . $id;
        } else {
            $ref = (string) ($customer['ref'] ?? '');
            $named = $this->customers->findCustomerByAlias($userId, $customer['type'] ?? null, $ref);
            $what = 'customer_ref ' . self::quoted($ref);
        }
        if ($named === $recorded) {
            return null;
        }

        return self::recordedSent(
            $recorded !== null ? 'customer ' . $recorded : 'no customer',
            $what . ($named !== null ? ' (customer ' . $named . ')' : ' (no customer of this account)')
        );
    }

    /**
     * How a request's line items differ from the ones stored on an event, or
     * null when they are the same items in the same order. Each sent item is
     * read as MysqlCustomerRepository::insertLineItems() writes it: quantity
     * 1 when left out, the amount unit_price x quantity when left out, both
     * negated on a negative event, and the product keyed `sku:<sku>` when it
     * has no external_product_id.
     *
     * @param list<array<string, mixed>> $items
     */
    public function itemsDifference(int $userId, ?int $eventId, array $items, float $eventAmount): ?string
    {
        $stored = $eventId !== null ? $this->customers->lineItemsOfEvent($userId, $eventId) : [];
        if (count($stored) !== count($items)) {
            return self::recordedSent(count($stored) . ' line item(s)', (string) count($items));
        }
        foreach ($items as $index => $item) {
            $sent = $this->lineItem($item, $eventAmount);
            $kept = $stored[$index];
            foreach ($sent as $field => $value) {
                if ($kept[$field] !== $value) {
                    return 'line item ' . $index . ' ' . $field . ': '
                        . self::recordedSent(self::quoted($kept[$field]), self::quoted($value));
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{external_product_id: string, sku: ?string, name: ?string, quantity: string,
     *               unit_price: ?string, amount: string}
     */
    private function lineItem(array $item, float $eventAmount): array
    {
        $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1.0;
        $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : null;
        $amount = isset($item['amount'])
            ? (float) $item['amount']
            : ($unitPrice !== null ? $unitPrice * $quantity : 0.0);
        if ($eventAmount < 0) {
            $amount = -abs($amount);
            $quantity = -$quantity;
        }
        $decimals = $this->customers->ledgerDecimals($amount, $quantity, $unitPrice ?? 0.0);
        $external = trim((string) ($item['external_product_id'] ?? ''));
        $sku = trim((string) ($item['sku'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));

        return [
            'external_product_id' => $external !== '' ? $external : 'sku:' . $sku,
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name !== '' ? $name : null,
            'quantity' => $decimals['quantity'],
            'unit_price' => $unitPrice !== null ? $decimals['unit_price'] : null,
            'amount' => $decimals['amount'],
        ];
    }

    private static function recordedSent(string $recorded, string $sent): string
    {
        return 'recorded ' . $recorded . ', sent ' . $sent;
    }

    private static function quoted(?string $value): string
    {
        return $value === null ? 'none' : '"' . $value . '"';
    }
}
