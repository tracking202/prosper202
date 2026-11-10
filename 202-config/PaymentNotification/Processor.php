<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Click\ClickRef;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\AmountOutOfRange;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Conversion\PurLinkCampaignChanged;
use Prosper202\Database\Connection;

/**
 * What a verified notification does to the ledger, for every provider: a
 * sale or rebill is a conversion on its click under its transaction id, a
 * refund or chargeback a reversal of the sale it names, through the writer
 * every legacy endpoint uses (p202RecordLegacyConversion()), with pixel
 * type 2 and the provider's ledger source.
 *
 * The click must be an account's whose secret verified the notification:
 * a click of another account reads as no click at all, so the answers do
 * not tell one key holder which click ids another account has. A retry of
 * a payment or refund already on file is a duplicate only when it states
 * the same amount (a refund that states none takes back the whole sale);
 * one that states another is refused, never answered as the first
 * (CLAUDE.md #15). Where the provider says what one notification is
 * (FingerprintsNotifications), a refund is a duplicate only when it is that
 * notification. A refund takes back at most what its sale recorded: one
 * stating more is capped at the sale and its answer names both amounts.
 * From a provider whose 1.9.56 handler wrote no rows (ReversesPreUpgradeSales),
 * a refund naming no sale on file takes its stated amount back from the value
 * the click carried from before the upgrade, at most what is left of it.
 */
final class Processor
{
    public function __construct(private readonly \mysqli $db)
    {
    }

    public function process(
        Notification $notification,
        Verification $verification,
        ProviderAdapter $provider,
        InboundRequest $request
    ): Outcome {
        $event = $notification->event;
        $accounts = $verification->accounts();
        $account = count($accounts) === 1 ? $accounts[0] : null;
        if ($notification->kind === NotificationKind::TEST) {
            if ($account !== null && $provider instanceof ConfirmsTestNotifications) {
                try {
                    $provider->confirmTest(new Connection($this->db), $account);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '%s notification: the %s verified for account %d, which could not be marked verified: %s',
                        $provider->name(),
                        $event,
                        $account,
                        $e->getMessage()
                    ));

                    return new Outcome(
                        OutcomeKind::FAILED,
                        'The ' . $event . ' notification verified, but Prosper202 could not mark the key verified'
                        . ' just now. Send the test again.',
                        null,
                        $account
                    );
                }
            }

