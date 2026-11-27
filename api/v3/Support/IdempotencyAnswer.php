<?php

declare(strict_types=1);

namespace Api\V3\Support;

/** What a request carrying an Idempotency-Key is to do, as ServerStateStore answers it. */
enum IdempotencyAnswer: string
{
    /** Nothing to replay; only a read answers this, and it holds no claim. */
    case Miss = 'miss';
    /** The key is now this request's: run, then record or release. */
    case Claimed = 'claimed';
    /** Answer the recorded response. */
    case Replay = 'replay';
    /** Another request holds the key and has not finished. */
    case InFlight = 'in_flight';
    /** A request held the key and whether its write landed is unknown: neither replay nor run. */
    case Indeterminate = 'indeterminate';
    /** The key was used for a different request. */
    case Mismatch = 'mismatch';
}
