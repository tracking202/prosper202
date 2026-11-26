<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Database\Connection;

/**
 * Slack notices waiting to be sent (202_slack_notices). A page queues a
 * notice and answers; the minute's cron job sends it, and on PHP-FPM or
 * LiteSpeed the same request does once the client has its answer
 * (afterAnswer()), so no person or network waits on Slack. A notice that
 * gets no answer, or a 408, 429 or 5xx, is tried again with the traffic
 * source outbox's backoff (NotificationOutbox::backoff()), MAX_ATTEMPTS
 * times in all.
 *
 * The webhook is the account's own (SlackWebhook::ofAccount()), read when
 * the notice is sent. A row is deleted once it is settled: sent, refused,
 * out of attempts, its account without a webhook, or older than MAX_AGE;
 * each but the first is logged with why.
 */
final class SlackNoticeQueue
{
    /** Tries in all: 1, 2, 4, 8 and 16 minutes apart, about half an hour. */
    public const MAX_ATTEMPTS = 6;

    /** Seconds after which a notice that has not gone out is dropped, not sent. */
    public const MAX_AGE = 86400;

    /** Seconds one send may take, the lookup included: nobody waits on it here. */
    public const SEND_TIMEOUT = 10;

    /** The width of `notice`, in characters. */
    private const NOTICE_CHARS = 64;

    /** The width of `last_error`, in characters. */
    private const ERROR_CHARS = 255;

    /** @var array<int, true> ids this request queued, for afterAnswer() */
    private static array $queuedHere = [];

    private static bool $hooked = false;

    /** @var callable|null */
    private $clock;

    private SlackWebhook $webhook;

    public function __construct(private Connection $conn, ?callable $clock = null, ?SlackWebhook $webhook = null)
    {
        $this->clock = $clock;
        $this->webhook = $webhook ?? new SlackWebhook();
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * Queue a notice for the account's webhook, due now: the new row's id.
     * Inside the caller's transaction it is queued, and rolled back, with it.
     *
     * @param string $notice the notice's name (Slack::push()'s placeholder), for the log
     * @param string $body what is posted (Slack::payload())
     * @throws \InvalidArgumentException for an account id below 1
     * @throws \Prosper202\Database\Exceptions\QueryException when it cannot be written
     */
    public function queue(int $accountId, string $notice, string $body): int
    {
        if ($accountId < 1) {
            throw new \InvalidArgumentException(
                'a Slack notice is queued for an account, and ' . $accountId . ' names none'
            );
        }
        $now = $this->now();
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_slack_notices (user_id, notice, body, attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $notice = mb_substr($notice, 0, self::NOTICE_CHARS, 'UTF-8');
        $this->conn->bind($stmt, 'issii', [$accountId, $notice, $body, $now, $now]);

        return $this->conn->executeInsert($stmt);
    }

    /**
     * Send the notices that are due, oldest first, at most $limit of them and
     * for about $seconds; with $ids, only those. A host that gave no answer
     * is not asked again in the same run.
     *
     * @param list<int>|null $ids
     * @return array{sent: int, failed: int, retrying: int} failed counts the notices dropped
     * @throws \Prosper202\Database\Exceptions\QueryException when the queue cannot be read or written
     */
    public function sendDue(int $limit, ?array $ids = null, int $seconds = 20): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'retrying' => 0];
        $stopAt = microtime(true) + max(1, $seconds);
        $now = $this->now();
        $sql = 'SELECT notice_id, user_id, notice, body, attempts, created_at FROM 202_slack_notices
                WHERE next_attempt_at <= ?';
        $types = 'i';
        $binds = [$now];
        if ($ids !== null) {
            if ($ids === []) {
                return $out;
            }
            $sql .= ' AND notice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            array_push($binds, ...array_map('intval', $ids));
        }
        // Paged by id, so a row this run passes over is not the next page's
        // first row (CLAUDE.md #37).
        $sql .= ' AND notice_id > ? ORDER BY notice_id LIMIT ?';
        $types .= 'ii';
        $limit = max(1, $limit);
        $after = 0;
        $tried = 0;
        /** @var array<string, true> $unanswered */
        $unanswered = [];
        /** @var array<int, string|null> $webhooks */
        $webhooks = [];

        while ($tried < $limit && microtime(true) < $stopAt) {
            $stmt = $this->conn->prepareWrite($sql);
            $this->conn->bind($stmt, $types, [...$binds, $after, $limit]);
            $rows = $this->conn->fetchAll($stmt);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int) $row['notice_id'];
                if ($tried >= $limit || microtime(true) >= $stopAt) {
                    break 2;
                }
                $id = (int) $row['notice_id'];
                $accountId = (int) $row['user_id'];
                $notice = (string) $row['notice'];
                $attempts = (int) $row['attempts'];

