<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

/**
 * A batch of conversion writes — a subid list, a campaign reset, a revenue
 * report — stopped part-way.
 *
 * Each item of such a batch is its own transaction (record() and
 * clearClicks() open one per click), so when item N fails, the writes before
 * it have committed and stay. An exception on its own cannot say that: it
 * reads as "nothing happened", and a caller that reports it so has told the
 * person the opposite of what the database holds (CLAUDE.md #13). This one
 * carries how far the batch got, so the caller can say what stands.
 */
final class BatchInterrupted extends \RuntimeException
{
    /**
     * @param string $at where it stopped, for the message: "line 13", "click 940012", "closing batch 7"
     * @param int $committed items whose write committed before the failure
     * @param int|null $batchId the revenue upload batch, once created (its row is itself a committed write)
     */
    public function __construct(
        public readonly string $at,
        public readonly int $committed,
        public readonly ?int $batchId,
        \Throwable $previous,
    ) {
        parent::__construct($at . ': ' . $previous->getMessage(), 0, $previous);
    }

    /** Whether anything was written before the batch stopped. */
    public function wroteSomething(): bool
    {
        return $this->committed > 0 || $this->batchId !== null;
    }
}
