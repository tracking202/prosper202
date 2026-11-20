<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The visitors a click script sent on with no click saved: dl.php, lp.php and
 * off.php, when the database refused the connection, redirect to the URL they
 * keep in memcache for an outage, and the click is lost. Under load the refusal
 * is MySQL's max_connections, and the only other trace is a line in the PHP
 * error log, so the count is kept here for the health checks and Settings.
 *
 * It is a file in the install's own 202-config/temp/, never a table: it is
 * written when the database is out of reach, and read by pages that cannot
 * know which host a click arrived on (a memcache key under systemHash() names
 * the host). Each web server or container keeps its own, and one started
 * again without that directory starts without it.
 *
 * Counting never holds a visitor up: a count waits at most about 30 ms for
 * another one to finish and is dropped after that, so under contention the
 * count can come out low, never high. A new count is written to a file of its
 * own and renamed into place, so a write that fails (a full disk) or stops
 * half way leaves the previous count whole.
 */
final class UnsavedClickRedirects
{
    public const FILE = '202-config/temp/unsaved-click-redirects.json';

    /** How long after the last one the health checks and Settings warn. */
    public const RECENT_SECONDS = 7 * 86400;

    /** Tries at the lock, 5 ms apart, before the count is dropped. */
    private const LOCK_TRIES = 7;

    /** More than the file ever holds; a longer one is unreadable, never read whole. */
    private const MAX_BYTES = 4096;

    /** How long a memcache-backed install keeps quiet after logging a count it could not keep. */
    private const LOG_EVERY_SECONDS = 300;

    public static function path(string $root): string
    {
        return rtrim($root, '/') . '/' . self::FILE;
    }

