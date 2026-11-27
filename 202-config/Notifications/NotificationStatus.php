<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * Where a traffic-source notification stands (202_notification_pending.status).
 * NotificationOutbox writes it, and only by the moves next() lists.
 *
 * A send is claimed by the attempt count, not by a state: a row a worker is
 * sending is still `pending`, with the attempt counted. So `attempts`
 * belongs to every question about whether a row went out (mayHaveGoneOut(),
 * reachedOnRetirement()).
 */
enum NotificationStatus: string implements State
{
    use Moves;

    /** Waiting for its first send, being sent, or backing off after a failed one. */
    case Pending = 'pending';
    case Sent = 'sent';
    /** Refused by the sender, or out of attempts. It may still have landed. */
    case Failed = 'failed';
    /** Stopped: its outcome was replaced, retired or revived first. */
    case Cancelled = 'cancelled';
    /** A correction or retraction no correction URL can carry. */
    case Suppressed = 'suppressed';
    /** Its visitor refused consent (AdPlatformConsent). */
    case Withheld = 'withheld';

    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Failed, self::Cancelled, self::Withheld],
            // Pending: a `reached` stopped unsent whose outcome counts again.
            // Sent: the send in flight when the cancel landed was delivered,
            // and the row says what the network holds (CLAUDE.md #13).
            self::Cancelled => [self::Pending, self::Sent],
            self::Suppressed => [self::Cancelled],
            self::Sent, self::Failed, self::Withheld => [],
        };
    }

    public static function initial(): array
    {
        return [self::Pending, self::Suppressed, self::Withheld];
    }

    /** @return list<string> every stored value, in the order the surfaces list them */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** The row may have reached the traffic source: it was sent, or a send was tried. */
    public function mayHaveGoneOut(int $attempts): bool
    {
        return $this === self::Sent || $this === self::Failed || $attempts > 0;
    }

    public const RETIRED_CANCEL = 'cancel';
    public const RETIRED_RETRACT = 'retract';
    public const RETIRED_NOTHING = 'nothing';

    /**
     * What retiring its outcome does with a `reached` row: cancelled while
     * it waits unattempted; taken back (a retraction or correction) where
     * it may have gone out; nothing where it was cancelled or withheld
     * before any attempt.
     *
     * @return self::RETIRED_*
     */
    public function reachedOnRetirement(int $attempts): string
    {
        if ($this === self::Pending && $attempts === 0) {
            return self::RETIRED_CANCEL;
        }
        if ($this === self::Cancelled || ($this === self::Withheld && $attempts === 0)) {
            return self::RETIRED_NOTHING;
        }

        return self::RETIRED_RETRACT;
    }

    /**
     * reachedOnRetirement() counted over `reached` rows in SQL, as select
     * columns `cancel` and `retract` (NotificationStatusIntegrationTest
     * holds the two to one answer).
     *
     * @param string $alias the outbox table's alias with its dot, or ''
     */
    public static function retirementCountsSql(string $alias = ''): string
    {
        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?$/', $alias) !== 1) {
            throw new \InvalidArgumentException('retirementCountsSql() takes an alias, not "' . $alias . '".');
        }
        $status = $alias . 'status';
        $attempts = $alias . 'attempts';
        $unattempted = "($status IN ('" . self::Pending->value . "', '" . self::Withheld->value . "') AND $attempts = 0)";

        return "COALESCE(SUM($status = '" . self::Pending->value . "' AND $attempts = 0), 0) AS cancel, "
            . "COALESCE(SUM($status <> '" . self::Cancelled->value . "' AND NOT $unattempted), 0) AS retract";
    }
}
