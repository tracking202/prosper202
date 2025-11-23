<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Indicates the lifecycle state of an attribution export job.
 */
enum ExportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