            return new Outcome(
                OutcomeKind::TEST,
                'Verified. This is a test-mode ' . $event . ' notification, so nothing was recorded.',
                null,
                $account
            );
        }
        if ($notification->kind === NotificationKind::IGNORED) {
            return new Outcome(
                OutcomeKind::IGNORED,
                'Verified. Prosper202 records sales and refunds; a ' . $event . ' notification records nothing.',
                null,
                $account
            );
        }

        try {
            return $notification->kind->records()
                ? $this->sale($notification, $verification, $provider, $request)
                : $this->reversal($notification, $verification, $provider, $request);
        } catch (AmountOutOfRange $e) {
            // Not worth a retry: the same amount is refused again (Y5N2).
            return new Outcome(OutcomeKind::MALFORMED, 'The ' . $event . ' was not recorded: ' . $e->getMessage() . '.', null, $account);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '%s notification: the %s on click %s could not be recorded: %s',
                $provider->name(),
                $event,
                $notification->clickId ?? 'none',
                $e->getMessage()
            ));

            // A retry is safe whether or not the row landed: the writer keys
            // it by its transaction id, so a second delivery is a duplicate.
            return new Outcome(
                OutcomeKind::FAILED,
                'The ' . $event . ' could not be recorded just now. Send it again: a transaction id is recorded'
                . ' once however often it arrives.'
            );
        }
    }

    private function sale(
        Notification $n,
        Verification $verification,
        ProviderAdapter $provider,
        InboundRequest $request
    ): Outcome {
        $transactionId = $n->transactionId;
        $amount = $n->amount;
        if ($transactionId === null || $amount === null) {
            return new Outcome(
                OutcomeKind::MALFORMED,
                'A ' . $n->event . ' notification needs its transaction id and its amount.'
            );
        }
        if ($n->clickId === null) {
            return self::noClick($n, $transactionId, $provider->clickField(), 'nothing was recorded');
        }
        $purLink = $this->purLink($n);
        if ($purLink === false) {
            return self::unknownClick($n->clickId, $provider->clickField());
        }
        $owner = $this->owner($n->clickId, $verification);
        if ($owner === null) {
            return self::unknownClick($n->clickId, $provider->clickField());
        }
        $currency = $this->currency($n, $owner, $provider);
        if ($currency instanceof Outcome) {
            return $currency;
        }

        return self::noted(
            $this->recordSale($n, $n->clickId, $owner, $provider, $request, $transactionId, $amount, $purLink),
            $currency
        );
    }

    private function recordSale(
        Notification $n,
        int $clickId,
        int $owner,
        ProviderAdapter $provider,
        InboundRequest $request,
        string $transactionId,
        string $amount,
        ?string $purLinkKey = null
    ): Outcome {
        if ($provider instanceof SignsThePaymentNotTheClick
            && $this->resignedSale($provider, $clickId, $transactionId, $amount, $owner)
        ) {
            return new Outcome(
                OutcomeKind::CONFLICT,
                'Transaction ' . $transactionId . ': this ' . $n->event . ' carries the signature of a sale already'
                . ' recorded under another click or transaction id, so it is not a new payment. Nothing was recorded.',
                null,
                $owner
            );
        }

        [$time, $dated] = self::dated($n->occurredAt, $this->latestVisitTime($clickId), 'the click');
        $what = [
            'transaction_id' => $transactionId,
            'use_pixel_payout' => true,
            'payout' => $amount,
            'conv_time' => $time,
        ];
        if ($purLinkKey !== null) {
            // A signed PurLink SubID records on its offer, as on pb.php and gpb.php.
            $what += ['purlink_subid' => $n->purLinkSubid, 'purlink_key' => $purLinkKey];
        }
        try {
            $result = $this->record($clickId, $owner, $provider, $request, $what);
        } catch (PurLinkCampaignChanged) {
            return self::unknownClick($clickId, $provider->clickField());
        }
        $on = 'Transaction ' . $transactionId . ' on click ' . $clickId;
        if ($result['recorded']) {
            return new Outcome(
                OutcomeKind::RECORDED,
                'Recorded transaction ' . $transactionId . ' on click ' . $clickId . ' at ' . $amount . '.' . $dated,
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'duplicate') {
            $stored = $this->storedAmount($result['conv_id'], $owner);
            if (Amount::toUnits($stored) !== Amount::toUnits($amount)) {
                return new Outcome(
                    OutcomeKind::CONFLICT,
                    'Transaction ' . $transactionId . ' is already recorded on click ' . $clickId . ' at '
                    . self::plain($stored) . '; this notification states ' . $amount . '. Nothing was changed.',
                    $result['conv_id'],
                    $owner
                );
            }
            // A sale sent again after its refund (JVZoo's when a refund is
            // undone) is not on file as counted, so it is no duplicate.
            $reversal = $this->liveReversalOf($result['conv_id'], $owner);
            if ($reversal !== null) {
                return new Outcome(
                    OutcomeKind::CONFLICT,
                    'Transaction ' . $transactionId . ' on click ' . $clickId . ' is recorded and reversed by'
                    . ' conversion ' . $reversal . '; this ' . $n->event . ' states the sale again. Nothing was'
                    . ' changed: if the refund was undone, delete conversion ' . $reversal . ' to count the sale.',
                    $result['conv_id'],
                    $owner
                );
            }

            return new Outcome(
                OutcomeKind::DUPLICATE,
                'Transaction ' . $transactionId . ' is already recorded on click ' . $clickId
                . '; nothing was changed.',
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'unknown_click' || $result['reason'] === 'foreign_click') {
            return self::unknownClick($clickId, $provider->clickField());
        }

        throw new \RuntimeException($on . ': the conversion writer answered "' . $result['reason'] . '"');
    }

    private function reversal(
        Notification $n,
        Verification $verification,
        ProviderAdapter $provider,
        InboundRequest $request
    ): Outcome {
        $original = $n->originalTransactionId;
        if ($original === null) {
            return new Outcome(
                OutcomeKind::MALFORMED,
                'A ' . $n->event . ' notification needs the transaction id of the sale it takes back.'
            );
        }
        if ($n->clickId === null) {
            return self::noClick($n, $original, $provider->clickField(), 'there is no sale to reverse');
        }
        if ($this->purLink($n) === false) {
            return self::unknownClick($n->clickId, $provider->clickField());
        }
        $owner = $this->owner($n->clickId, $verification);
        if ($owner === null) {
            return self::unknownClick($n->clickId, $provider->clickField());
        }
        $currency = $this->currency($n, $owner, $provider);
        if ($currency instanceof Outcome) {
            return $currency;
        }

        return self::noted(
            $this->recordReversal($n, $n->clickId, $owner, $provider, $request, $original),
            $currency
        );
    }

    private function recordReversal(
        Notification $n,
        int $clickId,
        int $owner,
        ProviderAdapter $provider,
        InboundRequest $request,
        string $original
    ): Outcome {
        // A refund with an id of its own is told apart from another refund
        // of the same sale by that id. One without is keyed by its body: a
        // retry sent again unchanged is that refund, whether or not its
        // reversal still stands, and any other is compared with the reversal
        // that stands (a sale is reversed once), or reverses the sale again
        // when none does: the one on file was deleted. A provider that says
        // what one notification is (FingerprintsNotifications) sends a retry
        // as that same notification, so any other is another reversal, never
        // a duplicate of the one that stands, whatever amount it states.
        $ownId = $n->transactionId !== null && $n->transactionId !== $original;
        $exact = $provider instanceof FingerprintsNotifications;
        $body = $exact ? $provider->fingerprint($request) : self::bodyFingerprint($request->fields);
        $reference = $ownId ? (string) $n->transactionId : 'body:' . $body;
        $on = 'Transaction ' . $original . ' on click ' . $clickId;
        // A refund is dated when it happened, the sale keeping its own day;
        // never before the sale it takes back.
        $sale = $this->saleOnFile($clickId, $original, $owner);
        [$time, $dated] = self::dated($n->occurredAt, $sale['time'] ?? 0, 'the sale');
        // A refund recorded under the key its provider used before, sent
        // again, is that refund, whether or not its reversal still stands.
        if (!$ownId && $sale !== null && $provider instanceof KeyedRefundsByBodyBefore1980) {
            $before = $this->reversalUnderKey(
                $clickId,
                DedupeKey::reversal($sale['conv_id'], 'body:' . self::bodyFingerprint($request->fields)),
                $owner
            );
            if ($before !== null) {
                return $this->repeatedReversal($n, $on, $before, $owner);
            }
        }
        // A refund takes back at most what its sale recorded (a sale is
        // reversed once): one stating more (a fee, a mismatched payment) is
        // capped there and answered 200 naming both amounts, which the log
        // keeps, since a provider retries a refusal for days.
        $amount = $n->amount;
        $by = $amount ?? 'its full amount';
        if ($amount !== null && $sale !== null && abs(Amount::toUnits($amount)) > $sale['units']) {
            $by = self::units($sale['units']) . ', the whole sale: this ' . $n->event . ' states ' . $amount
                . ', more than the sale recorded';
            $amount = Amount::fromUnits($sale['units']);
        }
        // A sale credited before the upgrade left no row to name: the writer
        // takes its refund back from the value the click carried over, capped
        // under the click lock at what is left of it.
        $carriedPath = $sale === null && $amount !== null && $provider instanceof ReversesPreUpgradeSales;
        $write = fn (string $ref): array => $this->record($clickId, $owner, $provider, $request, [
            'transaction_id' => $original,
            'reversal' => true,
            'reversal_ref' => $ref,
            'reverses_carried_value' => $carriedPath,
            'use_pixel_payout' => $amount !== null,
            'payout' => $amount ?? '',
            'conv_time' => $time,
        ]);
        try {
            $result = $write($reference);
            // Its key names a reversal since deleted, but it takes back
            // another amount, which that refund sent again would not: it is a
            // later refund, recorded under its body as before 1.9.80, so the
            // sale is reversed again as it would have been.
            if (
                $result['reason'] === 'duplicate' && !$carriedPath && !$ownId
                && $provider instanceof KeyedRefundsByBodyBefore1980
                && $this->deletedReversalOfAnotherAmount($n, $result['conv_id'], $owner)
            ) {
                $result = $write('body:' . self::bodyFingerprint($request->fields));
            }
        } catch (ReversalException $e) {
            $standing = !$ownId && $e->kind === ReversalException::CONFLICT
                ? $this->liveReversalOfTransaction($clickId, $original, $owner)
                : null;
            if ($standing !== null) {
                return $this->repeatedReversal($n, $on, $standing, $owner, $exact);
            }

            return new Outcome(
                $e->kind === ReversalException::CONFLICT ? OutcomeKind::CONFLICT : OutcomeKind::NOTHING_TO_REVERSE,
                $e->getMessage() . ' Nothing was changed.',
                null,
                $owner
            );
        }
        $taken = $carriedPath && $result['conv_id'] > 0 ? $this->carriedTaken($result['conv_id'], $owner) : null;
        if ($result['recorded']) {
            if ($provider->source() === ConversionSource::SHOPIFY && $result['payout'] !== '' && $amount !== null) {
                $actual = abs(Amount::toUnits($result['payout']));
                if ($actual !== abs(Amount::toUnits($amount))) {
                    $by = self::units($actual) . ', all that was left of the sale: this ' . $n->event . ' states ' . $amount;
                }
            }
            if ($taken !== null) {
                if ($taken !== abs(Amount::toUnits((string) $amount))) {
                    $by = self::units($taken) . ', all that was left of it: this ' . $n->event . ' states ' . $amount;
                }
                $by .= ', from the value the click carried from before the upgrade (no sale under ' . $original
                    . ' was recorded since)';
            }

            return new Outcome(
                OutcomeKind::REVERSED,
                'Reversed transaction ' . $original . ' on click ' . $clickId . ' by '
                . $by . '.' . $dated,
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'duplicate') {
            if ($taken !== null) {
                // Keyed by this notification, so a hit is this refund sent again.
                return new Outcome(
                    OutcomeKind::DUPLICATE,
                    $on . ' is already taken back from the value the click carried from before the upgrade;'
                    . ' nothing was changed.',
                    $result['conv_id'],
                    $owner
                );
            }

            return $this->repeatedReversal($n, $on, $result['conv_id'], $owner, false,
                $provider->source() === ConversionSource::SHOPIFY);
        }
        if ($result['reason'] === 'unknown_click' || $result['reason'] === 'foreign_click') {
            return self::unknownClick($clickId, $provider->clickField());
        }

        throw new \RuntimeException($on . ': the conversion writer answered "' . $result['reason'] . '"');
    }

    /**
     * The row, as a server-to-server postback (pixel type 2) under the
     * provider's source, for the click's account; the address is the
     * provider's server.
     *
     * @param array<string, mixed> $what the transaction, the amount, a reversal
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     */
    private function record(
        int $clickId,
        int $owner,
        ProviderAdapter $provider,
        InboundRequest $request,
        array $what
    ): array {
        require_once dirname(__DIR__) . '/static-endpoint-helpers.php';

        // The notification's tracking code named the click.
        return p202RecordLegacyConversion($this->db, $clickId, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, [
            'user_id' => $owner,
            'ip' => $request->senderAddress,
            'user_agent' => $request->userAgent,
            'source' => $provider->source()->value,
            // A sale is the traffic source's conversion too: its server postbacks are queued with the row,
            // as gpb.php's, and sent after the answer or by the worker (QZ3M). A reversal announces nothing.
            'notify_traffic_source' => true,
        ] + $what);
    }

    /**
     * The installation key that verified the notification's signed PurLink
     * SubID; null for a plain click id, false when the SubID is forged or its
     * click is gone. A key that cannot be read throws: the provider retries.
     */
    private function purLink(Notification $n): string|false|null
    {
        if ($n->purLinkSubid === null) {
            return null;
        }
        $verified = ClickRef::verify(new Connection($this->db), $n->purLinkSubid);

        return $verified === null || $verified['claims']['click_id'] !== $n->clickId ? false : $verified['key'];
    }

    /**
     * The click's account when it is one whose secret verified the
     * notification, or null. Every visit of a click has its account.
     */
    private function owner(int $clickId, Verification $verification): ?int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead('SELECT c.user_id FROM ' . LatestVisit::of('?') . ' AS c LIMIT 1');
        $conn->bind($stmt, 'i', [$clickId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $owner = (int) $row['user_id'];

        return $verification->verifies($owner) ? $owner : null;
    }

    /**
     * When the row is dated: the time the notification says the payment or
     * refund happened, held between the earliest it can be (the click a sale
     * credits, the sale a refund takes back) and its arrival, which a clock
     * ahead of this server's would pass. Refusing either would lose the
     * payment over a clock, and the provider would send the same time again.
     * With no time stated, its arrival.
     *
     * @return array{int, string} the time, and what the answer says when it is not the one stated
     */
    private static function dated(?int $stated, int $earliest, string $after): array
    {
        $now = time();
        $earliest = min($earliest, $now);
        if ($stated === null) {
            return [$now, ''];
        }
        if ($stated > $now) {
            return [$now, ' Dated when it arrived, ' . self::utc($now) . ': the time it states, ' . self::utc($stated)
                . ', is later than this server\'s clock.'];
        }
        if ($stated < $earliest) {
            return [$earliest, ' Dated at ' . $after . ', ' . self::utc($earliest) . ': the time it states, '
                . self::utc($stated) . ', is before ' . $after . '.'];
        }

        return [$stated, ''];
    }

    private static function utc(int $time): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', $time);
    }

    /** The time of the click's latest visit, the one a conversion credits. */
    private function latestVisitTime(int $clickId): int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead('SELECT c.click_time FROM ' . LatestVisit::of('?') . ' AS c LIMIT 1');
        $conn->bind($stmt, 'i', [$clickId]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? 0 : (int) $row['click_time'];
    }

    /**
     * When the sale a transaction id names on a click is dated and what it
     * recorded, as positive units (the writer's target), or null with none.
     *
     * @return array{conv_id: int, time: int, units: int}|null
     */
    private function saleOnFile(int $clickId, string $transactionId, int $owner): ?array
    {
        $conn = new Connection($this->db);
        // From the primary: the writer reverses what the primary holds, and a
        // replica behind it would skip the cap and the 1.9.79 key lookup.
        $stmt = $conn->prepareWrite(
            'SELECT conv_id, conv_time, click_payout FROM 202_conversion_logs'
            . ' WHERE click_id = ? AND transaction_id = ? AND reverses_conv_id IS NULL AND user_id = ?'
            . ' ORDER BY conv_id LIMIT 1'
        );
        $conn->bind($stmt, 'isi', [$clickId, $transactionId, $owner]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : [
            'conv_id' => (int) $row['conv_id'],
            'time' => (int) $row['conv_time'],
            'units' => abs(Amount::toUnits((string) $row['click_payout'])),
        ];
    }

    /**
     * Whether a sale of the account under this provider, on another click or
     * another transaction id, has the same signed payment text: the same
     * signed body re-aimed at another click, or with digits moved between its
     * transaction id and amount. The same id on the same click is the
     * writer's duplicate, answered as such.
     */
    private function resignedSale(
        SignsThePaymentNotTheClick $provider,
        int $clickId,
        string $transactionId,
        string $amount,
        int $owner
    ): bool {
        $signed = $provider->signedPayment($transactionId, $amount);
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT conv_id, click_id, transaction_id, click_payout FROM 202_conversion_logs'
            . ' WHERE user_id = ? AND source = ? AND reverses_conv_id IS NULL AND transaction_id IS NOT NULL'
            . ' AND transaction_id = LEFT(?, CHAR_LENGTH(transaction_id))'
        );
        $conn->bind($stmt, 'iss', [$owner, $provider->source()->value, $signed]);
        foreach ($conn->fetchAll($stmt) as $row) {
            $onFile = (string) $row['transaction_id'];
            if ((int) $row['click_id'] === $clickId && $onFile === $transactionId) {
                continue;
            }
            $rest = substr($signed, strlen($onFile));
            try {
                $same = $rest !== '' && Amount::toUnits($rest) === abs(Amount::toUnits((string) $row['click_payout']));
            } catch (\InvalidArgumentException) {
                $same = false;
            }
            if ($same) {
                error_log(sprintf(
                    'Payment notification: transaction %s on click %d is signed like conversion %d (transaction %s on'
                    . ' click %d) of account %d; refused as a re-aimed copy.',
                    $transactionId,
                    $clickId,
                    (int) $row['conv_id'],
                    $onFile,
                    (int) $row['click_id'],
                    $owner
                ));

                return true;
            }
        }

        return false;
    }

    /**
     * What a reversal row took back, in positive units, when the row it nets
     * against is a legacy baseline (the value a click carried from before
     * the ledger), or null when it reverses a sale of its own.
     */
    private function carriedTaken(int $conversionId, int $owner): ?int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT r.click_payout FROM 202_conversion_logs r'
            . ' JOIN 202_conversion_logs s ON s.conv_id = r.reverses_conv_id AND s.user_id = r.user_id'
            . ' WHERE r.conv_id = ? AND r.user_id = ? AND s.dedupe_key = ? LIMIT 1'
        );
        $conn->bind($stmt, 'iis', [$conversionId, $owner, DedupeKey::legacy()]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : abs(Amount::toUnits((string) $row['click_payout']));
    }

    /** The outcome with a sentence added to its answer. */
    private static function noted(Outcome $outcome, string $note): Outcome
    {
        return $note === ''
            ? $outcome
            : new Outcome($outcome->kind, $outcome->message . $note, $outcome->conversionId, $outcome->accountId);
    }

    /**
     * An amount in another currency than the account's: refused, since
     * nothing here converts it, or, from a provider that states every
     * account's amounts in one currency, kept as stated with the answer
     * saying so ('' when the currencies agree or none is stated).
     */
    private function currency(Notification $n, int $owner, ProviderAdapter $provider): Outcome|string
    {
        $accountCurrency = $this->otherCurrency($n, $owner);
        if ($accountCurrency === null) {
            return '';
        }
        if ($provider instanceof RecordsAmountsAsStated) {
            return ' The amount is in ' . strtoupper((string) $n->currency) . ' and this account counts in '
                . $accountCurrency . '; Prosper202 does not convert it, so it stands as stated.';
        }

        return new Outcome(
            OutcomeKind::CURRENCY_MISMATCH,
            'The amount is in ' . strtoupper((string) $n->currency) . ' and this account counts in ' . $accountCurrency
            . '; Prosper202 does not convert it, so nothing was recorded.',
            null,
            $owner
        );
    }

    /** The account's currency when the notification states another, or null. */
    private function otherCurrency(Notification $n, int $owner): ?string
    {
        if ($n->currency === null) {
            return null;
        }
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$owner]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('account ' . $owner . ' has no preferences row to read its currency from');
        }
        $accountCurrency = strtoupper(trim((string) $row['user_account_currency']));
        return strtoupper($n->currency) === $accountCurrency ? null : $accountCurrency;
    }

    private function storedAmount(int $conversionId, int $owner): string
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT click_payout FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$conversionId, $owner]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('conversion ' . $conversionId . ', which the writer named, is not there');
        }

        return (string) $row['click_payout'];
    }

    /**
     * A refund that repeats a reversal on file: a duplicate only when it takes
     * back what that reversal took back (one that states no amount takes the
     * whole sale), and refused naming both otherwise (CLAUDE.md #15). One
     * that is known to be another notification than the reversal's
     * ($another) is refused whatever it states: it is another refund.
     */
    private function repeatedReversal(
        Notification $n,
        string $on,
        int $reversalId,
        int $owner,
        bool $another = false,
        bool $shopifyPartial = false
    ): Outcome {
        $stored = $this->storedReversal($reversalId, $owner);
        // Capped as the reversal was: a refund stating more than its sale took back the whole sale.
        $cap = $shopifyPartial ? $this->shopifyRemainingBefore($reversalId, $owner, $stored['sale']) : $stored['sale'];
        $stated = $n->amount !== null ? min(abs(Amount::toUnits($n->amount)), $cap) : $cap;
        $by = self::units($stored['reversed']);
        if ($another || $stated !== $stored['reversed']) {
            $states = '; this ' . $n->event . ($another ? ' is another notification than that reversal\'s and' : '')
                . ' states ' . ($n->amount ?? 'its full amount, ' . self::units($stored['sale']));
            if ($stored['deleted']) {
                return new Outcome(
                    OutcomeKind::CONFLICT,
                    $on . ' was reversed by ' . $by . ' in conversion ' . $reversalId . ', since deleted, under this '
                    . $n->event . '\'s id' . $states . '. Nothing was changed.',
                    $reversalId,
                    $owner
                );
            }
            $rest = $stored['sale'] - $stored['reversed'];
            $credited = $rest > 0
                ? ', and ' . self::units($rest) . ' of its ' . self::units($stored['sale']) . ' is still credited'
                : '';

            return new Outcome(
                OutcomeKind::CONFLICT,
                $on . ' is already reversed by ' . $by . ($another ? ' in conversion ' . $reversalId : '') . $credited
                . $states
                . ', and a sale is reversed once. Nothing was changed.',
                $reversalId,
                $owner
            );
        }

        return new Outcome(
            OutcomeKind::DUPLICATE,
            $stored['deleted']
                ? $on . ' was reversed by conversion ' . $reversalId . ', since deleted; this ' . $n->event
                    . ' is that refund sent again, so nothing was changed.'
                : $on . ' is already reversed; nothing was changed.',
            $reversalId,
            $owner
        );
    }

    /** Remaining sale amount immediately before this Shopify reversal, conservatively excluding deleted rows. */
    private function shopifyRemainingBefore(int $reversalId, int $owner, int $sale): int
    {
        $conn = new Connection($this->db);
        // The writer just found this reversal on the primary. A lagging replica
        // can otherwise misclassify a retry immediately after it was recorded.
        $stmt = $conn->prepareWrite(
            'SELECT earlier.click_payout FROM 202_conversion_logs current'
            . ' JOIN 202_conversion_logs earlier ON earlier.reverses_conv_id = current.reverses_conv_id'
            . ' AND earlier.user_id = current.user_id AND earlier.conv_id < current.conv_id AND earlier.deleted = 0'
            . ' WHERE current.conv_id = ? AND current.user_id = ?'
        );
        $conn->bind($stmt, 'ii', [$reversalId, $owner]);
        foreach ($conn->fetchAll($stmt) as $row) {
            $sale -= abs(Amount::toUnits((string) $row['click_payout']));
        }

        return max(0, $sale);
    }

    /**
     * The reversal that stands against the sale a transaction id names on a
     * click (the sale the writer reverses: its first row under that id), or
     * null when none does.
     */
    private function liveReversalOfTransaction(int $clickId, string $transactionId, int $owner): ?int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT r.conv_id FROM 202_conversion_logs s'
            . ' JOIN 202_conversion_logs r ON r.reverses_conv_id = s.conv_id AND r.user_id = s.user_id'
            . ' AND r.deleted = 0'
            . ' WHERE s.click_id = ? AND s.transaction_id = ? AND s.reverses_conv_id IS NULL AND s.user_id = ?'
            . ' ORDER BY s.conv_id, r.conv_id LIMIT 1'
        );
        $conn->bind($stmt, 'isi', [$clickId, $transactionId, $owner]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['conv_id'];
    }

    /**
     * How much a reversal on file took back and how much the sale it
     * reverses holds, both as positive units, and whether it was deleted.
     *
     * @return array{deleted: bool, reversed: int, sale: int}
     */
    private function storedReversal(int $conversionId, int $owner): array
    {
        $conn = new Connection($this->db);
        // The duplicate was found on the primary, so read the same database.
        $stmt = $conn->prepareWrite(
            'SELECT r.click_payout AS reversed, r.deleted, s.click_payout AS sale FROM 202_conversion_logs r'
            . ' JOIN 202_conversion_logs s ON s.conv_id = r.reverses_conv_id AND s.user_id = r.user_id'
            . ' WHERE r.conv_id = ? AND r.user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$conversionId, $owner]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException(
                'conversion ' . $conversionId . ', which the writer named, is not a reversal of a sale on file'
            );
        }

        return [
            'deleted' => (int) $row['deleted'] === 1,
            'reversed' => abs(Amount::toUnits((string) $row['reversed'])),
            'sale' => abs(Amount::toUnits((string) $row['sale'])),
        ];
    }

    /**
     * Whether a reversal is deleted and took back another amount than this
     * refund states (capped at the sale, the whole sale when it states none),
     * the comparison repeatedReversal() makes.
     */
    private function deletedReversalOfAnotherAmount(Notification $n, int $reversalId, int $owner): bool
    {
        $stored = $this->storedReversal($reversalId, $owner);
        $stated = $n->amount !== null ? min(abs(Amount::toUnits($n->amount)), $stored['sale']) : $stored['sale'];

        return $stored['deleted'] && $stated !== $stored['reversed'];
    }

    /** The account's row on a click under a dedupe key, deleted or not, or null. */
    private function reversalUnderKey(int $clickId, string $dedupeKey, int $owner): ?int
    {
        $conn = new Connection($this->db);
        // From the primary, as the writer reads its duplicates.
        $stmt = $conn->prepareWrite(
            'SELECT conv_id FROM 202_conversion_logs'
            . ' WHERE click_id = ? AND dedupe_key = ? AND user_id = ? AND reverses_conv_id IS NOT NULL LIMIT 1'
        );
        $conn->bind($stmt, 'isi', [$clickId, $dedupeKey, $owner]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['conv_id'];
    }

    /** The reversal that still nets a conversion, or null when none does. */
    private function liveReversalOf(int $conversionId, int $owner): ?int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT conv_id FROM 202_conversion_logs WHERE reverses_conv_id = ? AND user_id = ? AND deleted = 0'
            . ' ORDER BY conv_id LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$conversionId, $owner]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['conv_id'];
    }

    private static function units(int $units): string
    {
        return self::plain(Amount::fromUnits($units));
    }

    /** A verified notification whose field for the click is empty, in its provider's names. */
    private static function noClick(Notification $n, string $transactionId, ClickField $click, string $so): Outcome
    {
        return new Outcome(
            OutcomeKind::NO_CLICK,
            'Verified. The ' . $n->event . ' notification of transaction ' . $transactionId . ' carries no '
            . $click->field . ' (' . $click->link . ' had no ' . $click->variable . '), so it names no click and '
            . $so . '.'
        );
    }

    /**
     * A click no account whose secret verified the notification has: one of
     * another account reads the same, so the answer tells no key holder which
     * click ids another account has.
     */
    private static function unknownClick(int $clickId, ClickField $click): Outcome
    {
        return new Outcome(
            OutcomeKind::UNKNOWN_CLICK,
            'There is no click ' . $clickId . ' in the account whose secret key verified this notification;'
            . ' nothing was recorded. The click is the notification\'s ' . $click->field . ', which '
            . $click->link . '\'s ' . $click->variable . ' carries when the buyer comes through a tracker of that'
            . ' account.'
        );
    }

    /**
     * The request's body, which a retry sent unchanged repeats: its fields in
     * name order, lists included. A refund without an id of its own is keyed
     * by it unless its provider says what one notification is
     * (FingerprintsNotifications); KeyedRefundsByBodyBefore1980 reads the
     * keys it wrote before.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function bodyFingerprint(array $fields): string
    {
        return hash('sha256', serialize(self::byName($fields)));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function byName(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::byName($item);
            }
        }

        return $value;
    }

    /** A stored decimal(11,5) without its trailing zeros: 47.00000 reads 47. */
    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
