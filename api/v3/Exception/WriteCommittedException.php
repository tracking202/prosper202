<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * The operation's write reached the database, and something after it failed.
 *
 * Every retry mechanism in the v3 API has to answer one question when a
 * handler throws: did the write happen? An exception on its own cannot say —
 * validation rejecting a payload and a response failing to render after a
 * committed INSERT both arrive as a Throwable — and guessing wrong duplicates
 * the record. Idempotency reservations and staged-change applies both hand
 * the caller a retry on failure, which is right only when nothing was
 * written.
 *
 * So the code that knows says so. A handler wraps the steps that run *after*
 * its write lands, and rethrows as this: the write stands, only the steps
 * after it failed. The seams that offer retries refuse to when they see it —
 * an idempotency claim stays spent, a staged change is closed as interrupted
 * rather than returned to `staged`.
 *
 * Carries the original failure as `previous`; the message names the entity so
 * an operator can find the orphaned record.
 */
final class WriteCommittedException extends HttpException
{
    public function __construct(string $entity, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'The write to %s completed, but the request could not be finished afterwards. The '
                . 'record exists — look it up rather than retrying, or a retry will create a second one.',
                $entity
            ),
            500,
            $previous
        );
    }
}
