<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * File-backed cache of CLI shell license checks, shared by web auth (which
 * warms and invalidates entries as it validates keys) and the v3 capabilities
 * endpoint (which reads and refreshes them).
 *
 * Entries live in a private 0700 directory under the system temp dir. The
 * directory and every entry must be owned by the current process user and
 * must not be symlinks; anything else is ignored. This prevents other local
 * users on a shared host from granting or revoking shell access by
 * pre-creating files at the predictable hashed paths.
 */
class ShellAccessCache
{
    /** Entries older than this require revalidation against ClickServer. */
    public const int TTL_SECONDS = 3600;

    /**
     * How long a check that got no usable answer holds off the next one.
     * An outage is not a verdict and is never written as one; this keeps
     * every request made during one from paying the connect timeout again.
     */
    public const int UNKNOWN_TTL_SECONDS = 60;

    public static function read(string $key, int $maxAgeSeconds = self::TTL_SECONDS): ?bool
    {
        $path = static::path($key);
        if ($path === null || !is_file($path) || is_link($path)) {
            return null;
        }
        if (fileowner($path) !== static::processUserId()) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > $maxAgeSeconds) {
            return null;
        }
        $content = @file_get_contents($path);
        // Only an exact entry is a verdict. An empty or partial file (an
        // interrupted write, a full disk) is "unknown", so the caller
        // revalidates instead of treating it as a denial.
        if ($content === '1') {
            return true;
        }
        if ($content === '0') {
            return false;
        }
        return null;
    }

    /**
     * Last known result regardless of age, for fallback when revalidation
     * is impossible (ClickServer unreachable).
     */
    public static function readStale(string $key): ?bool
    {
        return self::read($key, PHP_INT_MAX);
    }

    public static function write(string $key, bool $valid): void
    {
        $path = static::path($key);
        if ($path === null) {
            return;
        }
        // A verdict ends the hold-off an unanswered check may have left.
        @unlink(self::unknownMarker($path));
        self::put($path, $valid ? '1' : '0');
    }

    /**
     * Record that the last check of this key got no usable answer, so the
     * next UNKNOWN_TTL_SECONDS of requests use the last verdict (readStale)
     * instead of each asking ClickServer again. No verdict is written.
     */
    public static function markUnknown(string $key): void
    {
        $path = static::path($key);
        if ($path !== null) {
            self::put(self::unknownMarker($path), '?');
        }
    }

    /** Whether a check of this key went unanswered less than $maxAgeSeconds ago. */
    public static function recentlyUnknown(string $key, int $maxAgeSeconds = self::UNKNOWN_TTL_SECONDS): bool
    {
        $path = static::path($key);
        if ($path === null) {
            return false;
        }
        $marker = self::unknownMarker($path);
        if (!is_file($marker) || is_link($marker) || fileowner($marker) !== static::processUserId()) {
            return false;
        }
        $mtime = filemtime($marker);
        return $mtime !== false && (time() - $mtime) <= $maxAgeSeconds;
    }

    public static function invalidate(string $key): void
    {
        $path = static::path($key);
        if ($path !== null) {
            @unlink($path);
            @unlink(self::unknownMarker($path));
        }
    }

    private static function unknownMarker(string $path): string
    {
        return $path . '.unknown';
    }

    /**
     * Write a private temp file and rename it into place, so a reader never
     * sees a half-written entry.
     */
    private static function put(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false
            || !@chmod($tmp, 0600)
            || !@rename($tmp, $path)) {
            @unlink($tmp);
            // Not fatal (the next request just revalidates), but worth a trace
            // so repeated external validation calls can be diagnosed.
            error_log('ShellAccessCache: failed to write ' . $path);
        }
    }

    protected static function path(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $dir = static::dir();
        if ($dir === null) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key) . '.cache';
    }

    /** Directory name under the system temp dir; subclasses keep separate caches. */
    protected static function cacheDirName(): string
    {
        return 'p202-shell-access';
    }

    /**
     * Returns the private cache directory, or null if it cannot be created
     * with safe ownership (callers then skip caching entirely rather than
     * trust a directory another user may control).
     */
    protected static function dir(): ?string
    {
        $dir = sys_get_temp_dir() . '/' . static::cacheDirName();
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            error_log(static::class . ': cannot create ' . $dir . '; nothing is cached');
            return null;
        }
        if (is_link($dir) || fileowner($dir) !== static::processUserId()) {
            // Refused rather than trusted, and said: without a cache every
            // request revalidates, the outage hold-off included.
            error_log(static::class . ': ' . $dir . ' is a symlink or owned by another user; nothing is cached');
            return null;
        }
        return $dir;
    }

    protected static function processUserId(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int)getmyuid();
    }
}
