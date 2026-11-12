<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * Paid 1.9.56's scheduled Google Ads feed,
 * tracking202/static/feeds/google/offlineconversions/?cn=…&ac=…, answered
 * 410 Gone with where its replacement is made. Its HTTP Basic credentials
 * are not carried into this version; a fetch URL (FeedStore) carries a key
 * of its own account instead. A fetch that names a schedule is noted
 * (LegacyGoogleFeedFetches) after the answer, so Setup › Offline Conversions
 * can say Google Ads is still fetching it; the answer never waits on that.
 */
final class LegacyGoogleFeed
{
    public const STATUS = 410;

    private const SELF = 'tracking202/static/feeds/google/offlineconversions/index.php';
    private const SETUP = 'tracking202/setup/offline_conversions.php';

    /** The Setup › Offline Conversions path on the install this script is served from. */
    public static function setupPath(string $scriptName): string
    {
        $prefix = str_ends_with($scriptName, '/' . self::SELF)
            ? substr($scriptName, 0, -strlen(self::SELF))
            : '/';

        return $prefix . self::SETUP;
    }

    public static function body(string $setupPath): string
    {
        return "This Google Ads offline conversion feed was retired in Prosper202 1.9.80 and sends no conversions.\r\n"
            . "Its user name and password are not carried into this version, so it no longer serves a file.\r\n"
            . "\r\n"
            . "To keep uploading conversions:\r\n"
            . "1. In Prosper202, open Setup > Offline Conversions (" . $setupPath . ").\r\n"
            . "   It lists this schedule and moves it over with one button, which makes its new URL.\r\n"
            . "   By hand: under Conversion names, enter this schedule's conversion action (its cn= value) on\r\n"
            . "   the row of the campaign it named (ac=), or on Every other campaign if it had ac=--; under\r\n"
            . "   Fetch URLs, make a URL in the Google Ads Uploads template format for the last 90 days.\r\n"
            . "2. In Google Ads, Goals > Conversions > Uploads > Schedules, replace this schedule's URL with it.\r\n"
            . "   The key is in the new URL; Google Ads still asks for a user name and password,\r\n"
            . "   so enter anything.\r\n";
    }

    public static function refuse(string $scriptName): void
    {
        error_log('Prosper202: the retired Google Ads feed (tracking202/static/feeds/google/offlineconversions/)'
            . ' was fetched and answered 410; replace it in Google Ads with a fetch URL'
            . ' from Setup > Offline Conversions.');
        http_response_code(self::STATUS);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo self::body(self::setupPath($scriptName));
    }

    /**
     * Note the schedule a fetch named, on the connection 202-config.php
     * made. Never throws: the 410 is already sent, and a database that is
     * down or not upgraded yet only costs the note.
     *
     * @param array<array-key, mixed> $query
     */
    public static function note(mixed $db, array $query, int $now): void
    {
        $schedule = LegacyGoogleFeedFetches::schedule($query);
        if ($schedule === null) {
            return;
        }
        try {
            if (!$db instanceof \mysqli) {
                throw new \RuntimeException('no database connection');
            }
            if (!(new LegacyGoogleFeedFetches(new \Prosper202\Database\Connection($db)))->noteFetch($schedule, $now)) {
                error_log('Prosper202: the retired Google Ads feed was fetched for a schedule that was not noted:'
                    . ' it names no campaign of this install, or as many schedules as are kept were fetched in the last '
                    . intdiv(LegacyGoogleFeedFetches::IDLE_FOR, 86400) . ' days.');
            }
        } catch (\Throwable $e) {
            error_log('Prosper202: a fetch of the retired Google Ads feed could not be noted for'
                . ' Setup > Offline Conversions: ' . $e->getMessage());
        }
    }
}
