<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;

/**
 * The LTV maintenance jobs, every account's at once:
 *
 * - SWEEP, the subscription lifecycle: an active subscription past
 *   current_period_end + grace_days with no renewal is past_due, and 30 days
 *   after that it is canceled, dated the moment the rule says it lapsed, not
 *   the moment a run noticed: MysqlLtvRepository::mrr() counts churn by
 *   canceled_at over 90 days, and an install whose sweep never ran (none did
 *   by default before 1.9.79) would otherwise put every subscription that
 *   lapsed in the last years into this quarter's churn. Each batch's
 *   customers have their cached mrr and active_subscription_count recomputed
 *   in the batch's transaction. Each status it writes queues the
 *   subscription.changed webhook event the API's change of that status
 *   queues, in the same transaction, when the change took effect within
 *   EVENT_WINDOW_SECONDS: an install upgraded with years of lapsed
 *   subscriptions announces the last week's, not every one.
 * - RECONCILE: the customer rollups (order_count, total_revenue,
 *   refunded_amount, mrr, active_subscription_count) recomputed from the
 *   revenue ledger and the subscriptions, for the customers active in the
 *   last 48 hours (every customer with $full). Definitions as
 *   MysqlCustomerRepository::applyEventToRollups():
 *     total_revenue   = SUM(amount) over all events (voids/refunds negative)
 *     refunded_amount = SUM(-amount) over refund/chargeback events
 *     order_count     = COUNT(purchase|renewal|one_time)
 *                     - COUNT(adjustment with external_ref 'void:%')
 *                       (a void of a non-order event carries 'void-nc:%')
 *     mrr / active_subscription_count = the subscriptions with status active
 * - TRANSITIONS: each account's next-offer transition counts rebuilt from the
 *   ledger, and recommendation conversions stamped.
 * - COMPANIES: customers with a company string and no company attached.
 * - PURGE: personalization tokens past their replay window.
 *
 * 202-cronjobs/index.php runs the sweep in its hourly tier (HOURLY_SECONDS)
 * and the rest in its daily one (DAILY_SECONDS); 202-cronjobs/ltv_maintenance.php
 * runs them all on its own. Both take one MySQL named lock.
 *
 * Each job reads in batches and asks the deadline before each batch (at
 * least one runs), so a backlog drains over successive runs: an install
 * upgraded with years of lapsed subscriptions sweeps them oldest first,
 * BATCH at a time. A job that fails is reported and logged and the jobs
 * after it run. The reconcile starts from the first customer each run.
 *
 * @phpstan-type Report array{
 *     jobs: list<string>, past_due: int, canceled: int, swept_customers: int, announced: int, unannounced: int,
 *     reconciled: int, checked: int, full: bool, transitions: int, recommendation_conversions: int,
 *     companies_linked: int, company_strings: int, tokens_purged: int,
 *     more: list<string>, failed: array<string, string>
 * }
 */
final class LtvMaintenance
{
    public const SWEEP = 'sweep';
    public const RECONCILE = 'reconcile';
    public const TRANSITIONS = 'transitions';
    public const COMPANIES = 'companies';
    public const PURGE = 'purge';

    /** Every job, in the order a run takes them. */
    public const JOBS = [self::SWEEP, self::RECONCILE, self::TRANSITIONS, self::COMPANIES, self::PURGE];

    /** What index.php's daily tier runs; the hourly tier sweeps. */
    public const DAILY_JOBS = [self::RECONCILE, self::TRANSITIONS, self::COMPANIES, self::PURGE];

    public const LOCK_PREFIX = 'p202_ltv_maintenance:';

    /** How long index.php's hourly sweep runs. */
    public const HOURLY_SECONDS = 20;

    /** How long index.php's daily run of the rest runs. */
    public const DAILY_SECONDS = 60;

    /** Subscriptions swept, or customers reconciled, per statement. */
    public const BATCH = 500;

    /** Days past the grace period a past_due subscription is canceled. */
    public const CANCEL_AFTER_DAYS = 30;

    /**
     * A status change the sweep writes queues its subscription.changed event
     * only when it took effect this recently. The webhook dispatcher sends
     * nothing queued longer ago than this (WebhookDispatcher::MAX_AGE_SECONDS),
     * so a sweep that had always run would have announced no older change
     * either; without it, an install's first sweep queued one event for every
     * subscription that ever lapsed, ahead of every live event.
     */
    public const EVENT_WINDOW_SECONDS = WebhookDispatcher::MAX_AGE_SECONDS;

