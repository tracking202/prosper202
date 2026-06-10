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

    public static function read(string $key, int $maxAgeSeconds = self::TTL_SECONDS): ?bool
    {
        $path = self::path($key);
        if ($path === null || !is_file($path) || is_link($path)) {
            return null;
        }
        if (fileowner($path) !== self::processUserId()) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > $maxAgeSeconds) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        return $content === '1';
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
        $path = self::path($key);
        if ($path === null) {
            return;
        }
        if (@file_put_contents($path, $valid ? '1' : '0') !== false) {
            @chmod($path, 0600);
        }
    }

    public static function invalidate(string $key): void
    {
        $path = self::path($key);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private static function path(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $dir = self::dir();
        if ($dir === null) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key) . '.cache';
    }

    /**
     * Returns the private cache directory, or null if it cannot be created
     * with safe ownership (callers then skip caching entirely rather than
     * trust a directory another user may control).
     */
    private static function dir(): ?string
    {
        $dir = sys_get_temp_dir() . '/p202-shell-access';
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }
        if (is_link($dir) || fileowner($dir) !== self::processUserId()) {
            return null;
        }
        return $dir;
    }

    private static function processUserId(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int)getmyuid();
    }
}
