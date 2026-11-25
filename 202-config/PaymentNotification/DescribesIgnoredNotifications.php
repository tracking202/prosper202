<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider with a notification Prosper202 records nothing for that may
 * still carry money no article accounts for (ClickBank's SUBSCRIPTION-CHG):
 * the answer, which Setup › Inbound Log keeps, says what it stated.
 */
interface DescribesIgnoredNotifications
{
    /** A sentence added to the ignored answer of this verified request ('' for none). */
    public function ignoredNote(InboundRequest $request): string;
}
