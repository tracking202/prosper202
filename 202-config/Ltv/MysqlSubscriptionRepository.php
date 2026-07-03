<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Subscription lifecycle writes. Renewals/refunds append 202_revenue_events
 * (the ledger stays the source of truth for money); status/MRR live on
 * 202_subscriptions; the customer's cached mrr/active_subscription_count are
 * recomputed from the subscriptions table after every write (cheap per-
 * customer aggregate — no drift), and the ltv_maintenance cron reconciles
 * everything anyway.
 *
 * Time-based transitions (active -> past_due -> canceled) are handled by the
 * ltv_maintenance sweep, not here.
 */
final class MysqlSubscriptionRepository
{
    private const INTERVALS = ['day', 'week', 'month', 'year'];
    private const STATUSES = ['trialing', 'active', 'past_due', 'paused', 'canceled'];

    /** Months per interval unit, for normalizing amounts to MRR. */
    private const MONTHS_PER_INTERVAL = [
        'day' => 0.0328542,   // 1 / 30.4375
        'week' => 0.2299795,  // 7 / 30.4375
        'month' => 1.0,
        'year' => 12.0,
    ];

    public function __construct(
        private Connection $conn,
        private MysqlCustomerRepository $customers
    ) {
    }

    /**
     * Create or update a subscription, keyed on (user_id, external_sub_id).
     *
     * @param array<string, mixed> $payload Requires external_sub_id, amount,
     *        and a customer (customer_id, or customer_ref + optional
     *        customer_ref_type). Optional: plan_name, currency,
     *        billing_interval (default month), billing_interval_count,
     *        status, started_at, current_period_start, current_period_end,
     *        grace_days, customer_crm.
     * @return array{subscriptionId: int, customerId: int}
     */
    public function upsert(int $userId, array $payload): array
    {
        $externalSubId = trim((string) ($payload['external_sub_id'] ?? ''));
        if ($externalSubId === '') {
            throw new RuntimeException('external_sub_id is required');
        }

        $interval = strtolower(trim((string) ($payload['billing_interval'] ?? 'month')));
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new RuntimeException('billing_interval must be one of: ' . implode(', ', self::INTERVALS));
        }
        $intervalCount = max(1, (int) ($payload['billing_interval_count'] ?? 1));
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount < 0) {
            throw new RuntimeException('amount must not be negative');
        }

        $currency = $this->validateCurrency($userId, $payload['currency'] ?? null);

        $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('invalid subscription status: ' . $status);
        }

        $now = time();
        $startedAt = (int) ($payload['started_at'] ?? $now);
        $periodStart = (int) ($payload['current_period_start'] ?? $startedAt);
        $periodEnd = (int) ($payload['current_period_end'] ?? self::advancePeriod($periodStart, $interval, $intervalCount));
        if ($periodEnd <= $periodStart) {
            throw new RuntimeException('current_period_end must be after current_period_start');
        }
        $graceDays = max(0, (int) ($payload['grace_days'] ?? 3));
        $planName = trim((string) ($payload['plan_name'] ?? ''));

        // Trials do not collect money yet, so they carry no MRR.
        $mrr = $status === 'trialing' ? 0.0 : self::normalizeMrr($amount, $interval, $intervalCount);

        $write = fn (): array => $this->conn->transaction(function () use (
            $userId,
            $payload,
            $externalSubId,
            $planName,
            $amount,
            $currency,
            $interval,
            $intervalCount,
            $status,
            $mrr,
            $startedAt,
            $periodStart,
            $periodEnd,
            $graceDays,
            $now
        ): array {
            // Identity creation inside the same transaction: a failed
            // subscription write must roll the fresh customer/alias back
            // with it, never leave an orphan zero-MRR record.
            $customerId = $this->resolveCustomer($userId, $payload, $now, true);

            // An upsert may MOVE the subscription to a different customer;
            // capture the previous owner so their rollups get corrected too.
            // FOR UPDATE serializes concurrent upserts of the same sub (like
            // recordEvent's lock): the second writer blocks here until the
            // first commits, then reads the owner IT actually displaces —
            // an unlocked read would let both see the same stale owner and
            // leave an intermediate owner's MRR rollup never refreshed.
            $prevStmt = $this->conn->prepareWrite(
                'SELECT customer_id FROM 202_subscriptions WHERE user_id = ? AND external_sub_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($prevStmt, 'is', [$userId, $externalSubId]);
            $previous = $this->conn->fetchOne($prevStmt);

            $stmt = $this->conn->prepareWrite(
                "INSERT INTO 202_subscriptions
                    (user_id, customer_id, external_sub_id, plan_name, amount, currency,
                     billing_interval, billing_interval_count, status, mrr,
                     started_at, current_period_start, current_period_end, grace_days,
                     canceled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    subscription_id = LAST_INSERT_ID(subscription_id),
                    customer_id = VALUES(customer_id),
                    plan_name = VALUES(plan_name),
                    amount = VALUES(amount),
                    billing_interval = VALUES(billing_interval),
                    billing_interval_count = VALUES(billing_interval_count),
                    status = VALUES(status),
                    mrr = VALUES(mrr),
                    current_period_start = VALUES(current_period_start),
                    current_period_end = VALUES(current_period_end),
                    grace_days = VALUES(grace_days),
                    canceled_at = IF(VALUES(status) = 'canceled', COALESCE(canceled_at, VALUES(canceled_at), VALUES(updated_at)), NULL),
                    updated_at = VALUES(updated_at)"
            );
            // user_id(i) customer_id(i) external_sub_id(s) plan_name(s) amount(d)
            // currency(s) billing_interval(s) billing_interval_count(i) status(s)
            // mrr(d) started_at(i) period_start(i) period_end(i) grace_days(i)
            // canceled_at(i) created_at(i) updated_at(i)
            // A first-time INSERT arriving already canceled must carry
            // canceled_at — mrr()'s trailing-churn window filters on it.
            $this->conn->bind($stmt, 'iissdssisdiiiiiii', [
                $userId,
                $customerId,
                $externalSubId,
                $planName !== '' ? $planName : null,
                $amount,
                $currency,
                $interval,
                $intervalCount,
                $status,
                $mrr,
                $startedAt,
                $periodStart,
                $periodEnd,
                $graceDays,
                $status === 'canceled' ? $now : null,
                $now,
                $now,
            ]);
            $subscriptionId = $this->conn->executeInsert($stmt);
            if ($subscriptionId <= 0) {
                throw new RuntimeException('Subscription upsert did not yield a subscription_id');
            }

            $this->refreshCustomerSubscriptionRollups($userId, $customerId, $now);
            if ($previous !== null && (int) $previous['customer_id'] !== $customerId) {
                // Reassigned: the old owner must not keep the moved MRR.
                $this->refreshCustomerSubscriptionRollups($userId, (int) $previous['customer_id'], $now);
            }

            return [$subscriptionId, $customerId];
        });

        try {
            [$subscriptionId, $customerId] = $write();
        } catch (\Throwable $e) {
            if (!Connection::isRetryableLockError($e)) {
                throw $e;
            }
            // Two concurrent FIRST-TIME upserts of the same external_sub_id
            // can deadlock on the FOR UPDATE gap + insert-intention locks;
            // the retry sees the winner's committed row and updates it.
            [$subscriptionId, $customerId] = $write();
        }

        return ['subscriptionId' => $subscriptionId, 'customerId' => $customerId];
    }

    /**
     * Record a subscription lifecycle event.
     *
     * - renewal: appends a 'renewal' ledger event (idempotent on the caller's
     *   idempotency_key/transaction_id), advances the paid-through period and
     *   re-activates the subscription.
     * - cancel: marks the subscription canceled (no money movement).
     * - refund: appends a negative 'refund' ledger event.
     *
     * @param array<string, mixed> $payload Optional: amount (defaults to the
     *        subscription amount for renewal), occurred_at, idempotency_key,
     *        transaction_id, current_period_end (renewal).
     * @return array{eventId: int|null, inserted: bool, changed: bool, subscriptionId: int, customerId: int}
     *         `changed` is false for idempotent replays (duplicate
     *         renewal/refund keys, repeat cancels) — nothing was modified.
     */
    public function recordEvent(int $userId, string $externalSubId, string $eventType, array $payload): array
    {
        if (!in_array($eventType, ['renewal', 'cancel', 'refund'], true)) {
            throw new RuntimeException('event type must be renewal, cancel or refund');
        }

        $now = time();
        $occurredAt = (int) ($payload['occurred_at'] ?? $now);
        $currency = $this->validateCurrency($userId, $payload['currency'] ?? null);

        return $this->conn->transaction(function () use ($userId, $externalSubId, $eventType, $payload, $currency, $occurredAt, $now): array {
            // Lock the subscription row so concurrent renewals serialize.
            $stmt = $this->conn->prepareWrite(
                'SELECT subscription_id, customer_id, amount, billing_interval, billing_interval_count,
                        status, mrr, current_period_end
                 FROM 202_subscriptions
                 WHERE user_id = ? AND external_sub_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($stmt, 'is', [$userId, $externalSubId]);
            $sub = $this->conn->fetchOne($stmt);
            if ($sub === null) {
                throw new SubscriptionNotFoundException('Subscription not found: ' . $externalSubId);
            }

            $subscriptionId = (int) $sub['subscription_id'];
            $customerId = (int) $sub['customer_id'];
            $eventId = null;
            $inserted = false;
            $changed = false;

            if ($eventType === 'cancel') {
                // A repeat cancel is an idempotent no-op — report it as
                // unchanged so callers (webhooks) don't re-notify.
                $changed = ((string) $sub['status']) !== 'canceled';
                $upd = $this->conn->prepareWrite(
                    "UPDATE 202_subscriptions
                     SET status = 'canceled', canceled_at = COALESCE(canceled_at, ?), updated_at = ?
                     WHERE subscription_id = ?"
                );
                $this->conn->bind($upd, 'iii', [$occurredAt, $now, $subscriptionId]);
                $this->conn->executeUpdate($upd);
            } else {
                $isRefund = $eventType === 'refund';
                $amount = isset($payload['amount']) ? (float) $payload['amount'] : (float) $sub['amount'];
                if ($amount < 0) {
                    throw new RuntimeException('amount must not be negative; refunds are negated automatically');
                }
                $ledgerAmount = $isRefund ? -$amount : $amount;

                $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
                $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
                if ($idempotencyKey === '' && $transactionId !== '') {
                    // A billing-system transaction id is a natural idempotency key.
                    $idempotencyKey = 'sub:' . $subscriptionId . ':' . $eventType . ':' . $transactionId;
                }

                $event = $this->customers->insertRevenueEvent($userId, $customerId, [
                    'event_type' => $isRefund ? 'refund' : 'renewal',
                    'amount' => $ledgerAmount,
                    'currency' => $currency,
                    'occurred_at' => $occurredAt,
                    'source' => 'subscription',
                    'subscription_id' => $subscriptionId,
                    'external_ref' => $externalSubId,
                    'transaction_id' => $transactionId !== '' ? $transactionId : null,
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                ], $now);
                $eventId = $event['eventId'];
                $inserted = $event['inserted'];
                // Money events only change state when the ledger insert is
                // new; an idempotent replay leaves everything untouched.
                $changed = $inserted;

                if ($inserted) {
                    $this->customers->applyEventToRollups(
                        $userId,
                        $customerId,
                        $isRefund ? 'refund' : 'renewal',
                        $ledgerAmount,
                        $occurredAt,
                        $now
                    );

                    if (!$isRefund) {
                        // A renewal extends the paid-through period and clears
                        // past_due/paused back to active. MRR is recomputed
                        // here too: a trial row was stored with mrr = 0, and
                        // flipping it active without setting mrr would keep
                        // the converted subscriber reporting zero MRR.
                        $newPeriodEnd = isset($payload['current_period_end'])
                            ? (int) $payload['current_period_end']
                            : self::advancePeriod(
                                max((int) $sub['current_period_end'], $occurredAt),
                                (string) $sub['billing_interval'],
                                (int) $sub['billing_interval_count']
                            );
                        $mrr = self::normalizeMrr(
                            (float) $sub['amount'],
                            (string) $sub['billing_interval'],
                            (int) $sub['billing_interval_count']
                        );
                        $upd = $this->conn->prepareWrite(
                            "UPDATE 202_subscriptions
                             SET status = 'active', mrr = ?, current_period_start = ?, current_period_end = ?,
                                 canceled_at = NULL, updated_at = ?
                             WHERE subscription_id = ?"
                        );
                        $this->conn->bind($upd, 'diiii', [$mrr, $occurredAt, $newPeriodEnd, $now, $subscriptionId]);
                        $this->conn->executeUpdate($upd);
                    }
                }
            }

            $this->refreshCustomerSubscriptionRollups($userId, $customerId, $now);

            return [
                'eventId' => $eventId,
                'inserted' => $inserted,
                'changed' => $changed,
                'subscriptionId' => $subscriptionId,
                'customerId' => $customerId,
            ];
        });
    }

    /**
     * Normalize a per-period amount to a monthly recurring figure.
     */
    public static function normalizeMrr(float $amount, string $interval, int $intervalCount): float
    {
        $months = (self::MONTHS_PER_INTERVAL[$interval] ?? 1.0) * max(1, $intervalCount);

        return round($amount / $months, 5);
    }

    /**
     * Advance a period boundary by one billing interval. Month/year use
     * calendar arithmetic; day/week are fixed spans.
     */
    public static function advancePeriod(int $from, string $interval, int $intervalCount): int
    {
        $count = max(1, $intervalCount);
        $advanced = match ($interval) {
            'day' => strtotime("+{$count} day", $from),
            'week' => strtotime("+{$count} week", $from),
            'month' => strtotime("+{$count} month", $from),
            'year' => strtotime("+{$count} year", $from),
            default => strtotime("+{$count} month", $from),
        };

        return $advanced !== false ? $advanced : $from + $count * 2630016; // ~1 month fallback
    }

    /**
     * Recompute the customer's cached mrr / active_subscription_count from
     * the subscriptions table (precise, per-customer, cheap).
     */
    private function refreshCustomerSubscriptionRollups(int $userId, int $customerId, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_customers c
             LEFT JOIN (
                SELECT customer_id,
                       SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END) AS mrr,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subs
                FROM 202_subscriptions
                WHERE customer_id = ?
                GROUP BY customer_id
             ) s ON s.customer_id = c.customer_id
             SET c.mrr = COALESCE(s.mrr, 0),
                 c.active_subscription_count = COALESCE(s.active_subs, 0),
                 c.updated_at = ?
             WHERE c.customer_id = ? AND c.user_id = ?"
        );
        $this->conn->bind($stmt, 'iiii', [$customerId, $now, $customerId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCustomer(int $userId, array $payload, int $now, bool $inTransaction = false): int
    {
        $explicitId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : 0;
        if ($explicitId > 0) {
            if (!$this->customers->customerBelongsToUser($explicitId, $userId)) {
                throw new RuntimeException('customer_id ' . $explicitId . ' not found for this account');
            }
            return $this->customers->followMergePointer($explicitId);
        }

        $ref = trim((string) ($payload['customer_ref'] ?? ''));
        if ($ref === '') {
            throw new RuntimeException('A customer is required: pass customer_id or customer_ref');
        }
        $refType = isset($payload['customer_ref_type']) ? (string) $payload['customer_ref_type'] : 'custom';
        $crm = isset($payload['customer_crm']) && is_array($payload['customer_crm']) ? $payload['customer_crm'] : [];

        $resolve = fn (): int => $this->customers->resolveOrCreateByAlias($userId, $refType, $ref, $crm, null, $now);

        // upsert() passes true so a failed subscription write rolls the
        // fresh customer/alias back with it (Connection::transaction does
        // not nest).
        return $inTransaction ? $resolve() : $this->conn->transaction($resolve);
    }

    /**
     * Account-wide subscription list, optionally filtered by status, joined
     * to the owning customer for display. Newest lifecycle activity first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForUser(int $userId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $statuses = ['trialing', 'active', 'past_due', 'paused', 'canceled'];
        if ($status !== null && $status !== '' && !in_array($status, $statuses, true)) {
            throw new RuntimeException('status must be one of: ' . implode(', ', $statuses));
        }
        $statusWhere = ($status !== null && $status !== '') ? ' AND s.status = ?' : '';

        $sql = 'SELECT s.subscription_id, s.external_sub_id, s.plan_name, s.amount, s.currency,
                       s.billing_interval, s.billing_interval_count, s.status, s.mrr,
                       s.started_at, s.current_period_end, s.canceled_at,
                       s.customer_id, c.first_name, c.last_name, c.email, c.company
                FROM 202_subscriptions s
                LEFT JOIN 202_customers c ON c.customer_id = s.customer_id AND c.user_id = s.user_id
                WHERE s.user_id = ?' . $statusWhere . '
                ORDER BY s.updated_at DESC, s.subscription_id DESC
                LIMIT ? OFFSET ?';
        $stmt = $this->conn->prepareRead($sql);
        if ($statusWhere !== '') {
            $this->conn->bind($stmt, 'isii', [$userId, $status, max(1, $limit), max(0, $offset)]);
        } else {
            $this->conn->bind($stmt, 'iii', [$userId, max(1, $limit), max(0, $offset)]);
        }
        $rows = $this->conn->fetchAll($stmt);

        $countSql = 'SELECT COUNT(*) AS total FROM 202_subscriptions s WHERE s.user_id = ?' . $statusWhere;
        $countStmt = $this->conn->prepareRead($countSql);
        if ($statusWhere !== '') {
            $this->conn->bind($countStmt, 'is', [$userId, $status]);
        } else {
            $this->conn->bind($countStmt, 'i', [$userId]);
        }
        $count = $this->conn->fetchOne($countStmt);

        return ['rows' => $rows, 'total' => (int) ($count['total'] ?? 0)];
    }

    /**
     * @param mixed $requested
     */
    private function validateCurrency(int $userId, mixed $requested): string
    {
        $accountCurrency = $this->customers->accountCurrency($userId);
        if ($requested === null || trim((string) $requested) === '') {
            return $accountCurrency;
        }
        $currency = strtoupper(trim((string) $requested));
        if ($currency !== $accountCurrency) {
            throw new RuntimeException(
                "currency {$currency} does not match the account currency {$accountCurrency}; multi-currency is not supported"
            );
        }

        return $currency;
    }
}
