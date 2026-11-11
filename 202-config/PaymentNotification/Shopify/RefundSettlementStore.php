<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use InvalidArgumentException;
use Prosper202\Conversion\Ledger\Amount;
use JsonException;
use Prosper202\Database\Connection;

/** Persists only Shopify transaction identifiers and amounts, never webhook bodies. */
final class RefundSettlementStore
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /** @param list<array{id: string, status: string, amount: string, currency: string}> $transactions */
    public function register(int $userId, string $refundId, string $orderId, int $occurredAt, array $transactions): void
    {
        self::id($refundId);
        self::id($orderId);
        if ($userId <= 0 || $occurredAt < 0 || $transactions === []) {
            throw new InvalidArgumentException('A pending refund needs its account, click, time and transactions.');
        }
        RefundSettlement::resolve($transactions, []);
        usort($transactions, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        $json = json_encode($transactions, JSON_THROW_ON_ERROR);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_shopify_pending_refunds (user_id, refund_id, order_id, click_id, occurred_at, transactions)'
            . ' VALUES (?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE refund_id = refund_id'
        );
        $this->conn->bind($stmt, 'issis', [$userId, $refundId, $orderId, $occurredAt, $json]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite(
            'SELECT order_id, occurred_at, transactions FROM 202_shopify_pending_refunds WHERE user_id = ? AND refund_id = ?'
        );
        $this->conn->bind($stmt, 'is', [$userId, $refundId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || $row['order_id'] !== $orderId
            || (int) $row['occurred_at'] !== $occurredAt || $row['transactions'] !== $json) {
            throw new SettlementConflict(
                'Shopify refund ' . $refundId . ' was sent again with different details; the first is kept.'
            );
        }
    }

    public function update(int $userId, string $orderId, string $transactionId, string $status, string $amount, string $currency, int $receivedAt): void
    {
        self::id($orderId);
        self::id($transactionId);
        if ($userId <= 0 || $receivedAt <= 0 || !in_array($status, ['success', 'failure', 'error'], true)) {
            throw new InvalidArgumentException('A transaction update needs an account, time and terminal status.');
        }
        $currency = strtoupper($currency);
        RefundSettlement::resolve([['id' => $transactionId, 'status' => $status, 'amount' => $amount, 'currency' => $currency]], []);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_shopify_refund_transactions (user_id, transaction_id, order_id, status, amount, currency, received_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE transaction_id = transaction_id'
        );
        $this->conn->bind($stmt, 'isssssi', [$userId, $transactionId, $orderId, $status, $amount, $currency, $receivedAt]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite(
            'SELECT order_id, status, amount, currency FROM 202_shopify_refund_transactions WHERE user_id = ? AND transaction_id = ?'
        );
        $this->conn->bind($stmt, 'is', [$userId, $transactionId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || $row['order_id'] !== $orderId || $row['status'] !== $status
            || Amount::toUnits((string) $row['amount']) !== Amount::toUnits($amount) || $row['currency'] !== $currency) {
            throw new SettlementConflict(
                'Shopify transaction ' . $transactionId . ' was sent again with different details; the first is kept.'
            );
        }
    }

    /**
     * A refund whose own rows disagree is answered with `refused` set, so it
     * fails alone and the order's other refunds are still settled.
     *
     * @return list<array{refund_id: string, order_id: string, occurred_at: int, refused: ?string,
     *     pending: bool, amount: string, currency: ?string}>
     */
    public function forOrder(int $userId, string $orderId): array
    {
        self::id($orderId);
        $stmt = $this->conn->prepareWrite(
            'SELECT transaction_id, status, amount, currency FROM 202_shopify_refund_transactions WHERE user_id = ? AND order_id = ?'
        );
        $this->conn->bind($stmt, 'is', [$userId, $orderId]);
        $updates = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $updates[$row['transaction_id']] = ['status' => $row['status'], 'amount' => $row['amount'], 'currency' => $row['currency']];
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT refund_id, order_id, occurred_at, transactions FROM 202_shopify_pending_refunds'
            . ' WHERE user_id = ? AND order_id = ? AND completed_at IS NULL ORDER BY refund_id'
        );
        $this->conn->bind($stmt, 'is', [$userId, $orderId]);
        $result = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $refused = null;
            try {
                $transactions = json_decode($row['transactions'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($transactions)) {
                    throw new InvalidArgumentException('The stored refund transactions are not a list.');
                }
                $resolved = RefundSettlement::resolve($transactions, $updates);
            } catch (InvalidArgumentException | JsonException $e) {
                $refused = $e->getMessage();
                $resolved = ['pending' => false, 'amount' => Amount::fromUnits(0), 'currency' => null];
            }
            $result[] = [
                'refund_id' => $row['refund_id'], 'order_id' => $row['order_id'],
                'occurred_at' => (int) $row['occurred_at'], 'refused' => $refused,
            ] + $resolved;
        }

        return $result;
    }

    /** Closes a refund; $refused says why it was closed without a reversal. */
    public function complete(int $userId, string $refundId, int $now, ?string $refused = null): void
    {
        self::id($refundId);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_shopify_pending_refunds SET completed_at = ?, refused_reason = ?'
            . ' WHERE user_id = ? AND refund_id = ? AND completed_at IS NULL'
        );
        $this->conn->bind($stmt, 'isis', [$now, self::reason($refused), $userId, $refundId]);
        $this->conn->executeUpdate($stmt);
    }

    /** A sale of the order is on file, so its refunds that are not recorded overstate it. */
    public function orderOnFile(int $userId, string $orderId): void
    {
        self::id($orderId);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_shopify_pending_refunds SET order_on_file = 1'
            . ' WHERE user_id = ? AND order_id = ? AND order_on_file = 0'
        );
        $this->conn->bind($stmt, 'is', [$userId, $orderId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * A refund refused outright, kept closed with its reason as one line
     * stating what it returned. One already kept takes the new reason.
     */
    public function refuse(
        int $userId,
        string $refundId,
        string $orderId,
        int $occurredAt,
        string $amount,
        ?string $currency,
        string $reason,
        bool $orderOnFile,
        int $now
    ): void {
        self::id($refundId);
        self::id($orderId);
        if ($userId <= 0 || $occurredAt < 0 || $now <= 0 || preg_match('/^\d{1,6}(?:\.\d{1,5})?$/D', $amount) !== 1) {
            throw new InvalidArgumentException('A refused refund needs its account, time and amount.');
        }
        $json = json_encode([[
            'id' => $refundId, 'status' => 'success', 'amount' => $amount, 'currency' => strtoupper((string) $currency),
        ]], JSON_THROW_ON_ERROR);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_shopify_pending_refunds'
            . ' (user_id, refund_id, order_id, click_id, occurred_at, transactions, completed_at, refused_reason,'
            . ' order_on_file)'
            . ' VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE'
            . ' completed_at = COALESCE(completed_at, VALUES(completed_at)), refused_reason = VALUES(refused_reason),'
            . ' order_on_file = GREATEST(order_on_file, VALUES(order_on_file))'
        );
        $this->conn->bind($stmt, 'issisisi', [
            $userId, $refundId, $orderId, $occurredAt, $json, $now, self::reason($reason), $orderOnFile ? 1 : 0,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Closes a refund an operator settled or closed by hand: nothing is left
     * to list. Answers whether the refund was one still listed.
     */
    public function settledByHand(int $userId, string $refundId, int $now): bool
    {
        self::id($refundId);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_shopify_pending_refunds SET completed_at = COALESCE(completed_at, ?), refused_reason = NULL'
            . ' WHERE user_id = ? AND refund_id = ? AND (completed_at IS NULL OR refused_reason IS NOT NULL)'
        );
        $this->conn->bind($stmt, 'iis', [$now, $userId, $refundId]);

        return $this->conn->executeUpdate($stmt) > 0;
    }

    private static function reason(?string $reason): ?string
    {
        return $reason === null ? null : mb_substr($reason, 0, 500, 'UTF-8');
    }

    private static function id(string $id): void
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Expected a positive Shopify id.');
        }
    }
}
