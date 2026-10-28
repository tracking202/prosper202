<?php

declare(strict_types=1);

namespace Api\V3\Exception;

/**
 * A sync run without skip_errors stopped at one record: which entity and
 * record, what it was doing, and why, in a message a job can carry as its
 * error. The record's own failure is `previous`.
 *
 * The job runner used to record $e->getMessage() of whatever was thrown,
 * and a remote refusal was a DatabaseException whose message is "Internal
 * server error". When the cause is a conflict (the target answered 409:
 * the record changed after the sync read it), $conflict carries the record
 * the job names, and the runner does not retry the job by itself: a retry
 * re-reads the target and force-writes over the change the 409 protected
 * (CLAUDE.md #13).
 */
final class SyncRecordException extends \RuntimeException
{
    /**
     * $written is what the target had confirmed writing when the run
     * stopped, by entity (created, updated, pruned), and the message says
     * it, none included; what became of the write it stopped at is the
     * cause's to say (an answer, or none).
     *
     * @param array<string, mixed>|null $conflict the conflict record, when the cause is one
     * @param array<string, array<string, int>> $written
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $key,
        public readonly string $operation,
        string $what,
        public readonly ?array $conflict,
        \Throwable $previous,
        public readonly array $written = [],
    ) {
        $sentence = rtrim(sprintf('%s[%s] %s: %s', $entity, $key, $operation, $what), '. ');
        parent::__construct($sentence . '.' . self::writtenSentence($written), 0, $previous);
    }

    /** @param array<string, array<string, int>> $written */
    private static function writtenSentence(array $written): string
    {
        if ($written === []) {
            return ' The target confirmed no write before it stopped.';
        }
        $parts = [];
        foreach ($written as $entity => $counts) {
            $done = [];
            foreach ($counts as $what => $count) {
                $done[] = $count . ' ' . $what;
            }
            $parts[] = $entity . ' ' . implode(', ', $done);
        }

        return ' Writes the target confirmed before it stopped: ' . implode('; ', $parts) . '.';
    }
}
