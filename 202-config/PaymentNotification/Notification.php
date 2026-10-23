<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use InvalidArgumentException;

/**
 * A provider's notification as the processor reads it: one shape for every
 * provider, built by its adapter from a request whose signature verified.
 */
final class Notification
{
    /** An exact decimal, never negative: no sign, no exponent, no separators. */
    private const AMOUNT = '/^\d+(?:\.\d+)?$/D';

    /**
     * @param string $event the provider's own name for the event ("sales", "refund")
     * @param string|null $transactionId the provider's id for this payment (for a
     *        refund, the refund's own id when the provider gives it one)
     * @param string|null $originalTransactionId the payment a refund or
     *        chargeback takes back
     * @param string|null $amount what the payment is worth, or how much a refund
     *        takes back (null: all of it)
     * @param string|null $currency ISO 4217, when the provider states one
     * @param int|null $clickId the click the payment came from; null when the
     *        notification names none
     * @param int|null $occurredAt when the provider says it happened (Unix time)
     */
    public function __construct(
        public readonly NotificationKind $kind,
        public readonly string $event,
        public readonly ?string $transactionId = null,
        public readonly ?string $originalTransactionId = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $clickId = null,
        public readonly ?int $occurredAt = null,
    ) {
        if ($amount !== null && preg_match(self::AMOUNT, $amount) !== 1) {
            throw new InvalidArgumentException('a notification\'s amount is an exact decimal that is not negative');
        }
        if ($clickId !== null && $clickId <= 0) {
            throw new InvalidArgumentException('a notification\'s click id is a positive integer');
        }
    }
}
