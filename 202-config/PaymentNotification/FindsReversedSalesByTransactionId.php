<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose refund can arrive without the click its sale named
 * (ClickBank: no article says a refund carries the order's tracking codes)
 * while naming the sale's own transaction id. A reversal naming no click is
 * then taken back from the provider's sale under that id in the account
 * whose secret verified it, and answered no_click only when there is none.
 */
interface FindsReversedSalesByTransactionId
{
}
