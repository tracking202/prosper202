<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\RetentionClass;

/**
 * The Android source's retention classes (plan §4.6): which install rows the
 * open intake lets strangers mint, and how long each is kept. Trusted rows
 * are operator data and kept for good.
 *
 *   installs/refuted    trusted = 0 (bad_token, foreign_click, implausible):
 *                       90 days
 *   installs/unvouched  trusted IS NULL, settled (a pending install is still
 *                       being decided) and without events: 180 days. An
 *                       install that has reported events carries a funnel
 *                       the operator reads, and is kept like a trusted one;
 *                       the per-peer rate limit and the per-install event
 *                       cap bound how many such rows a stranger can mint.
 *
 * Pruning an install row leaves the goal outcomes it reached (the funnel
 * counts), exactly as a pruned postback leaves the report rows it fed.
 */
final class InstallRetention
{
    public const DEFAULT_RETENTION_DAYS_REFUTED = 90;
    public const DEFAULT_RETENTION_DAYS_UNVOUCHED = 180;

    private function __construct()
    {
    }

    /** @return list<RetentionClass> */
    public static function retentionClasses(): array
    {
        return [
            new RetentionClass('202_app_installs', 'refuted', 'trusted = 0', 'received_at', self::DEFAULT_RETENTION_DAYS_REFUTED),
            new RetentionClass(
                '202_app_installs',
                'unvouched',
                "trusted IS NULL AND match_state NOT IN ('pending_click', 'pending_integrity') AND has_events = 0",
                'received_at',
                self::DEFAULT_RETENTION_DAYS_UNVOUCHED
            ),
        ];
    }
}
