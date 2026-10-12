<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Closure;

/**
 * How fast one cron run deletes click data (ClickRetention): the size of each
 * batch, the pause after it, and when to stop until the next run.
 *
 * A batch is one transaction (ClickRetention::deleteClicks()), so how long it
 * takes is how long it holds its row locks against the click path. Two
 * controls, so a small shared host and a dedicated server both get what they
 * can take:
 *
 *  - Batch timing. A batch should hold its locks for about TARGET_SECONDS.
 *    One that finishes in under half of that doubles the next; one within it
 *    grows by a quarter; one over it shrinks in proportion to the overshoot.
 *    A server that deletes quickly reaches MAX_BATCH in a few batches; a slow
 *    one settles at what it can do in half a second.
 *  - Load. Before every batch RetentionLoadProbe reads the host and the
 *    database. Under MODERATE load batches are capped and every batch is
 *    followed by an equal pause (half the time spent deleting); under HIGH
 *    they are MIN_BATCH with a long pause; under SEVERE a run deletes one
 *    MIN_BATCH and stops, so a server that is always saturated is still
 *    cleaned, slowly.
 *
 * A lock wait timeout or deadlock (the click path writing the same rows)
 * rolls the batch back whole, quarters the next and pauses; a third in one
 * run ends it. A run never spends more than its time budget, pauses included.
 */
final class RetentionPacer
{
    public const MIN_BATCH = 100;
    public const START_BATCH = 500;
    public const MAX_BATCH = 10000;
    /** Under MODERATE load no batch is bigger than this. */
    public const MODERATE_CAP = 2000;
    /** How long one batch should hold its locks. */
    public const TARGET_SECONDS = 0.5;
    /** One cron run's time for deleting, pauses included. */
    public const BUDGET_SECONDS = 20.0;
    private const MAX_CONTENTION = 3;

    private int $size;
    private readonly float $startedAt;
    private float $lastBatchSeconds = 0.0;
    private int $batches = 0;
    private int $contention = 0;
    private ?string $stopReason = null;
    private RetentionPressure $pressure = RetentionPressure::NONE;

    /**
     * @param Closure(): RetentionPressure $probe
     * @param Closure(): float $clock seconds
     * @param Closure(float): void $sleep seconds
     */
    public function __construct(
        private readonly Closure $probe,
        private readonly Closure $clock,
        private readonly Closure $sleep,
        private readonly float $budgetSeconds = self::BUDGET_SECONDS,
        private readonly int $minBatch = self::MIN_BATCH,
        int $startBatch = self::START_BATCH,
        private readonly int $maxBatch = self::MAX_BATCH,
        private readonly ?int $maxBatches = null,
    ) {
        $this->size = max($minBatch, min($maxBatch, $startBatch));
        $this->startedAt = ($clock)();
    }

    /** The pacer a cron run uses: the real clock, real sleeps, the live load. */
    public static function forCron(\mysqli $db, float $budgetSeconds = self::BUDGET_SECONDS): self
    {
        $probe = new RetentionLoadProbe($db);

        return new self(
            static fn (): RetentionPressure => $probe->pressure(),
            static fn (): float => microtime(true),
            static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            },
            $budgetSeconds,
        );
    }

    /** How many clicks the next batch deletes; 0 means stop this run (see stopReason()). */
    public function nextBatch(): int
    {
        if ($this->stopReason !== null) {
            return 0;
        }
        if ($this->maxBatches !== null && $this->batches >= $this->maxBatches) {
            return $this->stop('batch limit reached');
        }
        if ($this->elapsed() + $this->lastBatchSeconds > $this->budgetSeconds) {
            return $this->stop('time budget spent');
        }
        $this->pressure = ($this->probe)();

        return match ($this->pressure) {
            // One small batch, then stop: a shared database whose other
            // tenants keep it saturated would otherwise never be cleaned.
            RetentionPressure::SEVERE => $this->batches === 0
                ? $this->minBatch
                : $this->stop('server under heavy load'),
            RetentionPressure::HIGH => $this->minBatch,
            RetentionPressure::MODERATE => max($this->minBatch, min($this->size, self::MODERATE_CAP)),
            RetentionPressure::NONE => $this->size,
        };
    }

    /**
     * After a batch: learn from how long it took, and return how long to
     * pause before the next.
     */
    public function afterBatch(int $deleted, int $asked, float $seconds): float
    {
        $this->batches++;
        $this->lastBatchSeconds = max(0.0, $seconds);
        if ($deleted >= $asked) {
            if ($seconds <= self::TARGET_SECONDS / 2) {
                $this->size = min($this->maxBatch, $this->size * 2);
            } elseif ($seconds <= self::TARGET_SECONDS) {
                $this->size = min($this->maxBatch, (int) ceil($this->size * 1.25));
            } else {
                $this->size = max($this->minBatch, (int) floor($this->size * self::TARGET_SECONDS / $seconds));
            }
        }

        return match ($this->pressure) {
            RetentionPressure::NONE => $this->lastBatchSeconds * 0.2,
            RetentionPressure::MODERATE => max(0.2, $this->lastBatchSeconds),
            default => max(2.0, $this->lastBatchSeconds * 4),
        };
    }

    /** After a lock wait timeout or deadlock: shrink, and return how long to pause. */
    public function afterContention(): float
    {
        $this->contention++;
        $this->size = max($this->minBatch, intdiv($this->size, 4));
        if ($this->contention >= self::MAX_CONTENTION) {
            $this->stop('lock contention');
        }

        return 2.0;
    }

    public function pause(float $seconds): void
    {
        $remaining = $this->budgetSeconds - $this->elapsed();
        if ($seconds > 0 && $remaining > 0) {
            ($this->sleep)(min($seconds, $remaining));
        }
    }

    public function now(): float
    {
        return ($this->clock)();
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function batches(): int
    {
        return $this->batches;
    }

    public function batchSize(): int
    {
        return $this->size;
    }

    private function elapsed(): float
    {
        return ($this->clock)() - $this->startedAt;
    }

    private function stop(string $reason): int
    {
        $this->stopReason ??= $reason;

        return 0;
    }
}