    /**
     * Counts one visitor sent on unsaved. False when the count was not kept
     * (the directory cannot be written, the lock stayed taken, the write
     * failed); that is logged, and the visitor is redirected all the same.
     */
    public static function record(string $root, string $script, ?int $now = null): bool
    {
        $now ??= time();
        $path = self::path($root);
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            self::logOnce($path, "Prosper202: $script sent a visitor on without saving the click"
                . ' (no database connection), and '
                . dirname($path) . ' cannot be written to count it: clicks lost this way are not counted.');

            return false;
        }
        try {
            $locked = false;
            for ($try = 0; $try < self::LOCK_TRIES && !$locked; $try++) {
                if ($try > 0) {
                    usleep(5000);
                }
                $locked = flock($lock, LOCK_EX | LOCK_NB);
            }
            if (!$locked) {
                // Another count is being written: this one is dropped rather
                // than keep the visitor waiting. Not logged; it is contention.
                return false;
            }
            $current = self::parse(self::contents($path));
            // An unreadable count starts again from this one: its date says so.
            $counted = $current ?? ['count' => 0, 'first_at' => $now, 'last_at' => $now];
            $body = json_encode([
                'count' => $counted['count'] + 1,
                'first_at' => $counted['first_at'],
                'last_at' => $now,
            ], JSON_THROW_ON_ERROR);
            $temp = $path . '.tmp';
            $written = @file_put_contents($temp, $body) === strlen($body) && @rename($temp, $path);
            if (!$written) {
                @unlink($temp);
                self::logOnce($path, "Prosper202: $script sent a visitor on without saving the click,"
                    . " and the count in $path could not be written.");
            }

            return $written;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * What the file says, never waiting on a count being written:
     * - `none`: no file, and one can be written (nothing counted on this
     *   server since 202-config/temp/ was made);
     * - `counted`: the count and the first and last times;
     * - `unreadable`: the file is there and cannot be read, never read as 0;
     * - `unrecordable`: this server cannot write the count, so a click lost
     *   now is not counted (with whatever count the file still holds).
     *
     * @return array{state: 'none'|'counted'|'unreadable'|'unrecordable', count: ?int, first_at: ?int, last_at: ?int}
     */
    public static function read(string $root): array
    {
        $path = self::path($root);
        $dir = dirname($path);
        clearstatcache(true, $path);
        clearstatcache(true, $path . '.lock');
        $recordable = is_dir($dir) && is_writable($dir)
            && (!file_exists($path . '.lock') || is_writable($path . '.lock'));
        $nothing = ['count' => null, 'first_at' => null, 'last_at' => null];
        if (!file_exists($path)) {
            return $recordable
                ? ['state' => 'none', 'count' => 0, 'first_at' => null, 'last_at' => null]
                : ['state' => 'unrecordable'] + $nothing;
        }
        $counted = self::parse(self::contents($path));
        if (!$recordable) {
            return ['state' => 'unrecordable'] + ($counted ?? $nothing);
        }

        return $counted === null ? ['state' => 'unreadable'] + $nothing : ['state' => 'counted'] + $counted;
    }

    /**
     * The sentence the signed-in health check and Settings show, or null: the
     * last one counted in the past 7 days, a count that cannot be read, or one
     * that cannot be kept.
     *
     * @param array{state: string, count: ?int, first_at: ?int, last_at: ?int} $read
     */
    public static function warning(array $read, ?int $now = null): ?string
    {
        $now ??= time();
        if ($read['state'] === 'unreadable') {
            return 'The count of clicks redirected without being saved (' . self::FILE . ') cannot be read.';
        }
        if ($read['state'] === 'unrecordable') {
            return 'Clicks redirected without being saved while the database is out of reach cannot be counted on this'
                . ' server: the web server cannot write to 202-config/temp/. Make that directory writable by the web'
                . ' server\'s user.';
        }
        if (!self::recent($read, $now)) {
            return null;
        }
        $count = (int) $read['count'];

        return sprintf(
            '%s redirected without being saved since %s, the last at %s UTC: the database refused the connection '
                . '(too many connections, or down). The visitors reached the offer; the clicks are not in any report.',
            $count === 1 ? '1 click was' : number_format($count) . ' clicks were',
            gmdate('Y-m-d H:i', (int) $read['first_at']),
            gmdate('Y-m-d H:i', (int) $read['last_at'])
        );
    }

    /**
     * What the unauthenticated health probe says: whether there is anything
     * to look at, and never the count, the times or the file's path.
     *
     * @param array{state: string, count: ?int, first_at: ?int, last_at: ?int} $read
     * @return array{state: 'ok'|'warning'}
     */
    public static function publicFields(array $read, ?int $now = null): array
    {
        return ['state' => self::publicWarning($read, $now) === null ? 'ok' : 'warning'];
    }

    /** @param array{state: string, count: ?int, first_at: ?int, last_at: ?int} $read */
    public static function publicWarning(array $read, ?int $now = null): ?string
    {
        $now ??= time();

        return match (true) {
            $read['state'] === 'unreadable' => 'The count of clicks redirected without being saved cannot be read.'
                . ' Account › Settings says more.',
            $read['state'] === 'unrecordable' => 'Clicks redirected without being saved cannot be counted on this'
                . ' server.'
                . ' Account › Settings says why.',
            self::recent($read, $now) => 'Clicks were redirected without being saved in the past 7 days: the database'
                . ' refused the connection. Account › Settings and GET /api/v3/system/info say how many.',
            default => null,
        };
    }

    /** @param array{state: string, count: ?int, first_at: ?int, last_at: ?int} $read */
    private static function recent(array $read, int $now): bool
    {
        return $read['state'] === 'counted' && $read['last_at'] !== null
            && $now - $read['last_at'] <= self::RECENT_SECONDS;
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

    /** The file's first bytes, '' when there is none; a file longer than any count reads as unreadable. */
    private static function contents(string $path): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }
        try {
            $body = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        return is_string($body) && strlen($body) <= self::MAX_BYTES ? $body : '';
    }

    /**
     * Logs a count that could not be kept: once a request, and where memcache
     * runs once every few minutes for the install, so an outage does not
     * write a line for every click it loses.
     */
    private static function logOnce(string $path, string $line): void
    {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;
        $memcache = $GLOBALS['memcache'] ?? null;
        $working = ($GLOBALS['memcacheWorking'] ?? false) === true;
        if ($working && is_object($memcache) && method_exists($memcache, 'add')) {
            $key = 'p202-unsaved-click-log-' . md5($path);
            try {
                $first = $memcache instanceof \Memcache
                    ? $memcache->add($key, 1, 0, self::LOG_EVERY_SECONDS)
                    : $memcache->add($key, 1, self::LOG_EVERY_SECONDS);
            } catch (\Throwable) {
                $first = true;
            }
            if ($first === false) {
                return;
            }
        }
        error_log($line);
    }
}
