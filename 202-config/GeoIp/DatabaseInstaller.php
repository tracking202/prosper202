<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * Puts a downloaded GeoLite2 archive in place of the database lookups read,
 * or refuses it and leaves that file as it was:
 *
 *  1. the archive's SHA-256 is the one MaxMind published for it;
 *  2. its .mmdb is unpacked into a new file in the database's own directory
 *     (a rename is atomic only within one filesystem);
 *  3. that file opens as a MaxMind database of the right edition, answers
 *     lookups, and was built after the installed one (the same build is
 *     "current", an older one is refused);
 *  4. the installed file is kept as GeoLite2-City.mmdb.previous, and the new
 *     one, on disk (fsync) and readable by every user but writable by its
 *     owner alone, is renamed over it.
 *
 * A database that is a symbolic link is refused: the rename would put a file
 * where the link was, and the file it points to, which something else may
 * keep current, would no longer be read.
 *
 * A lookup that opened the old file keeps reading it until it closes; the
 * next one opens the new file. Every temporary file is a dot-file beside
 * the database ending in .part, removed on the way out and, after a run
 * that was killed, by the next run's cleanLeftovers().
 */
final class DatabaseInstaller
{
    public const MAX_DATABASE_BYTES = 536870912;
    public const PREVIOUS_SUFFIX = '.previous';

    /** Addresses each new file is asked for: v4 and v6, so both halves of the tree are walked. */
    private const PROBES = ['1.1.1.1', '8.8.8.8', '81.2.69.160', '2001:4860:4860::8888', '2a02:cf40::1'];

