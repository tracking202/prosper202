<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Processor;
use Prosper202\PaymentNotification\Verification;

/** Reconciles authenticated Shopify refund and terminal transaction events. */
final class SettlementCoordinator
{
    private readonly Connection $conn;
    private readonly RefundSettlementStore $store;

    public function __construct(private readonly \mysqli $db, private readonly ShopifyAdapter $provider)
    {
        $this->conn = new Connection($db);
        $this->store = new RefundSettlementStore($this->conn);
    }

    /** @param array<string, mixed> $event */
    public function accept(array $event, Verification $verification, InboundRequest $request): Outcome
    {
        foreach ($verification->accounts() as $account) {
            if ($event['type'] === 'refund') {
                $this->store->register($account, $event['id'], $event['order_id'], 0,
                    $event['occurred_at'], $event['transactions']);
            } else {
                $this->store->update($account, $event['order_id'], $event['id'], $event['status'],
                    $event['amount'], $event['currency'], time());
            }
        }
        $refusal = null;
        foreach ($verification->accounts() as $account) {
            $outcome = $this->reconcile($account, $event['order_id'], $request);
            if ($outcome !== null) {
                $refusal ??= $outcome;
            }
        }

        if ($refusal !== null) {
            return $refusal;
        }

        return new Outcome(OutcomeKind::IGNORED,
            'Verified. Refund settlement is saved and will be reconciled when its transaction and order are available.',
            null, count($verification->accounts()) === 1 ? $verification->accounts()[0] : null);
    }

    /** Returns an operator-visible refusal or failure, otherwise null. */
    public function reconcile(int $account, string $orderId, InboundRequest $request): ?Outcome
    {
        foreach ($this->store->forOrder($account, $orderId) as $refund) {
            if ($refund['pending']) {
                continue;
            }
            if (Amount::toUnits($refund['amount']) === 0) {
                $this->store->complete($account, $refund['refund_id'], time());
                continue;
            }
            $stmt = $this->conn->prepareWrite(
                'SELECT DISTINCT click_id FROM 202_conversion_logs WHERE user_id = ? AND transaction_id = ?'
                . ' AND source = ? AND reverses_conv_id IS NULL AND deleted = 0'
            );
            $this->conn->bind($stmt, 'iss', [$account, $orderId, ConversionSource::SHOPIFY->value]);
            $clicks = $this->conn->fetchAll($stmt);
            if ($clicks === []) {
                continue;
            }
            if (count($clicks) !== 1) {
                return new Outcome(OutcomeKind::CONFLICT,
                    'Pending Shopify refund ' . $refund['refund_id'] . ' matches more than one order click; reconcile it manually.',
                    null, $account);
            }
            $notification = new Notification(NotificationKind::REFUND, 'refunds/create', $refund['refund_id'],
                $orderId, $refund['amount'], $refund['currency'], (int) $clicks[0]['click_id'],
                $refund['occurred_at'] ?: null);
            $outcome = (new Processor($this->db))->process($notification, new Verification([$account => 0]),
                $this->provider, $request);
            if (!in_array($outcome->kind, [OutcomeKind::REVERSED, OutcomeKind::DUPLICATE], true)) {
                return $outcome;
            }
            $this->store->complete($account, $refund['refund_id'], time());
        }

        return null;
    }
}
