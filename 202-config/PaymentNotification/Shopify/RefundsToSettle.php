<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Processor;
use Prosper202\PaymentNotification\Verification;

/**
 * An account's Shopify refunds that are not recorded while their order's
 * sale is: the ones waiting for a transaction to settle and the ones refused
 * for good. Each overstates the sale by what it returned until an operator
 * records it (through the processor every refund webhook goes through) or
 * closes it. Setup › Inbound Log lists them and the Shopify card counts them.
 */
final class RefundsToSettle
{
    /** A refund waiting longer than this is flagged: Shopify settles most within a day. */
    public const STALE_AFTER = 86400;
    public const LIMIT = 200;

    /** Where a refund was settled from, as the inbound log names it. */
    public const THE_PAGE = 'Setup › Inbound Log';
    public const THE_API = 'the API';

    private const AMOUNT = '/^\d{1,6}(?:\.\d{1,5})?$/D';

    private readonly Connection $conn;

    public function __construct(private readonly \mysqli $db)
    {
        $this->conn = new Connection($db);
    }

    /**
     * The oldest LIMIT refunds first. A refund whose order has no sale on
     * file any more (deleted since) is left out: nothing is overstated.
     *
     * @return array{
     *     refunds: list<array{refund_id: string, order_id: string, occurred_at: int, waiting: bool, stale: bool,
     *         reason: string, amount: string, currency: string, clicks: list<int>}>,
     *     more: bool,
     *     transaction_webhook_seen: bool
     * }
     */
    public function list(int $account, ?int $now = null): array
    {
        $now ??= time();
        $stmt = $this->conn->prepareRead(
            'SELECT refund_id, order_id, occurred_at, transactions, completed_at, refused_reason'
            . ' FROM 202_shopify_pending_refunds WHERE user_id = ? AND order_on_file = 1'
            . ' AND (completed_at IS NULL OR refused_reason IS NOT NULL) ORDER BY occurred_at, refund_id LIMIT '
            . (self::LIMIT + 1)
        );
        $this->conn->bind($stmt, 'i', [$account]);
        $rows = $this->conn->fetchAll($stmt);
        $more = count($rows) > self::LIMIT;
        $rows = array_slice($rows, 0, self::LIMIT);
        $clicks = $this->clicksByOrder($account, array_column($rows, 'order_id'));
        $failed = $this->failedTransactions($account, array_column($rows, 'order_id'));
        $refunds = [];
        foreach ($rows as $row) {
            $orderId = (string) $row['order_id'];
            if (!isset($clicks[$orderId])) {
                continue;
            }
            $waiting = $row['completed_at'] === null;
            $at = (int) $row['occurred_at'];
            $refunds[] = [
                'refund_id' => (string) $row['refund_id'],
                'order_id' => $orderId,
                'occurred_at' => $at,
                'waiting' => $waiting,
                'stale' => $waiting && $at > 0 && $now - $at > self::STALE_AFTER,
                'reason' => (string) ($row['refused_reason'] ?? ''),
                'clicks' => $clicks[$orderId],
            ] + self::stated((string) $row['transactions'], $failed);
        }

        return ['refunds' => $refunds, 'more' => $more, 'transaction_webhook_seen' => $this->transactionSeen($account)];
    }

    /**
     * Records the refund as a reversal of its order's sale, by the amount the
     * operator states in the account's currency, capped at what is left of
     * the sale as a refund webhook is, and closes it. Recorded once under
     * the refund's id, so the webhook settling later, or a second click of
     * the button, is that same reversal.
     *
     * @param int|null $clickId the sale's click, needed only when the order is on more than one
     */
    public function takeBack(
        int $account,
        string $refundId,
        string $amount,
        ?int $clickId,
        InboundRequest $request,
        string $from = self::THE_PAGE
    ): Outcome {
        $row = $this->listed($account, $refundId);
        if ($row === null) {
            return new Outcome(
                OutcomeKind::NOTHING_TO_REVERSE,
                'That refund is not one waiting to be recorded; it was recorded or closed already.',
                null,
                $account
            );
        }
        if (preg_match(self::AMOUNT, $amount) !== 1 || Amount::toUnits($amount) === 0) {
            return new Outcome(
                OutcomeKind::MALFORMED,
                'The amount is what the refund returned, such as 12.50: more than 0, at most 999999.99999.',
                null,
                $account
            );
        }
        $orderId = (string) $row['order_id'];
        $clicks = $this->clicksByOrder($account, [$orderId])[$orderId] ?? [];
        if ($clicks === []) {
            return new Outcome(
                OutcomeKind::NOTHING_TO_REVERSE,
                'Order ' . $orderId . ' has no sale recorded here any more, so there is nothing to take the refund'
                . ' from.'
                . ' Close the refund instead.',
                null,
                $account
            );
        }
        if (count($clicks) > 1 && !in_array($clickId, $clicks, true)) {
            return new Outcome(
                OutcomeKind::MALFORMED,
                'Order ' . $orderId . ' is recorded on clicks ' . implode(', ', $clicks)
                . '; choose the one this refund takes back from.',
                null,
                $account
            );
        }
        $click = count($clicks) === 1 ? $clicks[0] : (int) $clickId;
        $at = (int) $row['occurred_at'];
        $notification = new Notification(
            NotificationKind::REFUND,
            'refund recorded by hand',
            $refundId,
            $orderId,
            $amount,
            null,
            $click,
            $at > 0 ? $at : null
        );
        $outcome = (new Processor($this->db))->process(
            $notification,
            new Verification([$account => 0]),
            new ShopifyAdapter($this->db),
            $request
        );
        if (in_array($outcome->kind, [OutcomeKind::REVERSED, OutcomeKind::DUPLICATE], true)) {
            (new RefundSettlementStore($this->conn))->settledByHand($account, $refundId, time());
        }
        $this->log($account, $outcome, 'Recorded by hand from ' . $from . '. ', $refundId, $click);

        return $outcome;
    }

