<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * The GeoLite2 City database every location lookup reads: the click path's
 * getGeoData() (connect2.php), privacy 'eu' (Prosper202\Http\EuropeanVisitor),
 * and through them the geo reports and the rotator's country and region
 * criteria. One path, named here (CityDatabaseReadersTest holds every reader
 * to it); a refresh replaces the file at this path by rename, and every
 * reader opens it per lookup, so the next lookup reads the new file.
 *
 * Its age is the build date MaxMind writes into the file's metadata, never
 * the file's mtime, which a copy, an unpacked release or a checkout resets.
 */
final class CityDatabase
{
    public const PATH = __DIR__ . '/../geo/GeoLite2-City.mmdb';

    /** The edition MaxMind builds this file from, and its metadata's database_type. */
    public const EDITION = 'GeoLite2-City';

    /**
     * Older than this, the database is reported stale. GeoLite City is built
     * every Tuesday and Friday and the refresh runs weekly, so a refreshing
     * install's file is at most about 11 days old; at 30 the refresh has
     * failed for three weeks. It is also the GeoLite EULA's limit (§6.3: an
     * old version is destroyed within 30 days of a new release).
     */
    public const STALE_AFTER_DAYS = 30;

    /** A build date further ahead of this server's clock than this is not believed. */
    private const FUTURE_SLACK_SECONDS = 86400;

    private function __construct()
    {
    }

    /** What the file at $path is, as of $now. */
    public static function status(
        string $path = self::PATH,
        ?int $now = null,
        int $staleAfterDays = self::STALE_AFTER_DAYS,
    ): DatabaseStatus {
        $now ??= time();
        $directory = realpath(dirname($path));
        $path = $directory === false ? $path : $directory . '/' . basename($path);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return DatabaseStatus::missing($path, $staleAfterDays, 'There is no GeoIP database at ' . $path . '.');
        }
        if (!class_exists(\MaxMind\Db\Reader::class)) {
            return DatabaseStatus::unreadable(
                $path,
                $staleAfterDays,
                null,
                'The MaxMind DB reader library is not installed (vendor/maxmind-db/reader).'
            );
        }
        try {
            $reader = new \MaxMind\Db\Reader($path);
            try {
                $metadata = $reader->metadata();
            } finally {
                $reader->close();
            }
        } catch (\Throwable $e) {
            return DatabaseStatus::unreadable(
                $path,
                $staleAfterDays,
                null,
                'The GeoIP database at ' . $path . ' could not be opened: ' . $e->getMessage()
            );
        }

        return self::fromMetadata($path, $metadata->databaseType, $metadata->buildEpoch, $now, $staleAfterDays);
    }

    /**
     * The status a file's metadata gives it. A type that is not a City
     * database, or a build date that is missing or after tomorrow, is
     * unreadable: read as an age, either would pass for a fresh file.
     */
    public static function fromMetadata(
        string $path,
        mixed $type,
        mixed $buildEpoch,
        int $now,
        int $staleAfterDays = self::STALE_AFTER_DAYS,
    ): DatabaseStatus {
        $type = is_string($type) ? $type : null;
        if ($type === null || !str_contains($type, 'City')) {
            return DatabaseStatus::unreadable($path, $staleAfterDays, $type, 'The file at ' . $path . ' is a '
                . ($type ?? 'untyped') . ' database, not a City one, so lookups cannot read it.');
        }
        if (!is_int($buildEpoch) || $buildEpoch <= 0) {
            return DatabaseStatus::unreadable(
                $path,
                $staleAfterDays,
                $type,
                'The GeoIP database at ' . $path . ' carries no build date.'
            );
        }
        if ($buildEpoch > $now + self::FUTURE_SLACK_SECONDS) {
            return DatabaseStatus::unreadable(
                $path,
                $staleAfterDays,
                $type,
                'The GeoIP database at ' . $path . ' says it was built on ' . gmdate('Y-m-d', $buildEpoch)
                    . ', after today (' . gmdate('Y-m-d', $now) . '); check this server\'s clock.'
            );
        }

        return DatabaseStatus::built($path, $staleAfterDays, $type, $buildEpoch, $now);
    }
}
