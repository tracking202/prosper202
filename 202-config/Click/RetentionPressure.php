<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * How loaded the server is while click data is being deleted, from
 * RetentionLoadProbe. RetentionPacer turns it into batch sizes and pauses:
 * NONE lets batches grow to what the database handles quickly, MODERATE and
 * HIGH cap them and pause longer between them, SEVERE allows one small
 * batch a run.
 */
enum RetentionPressure: int
{
    case NONE = 0;
    case MODERATE = 1;
    case HIGH = 2;
    case SEVERE = 3;

    public function worse(self $other): self
    {
        return $other->value > $this->value ? $other : $this;
    }
}
