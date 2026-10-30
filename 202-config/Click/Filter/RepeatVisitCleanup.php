<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * The daily cleanup of 202_last_ips, the repeat check's memory (the cron
 * job's daily tier). An address is forgotten once it is a day old, as it
 * always was, unless its account's repeat window is longer and it is still
 * inside that (ClickFilterSettings::WINDOWS, 7 days at most). Every
 * account's rows, by age alone: the install's own housekeeping.
 */
final class RepeatVisitCleanup
{
    private function __construct()
    {
    }

    public static function sql(int $now): string
    {
        $day = $now - 86400;

        return 'DELETE l FROM 202_last_ips l'
            . ' LEFT JOIN 202_click_filter_settings s ON s.user_id = l.user_id'
            . ' WHERE l.time < ' . $day
            . ' AND (s.user_id IS NULL OR s.repeat_visits = 0 OR s.repeat_window <= 86400'
            . ' OR l.time < ' . $now . ' - s.repeat_window)';
    }
}
