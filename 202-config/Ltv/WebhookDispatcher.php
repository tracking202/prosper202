<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;

/**
 * Sends the LTV webhook deliveries the LTV writes queue
 * (MysqlWebhookRepository::enqueue()). The only place LTV webhook HTTP
 * happens.
 *
 * Two entry points run it: the minutely tier of 202-cronjobs/index.php, which
 * every default scheduler runs (the installer's cron line, install.sh, both
 * Docker cron services, AutoCron), and 202-cronjobs/ltv_webhooks.php for a
 * deployment that runs workers on their own (docker-compose.coolify.yaml).
 * Both take one MySQL named lock, and each delivery is also claimed by
 * compare-and-set before it is sent (MysqlWebhookRepository::claimDelivery()),
 * so a run the lock does not see cannot send it again either.
 *
 * A run is bounded by its deadline, asked before each delivery (at least one
 * is sent), so a run ends at most one delivery past it: the guard's lookup
 * and the request's timeout (WebhookSender::TIMEOUT, 15 s). A
 * webhook whose delivery failed after SLOW_FAILURE_SECONDS or more is not
 * sent to again in that run: its other deliveries stay due, untouched, for
 * the next one, so a receiver that times out spends one timeout per run and
 * not the budget every other webhook on the install shares. A failure that
 * comes back at once costs nothing to repeat, and each delivery is tried at
 * its own pace.
 *
 * A delivery still waiting MAX_AGE_SECONDS after it was queued is not sent:
 * it is marked failed, its reason in its log (`expired: …`). An install
 * whose queue nothing sent for months (no default deployment ran a sender
 * before 1.9.79) sends the last week of it, oldest first, not a year of stale
 * events. Retries (MysqlWebhookRepository::recordAttempt()) are 2^attempts
 * minutes apart and stop at MAX_ATTEMPTS, which marks the webhook dead:
 * about an hour, far inside the limit. A dead webhook's deliveries are held,
 * not tried (MysqlWebhookRepository::holdDelivery()); reviving it within
 * MAX_AGE_SECONDS of a delivery's queueing sends that delivery (revive()).
 * A revival changes deliveries only between runs, under the same lock
 * (betweenRuns()).
 */
final class WebhookDispatcher
{
    public const LOCK_PREFIX = 'p202_ltv_webhooks:';

    /** How long index.php's minutely run sends. */
    public const RUN_SECONDS = 15;

    /** Deliveries read per query. */
    public const BATCH = 100;

    /** A failed send that took this long defers its webhook's other deliveries to the next run. */
    public const SLOW_FAILURE_SECONDS = 2;

    /** A delivery not sent this long after it was queued is expired instead. */
    public const MAX_AGE_SECONDS = 7 * 86400;

    /** Deliveries expired per statement. */
    public const EXPIRE_BATCH = 1000;

    public const EXPIRED_REASON = 'expired: still unsent 7 days after it was queued, so it was not sent';

