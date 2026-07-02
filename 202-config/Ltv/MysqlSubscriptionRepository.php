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

        $customerId = $this->resolveCustomer($userId, $payload, $now);

        $subscriptionId = $this->conn->transaction(function () use (
            $userId,
            $customerId,
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
        ): int {
            $stmt = $this->conn->prepareWrite(
                "INSERT INTO 202_subscriptions
                    (user_id, customer_id, external_sub_id, plan_name, amount, currency,
                     billing_interval, billing_interval_count, status, mrr,
                     started_at, current_period_start, current_period_end, grace_days,
                     canceled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
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
                    canceled_at = IF(VALUES(status) = 'canceled', COALESCE(canceled_at, VALUES(updated_at)), NULL),
                    updated_at = VALUES(updated_at)"
            );
            // user_id(i) customer_id(i) external_sub_id(s) plan_name(s) amount(d)
            // currency(s) billing_interval(s) billing_interval_count(i) status(s)
            // mrr(d) started_at(i) period_start(i) period_end(i) grace_days(i)
            // created_at(i) updated_at(i)
            $this->conn->bind($stmt, 'iissdssisdiiiiii', [
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
                $now,
                $now,
            ]);
            $subscriptionId = $this->conn->executeInsert($stmt);
            if ($subscriptionId <= 0) {
                throw new RuntimeException('Subscription upsert did not yield a subscription_id');
            }

            $this->refreshCustomerSubscriptionRollups($userId, $customerId, $now);

            return $subscriptionId;
        });

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
     * @return array{eventId: int|null, inserted: bool, subscriptionId: int, customerId: int}
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

            if ($eventType === 'cancel') {
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
                        // past_due/paused back to active.
                        $newPeriodEnd = isset($payload['current_period_end'])
                            ? (int) $payload['current_period_end']
                            : self::advancePeriod(
                                max((int) $sub['current_period_end'], $occurredAt),
                                (string) $sub['billing_interval'],
                                (int) $sub['billing_interval_count']
                            );
                        $upd = $this->conn->prepareWrite(
                            "UPDATE 202_subscriptions
                             SET status = 'active', current_period_start = ?, current_period_end = ?,
                                 canceled_at = NULL, updated_at = ?
                             WHERE subscription_id = ?"
                        );
                        $this->conn->bind($upd, 'iiii', [$occurredAt, $newPeriodEnd, $now, $subscriptionId]);
                        $this->conn->executeUpdate($upd);
                    }
                }
            }

            $this->refreshCustomerSubscriptionRollups($userId, $customerId, $now);

            return [
                'eventId' => $eventId,
                'inserted' => $inserted,
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
    private function resolveCustomer(int $userId, array $payload, int $now): int
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

        return $this->conn->transaction(
            fn (): int => $this->customers->resolveOrCreateByAlias($userId, $refType, $ref, $crm, null, $now)
        );
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