                if ((int) $row['created_at'] < $now - self::MAX_AGE) {
                    if ($this->deleteSettled($id, $accountId, $attempts)) {
                        $this->log($notice, $accountId, 'it waited more than a day to be sent, and was dropped');
                        $out['failed']++;
                    }
                    continue;
                }
                if (!array_key_exists($accountId, $webhooks)) {
                    $webhooks[$accountId] = SlackWebhook::ofAccount($this->conn, $accountId);
                }
                $url = $webhooks[$accountId];
                if ($url === null) {
                    if ($this->deleteSettled($id, $accountId, $attempts)) {
                        $this->log($notice, $accountId, 'the account has no Slack webhook now');
                        $out['failed']++;
                    }
                    continue;
                }
                $origin = PostbackSender::origin($url);
                if (isset($unanswered[$origin])) {
                    continue;
                }

                // Claim: only the worker that moves the attempt count sends.
                $claim = $this->conn->prepareWrite(
                    'UPDATE 202_slack_notices SET attempts = ?, next_attempt_at = ?
                     WHERE notice_id = ? AND user_id = ? AND attempts = ?'
                );
                $due = $now + NotificationOutbox::backoff($attempts + 1);
                $this->conn->bind($claim, 'iiiii', [$attempts + 1, $due, $id, $accountId, $attempts]);
                if ($this->conn->executeUpdate($claim) !== 1) {
                    continue;
                }
                $attempts++;
                $tried++;

                $timeout = max(1, min(self::SEND_TIMEOUT, (int) ceil($stopAt - microtime(true))));
                $result = $this->webhook->deliver($url, (string) $row['body'], $timeout);
                if ($result->isDelivered()) {
                    $this->deleteSettled($id, $accountId, $attempts);
                    $out['sent']++;
                    continue;
                }
                // Only a host that gave no answer waits for the next run; one
                // that answered, even with an error, is not slow.
                if (!$result->isRefused() && $result->status === null) {
                    $unanswered[$origin] = true;
                }
                if ($result->isRefused() || $attempts >= self::MAX_ATTEMPTS) {
                    $this->deleteSettled($id, $accountId, $attempts);
                    $this->log($notice, $accountId, $result->reason . ($result->isRefused()
                        ? '' : ' (after ' . $attempts . ' tries, so it was dropped)'));
                    $out['failed']++;
                    continue;
                }
                $mark = $this->conn->prepareWrite(
                    'UPDATE 202_slack_notices SET last_error = ? WHERE notice_id = ? AND user_id = ? AND attempts = ?'
                );
                $error = mb_strimwidth($result->reason, 0, self::ERROR_CHARS, '…', 'UTF-8');
                $this->conn->bind($mark, 'siii', [$error, $id, $accountId, $attempts]);
                $this->conn->executeUpdate($mark);
                $out['retrying']++;
            }
        }

        return $out;
    }

    /**
     * Send this notice from the request that queued it, once the client has
     * its answer: at the end of the request, after every other shutdown
     * function, and only where the answer can be finished first (PHP-FPM,
     * LiteSpeed). Elsewhere, mod_php or the CLI, the cron job sends it.
     */
    public static function afterAnswer(Connection $conn, int $noticeId): void
    {
        if ($noticeId < 1 || !self::canFinishTheAnswer()) {
            return;
        }
        self::$queuedHere[$noticeId] = true;
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        // Registered from a shutdown function, so it runs after every one the
        // request registers, the ones that may still write the session included.
        register_shutdown_function(static function () use ($conn): void {
            register_shutdown_function(static function () use ($conn): void {
                self::sendQueuedHere($conn);
            });
        });
    }

    /** Whether this SAPI can end the answer before the script does. */
    private static function canFinishTheAnswer(): bool
    {
        return (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request'))
            || function_exists('litespeed_finish_request');
    }

    private static function sendQueuedHere(Connection $conn): void
    {
        $ids = array_keys(self::$queuedHere);
        self::$queuedHere = [];
        if ($ids === []) {
            return;
        }
        // The session is saved now, so the visitor's next request is not held
        // on its lock while Slack is asked.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
            // False under FPM is a request already finished (cb202.php
            // finishes it first): the client has its answer either way.
            fastcgi_finish_request();
        } elseif (!function_exists('litespeed_finish_request') || litespeed_finish_request() !== true) {
            return;
        }
        ignore_user_abort(true);
        try {
            (new self($conn))->sendDue(count($ids), $ids, self::SEND_TIMEOUT);
        } catch (\Throwable $e) {
            error_log('Slack: ' . count($ids) . ' notice(s) were left to the cron job: ' . $e->getMessage());
        }
    }

    /** Delete a settled row, if no other worker has claimed it since: whether it was this one's. */
    private function deleteSettled(int $id, int $accountId, int $attempts): bool
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_slack_notices WHERE notice_id = ? AND user_id = ? AND attempts = ?'
        );
        $this->conn->bind($stmt, 'iii', [$id, $accountId, $attempts]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    private function log(string $notice, int $accountId, string $why): void
    {
        error_log('Slack: the "' . $notice . '" notice for account ' . $accountId . ' was not sent: ' . $why);
    }
}
