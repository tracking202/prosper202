<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * What InstallIntake::admit() took from an abuse cap for one request: the
 * bucket, how much, and the window it was charged to — everything
 * InstallIntake::refund() needs to give back what the request did not end
 * up recording, to that window and no other.
 */
final class QuotaCharge
{
    public function __construct(
        public readonly string $bucket,
        public readonly int $cost,
        public readonly int $windowStart,
        public readonly int $windowSeconds,
        public readonly int $registrationId,
        public readonly string $column,
    ) {
    }
}
