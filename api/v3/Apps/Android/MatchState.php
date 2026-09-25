<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\Verdict;

/**
 * What the Android intake concluded about one install (plan §5.3): the
 * Android signal source's Verdict. The backing values are what
 * 202_app_installs.match_state stores and what the API filters on.
 *
 *   state              trust  meaning
 *   attributed         1      MAC verified, click found and owned, timing
 *                             plausible, inside the window
 *   organic            null   Play's organic referrer, or no referrer
 *   third_party        null   gclid, Meta's envelope, another tracker's
 *                             referrer; the parsed fields are stored
 *   unavailable        null   the referrer API was not available
 *   pending_click      null   token valid, click row not written yet; the
 *                             cron settles it within 24 h
 *   bad_token          0      MAC fails, malformed, or the click was never
 *                             recorded
 *   foreign_click      0      the click belongs to another user, or to a
 *                             campaign linked to another registration
 *   implausible        0      timing contradicts the click
 *   outside_window     null   later than attribution_window_days
 *   duplicate_click    null   the click already has an install conversion
 *   pending_integrity  null   the install would be attributed, and its
 *                             registration requires Play Integrity: it
 *                             waits for the verdict worker
 *   integrity_failed   0      it would be attributed, but its Play Integrity
 *                             verdict failed the policy (or it sent another
 *                             install's token)
 *   integrity_unverified null it would be attributed, but no verdict could
 *                             be had: no token sent, or none decoded before
 *                             the worker's deadline. Recorded, never paid
 *
 * A test install (the SDK's `test: true`) is judged the same way; what the
 * test flag changes is priced by InstallVerdict, which pairs a state with it.
 */
enum MatchState: string implements Verdict
{
    case ATTRIBUTED = 'attributed';
    case ORGANIC = 'organic';
    case THIRD_PARTY = 'third_party';
    case UNAVAILABLE = 'unavailable';
    case PENDING_CLICK = 'pending_click';
    case BAD_TOKEN = 'bad_token';
    case FOREIGN_CLICK = 'foreign_click';
    case IMPLAUSIBLE = 'implausible';
    case OUTSIDE_WINDOW = 'outside_window';
    case DUPLICATE_CLICK = 'duplicate_click';
    case PENDING_INTEGRITY = 'pending_integrity';
    case INTEGRITY_FAILED = 'integrity_failed';
    case INTEGRITY_UNVERIFIED = 'integrity_unverified';

    public function trustBit(AppPolicy $policy): ?int
    {
        return match ($this) {
            self::ATTRIBUTED => 1,
            self::BAD_TOKEN, self::FOREIGN_CLICK, self::IMPLAUSIBLE, self::INTEGRITY_FAILED => 0,
            self::ORGANIC, self::THIRD_PARTY, self::UNAVAILABLE, self::PENDING_CLICK,
            self::OUTSIDE_WINDOW, self::DUPLICATE_CLICK, self::PENDING_INTEGRITY, self::INTEGRITY_UNVERIFIED => null,
        };
    }

    /** Whether the state is test-ness: none is; the flag rides beside the state (InstallVerdict). */
    public function isTest(): bool
    {
        return false;
    }

    /** A state a later job settles; events wait for it. */
    public function isPending(): bool
    {
        return $this === self::PENDING_CLICK || $this === self::PENDING_INTEGRITY;
    }

    /** A state that was checked and found false. */
    public function isRefuted(): bool
    {
        return $this->trustBit(AppPolicy::untrusting()) === 0;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
