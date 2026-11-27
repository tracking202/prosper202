<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * 202_destination_deliveries.status. Written only by DeliveryRows.
 *
 * A claim is not a state: a row being sent is pending, with its try counted
 * (attempts) and not due again before the try's backoff (next_attempt_at).
 */
enum DeliveryStatus: string implements State
{
    use Moves;

    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Withheld = 'withheld';
    case Cancelled = 'cancelled';

    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Failed, self::Withheld, self::Cancelled],
            // Pending: sent again by the operator, or once a currency is recorded (WithheldFor).
            // Sent: a send that outlived its claim, or began before its destination was deleted,
            // reached the platform whatever the row was moved to meanwhile (CLAUDE.md #13).
            self::Failed, self::Withheld => [self::Pending, self::Sent],
            self::Cancelled => [self::Sent],
            self::Sent => [],
        };
    }

    public static function initial(): array
    {
        // A test (kind 'test') is logged as it ended and never moves.
        return [self::Pending, self::Sent, self::Failed];
    }

    /**
     * The states the operator can send a row again from.
     *
     * @return list<self>
     */
    public static function resendable(): array
    {
        return self::sources(self::Pending);
    }

    /** What the pages call it. */
    public function label(): string
    {
        return $this === self::Pending ? 'waiting' : $this->value;
    }

    /** The value as an SQL string literal: a case's value is a word, never input. */
    public function quoted(): string
    {
        return "'" . $this->value . "'";
    }

    /** @param list<self> $states */
    public static function quotedList(array $states): string
    {
        return implode(',', array_map(static fn (self $s): string => $s->quoted(), $states));
    }

    /**
     * @param list<self>|null $states every state when null
     * @return list<string>
     */
    public static function values(?array $states = null): array
    {
        return array_map(static fn (self $s): string => $s->value, $states ?? self::cases());
    }
}