    /** Closes the refund with nothing recorded (no money was returned, or it is handled elsewhere). */
    public function close(int $account, string $refundId, string $from = self::THE_PAGE): bool
    {
        if (
            $this->listed($account, $refundId) === null
            || !(new RefundSettlementStore($this->conn))->settledByHand($account, $refundId, time())
        ) {
            return false;
        }
        $this->log(
            $account,
            new Outcome(OutcomeKind::IGNORED, 'Nothing was recorded.', null, $account),
            'Closed by hand from ' . $from . '. ',
            $refundId,
            null
        );

        return true;
    }

    private function log(int $account, Outcome $outcome, string $lead, string $refundId, ?int $click): void
    {
        (new InboundLog($this->db))->append(new Entry(
            'shopify',
            'HAND',
            200,
            $outcome->kind->value,
            $lead . $outcome->message,
            $account,
            $click,
            $outcome->conversionId,
            $refundId,
            ['topic' => 'refund ' . $refundId . ' by hand']
        ));
    }

    /** @return array<string, mixed>|null the refund when it is still one to settle */
    public function listed(int $account, string $refundId): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $refundId) !== 1) {
            return null;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT order_id, occurred_at FROM 202_shopify_pending_refunds WHERE user_id = ? AND refund_id = ?'
            . ' AND (completed_at IS NULL OR refused_reason IS NOT NULL)'
        );
        $this->conn->bind($stmt, 'is', [$account, $refundId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * @param list<string> $orderIds
     * @return array<string, list<int>> the clicks each order's Shopify sale is recorded on
     */
    private function clicksByOrder(int $account, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('strval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($orderIds), '?'));
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT transaction_id, click_id FROM 202_conversion_logs WHERE user_id = ? AND source = ?'
            . ' AND reverses_conv_id IS NULL AND deleted = 0 AND transaction_id IN (' . $in . ')'
            . ' ORDER BY click_id'
        );
        $this->conn->bind(
            $stmt,
            'is' . str_repeat('s', count($orderIds)),
            [$account, ConversionSource::SHOPIFY->value, ...$orderIds]
        );
        $clicks = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $clicks[(string) $row['transaction_id']][] = (int) $row['click_id'];
        }

        return $clicks;
    }

    /** Whether Shopify's Transaction creation webhook has ever settled a refund transaction for the account. */
    private function transactionSeen(int $account): bool
    {
        $stmt = $this->conn->prepareRead(
            'SELECT transaction_id FROM 202_shopify_refund_transactions WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$account]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * The orders' refund transactions Shopify has since reported as failed.
     *
     * @param list<string> $orderIds
     * @return array<string, true> by transaction id
     */
    private function failedTransactions(int $account, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('strval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($orderIds), '?'));
        $stmt = $this->conn->prepareRead(
            'SELECT transaction_id FROM 202_shopify_refund_transactions WHERE user_id = ?'
            . " AND status IN ('failure', 'error') AND order_id IN (" . $in . ')'
        );
        $this->conn->bind($stmt, 'i' . str_repeat('s', count($orderIds)), [$account, ...$orderIds]);
        $failed = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $failed[(string) $row['transaction_id']] = true;
        }

        return $failed;
    }

    /**
     * What the refund returns as far as is known: its transactions added
     * up, less the ones that failed, when the refund arrived or since
     * ($failed). Stored transactions that cannot be read state nothing.
     *
     * @param array<string, true> $failed
     * @return array{amount: string, currency: string}
     */
    private static function stated(string $transactions, array $failed): array
    {
        $units = 0;
        $currency = '';
        $list = json_decode($transactions, true, 64);
        foreach (is_array($list) ? $list : [] as $transaction) {
            $amount = is_array($transaction) ? ($transaction['amount'] ?? null) : null;
            if (
                !is_string($amount) || preg_match(self::AMOUNT, $amount) !== 1
                || in_array($transaction['status'] ?? null, ['failure', 'error'], true)
                || isset($failed[(string) ($transaction['id'] ?? '')])
            ) {
                continue;
            }
            $units += Amount::toUnits($amount);
            if ($currency === '' && is_string($transaction['currency'] ?? null)) {
                $currency = $transaction['currency'];
            }
        }
        $amount = Amount::fromUnits($units);

        return [
            'amount' => str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount,
            'currency' => $currency,
        ];
    }
}
