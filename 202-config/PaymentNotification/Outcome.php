<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * What became of one notification, said to the provider (Response) and to
 * the log (Receiver). The message is written for the operator reading the
 * provider's delivery log: it never carries a secret or the buyer's details.
 */
final class Outcome
{
    public function __construct(
        public readonly OutcomeKind $kind,
        public readonly string $message,
        public readonly ?int $conversionId = null,
        public readonly ?int $accountId = null,
    ) {
    }
}
