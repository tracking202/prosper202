<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * A redirector re-click keeps the traffic source and cost of the click's
 * first visit (the source the visitor came from), and takes its campaign,
 * offer and payout from the latest (LatestVisit). Visits written before that
 * rule carry the redirector link's own source and cost on the later rows, so
 * whatever reads a click's source reads the first visit's row.
 *
 * Two rows in the first visit's second (written before 1.9.79, when a
 * re-click in that second added a row rather than rewriting the visit's)
 * are read in whatever order the database gives: the table has no
 * per-visit key to tell them apart.
 */
final class FirstVisit
{
    /**
     * A derived table holding one click's first 202_clicks row, to stand
     * where the statement read `202_clicks`: $clickId is `?` (bound) or the
     * click id as digits.
     */
    public static function of(string $clickId): string
    {
        if ($clickId !== '?' && preg_match('/\A[1-9][0-9]{0,19}\z/', $clickId) !== 1) {
            throw new InvalidArgumentException('A click id is ? or its digits');
        }

        return '(SELECT fv.* FROM 202_clicks AS fv'
            . " WHERE fv.click_id = {$clickId} ORDER BY fv.click_time ASC LIMIT 1)";
    }
}
