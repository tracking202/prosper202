<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The one way a test deletes a directory's contents: only inside the system
 * temp directory, and never by following a symbolic link.
 *
 * Tests cleaned up with `glob($dir . '/*')` and unlink(), and when setUp
 * stopped before it made $dir (no scratch database, no python3), $dir was ''
 * and the glob was '/*'. As an ordinary user the deletes failed quietly; run
 * as root in a container they removed /bin, /lib, /sbin and /.dockerenv,
 * twice in one session. TestsDeleteOnlyThroughScratchDirTest refuses any
 * other way of deleting what a glob or a directory walk found.
 *
 * '' and a directory that does not exist are nothing to clean up. Anything
 * else outside the temp directory is a mistake in the test, and throws.
 */
final class ScratchDir
{
    private function __construct()
    {
    }

    /** $dir, everything in it, and nothing outside it. */
    public static function remove(string $dir): void
    {
        $root = self::owned($dir);
        if ($root === null) {
            return;
        }
        self::clearTree($root);
        @rmdir($root);
    }

    /**
     * The entries of $dir that $pattern (a glob, GLOB_BRACE) names, and
     * everything under those that are directories. $dir itself stays.
     */
    public static function clear(string $dir, string $pattern = '*'): void
    {
        if (str_contains($pattern, '/') || str_contains($pattern, '..')) {
            throw new \LogicException('ScratchDir::clear() takes a pattern for names in one directory, not a path: ' . $pattern);
        }
        $root = self::owned($dir);
        if ($root === null) {
            return;
        }
        foreach (glob($root . '/' . $pattern, GLOB_BRACE) ?: [] as $path) {
            $name = basename($path);
            if ($name === '.' || $name === '..') {
                continue;
            }
            self::delete($path);
        }
    }

    /** The real path of $dir when it is a directory of the temp directory's, null when there is nothing to do. */
    private static function owned(string $dir): ?string
    {
        if ($dir === '' || !file_exists($dir) || is_link($dir)) {
            return null;
        }
        $real = realpath($dir);
        $temp = realpath(sys_get_temp_dir());
        if ($real === false || $temp === false || !is_dir($real) || !str_starts_with($real, rtrim($temp, '/') . '/')) {
            throw new \LogicException('A test may only delete inside the temp directory (' . sys_get_temp_dir() . '): ' . $dir);
        }

        return $real;
    }

    private static function clearTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::delete($dir . '/' . $name);
            }
        }
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        self::clearTree($path);
        @rmdir($path);
    }
}
