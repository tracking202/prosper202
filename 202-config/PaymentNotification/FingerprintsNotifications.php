<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose notification is not its form fields (ClickBank's is an
 * encrypted JSON order, so the fields are empty and would make every refund
 * the same one): what identifies one notification, so that it sent again
 * reads as itself and another reversal of the same payment does not. The
 * processor keys a refund without an id of its own by it, and refuses any
 * other reversal of a payment that one already stands against, whatever it
 * states: the provider's retry is the same notification, so it is another.
 */
interface FingerprintsNotifications
{
    public function fingerprint(InboundRequest $request): string;
}
