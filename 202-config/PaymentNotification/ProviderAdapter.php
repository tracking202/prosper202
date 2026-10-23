<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;

/**
 * One payment provider's notifications (PayKickstart's IPN; JVZoo's and
 * ClickBank's are meant to follow): where its secrets are stored, how a
 * request is verified against them, and how a verified request reads as a
 * Notification. Recording is the Processor's, the same for every provider.
 *
 * The account a notification is for is the one whose stored secret
 * verifies it, never one the request names (CLAUDE.md #16).
 */
interface ProviderAdapter
{
    /** The provider's name, for the log and the answers ("PayKickstart"). */
    public function name(): string;

    /** The ledger source its conversions are recorded under. */
    public function source(): ConversionSource;

    /** The field its notifications carry their signature in ("hash"), for the answers. */
    public function signatureField(): string;

    /**
     * Every account's stored secrets for this provider. An entry that cannot
     * be read is left out, so it verifies nothing.
     *
     * @return array<int, list<string>> account id => its secrets
     */
    public function storedSecrets(Connection $conn): array;

    /**
     * Which accounts' secrets the request is signed with, or null when none
     * is. Throws Refusal for a request that is not this provider's
     * notification at all (an unreadable body), which no secret can verify.
     *
     * @param array<int, list<string>> $secrets as storedSecrets()
     * @throws Refusal
     */
    public function verify(InboundRequest $request, array $secrets): ?Verification;

    /**
     * A verified request as a Notification. Throws Refusal naming the field
     * for one it cannot read, rather than reading it as something else.
     *
     * @throws Refusal
     */
    public function parse(InboundRequest $request): Notification;
}