    private readonly string $directory;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly string $target = CityDatabase::PATH,
        private readonly string $edition = CityDatabase::EDITION,
        ?\Closure $clock = null,
    ) {
        $this->directory = dirname($target);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function target(): string
    {
        return $this->target;
    }

    /**
     * A new, empty temporary file beside the database, opened for writing.
     *
     * @return array{0: string, 1: resource}
     * @throws RefreshFailed
     */
    public function createTemporary(string $kind): array
    {
        $path = $this->temporaryPath($kind);
        error_clear_last();
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RefreshFailed('A file could not be created in ' . $this->directory . ' (' . self::lastError()
                . '). ' . self::whoNeedsAccess($this->directory));
        }

        return [$path, $handle];
    }

    private function temporaryPath(string $kind): string
    {
        return $this->directory . '/.' . basename($this->target) . '.' . bin2hex(random_bytes(6)) . '.' . $kind
            . '.part';
    }

    /** Removes what a refresh stopped part way left beside the database. Call it under the refresh lock. */
    public function cleanLeftovers(): void
    {
        foreach (glob($this->directory . '/.' . basename($this->target) . '.*.part') ?: [] as $leftover) {
            @unlink($leftover);
        }
    }

    /** @throws RefreshFailed */
    public function install(string $archive, string $publishedSha256): InstallOutcome
    {
        $expected = strtolower(trim($publishedSha256));
        if (preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1) {
            throw new RefreshFailed('The published checksum is not a SHA-256, so the download could not be checked'
                . ' and was not installed.');
        }
        $actual = @hash_file('sha256', $archive);
        if (!is_string($actual)) {
            throw new RefreshFailed('The downloaded archive could not be read to check its SHA-256.');
        }
        if (!hash_equals($expected, $actual)) {
            // Sixteen digits of each tell them apart, and wrap on a phone.
            throw new RefreshFailed('The download\'s SHA-256 begins ' . substr($actual, 0, 16) . ', not the '
                . substr($expected, 0, 16) . ' MaxMind published for it, so it was not installed.');
        }

        $this->refuseALink();
        [$candidate, $handle] = $this->createTemporary('mmdb');
        try {
            try {
                TarGzMember::extract($archive, $this->edition . '.mmdb', $handle, self::MAX_DATABASE_BYTES);
                // On disk before the rename makes it the database: after a
                // crash, a renamed file whose blocks were never written is an
                // empty database every lookup fails on.
                error_clear_last();
                if (!fflush($handle) || !fsync($handle)) {
                    throw new RefreshFailed('The unpacked database could not be written to disk ('
                        . self::lastError() . '), so it was not installed.');
                }
            } finally {
                $closed = fclose($handle);
            }
            if (!$closed) {
                throw new RefreshFailed('The unpacked database could not be closed, so it was not installed.');
            }
            $new = $this->check($candidate);
            $installed = CityDatabase::status($this->target, ($this->clock)());
            if ($installed->builtAt !== null && $new->builtAt !== null) {
                if ($new->builtAt === $installed->builtAt) {
                    return InstallOutcome::alreadyInstalled($installed);
                }
                if ($new->builtAt < $installed->builtAt) {
                    throw new RefreshFailed('The download was built on ' . $new->builtOn() . ', before the installed'
                        . ' database (' . $installed->builtOn() . '), so the installed one was kept.');
                }
            }
            $this->keepPrevious();
            $this->swap($candidate);

            return InstallOutcome::installed(CityDatabase::status($this->target, ($this->clock)()), $installed);
        } finally {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    /** @throws RefreshFailed */
    public function refuseALink(): void
    {
        if (!is_link($this->target)) {
            return;
        }
        $points = @readlink($this->target);
        throw new RefreshFailed('The GeoIP database at ' . $this->target . ' is a symbolic link'
            . (is_string($points) ? ' to ' . $points : '') . ', and a refresh would put a file in its place, so'
            . ' the file it points to would no longer be read; it was left as it is. Keep that file current where'
            . ' it is, or put the database itself at ' . $this->target . '.');
    }

    /**
     * The unpacked file, read as the lookups read it.
     *
     * @throws RefreshFailed
     */
    private function check(string $candidate): DatabaseStatus
    {
        $name = $this->edition . '.mmdb';
        try {
            $reader = new \MaxMind\Db\Reader($candidate);
            try {
                $metadata = $reader->metadata();
                foreach (self::PROBES as $address) {
                    $reader->get($address);
                }
            } finally {
                $reader->close();
            }
        } catch (\Throwable $e) {
            throw new RefreshFailed('The downloaded ' . $name . ' does not open as a MaxMind database ('
                . $e->getMessage() . '), so it was not installed.');
        }
        if ($metadata->databaseType !== $this->edition) {
            throw new RefreshFailed('The downloaded ' . $name . ' is a ' . $metadata->databaseType . ' database, not '
                . $this->edition . ', so it was not installed.');
        }
        if ($metadata->ipVersion !== 6) {
            throw new RefreshFailed('The downloaded ' . $name . ' covers IPv4 only, so it was not installed.');
        }
        if (class_exists(\GeoIp2\Database\Reader::class)) {
            try {
                $city = new \GeoIp2\Database\Reader($candidate);
                try {
                    foreach (self::PROBES as $address) {
                        try {
                            $city->city($address);
                        } catch (\GeoIp2\Exception\AddressNotFoundException) {
                        }
                    }
                } finally {
                    $city->close();
                }
            } catch (\Throwable $e) {
                throw new RefreshFailed('The downloaded ' . $name . ' does not answer City lookups ('
                    . $e->getMessage() . '), so it was not installed.');
            }
        }
        $status = CityDatabase::fromMetadata(
            $candidate,
            $metadata->databaseType,
            $metadata->buildEpoch,
            ($this->clock)()
        );
        if (!$status->readable()) {
            throw new RefreshFailed('The downloaded ' . $name . ' was not installed: ' . $status->reason);
        }

        return $status;
    }

    /** @throws RefreshFailed */
    private function keepPrevious(): void
    {
        clearstatcache(true, $this->target);
        if (!is_file($this->target)) {
            return;
        }
        $kept = basename($this->target) . self::PREVIOUS_SUFFIX;
        $copy = $this->temporaryPath('previous');
        // A second name for the installed file costs no space; a copy is the
        // fallback on a filesystem without hard links.
        error_clear_last();
        if (!@link($this->target, $copy) && !@copy($this->target, $copy)) {
            throw new RefreshFailed('The installed database could not be kept as ' . $kept . ' ('
                . self::lastError() . '), so it was not replaced.');
        }
        error_clear_last();
        if (!@rename($copy, $this->target . self::PREVIOUS_SUFFIX)) {
            $error = self::lastError();
            @unlink($copy);
            throw new RefreshFailed('The installed database could not be kept as ' . $kept . ' (' . $error
                . '), so it was not replaced.');
        }
    }

    /** @throws RefreshFailed */
    private function swap(string $candidate): void
    {
        clearstatcache(true, $this->target);
        $mode = is_file($this->target) ? ((int) fileperms($this->target) & 0777) : 0644;
        // Readable by every user: the cron job and the web server are often
        // different users, and a file only its writer can read is a database
        // no lookup can open. Writable by no one else, whatever the old one
        // allowed: a file anyone can rewrite is a database anyone can choose.
        error_clear_last();
        if (!@chmod($candidate, ($mode | 0444) & ~0022)) {
            throw new RefreshFailed('The new database\'s permissions could not be set (' . self::lastError()
                . '), so it was not installed.');
        }
        error_clear_last();
        if (!@rename($candidate, $this->target)) {
            throw new RefreshFailed('The new database could not be moved into place at ' . $this->target . ' ('
                . self::lastError() . '). ' . self::whoNeedsAccess($this->directory));
        }
        clearstatcache(true, $this->target);
    }

    public static function whoNeedsAccess(string $directory): string
    {
        $user = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            $user = is_array($entry) ? (string) $entry['name'] : null;
        }

        return 'The user this runs as' . ($user === null ? '' : ' (' . $user . ')') . ' needs write access to '
            . $directory . '; the installed database was left as it was.';
    }

    private static function lastError(): string
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return 'no reason given';
        }

        return preg_replace('/^[a-z_]+\(.*?\): /i', '', $error['message']) ?? $error['message'];
    }
}