    /** What the API's change of each status the sweep writes queues as its action. */
    public const EVENT_ACTIONS = ['past_due' => 'upsert', 'canceled' => 'cancel'];

    /** The reconcile's window: customers active this recently. */
    public const ACTIVE_WINDOW_SECONDS = 172800;

    /** Customers linked to a company per call. */
    public const COMPANY_BATCH = 2000;

    /** Expired personalization tokens deleted per statement. */
    public const PURGE_BATCH = 5000;

    /** @var callable(): int */
    private $clock;

    private int $deadline = 0;

    private int $batches = 0;

    /**
     * @param (callable(): int)|null $clock the time the deadline is asked of
     */
    public function __construct(
        private readonly Connection $conn,
        private readonly bool $full = false,
        private readonly int $batch = self::BATCH,
        ?callable $clock = null
    ) {
        if ($batch < 1) {
            throw new \InvalidArgumentException('A batch is 1 or more, not ' . $batch);
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * run() under the maintenance lock, or null when another run holds it.
     *
     * @param list<string> $jobs a subset of JOBS
     * @param (callable(): int)|null $clock
     * @return Report|null
     */
    public static function runExclusive(
        Connection $conn,
        int $deadline,
        array $jobs = self::JOBS,
        bool $full = false,
        int $batch = self::BATCH,
        ?callable $clock = null
    ): ?array {
        $maintenance = new self($conn, $full, $batch, $clock);
        $name = self::lockName($conn);
        $got = $conn->fetchOne(self::lockStatement($conn, 'SELECT GET_LOCK(?, 0) AS got', $name));
        if ($got === null || $got['got'] === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . $name);
        }
        if ((int) $got['got'] !== 1) {
            return null;
        }
        $failure = null;
        try {
            return $maintenance->run($deadline, $jobs);
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $conn->fetchOne(self::lockStatement($conn, 'SELECT RELEASE_LOCK(?) AS released', $name));
            } catch (\Throwable $releaseError) {
                if ($failure === null) {
                    throw $releaseError;
                }
            }
        }
    }

    /**
     * The jobs asked for, in JOBS order, until $deadline (unix seconds). A
     * job's failure is in `failed` (and the log) and the next job runs; a
     * job the deadline stopped with work left, or never reached, is in
     * `more`.
     *
     * @param list<string> $jobs a subset of JOBS
     * @return Report
     */
    public function run(int $deadline, array $jobs = self::JOBS): array
    {
        foreach ($jobs as $job) {
            if (!in_array($job, self::JOBS, true)) {
                throw new \InvalidArgumentException('Not an LTV maintenance job: ' . $job);
            }
        }
        $this->deadline = $deadline;
        $this->batches = 0;
        $report = [
            'jobs' => [],
            'past_due' => 0,
            'canceled' => 0,
            'swept_customers' => 0,
            'announced' => 0,
            'unannounced' => 0,
            'reconciled' => 0,
            'checked' => 0,
            'full' => $this->full,
            'transitions' => 0,
            'recommendation_conversions' => 0,
            'companies_linked' => 0,
            'company_strings' => 0,
            'tokens_purged' => 0,
            'more' => [],
            'failed' => [],
        ];
        foreach (self::JOBS as $job) {
            if (!in_array($job, $jobs, true)) {
                continue;
            }
            $report['jobs'][] = $job;
            if ($this->outOfTime()) {
                $report['more'][] = $job;
                continue;
            }
            try {
                $done = match ($job) {
                    self::SWEEP => $this->sweep($report),
                    self::RECONCILE => $this->reconcile($report),
                    self::TRANSITIONS => $this->transitions($report),
                    self::COMPANIES => $this->companies($report),
                    self::PURGE => $this->purge($report),
                };
                if (!$done) {
                    $report['more'][] = $job;
                }
            } catch (\Throwable $e) {
                $report['failed'][$job] = $e->getMessage();
                error_log('ltv_maintenance ' . $job . ' failed: ' . $e->getMessage());
            }
        }

        return $report;
    }

    /** Past the deadline, once a batch has run. */
    private function outOfTime(): bool
    {
        return $this->batches > 0 && ($this->clock)() >= $this->deadline;
    }

