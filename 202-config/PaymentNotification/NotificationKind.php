<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * What a payment provider's notification asks of the ledger, whatever the
 * provider calls the event.
 */
enum NotificationKind: string
{
    /** A payment: a conversion on the click, under its transaction id. */
    case SALE = 'sale';
    /** A subscription's later payment: recorded as a sale is, under its own transaction id. */
    case REBILL = 'rebill';
    /** Money given back: a reversal of the payment it names. */
    case REFUND = 'refund';
    case CHARGEBACK = 'chargeback';
    /** A test-mode notification: verified, and nothing recorded. */
    case TEST = 'test';
    /** An event the ledger has nothing to do with (a subscription created, a lead added). */
    case IGNORED = 'ignored';

    public function records(): bool
    {
        return $this === self::SALE || $this === self::REBILL;
    }

    public function reverses(): bool
    {
        return $this === self::REFUND || $this === self::CHARGEBACK;
    }
}
