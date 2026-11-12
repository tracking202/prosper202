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
        $active = [];
        $refusal = null;
        foreach ($verification->accounts() as $account) {
            // Data that disagrees with what is stored is refused, not retried.
            try {
                $saved = $this->save($account, $event);
            } catch (SettlementConflict $e) {
                $refusal ??= new Outcome(OutcomeKind::CONFLICT, $e->getMessage(), null, $account);
                continue;
            } catch (\InvalidArgumentException $e) {
                $refusal ??= new Outcome(OutcomeKind::MALFORMED, $e->getMessage(), null, $account);
                continue;
            }
            if ($saved) {
                $active[] = $account;
            }
        }
        $settled = null;
        foreach ($active as $account) {
            $settled = self::worse($settled, $this->reconcile($account, $event['order_id'], $request));
        }
        $outcome = self::worse($refusal, $settled);
        if ($outcome !== null) {
            return $outcome;
        }

        return new Outcome(OutcomeKind::IGNORED,
            $active === [] ? 'Verified, but the account is no longer active; nothing was saved.'
                : 'Verified. Refund settlement is saved and will be reconciled when its transaction and order are available.',
            null, count($active) === 1 ? $active[0] : null);
    }

    /**
     * A sale's answer with what became of the order's pending refunds. The
     * sale has landed, so it keeps its conversion; only a failure a retry can
     * mend changes the status, and Shopify sends the order again.
     */
    public function afterSale(Outcome $sale, string $orderId, InboundRequest $request): Outcome
    {
        $this->store->orderOnFile((int) $sale->accountId, $orderId);
        $settled = $this->reconcile((int) $sale->accountId, $orderId, $request);
        if ($settled === null) {
            return $sale;
        }
        $kind = self::rank($settled->kind) === 2 ? $settled->kind : $sale->kind;
        $message = $sale->message . ' A pending refund of this order: ' . $settled->message;

        return new Outcome($kind, $message, $sale->conversionId, $sale->accountId);
    }

    /**
     * The purge locks this same row before deleting settlement data. Verify
     * and write while holding it so an in-flight webhook cannot recreate the
     * deleted account's rows after the purge commits.
     *
     * @param array<string, mixed> $event
     */
    private function save(int $account, array $event): bool
    {
        return $this->conn->transaction(function () use ($account, $event): bool {
            $stmt = $this->conn->prepareWrite('SELECT user_deleted FROM 202_users WHERE user_id = ? FOR UPDATE');
            $this->conn->bind($stmt, 'i', [$account]);
            $user = $this->conn->fetchOne($stmt);
            if ($user === null || (int) $user['user_deleted'] !== 0) {
                return false;
            }
            if ($event['type'] === 'refund') {
                $this->store->register($account, $event['id'], $event['order_id'],
                    $event['occurred_at'], $event['transactions']);
            } else {
                $this->store->update($account, $event['order_id'], $event['id'], $event['status'],
                    $event['amount'], $event['currency'], time());
            }

            return true;
        });
    }

    /**
     * Settles every refund of the order that is ready and answers the one
     * outcome most worth showing: a failure a retry can mend, else a refusal,
     * else a recorded reversal, else null (nothing was ready). A refund that
     * is refused for good is closed, so it fails once and alone.
     */
    public function reconcile(int $account, string $orderId, InboundRequest $request): ?Outcome
    {
        $shown = null;
        $refunds = $this->store->forOrder($account, $orderId);
        if ($refunds === []) {
            return null;
        }
        $clicks = $this->clicksOf($account, $orderId);
        if ($clicks !== []) {
            $this->store->orderOnFile($account, $orderId);
        }
        foreach ($refunds as $refund) {
            if ($refund['refused'] !== null) {
                $this->store->complete($account, $refund['refund_id'], time(), $refund['refused']);
                $message = 'Pending Shopify refund ' . $refund['refund_id'] . ' of order ' . $orderId
                    . ' was not recorded and will not be tried again: ' . $refund['refused'] . self::BY_HAND;
                $shown = self::worse($shown, new Outcome(OutcomeKind::CONFLICT, $message, null, $account));
                continue;
            }
            if ($refund['pending']) {
                continue;
            }
            if (Amount::toUnits($refund['amount']) === 0) {
                $this->store->complete($account, $refund['refund_id'], time());
                continue;
            }
            if ($clicks === []) {
                continue;
            }
            if (count($clicks) !== 1) {
                $why = 'the order is on more than one click.';
                $this->store->complete($account, $refund['refund_id'], time(), $why);
                $message = 'Pending Shopify refund ' . $refund['refund_id'] . ' of order ' . $orderId
                    . ' was not recorded and will not be tried again: ' . $why . self::BY_HAND;
                $shown = self::worse($shown, new Outcome(OutcomeKind::CONFLICT, $message, null, $account));
                continue;
            }
            $notification = new Notification(NotificationKind::REFUND, 'refunds/create', $refund['refund_id'],
                $orderId, $refund['amount'], $refund['currency'], $clicks[0],
                $refund['occurred_at'] ?: null);
            $outcome = (new Processor($this->db))->process($notification, new Verification([$account => 0]),
                $this->provider, $request);
            // Refused because this refund is on file already, at another
            // amount: it is recorded, and closed so, never listed as refused.
            if ($outcome->kind === OutcomeKind::CONFLICT && $this->isLiveReversal($account, $outcome->conversionId, (string) $refund['refund_id'])) {
                $this->store->complete($account, $refund['refund_id'], time());
                continue;
            }
            // A failure a retry can mend leaves the refund open for the retry.
            if (self::rank($outcome->kind) !== 2) {
                $refusal = self::rank($outcome->kind) === 1 ? $outcome->message : null;
                $this->store->complete($account, $refund['refund_id'], time(), $refusal);
            }
            if ($outcome->kind !== OutcomeKind::DUPLICATE) {
                $shown = self::worse($shown, $outcome);
            }
        }

        return $shown;
    }

    /**
     * Keeps a refund that was refused outright (none of its transactions was
     * pending) where Setup › Inbound Log lists it, when no retry can mend it.
     *
     * @param array{id: string, order_id: string, occurred_at: int, amount: string, currency: ?string} $refund
     */
    public function keepRefused(array $refund, Outcome $outcome): void
    {
        if ($outcome->accountId === null || self::rank($outcome->kind) !== 1) {
            return;
        }
        $account = $outcome->accountId;
        // Refused because it is on file already, at another amount: it is
        // recorded, and listing it as not recorded would say otherwise.
        if ($outcome->kind === OutcomeKind::CONFLICT && $this->isLiveReversal($account, $outcome->conversionId, $refund['id'])) {
            return;
        }
        $onFile = $this->clicksOf($account, $refund['order_id']) !== [];
        $this->store->refuse(
            $account,
            $refund['id'],
            $refund['order_id'],
            $refund['occurred_at'],
            $refund['amount'],
            $refund['currency'],
            $outcome->message,
            $onFile,
            time()
        );
        // A sale that landed between the read and the insert marked no row
        // (afterSale() runs once it has committed): read again, now the row is there.
        if (!$onFile && $this->clicksOf($account, $refund['order_id']) !== []) {
            $this->store->orderOnFile($account, $refund['order_id']);
        }
    }

    /** Whether $conversionId is this refund's reversal, of the account's, still on file. */
    private function isLiveReversal(int $account, ?int $conversionId, string $refundId): bool
    {
        if ($conversionId === null || $conversionId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            // Keyed by the refund's own id (DedupeKey::reversal(): rev:<sale>:<refund id>).
            'SELECT 1 FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? AND reverses_conv_id IS NOT NULL'
            . " AND dedupe_key = CONCAT('rev:', reverses_conv_id, ':', ?) AND deleted = 0 LIMIT 1"
        );
        $this->conn->bind($stmt, 'iis', [$conversionId, $account, $refundId]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * The clicks the order's Shopify sale is recorded on, for the account.
     *
     * @return list<int>
     */
    public function clicksOf(int $account, string $orderId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT click_id FROM 202_conversion_logs WHERE user_id = ? AND transaction_id = ?'
            . ' AND source = ? AND reverses_conv_id IS NULL AND deleted = 0 ORDER BY click_id'
        );
        $this->conn->bind($stmt, 'iss', [$account, $orderId, ConversionSource::SHOPIFY->value]);

        return array_map(static fn (array $row): int => (int) $row['click_id'], $this->conn->fetchAll($stmt));
    }

    private const BY_HAND = ' Its amount stays counted in the sale. Check the refund in Shopify, then record or close'
        . ' it under Setup › Inbound Log, which lists it.';

    /** 2: a retry can mend it. 1: refused for good. 0: recorded. */
    private static function rank(OutcomeKind $kind): int
    {
        return match ($kind) {
            OutcomeKind::REVERSED, OutcomeKind::DUPLICATE => 0,
            OutcomeKind::CONFLICT, OutcomeKind::CURRENCY_MISMATCH, OutcomeKind::MALFORMED => 1,
            default => 2,
        };
    }

    private static function worse(?Outcome $a, ?Outcome $b): ?Outcome
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return self::rank($b->kind) > self::rank($a->kind) ? $b : $a;
    }
}
