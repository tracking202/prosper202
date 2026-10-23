<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ReversalException;
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
 * (CLAUDE.md #15).
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
        if ($notification->kind === NotificationKind::TEST) {
            return new Outcome(
                OutcomeKind::TEST,
                'Verified. This is a test-mode ' . $event . ' notification, so nothing was recorded.'
            );
        }
        if ($notification->kind === NotificationKind::IGNORED) {
            return new Outcome(
                OutcomeKind::IGNORED,
                'Verified. Prosper202 records sales and refunds; a ' . $event . ' notification records nothing.'
            );
        }

        try {
            return $notification->kind->records()
                ? $this->sale($notification, $verification, $provider, $request)
                : $this->reversal($notification, $verification, $provider, $request);
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
            return new Outcome(
                OutcomeKind::NO_CLICK,
                'Verified. Transaction ' . $transactionId . ' names no click (the checkout link carried no subid),'
                . ' so nothing was recorded.'
            );
        }
        $owner = $this->owner($n->clickId, $verification);
        if ($owner === null) {
            return self::unknownClick($n->clickId);
        }
        $currency = $this->currencyRefusal($n, $owner);
        if ($currency !== null) {
            return $currency;
        }

        $result = $this->record($n->clickId, $owner, $provider, $request, [
            'transaction_id' => $transactionId,
            'use_pixel_payout' => true,
            'payout' => $amount,
        ]);
        $on = 'Transaction ' . $transactionId . ' on click ' . $n->clickId;
        if ($result['recorded']) {
            return new Outcome(
                OutcomeKind::RECORDED,
                'Recorded transaction ' . $transactionId . ' on click ' . $n->clickId . ' at ' . $amount . '.',
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'duplicate') {
            $stored = $this->storedAmount($result['conv_id'], $owner);
            if (Amount::toUnits($stored) !== Amount::toUnits($amount)) {
                return new Outcome(
                    OutcomeKind::CONFLICT,
                    'Transaction ' . $transactionId . ' is already recorded on click ' . $n->clickId . ' at '
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
                    'Transaction ' . $transactionId . ' on click ' . $n->clickId . ' is recorded and reversed by'
                    . ' conversion ' . $reversal . '; this ' . $n->event . ' states the sale again. Nothing was'
                    . ' changed: if the refund was undone, delete conversion ' . $reversal . ' to count the sale.',
                    $result['conv_id'],
                    $owner
                );
            }

            return new Outcome(
                OutcomeKind::DUPLICATE,
                'Transaction ' . $transactionId . ' is already recorded on click ' . $n->clickId
                . '; nothing was changed.',
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'unknown_click' || $result['reason'] === 'foreign_click') {
            return self::unknownClick($n->clickId);
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
            return new Outcome(
                OutcomeKind::NO_CLICK,
                'Verified. The ' . $n->event . ' of transaction ' . $original . ' names no click (the checkout'
                . ' link carried no subid), so there is no sale to reverse.'
            );
        }
        $owner = $this->owner($n->clickId, $verification);
        if ($owner === null) {
            return self::unknownClick($n->clickId);
        }
        $currency = $this->currencyRefusal($n, $owner);
        if ($currency !== null) {
            return $currency;
        }

        // A refund with an id of its own is told apart from another refund
        // of the same sale by it; without one, a sale is reversed once.
        $reference = $n->transactionId !== null && $n->transactionId !== $original ? $n->transactionId : '';
        try {
            $result = $this->record($n->clickId, $owner, $provider, $request, [
                'transaction_id' => $original,
                'reversal' => true,
                'reversal_ref' => $reference,
                'use_pixel_payout' => $n->amount !== null,
                'payout' => $n->amount ?? '',
            ]);
        } catch (ReversalException $e) {
            return new Outcome(
                $e->kind === ReversalException::CONFLICT ? OutcomeKind::CONFLICT : OutcomeKind::NOTHING_TO_REVERSE,
                $e->getMessage() . ' Nothing was changed.',
                null,
                $owner
            );
        }
        $on = 'Transaction ' . $original . ' on click ' . $n->clickId;
        if ($result['recorded']) {
            return new Outcome(
                OutcomeKind::REVERSED,
                'Reversed transaction ' . $original . ' on click ' . $n->clickId . ' by '
                . ($n->amount ?? 'its full amount') . '.',
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'duplicate') {
            // A refund that states no amount takes back the whole sale, so it
            // repeats the reversal on file only when that one took it all too.
            $stored = $this->storedReversal($result['conv_id'], $owner);
            $stated = $n->amount !== null ? Amount::toUnits($n->amount) : $stored['sale'];
            if ($stated !== $stored['reversed']) {
                $rest = $stored['sale'] - $stored['reversed'];
                $credited = $rest > 0
                    ? ', and ' . self::units($rest) . ' of its ' . self::units($stored['sale']) . ' is still credited'
                    : '';

                return new Outcome(
                    OutcomeKind::CONFLICT,
                    $on . ' is already reversed by ' . self::units($stored['reversed']) . $credited
                    . '; this ' . $n->event . ' states '
                    . ($n->amount ?? 'its full amount, ' . self::units($stored['sale']))
                    . ', and a sale is reversed once. Nothing was changed.',
                    $result['conv_id'],
                    $owner
                );
            }

            return new Outcome(
                OutcomeKind::DUPLICATE,
                $on . ' is already reversed; nothing was changed.',
                $result['conv_id'],
                $owner
            );
        }
        if ($result['reason'] === 'unknown_click' || $result['reason'] === 'foreign_click') {
            return self::unknownClick($n->clickId);
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

        return p202RecordLegacyConversion($this->db, $clickId, 2, [
            'user_id' => $owner,
            'ip' => $request->senderAddress,
            'user_agent' => $request->userAgent,
            'source' => $provider->source()->value,
        ] + $what);
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

    /** An amount in another currency is refused: nothing here converts it. */
    private function currencyRefusal(Notification $n, int $owner): ?Outcome
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
        if (strtoupper($n->currency) === $accountCurrency) {
            return null;
        }

        return new Outcome(
            OutcomeKind::CURRENCY_MISMATCH,
            'The amount is in ' . strtoupper($n->currency) . ' and this account counts in ' . $accountCurrency
            . '; Prosper202 does not convert it, so nothing was recorded.',
            null,
            $owner
        );
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
     * How much a reversal on file took back and how much the sale it
     * reverses holds, both as positive units.
     *
     * @return array{reversed: int, sale: int}
     */
    private function storedReversal(int $conversionId, int $owner): array
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT r.click_payout AS reversed, s.click_payout AS sale FROM 202_conversion_logs r'
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
            'reversed' => abs(Amount::toUnits((string) $row['reversed'])),
            'sale' => abs(Amount::toUnits((string) $row['sale'])),
        ];
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

    private static function unknownClick(int $clickId): Outcome
    {
        return new Outcome(
            OutcomeKind::UNKNOWN_CLICK,
            'There is no click ' . $clickId . ' in the account whose secret key signed this notification;'
            . ' nothing was recorded.'
        );
    }

    /** A stored decimal(11,5) without its trailing zeros: 47.00000 reads 47. */
    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
