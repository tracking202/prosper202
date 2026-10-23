<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Where a signed-in visitor who reached the 404 most likely meant to go.
 * 1.9.79 renamed tracking202/setup/ and tracking202/analyze/ to Setup/ and
 * Analyze/ (#250), so bookmarks and old links name the old spelling.
 */
final class NotFoundPage
{
    /** The old spelling of each folder 1.9.79 renamed => the current one. */
    public const MOVED = [
        'tracking202/setup' => 'tracking202/Setup',
        'tracking202/analyze' => 'tracking202/Analyze',
    ];

    private function __construct()
    {
    }

    /**
     * The request's path relative to the install, decoded, or null when the
     * request is not below the install's base path.
     */
    public static function requestPath(string $requestUri, string $base): ?string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $path = rawurldecode($path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : null;
    }

    /**
     * The page a path most likely meant, relative to the install: a renamed
     * folder's old spelling replaced by the current one, or else the path as
     * the install spells it when exactly one entry matches each segment with
     * case ignored. Null when that names no page, or the path itself.
     */
    public static function suggestion(string $root, string $relative): ?string
    {
        $segments = explode('/', trim($relative, '/'));
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || strpbrk($segment, "\0\\") !== false) {
                return null;
            }
        }
        $path = implode('/', $segments);
        foreach (self::MOVED as $old => $new) {
            if ($path === $old || str_starts_with($path, $old . '/')) {
                return self::page($root, $new . substr($path, strlen($old)));
            }
        }

        $dir = rtrim($root, '/');
        $found = [];
        foreach ($segments as $segment) {
            $entries = is_dir($dir) ? scandir($dir) : false;
            if ($entries === false) {
                return null;
            }
            if (!in_array($segment, $entries, true)) {
                $matches = array_values(array_filter(
                    $entries,
                    static fn (string $entry): bool => strcasecmp($entry, $segment) === 0
                ));
                if (count($matches) !== 1) {
                    return null; // none, or two spellings: no guess
                }
                $segment = $matches[0];
            }
            $found[] = $segment;
            $dir .= '/' . $segment;
        }
        $candidate = implode('/', $found);

        return $candidate === $path ? null : self::page($root, $candidate);
    }

    /**
     * When $script runs from a leftover copy of a renamed folder, its path
     * relative to the install (suggestion() names where it is now), else
     * null. An upgrade that
     * copies the files over the old ones on Linux leaves tracking202/setup/
     * beside tracking202/Setup/, and its old pages would run as they were; on
     * a disk that ignores case the two spellings are one folder, and a page
     * asked for as setup/ is no leftover.
     */
    public static function leftoverCopy(string $root, string $script): ?string
    {
        $root = realpath($root);
        if ($root === false) {
            return null;
        }
        // The path as the server gave it first: a disk that ignores case
        // keeps the spelling asked for there, and resolving it could not tell
        // the two spellings apart. Resolved, for a document root that is a
        // link to the install.
        $relative = null;
        foreach ([$script, realpath($script)] as $file) {
            if (
                is_string($file) && str_starts_with($file, $root . '/')
                && preg_match('#/\.{1,2}(/|$)#', substr($file, strlen($root))) !== 1
            ) {
                $relative = substr($file, strlen($root) + 1);
                break;
            }
        }
        if ($relative === null) {
            return null;
        }
        foreach (self::MOVED as $old => $new) {
            if (str_starts_with($relative, $old . '/')) {
                return self::folderState($root, $old, $new) === 'leftover' ? $relative : null;
            }
        }

        return null;
    }

    /**
     * Each renamed folder this server does not hold as 1.9.79 ships it:
     * 'leftover', the old folder beside the new (files copied over the old
     * ones on Linux); 'misspelled', the old name alone on a server that tells
     * the two apart (files copied over the old folder on a Mac or Windows
     * computer keep its name, and the new name's pages and includes are not
     * found); 'missing', neither (a git pull of the rename on a disk that
     * ignores case deletes both from the checkout).
     *
     * @return list<array{old: string, new: string, problem: 'leftover'|'misspelled'|'missing'}>
     */
    public static function folderProblems(string $root): array
    {
        $problems = [];
        foreach (self::MOVED as $old => $new) {
            $problem = self::folderState(rtrim($root, '/'), $old, $new);
            if ($problem !== null) {
                $problems[] = ['old' => $old, 'new' => $new, 'problem' => $problem];
            }
        }

        return $problems;
    }

    /** @return 'leftover'|'misspelled'|'missing'|null */
    private static function folderState(string $root, string $old, string $new): ?string
    {
        // Both spellings are entries of their folder only on a disk that
        // tells them apart; one that ignores case lists the folder once (its
        // inode numbers are no answer: FUSE gives each name its own).
        $parent = dirname("{$root}/{$new}");
        $entries = is_dir($parent) ? scandir($parent) : false;
        if ($entries === false) {
            return null; // unreadable: no claim either way
        }
        $hasOld = in_array(basename($old), $entries, true);
        $hasNew = in_array(basename($new), $entries, true);
        if ($hasOld && $hasNew) {
            // A link from the old spelling to the new folder is the new folder.
            return realpath("{$root}/{$old}") === realpath("{$root}/{$new}") ? null : 'leftover';
        }
        if ($hasNew || is_dir("{$root}/{$new}")) {
            return null; // the new name, or a disk that finds it under the old
        }

        return $hasOld ? 'misspelled' : 'missing';
    }

    /** A page a visitor can open: a .php file, or a folder with an index. */
    private static function page(string $root, string $relative): ?string
    {
        $relative = rtrim($relative, '/');
        if (str_starts_with($relative, 'vendor/')) {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.') || str_starts_with($segment, '_')) {
                return null; // hidden files and _includes, _config: not pages
            }
        }
        $full = rtrim($root, '/') . '/' . $relative;
        if (str_ends_with($relative, '.php') && is_file($full)) {
            return $relative;
        }

        return is_file($full . '/index.php') ? $relative . '/' : null;
    }
}
