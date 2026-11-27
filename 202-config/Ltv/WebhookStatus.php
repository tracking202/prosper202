<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * An LTV webhook's status (202_ltv_webhooks.status). The server marks an
 * active one dead when a delivery fails MAX_ATTEMPTS times; a revival makes
 * it active again. Deleting it removes the row.
 *
 * The column's ENUM also holds `disabled`, which nothing writes: it is not
 * a state. A row holding it, or any value no case names, is queued nothing
 * and sent nothing, and a revival refuses it (UnknownState).
 */
enum WebhookStatus: string implements State
{
    use Moves;

    case Active = 'active';
    case Dead = 'dead';

    public function next(): array
    {
        return match ($this) {
            self::Active => [self::Dead],
            self::Dead => [self::Active],
        };
    }

    public static function initial(): array
    {
        return [self::Active];
    }
}
