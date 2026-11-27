<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * An LTV webhook delivery's status (202_ltv_webhook_deliveries.status).
 * Queued pending, and a retry leaves it pending. Failed is where it rests
 * undelivered, for one of the reasons below or because its attempts ran
 * out; a revival with requeue makes a failed one pending again. Delivered
 * is final: what the receiver took is never sent again.
 */
enum DeliveryStatus: string implements State
{
    use Moves;

    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * Why a delivery was set aside unsent, as its last_response_body holds
     * it. A failed delivery with none of these was tried MAX_ATTEMPTS times,
     * and holds the receiver's last answer.
     */
    public const HELD = 'held: its webhook is dead, so it was not sent; reviving the webhook within 7 days'
        . ' of when this was queued sends it';
    public const HELD_ON_REVIVAL = 'held: its webhook was revived for new events only, so it was not sent';
    public const EXPIRED = 'expired: still unsent 7 days after it was queued, so it was not sent';

    /** The last response of an attempt not made: the webhook's stored status is one WebhookStatus does not name. */
    public const WEBHOOK_NOT_ACTIVE = 'endpoint not active';

    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Delivered, self::Failed],
            self::Failed => [self::Pending],
            self::Delivered => [],
        };
    }

    public static function initial(): array
    {
        return [self::Pending];
    }

    /** @return list<string> the stored values, in the column's order */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
