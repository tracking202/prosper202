<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Where export files live on disk, and the only way a stored name becomes a
 * path.
 *
 * The directory is `P202_EXPORT_DIR` when 202-config.php defines it (put it
 * outside the web root where you can), else `202-config/temp/attribution-exports`.
 * That default is inside the web root, so it is made unreachable three ways:
 * the server config denies `202-config/temp` (the Dockerfile and the README's
 * Apache and Nginx examples; the directory's own `.htaccess` also denies
 * everything, but Apache reads it only under `AllowOverride AuthConfig`,
 * answers 500 under the shipped `FileInfo` set and ignores it under `None`),
 * an empty `index.html` (no listing), and names no one can guess —
 * `u<user>-e<export>-<128 random bits>.csv`. Files are served only through the authenticated download
 * endpoints, which look the name up on the account's own export row.
 *
 * The row stores the name, never a path, and a name that does not match
 * the pattern is refused before it touches the filesystem, so a corrupt or
 * edited row cannot point a download at another file.
 */
final class ExportFiles
{
    private const NAME_PATTERN = '/^u[0-9]{1,10}-e[0-9]{1,20}-[0-9a-f]{32}\.csv$/D';

    public function __construct(private ?string $directory = null)
    {
    }

    public function directory(): string
    {
        if ($this->directory !== null) {
            return rtrim($this->directory, '/');
        }
        if (defined('P202_EXPORT_DIR') && (string) constant('P202_EXPORT_DIR') !== '') {
            return rtrim((string) constant('P202_EXPORT_DIR'), '/');
        }

        return dirname(__DIR__) . '/temp/attribution-exports';
    }

    public static function isName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Write a new export file and return its stored name.
     *
     * Written to a temporary name in the same directory and renamed into
     * place, so a reader never sees half a file.
     *
     * @throws \RuntimeException naming the directory when it cannot be written
     */
    public function write(int $userId, int $exportId, string $body): string
    {
        $dir = $this->prepareDirectory();
        $name = 'u' . $userId . '-e' . $exportId . '-' . bin2hex(random_bytes(16)) . '.csv';
        $tmp = $dir . '/.' . $name . '.tmp';
        if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) {
            @unlink($tmp);
            throw new \RuntimeException('The export file could not be written to ' . $dir . '; check that the web server can write there.');
        }
        if (!rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            throw new \RuntimeException('The export file could not be moved into place in ' . $dir . '.');
        }

        return $name;
    }

    /**
     * The file's contents, or null when there is no such file.
     *
     * @throws \UnexpectedValueException when the stored name is not an export file name
     * @throws \RuntimeException when the file is there and cannot be read (a
     *   runner writing as another user than the web server, usually) — not
     *   the same answer as "gone", which would send the reader to retry an
     *   export whose file is fine
     */
    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException('The export file ' . $name . ' is on disk but cannot be read; check that the web server can read ' . $this->directory() . '.');
        }

        return $body;
    }

    /** Remove a file; true when it is gone (or never existed). */
    public function remove(?string $name): bool
    {
        if ($name === null || $name === '') {
            return true;
        }
        $path = $this->path($name);

        return !is_file($path) || unlink($path);
    }

    /** @throws \UnexpectedValueException */
    private function path(string $name): string
    {
        if (!self::isName($name)) {
            throw new \UnexpectedValueException('"' . mb_substr($name, 0, 80) . '" is not an export file name; the row is corrupt.');
        }

        return $this->directory() . '/' . $name;
    }

    private function prepareDirectory(): string
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The export directory ' . $dir . ' does not exist and could not be created.');
        }
        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.html' => ''] as $file => $content) {
            if (!is_file($dir . '/' . $file) && file_put_contents($dir . '/' . $file, $content) === false) {
                throw new \RuntimeException('The export directory ' . $dir . ' is not writable (' . $file . ' could not be written).');
            }
        }

        return $dir;
    }
}
