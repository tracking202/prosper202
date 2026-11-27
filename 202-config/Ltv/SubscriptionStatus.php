<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * A subscription's status (202_subscriptions.status).
 *
 * The billing system's word decides: an upsert may state any status, for a
 * new subscription or an old one, so every state is initial and none is
 * terminal. A canceled subscription that is renewed, or upserted with
 * another status, is one the billing system reactivated. The sweep asks for
 * its own, narrower moves (lapsesTo()).
 *
 * canceled_at is set exactly while the status is canceled: every writer
 * that moves a subscription to or from canceled writes it too.
 */
enum SubscriptionStatus: string implements State
{
    use Moves;

    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Canceled = 'canceled';

    public function next(): array
    {
        return array_values(array_filter(self::cases(), fn (self $to): bool => $to !== $this));
    }

    public static function initial(): array
    {
        return self::cases();
    }

    /**
     * Where the sweep moves a subscription whose period and grace are over:
     * past due, or canceled once the cancel window is over too. Empty for a
     * status that does not lapse.
     *
     * @return list<self>
     */
    public function lapsesTo(): array
    {
        return match ($this) {
            self::Active, self::Trialing, self::Paused => [self::PastDue, self::Canceled],
            self::PastDue => [self::Canceled],
            self::Canceled => [],
        };
    }

    /**
     * The statuses the sweep reads, in the order it takes them.
     *
     * @return list<self>
     */
    public static function lapsing(): array
    {
        return [self::Active, self::Trialing, self::Paused, self::PastDue];
    }

    /** @return list<string> the stored values, in the column's order */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