    /** @var callable(array<string, mixed>): array{0: bool, 1: ?int, 2: string} */
    private $send;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(array<string, mixed>): array{0: bool, 1: ?int, 2: string})|null $send one
     *        delivery's POST, post() by default
     * @param (callable(): int)|null $clock the time the deadline is asked of
     */
    public function __construct(private readonly Connection $conn, ?callable $send = null, ?callable $clock = null)
    {
        $this->send = $send ?? self::post(...);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * run() under the dispatcher's lock, or null when another run holds it.
     *
     * When the run throws, that exception is what the caller sees: a
     * RELEASE_LOCK that fails too (the connection died under the run) is
     * dropped, since the lock goes with the session.
     *
     * @param (callable(array<string, mixed>): array{0: bool, 1: ?int, 2: string})|null $send
     * @param (callable(): int)|null $clock
     * @return array{delivered: int, failed: int, held: int, expired: int, deferred: int, taken: int, more: bool}|null
     */
    public static function runExclusive(
        Connection $conn,
        int $deadline,
        ?callable $send = null,
        ?callable $clock = null
    ): ?array {
        $name = self::lockName($conn);
        $got = $conn->fetchOne(self::lockStatement($conn, 'SELECT GET_LOCK(?, 0) AS got', $name));
        if ($got === null || $got['got'] === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . $name);
        }
        if ((int) $got['got'] !== 1) {
            return null;
        }
        return self::holding($conn, $name, static fn (): array => (new self($conn, $send, $clock))->run($deadline));
    }

    /**
     * $work between runs: under the dispatcher's lock, so no run is between
     * a delivery's claim and its record while $work changes deliveries
     * (MysqlWebhookRepository::revive()). A run's claim cannot be told from
     * a retry's backoff on the row, so the lock is what says none is in
     * flight. Waits up to $waitSeconds for a run to end; one that does not is
     * an LtvConflictException, and $work never runs.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function betweenRuns(Connection $conn, int $waitSeconds, callable $work): mixed
    {
        $name = self::lockName($conn);
        $stmt = $conn->prepareWrite('SELECT GET_LOCK(?, ?) AS got');
        $conn->bind($stmt, 'si', [$name, max(0, $waitSeconds)]);
        $got = $conn->fetchOne($stmt);
        if ($got === null || $got['got'] === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . $name);
        }
        if ((int) $got['got'] !== 1) {
            throw new LtvConflictException('LTV webhook deliveries are being sent right now, and the run did not end'
                . ' within ' . $waitSeconds . ' seconds; nothing was changed. Try again in a minute.');
        }

        return self::holding($conn, $name, $work);
    }

    /**
     * $work, then the lock $name released. When $work throws, that
     * exception is what the caller sees: a RELEASE_LOCK that fails too (the
     * connection died under it) is dropped, since the lock goes with the
     * session.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function holding(Connection $conn, string $name, callable $work): mixed
    {
        $failure = null;
        try {
            return $work();
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
     * Expire what is too old, then send what is due until $deadline (unix
     * seconds) or the queue's end. A delivery's failure is recorded on its
     * row and the run goes on; a database failure throws, and a delivery it
     * had claimed is due again once the claim lapses.
     *
     * @return array{delivered: int, failed: int, held: int, expired: int, deferred: int, taken: int, more: bool}
     */
    public function run(int $deadline): array
    {
        $repo = new MysqlWebhookRepository($this->conn);
        $report = [
            'delivered' => 0, 'failed' => 0, 'held' => 0, 'expired' => 0, 'deferred' => 0, 'taken' => 0,
            'more' => false,
        ];

        do {
            $now = time();
            $expired = $repo->expireUnsent(
                $now,
                $now - self::MAX_AGE_SECONDS,
                self::EXPIRED_REASON,
                self::EXPIRE_BATCH
            );
            $report['expired'] += $expired;
        } while ($expired === self::EXPIRE_BATCH && ($this->clock)() < $deadline);
        if ($expired === self::EXPIRE_BATCH) {
            // The deliveries left to expire are the oldest due, the first a send would take.
            $report['more'] = true;

            return $report;
        }

        $asOf = time();
        $afterDueAt = 0;
        $afterId = 0;
        $claimed = 0;
        /** @var array<int, true> $deferredHooks */
        $deferredHooks = [];
        do {
            $batch = $repo->duePending($asOf, self::BATCH, $afterDueAt, $afterId);
            foreach ($batch as $delivery) {
                $deliveryId = (int) $delivery['delivery_id'];
                $webhookId = (int) $delivery['webhook_id'];
                if ($claimed > 0 && ($this->clock)() >= $deadline) {
                    $report['more'] = true;
                    break 2;
                }
                $afterDueAt = (int) $delivery['next_attempt_at'];
                $afterId = $deliveryId;
                if (isset($deferredHooks[$webhookId])) {
                    $report['deferred']++;
                    continue;
                }
                if (!$repo->claimDelivery($deliveryId, time())) {
                    $report['taken']++;
                    continue;
                }
                $claimed++;

                $webhookStatus = (string) $delivery['webhook_status'];
                if ($webhookStatus === 'dead' && $repo->holdDelivery($deliveryId, time())) {
                    // Kept for a revival (MysqlWebhookRepository::revive()), not tried.
                    $report['held']++;
                    continue;
                }
                if ($webhookStatus !== 'active' && $webhookStatus !== 'dead') {
                    $repo->recordAttempt($deliveryId, $webhookId, false, null, 'endpoint not active');
                    $report['failed']++;
                    continue;
                }

                $started = ($this->clock)();
                try {
                    [$success, $statusCode, $responseBody] = ($this->send)($delivery);
                } catch (\Throwable $e) {
                    [$success, $statusCode, $responseBody] = [false, null, 'error: ' . $e->getMessage()];
                }
                $repo->recordAttempt($deliveryId, $webhookId, $success, $statusCode, $responseBody);

                if ($success) {
                    $report['delivered']++;
                    continue;
                }
                $report['failed']++;
                error_log('ltv_webhooks: delivery ' . $deliveryId . ' to webhook ' . $webhookId
                    . ' failed (status ' . ($statusCode ?? 0) . ')');
                if (($this->clock)() - $started >= self::SLOW_FAILURE_SECONDS) {
                    $deferredHooks[$webhookId] = true;
                }
            }
        } while (count($batch) === self::BATCH);

        return $report;
    }

    /**
     * One delivery's POST, made by DeliverySender: the export webhooks'
     * sender and guard, which checks the URL when it is sent, pins the
     * request to an address it approved, follows no redirect and verifies
     * TLS. A refusal or a failure is the answer, never a throw: the run
     * records it on the delivery's own row.
     *
     * @param array<string, mixed> $delivery a duePending() row
     * @return array{0: bool, 1: ?int, 2: string} 2xx, the HTTP status (null without one), the body or the error
     */
    public static function post(array $delivery): array
    {
        return (new DeliverySender())->post($delivery);
    }

    /**
     * The request headers of a delivery: its signature over the exact body
     * with the webhook's secret, its event and its id, which a receiver
     * de-duplicates by (a delivery whose outcome could not be recorded is
     * sent again once its claim lapses).
     *
     * @param array<string, mixed> $delivery a duePending() row
     * @return list<string>
     */
    public static function headers(array $delivery): array
    {
        return DeliverySender::headers($delivery);
    }

    /**
     * One line, as both entry points print it.
     *
     * @param array{
     *     delivered: int, failed: int, held: int, expired: int, deferred: int, taken: int, more: bool
     * } $report
     */
    public static function summary(array $report): string
    {
        $parts = [$report['delivered'] . ' delivered', $report['failed'] . ' failed or retrying'];
        if ($report['held'] > 0) {
            $parts[] = $report['held'] . ' held for a dead webhook';
        }
        if ($report['expired'] > 0) {
            $parts[] = $report['expired'] . ' expired unsent';
        }
        if ($report['deferred'] > 0) {
            $parts[] = $report['deferred'] . ' left for the next run (their webhook was slow to fail)';
        }
        if ($report['taken'] > 0) {
            $parts[] = $report['taken'] . ' sent by another run';
        }

        return implode(', ', $parts) . ($report['more'] ? '; more due, next run' : '');
    }

    /**
     * Whether a report has anything to say.
     *
     * @param array{
     *     delivered: int, failed: int, held: int, expired: int, deferred: int, taken: int, more: bool
     * } $report
     */
    public static function didWork(array $report): bool
    {
        return $report['delivered'] + $report['failed'] + $report['held'] + $report['expired']
            + $report['deferred'] + $report['taken'] > 0;
    }

    /**
     * Per database, as the attribution worker's: GET_LOCK names are
     * server-wide and at most 64 characters, so the name carries the
     * database's SHA-1. No database selected is an error, not a shared lock.
     */
    public static function lockName(Connection $conn): string
    {
        $row = $conn->fetchOne($conn->prepareWrite('SELECT DATABASE() AS db'));
        $db = $row['db'] ?? null;
        if (!is_string($db) || $db === '') {
            throw new \RuntimeException('The LTV webhook dispatcher needs a selected database to name its lock.');
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
}
