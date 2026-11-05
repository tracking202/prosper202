<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider answered otherwise than Response::status() says (Shopify drops
 * a subscription its retries keep failing): the status sent, which the
 * endpoint answers and the Receiver's log line names.
 */
interface AnswersWithItsOwnStatus
{
    public function deliveryStatus(OutcomeKind $kind): int;
}
