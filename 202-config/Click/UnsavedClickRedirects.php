<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The visitors a click script sent on with no click saved: dl.php, lp.php and
 * off.php, when the database refused the connection, redirect to the URL they
 * keep in memcache for an outage, and the click is lost. Under load the refusal
 * is MySQL's max_connections, and the only other trace is a line in the PHP
 * error log, so the count is kept here for the health check and Settings.
 *
 * It is a file in the install's own 202-config/temp/, never a table: it is
 * written when the database is out of reach, and read by pages that cannot
 * know which host a click arrived on (a memcache key under systemHash() names
 * the host). A container that is started again without that directory starts
 * without the file, which reads as "none counted", with no date to count from.
 */
final class UnsavedClickRedirects
{
    public const FILE = '202-config/temp/unsaved-click-redirects.json';

    /** How long after the last one the health check and Settings warn. */
    public const RECENT_SECONDS = 7 * 86400;

    public static function path(string $root): string
    {
        return rtrim($root, '/') . '/' . self::FILE;
    }

    /**
     * Counts one visitor sent on unsaved. False when the count could not be
     * written; that is logged, and the visitor is redirected all the same.
     */
    public static function record(string $root, string $script, ?int $now = null): bool
    {
        $now ??= time();
        $path = self::path($root);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            error_log("Prosper202: $script sent a visitor on without saving the click (no database connection), and $path could not be opened to count it.");

            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                error_log("Prosper202: $script sent a visitor on without saving the click, and $path could not be locked to count it.");

                return false;
            }
            $current = self::parse((string) stream_get_contents($handle));
            // An unreadable count starts again from this one: its date says so.
            $counted = $current ?? ['count' => 0, 'first_at' => $now, 'last_at' => $now];
            $next = [
                'count' => $counted['count'] + 1,
                'first_at' => $counted['first_at'],
                'last_at' => $now,
            ];
            $body = json_encode($next, JSON_THROW_ON_ERROR);
            $written = ftruncate($handle, 0) && rewind($handle)
                && fwrite($handle, $body) === strlen($body) && fflush($handle);
            if (!$written) {
                error_log("Prosper202: $script sent a visitor on without saving the click, and the count in $path could not be written.");
            }

            return $written;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * What the file says: `none` when there is no file (nothing counted since
     * 202-config/temp/ was made), `counted` with the count and the first and
     * last times, or `unreadable` when the file is there and cannot be read,
     * which is never read as zero.
     *
     * @return array{state: 'none'|'counted'|'unreadable', count: ?int, first_at: ?int, last_at: ?int}
     */
    public static function read(string $root): array
    {
        $path = self::path($root);
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return ['state' => 'none', 'count' => 0, 'first_at' => null, 'last_at' => null];
        }
        $unreadable = ['state' => 'unreadable', 'count' => null, 'first_at' => null, 'last_at' => null];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $unreadable;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return $unreadable;
            }
            $counted = self::parse((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($counted === null) {
            return $unreadable;
        }

        return ['state' => 'counted'] + $counted;
    }

    /**
     * The sentence the health check and Settings show while the last one is
     * recent, or null.
     *
     * @param array{state: string, count: ?int, first_at: ?int, last_at: ?int} $read
     */
    public static function warning(array $read, ?int $now = null): ?string
    {
        $now ??= time();
        if ($read['state'] === 'unreadable') {
            return 'The count of clicks redirected without being saved (' . self::FILE . ') cannot be read.';
        }
        if ($read['state'] !== 'counted' || $read['last_at'] === null || $now - $read['last_at'] > self::RECENT_SECONDS) {
            return null;
        }
        $count = (int) $read['count'];

        return sprintf(
            '%s redirected without being saved since %s, the last at %s UTC: the database refused the connection '
                . '(too many connections, or down). The visitors reached the offer; the clicks are not in any report.',
            $count === 1 ? '1 click was' : number_format($count) . ' clicks were',
            gmdate('Y-m-d H:i', (int) $read['first_at']),
            gmdate('Y-m-d H:i', $read['last_at'])
        );
    }

    /** @return array{count: int, first_at: int, last_at: int}|null */
    private static function parse(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        foreach (['count', 'first_at', 'last_at'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 0) {
                return null;
            }
        }

        return ['count' => $data['count'], 'first_at' => $data['first_at'], 'last_at' => $data['last_at']];
    }
}