    /**
     * Active subscriptions lapsed past their grace (to past_due, or straight
     * to canceled when they lapsed past that too), then past_due ones past
     * the cancel window. Oldest period end first, by the period_end_sweep
     * index; a cursor keeps a run from reading a row twice.
     *
     * @param Report $report
     */
    private function sweep(array &$report): bool
    {
        $phases = [
            // active: lapsed when its period and grace are over
            ['active', 'current_period_end + grace_days * 86400'],
            // past_due: canceled once the cancel window is over too
            ['past_due', 'current_period_end + (grace_days + ' . self::CANCEL_AFTER_DAYS . ') * 86400'],
        ];
        $cancelAt = 'current_period_end + (grace_days + ' . self::CANCEL_AFTER_DAYS . ') * 86400';
        foreach ($phases as [$status, $lapsedAt]) {
            $now = time();
            $afterEnd = 0;
            $afterId = 0;
            do {
                if ($this->outOfTime()) {
                    return false;
                }
                // current_period_end < ? first: the index can range on it.
                $stmt = $this->conn->prepareWrite(
                    "SELECT subscription_id, user_id, customer_id, current_period_end
                     FROM 202_subscriptions
                     WHERE status = ? AND current_period_end < ? AND {$lapsedAt} < ?
                       AND (current_period_end > ? OR (current_period_end = ? AND subscription_id > ?))
                     ORDER BY current_period_end ASC, subscription_id ASC
                     LIMIT ?"
                );
                $this->conn->bind(
                    $stmt,
                    'siiiiii',
                    [$status, $now, $now, $afterEnd, $afterEnd, $afterId, $this->batch]
                );
                $rows = $this->conn->fetchAll($stmt);
                if ($rows === []) {
                    break;
                }
                $last = $rows[count($rows) - 1];
                $afterEnd = (int) $last['current_period_end'];
                $afterId = (int) $last['subscription_id'];
                $this->batches++;

                $byUser = [];
                foreach ($rows as $row) {
                    $byUser[(int) $row['user_id']]['subs'][] = (int) $row['subscription_id'];
                    $byUser[(int) $row['user_id']]['customers'][(int) $row['customer_id']] = true;
                }
                [$pastDue, $canceled, $customers, $announced, $unannounced] = $this->conn->transaction(
                    fn (): array => $this->sweepBatch($byUser, $status, $lapsedAt, $cancelAt, $now)
                );
                $report['past_due'] += $pastDue;
                $report['canceled'] += $canceled;
                $report['swept_customers'] += $customers;
                $report['announced'] += $announced;
                $report['unannounced'] += $unannounced;
            } while (count($rows) === $this->batch);
        }

        return true;
    }

    /**
     * One batch, inside its transaction: each account's rows locked and
     * asked again (a renewal since the read moved one on, and a locking read
     * sees it), moved, announced, and their customers' rollups refreshed.
     * Answers [past_due, canceled, customers refreshed, announced, too old
     * to announce].
     *
     * @param array<int, array{subs: list<int>, customers: array<int, true>}> $byUser
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
     */
    private function sweepBatch(array $byUser, string $status, string $lapsedAt, string $cancelAt, int $now): array
    {
        $counts = [0, 0, 0, 0, 0];
        $webhooks = new MysqlWebhookRepository($this->conn);
        foreach ($byUser as $userId => $ids) {
            $subs = $ids['subs'];
            $stmt = $this->conn->prepareWrite(
                "SELECT subscription_id, customer_id, {$lapsedAt} AS lapsed_at, {$cancelAt} AS cancel_at
                 FROM 202_subscriptions
                 WHERE user_id = ? AND subscription_id IN (" . self::placeholders(count($subs)) . ")
                   AND status = ? AND {$lapsedAt} < ?
                 FOR UPDATE"
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($subs)) . 'si', [$userId, ...$subs, $status, $now]);
            // new status => [subscription id, customer id, the moment it took effect]
            $to = ['canceled' => [], 'past_due' => []];
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $canceling = (int) $row['cancel_at'] < $now;
                $to[$canceling ? 'canceled' : 'past_due'][] = [
                    (int) $row['subscription_id'],
                    (int) $row['customer_id'],
                    (int) ($canceling ? $row['cancel_at'] : $row['lapsed_at']),
                ];
            }
            $set = [
                'canceled' => "status = 'canceled', canceled_at = {$cancelAt}",
                'past_due' => "status = 'past_due'",
            ];
            foreach ($to as $newStatus => $changes) {
                if ($changes === []) {
                    continue;
                }
                $changed = array_column($changes, 0);
                $stmt = $this->conn->prepareWrite(
                    "UPDATE 202_subscriptions SET {$set[$newStatus]}, updated_at = ?
                     WHERE user_id = ? AND subscription_id IN (" . self::placeholders(count($changed)) . ')
                       AND status = ?'
                );
                $this->conn->bind(
                    $stmt,
                    'ii' . str_repeat('i', count($changed)) . 's',
                    [$now, $userId, ...$changed, $status]
                );
                $written = $this->conn->executeUpdate($stmt);
                if ($written !== count($changed)) {
                    // Locked above, so each still matches; an event for a row not moved would be false.
                    throw new \RuntimeException('The sweep locked ' . count($changed) . ' subscriptions to move to '
                        . $newStatus . ' and moved ' . $written);
                }
                $counts[$newStatus === 'canceled' ? 1 : 0] += $written;
                foreach ($changes as [$subscriptionId, $customerId, $effectiveAt]) {
                    if ($effectiveAt < $now - self::EVENT_WINDOW_SECONDS) {
                        $counts[4]++;
                        continue;
                    }
                    $webhooks->enqueue($userId, 'subscription.changed', [
                        'subscription_id' => $subscriptionId,
                        'customer_id' => $customerId,
                        'action' => self::EVENT_ACTIONS[$newStatus],
                    ]);
                    $counts[3]++;
                }
            }
            $counts[2] += $this->refreshSubscriptionRollups($userId, array_keys($ids['customers']), $now);
        }

