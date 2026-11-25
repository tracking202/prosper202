<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider that says what identifies one notification, so that it sent
 * again reads as itself and another reversal of the same payment does not:
 * ClickBank's notification is not its form fields (an encrypted JSON order,
 * so the fields are empty and would make every refund the same one), and
 * PayKickstart's refund is told from another by its transaction_time. The
 * processor keys a refund without an id of its own by it, and records any
 * other as another partial refund, at most what the payment still credits:
 * the provider's retry is the same notification, so it is another.
 */
interface FingerprintsNotifications
{
    public function fingerprint(InboundRequest $request): string;
}
