<?php

declare(strict_types=1);

namespace Api\V3\Exception;

/**
 * Internal control-flow marker, never surfaced to callers: thrown inside the
 * /ltv/revenue write transaction when the insert loses a concurrent race on
 * the idempotency key, so the whole transaction — including any customer or
 * alias this request resolved/created — rolls back. The controller catches it
 * and re-reads the winner's event outside the rolled-back snapshot.
 */
final class LostIdempotencyRaceException extends \RuntimeException
{
}
