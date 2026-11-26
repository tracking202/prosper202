<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * Why a sync could not call, or could not read, one of its two remotes, in a
 * sentence that names the remote by its origin and never by its key, path or
 * query: the job's `error` carries it as it is, and `error_code` says which
 * kind it was, so a client can say what to do without reading the sentence.
 *
 * Most kinds stop the whole sync, with skip_errors or without: a URL this
 * server will not call, or a cap reached, is the same for the next record.
 * Of those, only a host that did not resolve is tried again by the job; the
 * rest would be refused the same way. A remote that did not answer, or
 * answered something a Prosper202 API does not, fails the request alone, as
 * a failed write always has.
 *
 * Escaping to an HTTP response (POST /sync/plan) it is a 422 with its
 * sentence, except a remote that did not answer, which is the 502 the
 * handler words as any server error.
 */
final class SyncException extends HttpException
{
    public const URL_REFUSED = 'sync_url_refused';
    public const URL_UNRESOLVED = 'sync_url_unresolved';
    public const LIMIT = 'sync_limit';
    public const KEY_UNREADABLE = 'sync_key_unreadable';
    public const REMOTE_FAILED = 'sync_remote_failed';
    /** A record this sync cannot write as it is: nothing to match it by, a reference the other side lacks. */
    public const RECORD_UNUSABLE = 'sync_record_unusable';
    /** Another run between the same two instances holds their lock; the job is tried again. */
    public const PAIR_BUSY = 'sync_pair_busy';
    /** The run's process ended without finishing (killed, out of memory, out of time). */
    public const WORKER_LOST = 'sync_worker_lost';
    /** An error inside this server, not either remote's answer; logged with the job's id. */
    public const INTERNAL = 'sync_internal';

    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $stopsSync,
        public readonly bool $retryable,
        int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** A URL this server will not call: refused by the guard, a redirect, a pin that did not hold. */
    public static function urlRefused(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::URL_REFUSED, true, false, 422, $previous);
    }

    public static function urlUnresolved(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::URL_UNRESOLVED, true, true, 422, $previous);
    }

    /** A cap reached: answer size, pages of one list, or the sync's whole time. */
    public static function limit(string $message): self
    {
        return new self($message, self::LIMIT, true, false, 422);
    }

    /** A job's sealed key that cannot be opened; sending the request again is the remedy. */
    public static function keyUnreadable(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, self::KEY_UNREADABLE, true, false, 422, $previous);
    }

    /** One request that got no answer, or one no Prosper202 API gives. */
    public static function remoteFailed(string $message): self
    {
        return new self($message, self::REMOTE_FAILED, false, true, 502);
    }

    /**
     * One record the sync cannot write as the source holds it. It fails that
     * record alone (skip_errors goes on), and the job does not try it again:
     * the next run reads the same record.
     */
    public static function recordUnusable(string $message): self
    {
        return new self($message, self::RECORD_UNUSABLE, false, false, 422);
    }

    /** The pair's lock is held by another run; the job waits its turn. */
    public static function pairBusy(): self
    {
        return new self(
            'Another sync between the same source and target is running on this server, so this job waits for it '
                . 'and is tried again.',
            self::PAIR_BUSY,
            true,
            true,
            409
        );
    }

    /** The same failure, saying that the sync stopped before it wrote anything. */
    public function beforeAnyWrite(): self
    {
        return new self(
            $this->getMessage() . ' Nothing was written.',
            $this->errorCode,
            $this->stopsSync,
            $this->retryable,
            $this->getHttpStatus(),
            $this
        );
    }

    /** The SyncException a failure is or was caused by, nearest first. */
    public static function in(\Throwable $error): ?self
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }
}
