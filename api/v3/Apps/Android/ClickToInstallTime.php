<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppLimits;

/**
 * Click-to-install time (CTIT, plan §7.1): how long after its click an
 * install began, and which tail of the distribution, if either, it falls in.
 * Pure, so the intake, the pending-click settler and the integrity worker
 * measure alike and tests can drive it without a database.
 *
 * The install's start is Google's server install-begin time, the clock the
 * classifier's timing rules already trust; an install Play gave no server
 * time for is measured from its receipt instead (it is `implausible` for
 * the missing timestamp anyway, and the receipt is the latest it can have
 * begun). The click's time is ours, `202_clicks.click_time`. A negative
 * CTIT is possible within the classifier's clock-skew allowance and is
 * stored as it is: it is `short`.
 *
 * A flag marks an install for the report; it never refuses one. Refusal
 * stays where it was: install-begin before Google's own click time is click
 * injection and `implausible` (InstallClassifier), whatever the CTIT.
 */
final class ClickToInstallTime
{
    public const SHORT = 'short';
    public const OK = 'ok';
    public const LONG = 'long';
    /** The report's name for an install with no CTIT (no click of this account to measure from). */
    public const UNMEASURED = 'unmeasured';

    /** @return list<string> every value the report's ctit_flag filter takes */
    public static function filterValues(): array
    {
        return [self::SHORT, self::OK, self::LONG, self::UNMEASURED];
    }

    private function __construct()
    {
    }

    public static function seconds(?int $installBeginServerAt, int $receivedAt, int $clickTime): int
    {
        return ($installBeginServerAt ?? $receivedAt) - $clickTime;
    }

    /** `short` under the registration's minimum, `long` over its maximum, `ok` between. */
    public static function flag(int $seconds, AppLimits $limits): string
    {
        if ($seconds < $limits->ctitMinSeconds) {
            return self::SHORT;
        }
        if ($seconds > $limits->ctitMaxSeconds) {
            return self::LONG;
        }

        return self::OK;
    }
}
