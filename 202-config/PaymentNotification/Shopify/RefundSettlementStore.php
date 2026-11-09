<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use InvalidArgumentException;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;
use RuntimeException;

/** Persists only Shopify transaction identifiers and amounts, never webhook bodies. */
final class RefundSettlementStore
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /** @param list<array{id: string, status: string, amount: string, currency: string}> $transactions */
    public function register(int $userId, string $refundId, string $orderId, int $clickId, int $occurredAt, array $transactions): void
    {
        self::id($refundId);
        self::id($orderId);
        if ($userId <= 0 || $clickId < 0 || $occurredAt < 0 || $transactions === []) {
            throw new InvalidArgumentException('A pending refund needs its account, click, time and transactions.');
        }
        RefundSettlement::resolve($transactions, []);
        usort($transactions, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        $json = json_encode($transactions, JSON_THROW_ON_ERROR);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_shopify_pending_refunds (user_id, refund_id, order_id, click_id, occurred_at, transactions)'
            . ' VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE refund_id = refund_id'
        );
        $this->conn->bind($stmt, 'issiis', [$userId, $refundId, $orderId, $clickId, $occurredAt, $json]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite(
            'SELECT order_id, click_id, occurred_at, transactions FROM 202_shopify_pending_refunds WHERE user_id = ? AND refund_id = ?'
        );
        $this->conn->bind($stmt, 'is', [$userId, $refundId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || $row['order_id'] !== $orderId || (int) $row['click_id'] !== $clickId
            || (int) $row['occurred_at'] !== $occurredAt || $row['transactions'] !== $json) {
            throw new RuntimeException('A Shopify refund id was repeated with different details.');
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
            throw new RuntimeException('A Shopify transaction id was repeated with different details.');
        }
    }

    /** @return list<array{refund_id: string, order_id: string, click_id: int, occurred_at: int, pending: bool, amount: string, currency: ?string}> */
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
            'SELECT refund_id, order_id, click_id, occurred_at, transactions FROM 202_shopify_pending_refunds'
            . ' WHERE user_id = ? AND order_id = ? AND completed_at IS NULL ORDER BY refund_id'
        );
        $this->conn->bind($stmt, 'is', [$userId, $orderId]);
        $result = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $transactions = json_decode($row['transactions'], true, 64, JSON_THROW_ON_ERROR);
            $result[] = [
                'refund_id' => $row['refund_id'], 'order_id' => $row['order_id'],
                'click_id' => (int) $row['click_id'], 'occurred_at' => (int) $row['occurred_at'],
            ] + RefundSettlement::resolve($transactions, $updates);
        }

        return $result;
    }

    public function complete(int $userId, string $refundId, int $now): void
    {
        self::id($refundId);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_shopify_pending_refunds SET completed_at = ? WHERE user_id = ? AND refund_id = ? AND completed_at IS NULL'
        );
        $this->conn->bind($stmt, 'iis', [$now, $userId, $refundId]);
        $this->conn->executeUpdate($stmt);
    }

    private static function id(string $id): void
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Expected a positive Shopify id.');
        }
    }
}
