<?php

declare(strict_types=1);

namespace Prosper202\Cron;

/**
 * Whether Account › Settings leaves AutoCron off because this install has
 * its own cron job: a scheduler's run in the last five minutes, read from
 * 202_cronjob_logs, which records only schedulers' runs (CronRun). With
 * AutoCron already on, the runs recorded may be AutoCron's own, so saving On
 * again registers again (the service takes that as a refresh) rather than
 * reading them as a cron job.
 */
final class AutoCronSwitch
{
    public const WINDOW_SECONDS = 300;

    /**
     * What Settings says when a recent run leaves AutoCron off. The record is
     * not proof of a cron job (the cron's address needs no sign-in, so anyone
     * can make one), so the page then offers to turn AutoCron on anyway.
     */
    public const RAN_RECENTLY = 'A cron job ran on this install in the last five minutes, so AutoCron may not be'
        . ' needed and was left off. Anyone can open this install\'s cron address, so that run may not be your'
        . ' own cron job\'s: if you have none, turn AutoCron on with the button below.';

    /** The switch's own value: '1' (On) or '0' (Off); anything else is no answer. */
    public static function posted(mixed $value): ?bool
    {
        return \Prosper202\Http\PostedSwitch::read($value, '1', '0');
    }

    /** @param ?int $lastScheduledRun unix time of row 1, null when there is none */
    public static function ownCronJobRanRecently(?int $lastScheduledRun, bool $autoCronOn, int $now): bool
    {
        return !$autoCronOn && $lastScheduledRun !== null && $lastScheduledRun >= $now - self::WINDOW_SECONDS;
    }
}
