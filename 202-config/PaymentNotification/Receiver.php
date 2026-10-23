<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Database\Connection;
use Prosper202\Database\SchemaVersion;

/**
 * One notification endpoint's work, for any provider: refuse what is not
 * a POST, verify the request against every account's stored secrets before
 * anything reads it as a notification, process it, and log one line saying
 * what became of it. Nothing is written before the signature verifies.
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

        return $outcome;
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
        try {
            $verification = $this->provider->verify($request, $secrets);
            if ($verification === null) {
                return [new Outcome(
                    OutcomeKind::UNVERIFIED,
                    'The notification does not verify with any ' . $name . ' secret key stored in'
                    . ' Prosper202 (Account > API integrations > ' . $name . '); nothing was recorded.'
                ), null];
            }
            $notification = $this->provider->parse($request);
        } catch (Refusal $refusal) {
            return [$refusal->outcome, null];
        } catch (\Throwable $e) {
            error_log($name . ' notification: it could not be read: ' . $e->getMessage());

            return [new Outcome(
                OutcomeKind::FAILED,
                'The notification could not be read just now; nothing was recorded. Send it again.'
            ), null];
        }

        $outcome = (new Processor($this->db))->process($notification, $verification, $this->provider, $request);

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
        $line .= ': ' . $outcome->kind->value . ' (' . Response::status($outcome->kind) . ')';
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
