<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * What became of a notification. Response maps each to the status the
 * provider's delivery log shows.
 */
enum OutcomeKind: string
{
    case RECORDED = 'recorded';
    case REVERSED = 'reversed';
    /** A retry of a payment or refund already on file, stating the same amount. */
    case DUPLICATE = 'duplicate';
    case TEST = 'test';
    case IGNORED = 'ignored';
    /** Verified, but the notification names no click to credit. */
    case NO_CLICK = 'no_click';
    case MALFORMED = 'malformed';
    /** A body this endpoint does not take at all (another product's notification). */
    case UNSUPPORTED_BODY = 'unsupported_body';
    case UNVERIFIED = 'unverified';
    /** No such click in the accounts whose secret verified (another account's reads the same). */
    case UNKNOWN_CLICK = 'unknown_click';
    case NOTHING_TO_REVERSE = 'nothing_to_reverse';
    /** On file already, and the notification states something else. */
    case CONFLICT = 'conflict';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case METHOD_NOT_ALLOWED = 'method_not_allowed';
    case FAILED = 'failed';
    /** A failure while the database's schema is behind this code's. */
    case UPGRADE_PENDING = 'upgrade_pending';
}