        return $counts;
    }

    /**
     * One account's customers' cached mrr and active_subscription_count, from
     * their subscriptions; a row is written only where it differs. Answers
     * how many were.
     *
     * @param list<int> $customerIds
     */
    private function refreshSubscriptionRollups(int $userId, array $customerIds, int $now): int
    {
        $in = self::placeholders(count($customerIds));
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_customers c
             LEFT JOIN (
                SELECT customer_id,
                       SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END) AS mrr,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subs
                FROM 202_subscriptions
                WHERE user_id = ? AND customer_id IN ({$in})
                GROUP BY customer_id
             ) s ON s.customer_id = c.customer_id
             SET c.mrr = COALESCE(s.mrr, 0),
                 c.active_subscription_count = COALESCE(s.active_subs, 0),
                 c.updated_at = ?
             WHERE c.user_id = ? AND c.customer_id IN ({$in})
               AND (c.mrr <> COALESCE(s.mrr, 0) OR c.active_subscription_count <> COALESCE(s.active_subs, 0))"
        );
        $types = 'i' . str_repeat('i', count($customerIds)) . 'ii' . str_repeat('i', count($customerIds));
        $this->conn->bind($stmt, $types, [$userId, ...$customerIds, $now, $userId, ...$customerIds]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * The rollups of the customers active in the window (all with $full),
     * BATCH customers at a time by id. Each account's ledger and
     * subscriptions are read by their (user_id, customer_id) indexes: the
     * statement this replaces asked the ledger for an id range with no index
     * on it, and read all of it for every 500 ids (60 s for 3,349 customers
     * of 100,000, measured; this takes 0.7 s).
     *
     * @param Report $report
     */
    private function reconcile(array &$report): bool
    {
        $now = time();
        $since = $now - self::ACTIVE_WINDOW_SECONDS;
        $after = 0;
        do {
            if ($this->outOfTime()) {
                return false;
            }
            if ($this->full) {
                $stmt = $this->conn->prepareWrite(
                    'SELECT customer_id, user_id FROM 202_customers
                     WHERE customer_id > ? ORDER BY customer_id ASC LIMIT ?'
                );
                $this->conn->bind($stmt, 'ii', [$after, $this->batch]);
            } else {
                $stmt = $this->conn->prepareWrite(
                    'SELECT customer_id, user_id FROM 202_customers
                     WHERE customer_id > ? AND last_activity_time >= ? ORDER BY customer_id ASC LIMIT ?'
                );
                $this->conn->bind($stmt, 'iii', [$after, $since, $this->batch]);
            }
            $rows = $this->conn->fetchAll($stmt);
            if ($rows === []) {
                break;
            }
            $after = (int) $rows[count($rows) - 1]['customer_id'];
            $this->batches++;
            $report['checked'] += count($rows);

            $byUser = [];
            foreach ($rows as $row) {
                $byUser[(int) $row['user_id']][] = (int) $row['customer_id'];
            }
            foreach ($byUser as $userId => $ids) {
                $report['reconciled'] += $this->reconcileCustomers($userId, $ids, $now);
            }
        } while (count($rows) === $this->batch);

        return true;
    }

    /**
     * @param list<int> $ids one account's customers
     */
    private function reconcileCustomers(int $userId, array $ids, int $now): int
    {
        $in = self::placeholders(count($ids));
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_customers c
             LEFT JOIN (
                SELECT customer_id,
                       SUM(amount) AS revenue,
                       SUM(CASE WHEN event_type IN ('refund','chargeback') THEN -amount ELSE 0 END) AS refunded,
                       SUM(CASE WHEN event_type IN ('purchase','renewal','one_time') THEN 1
                                WHEN event_type = 'adjustment' AND external_ref LIKE 'void:%' THEN -1
                                ELSE 0 END) AS orders
                FROM 202_revenue_events
                WHERE user_id = ? AND customer_id IN ({$in})
                GROUP BY customer_id
             ) e ON e.customer_id = c.customer_id
             LEFT JOIN (
                SELECT customer_id,
                       SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END) AS mrr,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subs
                FROM 202_subscriptions
                WHERE user_id = ? AND customer_id IN ({$in})
                GROUP BY customer_id
             ) s ON s.customer_id = c.customer_id
             SET c.order_count = GREATEST(0, COALESCE(e.orders, 0)),
                 c.total_revenue = COALESCE(e.revenue, 0),
                 c.refunded_amount = GREATEST(0, COALESCE(e.refunded, 0)),
                 c.mrr = COALESCE(s.mrr, 0),
                 c.active_subscription_count = COALESCE(s.active_subs, 0),
                 c.updated_at = ?
             WHERE c.user_id = ? AND c.customer_id IN ({$in})
               AND (c.order_count <> GREATEST(0, COALESCE(e.orders, 0))
                    OR c.total_revenue <> COALESCE(e.revenue, 0)
                    OR c.refunded_amount <> GREATEST(0, COALESCE(e.refunded, 0))
                    OR c.mrr <> COALESCE(s.mrr, 0)
                    OR c.active_subscription_count <> COALESCE(s.active_subs, 0))"
        );
        $list = str_repeat('i', count($ids));
        $this->conn->bind(
            $stmt,
            'i' . $list . 'i' . $list . 'ii' . $list,
            [$userId, ...$ids, $userId, ...$ids, $now, $userId, ...$ids]
        );

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * Each account with conversion or import revenue: its transitions
     * rebuilt (one statement per account, which the deadline does not
     * interrupt) and its recommendation conversions stamped.
     *
     * @param Report $report
     */
    private function transitions(array &$report): bool
    {
        $users = $this->conn->fetchAll($this->conn->prepareWrite(
            "SELECT DISTINCT user_id FROM 202_revenue_events WHERE source IN ('conversion','import') ORDER BY user_id"
        ));
        $recommendations = new MysqlRecommendationRepository($this->conn);
        foreach ($users as $row) {
            if ($this->outOfTime()) {
                return false;
            }
            $this->batches++;
            $userId = (int) $row['user_id'];
            $report['transitions'] += $recommendations->rebuildTransitions($userId, time());
            $report['recommendation_conversions'] += $recommendations->stampRecommendationConversions($userId);
        }

        return true;
    }

    /**
     * Customers carrying a company string and no company: attached, account
     * by account, then the attached strings converged on their companies'
     * names.
     *
     * @param Report $report
     */
    private function companies(array &$report): bool
    {
        $companies = new MysqlCompanyRepository($this->conn);
        $users = $this->conn->fetchAll(
            $this->conn->prepareWrite('SELECT user_id FROM 202_users_pref ORDER BY user_id')
        );
        foreach ($users as $row) {
            $userId = (int) $row['user_id'];
            // One probe per account on the (user_id, company_id) index: a
            // DISTINCT over every account's customers would read them all.
            $probe = $this->conn->prepareWrite(
                "SELECT 1 AS pending FROM 202_customers
                 WHERE user_id = ? AND company_id IS NULL
                   AND company IS NOT NULL AND company <> ''
                   AND merged_into_customer_id IS NULL
                 LIMIT 1"
            );
            $this->conn->bind($probe, 'i', [$userId]);
            if ($this->conn->fetchOne($probe) === null) {
                continue;
            }
            do {
                if ($this->outOfTime()) {
                    return false;
                }
                $this->batches++;
                $linked = $companies->linkUnlinkedCustomers($userId, self::COMPANY_BATCH);
                $report['companies_linked'] += $linked;
            } while ($linked === self::COMPANY_BATCH);
        }
        if ($this->outOfTime()) {
            return false;
        }
        $this->batches++;
        $report['company_strings'] += $companies->reconcileAttachedStrings();

        return true;
    }

    /** @param Report $report */
    private function purge(array &$report): bool
    {
        $tokens = new MysqlPersonalizationRepository($this->conn);
        $now = time();
        do {
            if ($this->outOfTime()) {
                return false;
            }
            $this->batches++;
            $purged = $tokens->purgeExpired($now, self::PURGE_BATCH);
            $report['tokens_purged'] += $purged;
        } while ($purged === self::PURGE_BATCH);

        return true;
    }

    /**
     * One line, as both entry points print it.
     *
     * @param Report $report
     */
    public static function summary(array $report): string
    {
        $jobs = $report['jobs'];
        $parts = [];
        if (in_array(self::SWEEP, $jobs, true)) {
            $parts[] = 'subscriptions ' . (int) $report['past_due'] . ' to past_due, ' . (int) $report['canceled']
                . ' to canceled, ' . (int) $report['swept_customers'] . ' customer rollups refreshed, '
                . (int) $report['announced'] . ' announced as subscription.changed'
                . ($report['unannounced'] > 0
                    ? ' (' . (int) $report['unannounced'] . ' that took effect more than '
                        . intdiv(self::EVENT_WINDOW_SECONDS, 86400) . ' days ago not announced)'
                    : '');
        }
        if (in_array(self::RECONCILE, $jobs, true)) {
            $parts[] = 'rollups ' . (int) $report['reconciled'] . ' of ' . (int) $report['checked']
                . ' customers corrected (' . ($report['full'] ? 'every customer' : 'active in 48 hours') . ')';
        }
        if (in_array(self::TRANSITIONS, $jobs, true)) {
            $parts[] = 'offer transitions ' . (int) $report['transitions'] . ' pairs, '
                . (int) $report['recommendation_conversions'] . ' recommendation conversions stamped';
        }
        if (in_array(self::COMPANIES, $jobs, true)) {
            $parts[] = 'companies ' . (int) $report['companies_linked'] . ' customers attached, '
                . (int) $report['company_strings'] . ' names converged';
        }
        if (in_array(self::PURGE, $jobs, true)) {
            $parts[] = 'personalization ' . (int) $report['tokens_purged'] . ' expired tokens removed';
        }
        $line = implode('; ', $parts);
        $more = $report['more'];
        if ($more !== []) {
            $line .= '; out of time, next run: ' . implode(', ', $more);
        }
        $failed = $report['failed'];
        if ($failed !== []) {
            $line .= '; FAILED: ' . implode(', ', array_keys($failed));
        }

        return $line;
    }

    /**
     * Whether a report has anything to say: a row changed, work left, or a
     * failure.
     *
     * @param Report $report
     */
    public static function didWork(array $report): bool
    {
        $changed = ['past_due', 'canceled', 'swept_customers', 'reconciled', 'companies_linked', 'company_strings',
            'tokens_purged'];
        foreach ($changed as $count) {
            if ($report[$count] > 0) {
                return true;
            }
        }

        return $report['more'] !== [] || $report['failed'] !== [];
    }

    /**
     * Per database, as the webhook dispatcher's: GET_LOCK names are
     * server-wide and at most 64 characters, so the name carries the
     * database's SHA-1. No database selected is an error, not a shared lock.
     */
    public static function lockName(Connection $conn): string
    {
        $row = $conn->fetchOne($conn->prepareWrite('SELECT DATABASE() AS db'));
        $db = $row['db'] ?? null;
        if (!is_string($db) || $db === '') {
            throw new \RuntimeException('LTV maintenance needs a selected database to name its lock.');
        }

        return self::LOCK_PREFIX . sha1($db);
    }

    /** @return \mysqli_stmt */
    private static function lockStatement(Connection $conn, string $sql, string $name)
    {
        $stmt = $conn->prepareWrite($sql);
        $conn->bind($stmt, 's', [$name]);

        return $stmt;
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }
}
