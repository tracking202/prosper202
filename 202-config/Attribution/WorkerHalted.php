<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The worker stopped because the database failed, not because a row did:
 * every pending row is left as it was, for the next run to retry.
 */
final class WorkerHalted extends \RuntimeException
{
}
