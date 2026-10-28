<?php

declare(strict_types=1);

namespace Prosper202\Cron;

/**
 * The cron lock could not be taken for a reason other than another run
 * holding it, so the run did nothing; the message says what to change.
 */
final class CronLockFailed extends \RuntimeException
{
}
