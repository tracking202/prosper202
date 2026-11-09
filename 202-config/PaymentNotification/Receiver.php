<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Database\Connection;
use Prosper202\Database\SchemaVersion;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\Redactor;
use Prosper202\PaymentNotification\Shopify\SettlementCoordinator;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;

/**
 * One notification endpoint's work, for any provider: refuse what is not
 * a POST, verify the request against every account's stored secrets before
 * anything reads it as a notification, process it, and log one line saying
 * what became of it, in the server log and the inbound log (InboundLog).
 * Nothing else is written before the signature verifies.
 *
 * A failure while the files are deployed and the upgrade has not run says
 * so (a 503 naming the upgrade), as the API's answers do: the older schema
 * is the likely cause, and the provider's retry will land once it has run.
 */
final class Receiver
{
    public function __construct(
        private readonly ProviderAdapter $provider,
        private readonly \mysqli $db,
        private readonly ?InboundLog $inboundLog = null,
    ) {
    }

    public function receive(InboundRequest $request): Outcome
    {
        [$outcome, $notification] = $this->decide($request);
        if ($outcome->kind === OutcomeKind::FAILED) {
            require_once dirname(__DIR__) . '/version.php';
            $pending = SchemaVersion::pendingUpgrade($this->db, \PROSPER202_VERSION);
            if ($pending !== null) {
                $outcome = new Outcome(OutcomeKind::UPGRADE_PENDING, 'Nothing was recorded: ' . $pending
                    . ' Send it again once it has run.');
            }
        }
        error_log($this->logLine($request, $outcome, $notification));
        try {
            $endpoint = strtolower($this->provider->name());
            ($this->inboundLog ?? new InboundLog($this->db))->append(new Entry(
                $endpoint,
                $request->method !== '' ? $request->method : 'GET',
                $this->status($outcome),
                $outcome->kind->value,
                $outcome->message,
                $outcome->accountId ?? 0,
                $notification?->clickId,
                $outcome->conversionId,
                $notification?->transactionId,
                Redactor::fields($request->fields, $endpoint),
            ));
        } catch (\Throwable $e) {
            error_log($this->provider->name() . ' notification: the inbound log could not be written: ' . $e->getMessage());
        }

        return $outcome;
    }

    /** The status the provider is answered with, which the log line names too. */
    public function status(Outcome $outcome): int
    {
        return $this->provider instanceof AnswersWithItsOwnStatus
            ? $this->provider->deliveryStatus($outcome->kind)
            : Response::status($outcome->kind);
    }

    /** @return array{0: Outcome, 1: ?Notification} */
    private function decide(InboundRequest $request): array
    {
        $name = $this->provider->name();
        if ($request->method !== 'POST') {
            return [new Outcome(
                OutcomeKind::METHOD_NOT_ALLOWED,
                'This URL takes ' . $name . '\'s notifications, which arrive as a POST.'
            ), null];
        }
        try {
            $secrets = $this->provider->storedSecrets(new Connection($this->db));
        } catch (\Throwable $e) {
            error_log($name . ' notification: the stored secret keys could not be read: ' . $e->getMessage());

            return [new Outcome(
                OutcomeKind::FAILED,
                'The stored secret keys could not be read just now; nothing was recorded. Send it again.'
            ), null];
        }
        $verification = null;
        try {
            $verification = $this->provider->verify($request, $secrets);
            if ($verification === null) {
                return [new Outcome(
                    OutcomeKind::UNVERIFIED,
                    'The notification\'s ' . $this->provider->signatureField() . ' does not verify with any '
                    . $name . ' secret key stored in'
                    . ' Prosper202 (Account > API integrations > ' . $name . '); nothing was recorded.'
                ), null];
            }
            $notification = $this->provider->parse($request);
        } catch (Refusal $refusal) {
            // A verified request is its account's, so the inbound log can show it there.
            $accounts = $verification?->accounts() ?? [];
            $refused = $refusal->outcome;
            if ($refused->accountId === null && count($accounts) === 1) {
                $refused = new Outcome($refused->kind, $refused->message, $refused->conversionId, $accounts[0]);
            }

            return [$refused, null];
        } catch (\Throwable $e) {
            error_log($name . ' notification: it could not be read: ' . $e->getMessage());

            return [new Outcome(
                OutcomeKind::FAILED,
                'The notification could not be read just now; nothing was recorded. Send it again.'
            ), null];
        }

        try {
            if ($this->provider instanceof ShopifyAdapter && $this->provider->settlement() !== null) {
                $outcome = (new SettlementCoordinator($this->db, $this->provider))
                    ->accept($this->provider->settlement(), $verification, $request);
            } else {
                $outcome = (new Processor($this->db))->process($notification, $verification, $this->provider, $request);
                if ($this->provider instanceof ShopifyAdapter && $notification->kind->records()
                    && $notification->transactionId !== null && $outcome->accountId !== null
                    && $outcome->conversionId !== null
                    && in_array($outcome->kind, [OutcomeKind::RECORDED, OutcomeKind::DUPLICATE, OutcomeKind::CONFLICT], true)) {
                    $settlement = (new SettlementCoordinator($this->db, $this->provider))
                        ->reconcile($outcome->accountId, $notification->transactionId, $request);
                    if ($settlement !== null) {
                        $outcome = $settlement;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log($name . ' settlement: ' . $e->getMessage());
            $outcome = new Outcome(OutcomeKind::FAILED,
                'The Shopify refund settlement could not be reconciled. Send the webhook again.',
                null, count($verification->accounts()) === 1 ? $verification->accounts()[0] : null);
        }

        return [$outcome, $notification];
    }

    /**
     * What the log keeps of a notification: the outcome and the values the
     * adapter read, never a secret or the buyer's details. A request that
     * did not verify is named by the address it came from.
     */
    private function logLine(InboundRequest $request, Outcome $outcome, ?Notification $notification): string
    {
        $line = $this->provider->name() . ' notification';
        if ($notification === null) {
            $line .= ' from ' . ($request->peerAddress !== '' ? $request->peerAddress : 'an unknown address');
        }
        $line .= ': ' . $outcome->kind->value . ' (' . $this->status($outcome) . ')';
        if ($notification !== null) {
            $parts = [$notification->event . ' (' . $notification->kind->value . ')'];
            if ($notification->clickId !== null) {
                $parts[] = 'click ' . $notification->clickId;
            }
            if ($notification->transactionId !== null) {
                $parts[] = 'transaction ' . $notification->transactionId;
            }
            if ($outcome->accountId !== null) {
                $parts[] = 'account ' . $outcome->accountId;
            }
            if ($outcome->conversionId !== null) {
                $parts[] = 'conversion ' . $outcome->conversionId;
            }
            $line .= ', ' . implode(', ', $parts);
        }

        return $line . ': ' . $outcome->message;
    }
}
