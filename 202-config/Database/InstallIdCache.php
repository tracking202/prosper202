<?php

declare(strict_types=1);

namespace Prosper202\Database;

use Closure;

/**
 * The install id (InstallId) in the cache namespace (systemHash()), without a
 * database read per request. The directory and database name an install's
 * place, which an in-place reinstall keeps; the id is what changes, so every
 * entry the old install cached (lookup ids for 30 days, tracker rows, the
 * outage URLs) is under another namespace from the moment the new id is
 * known.
 *
 * memcached itself holds the id under a key named by the machine, the
 * directory and the database ("<id>:<when it was read>"), with no expiry: the
 * outage fallback reads it with no database to ask. A request with a
 * database trusts it for VERIFY_EVERY_SECONDS after it was read and then
 * reads the id again, so a reinstall the installer could not announce (its
 * memcached write failed) is seen within that time; the installer writes the
 * new id at once (remember()). When the id cannot be known the request
 * caches nothing: its namespace is one no entry is under, and memcache is off
 * for the rest of it (CLAUDE.md #11).
 */
final class InstallIdCache
{
    public const VERIFY_EVERY_SECONDS = 300;

    /** @var array<string, string> what this process has resolved, by pointer key */
    private static array $known = [];

    private function __construct()
    {
    }

    /**
     * The id that names this install's cache entries, read through the
     * bootstrap's globals ($memcache, $whatCache, $memcacheWorking, $db),
     * once a process for each place. A value no entry is written under when
     * memcache is off or the id cannot be known.
     *
     * @param list<string> $location the install's directory and database settings
     */
    public static function forBootstrap(array $location): string
    {
        $key = self::pointerKey((string) gethostname(), $location);
        if (isset(self::$known[$key])) {
            return self::$known[$key];
        }
        $client = self::client();
        $db = $GLOBALS['db'] ?? null;
        $id = $client === null ? null : self::resolve(
            $key,
            $client[0],
            $client[1],
            $db instanceof \mysqli ? static fn (): string => InstallId::of($db) : null,
            time()
        );
        if ($id === null) {
            $GLOBALS['memcacheWorking'] = false;
            $id = 'uncached:' . bin2hex(random_bytes(16));
        }

        return self::$known[$key] = $id;
    }

    /**
     * Write this database's id where every request of the install reads it:
     * the installer, so a reinstall in place is a new namespace at once.
     * Logged, not thrown, when memcached does not take it: the install has
     * been made, and requests read the id again within VERIFY_EVERY_SECONDS.
     *
     * @param list<string> $location the install's directory and database settings
     */
    public static function remember(\mysqli $db, array $location): void
    {
        $key = self::pointerKey((string) gethostname(), $location);
        unset(self::$known[$key]);
        $client = self::client();
        if ($client === null) {
            return;
        }
        try {
            $written = ($client[1])($key, InstallId::of($db) . ':' . time());
        } catch (\Throwable) {
            $written = false;
        }
        if ($written !== true) {
            error_log('The new install id was not written to memcached: entries the previous install cached'
                . ' here can be read for up to ' . self::VERIFY_EVERY_SECONDS . ' seconds.');
        }
    }

    /**
     * The id under $key when it is fresh, or when there is no database to
     * ask ($read null: the outage fallback); otherwise read with $read and
     * written back. Null when it cannot be known: no pointer and no
     * database, or a read that failed (logged).
     *
     * @param Closure(string): mixed       $get
     * @param Closure(string, string): mixed $set
     * @param (Closure(): string)|null     $read
     */
    public static function resolve(string $key, Closure $get, Closure $set, ?Closure $read, int $now): ?string
    {
        try {
            $held = $get($key);
        } catch (\Throwable) {
            $held = false;
        }
        $pointer = is_string($held) && preg_match('/^([0-9a-f]{64}):(0|[1-9][0-9]{0,18})$/D', $held, $m) === 1
            ? [$m[1], (int) $m[2]]
            : null;
        if ($pointer !== null && ($read === null || abs($now - $pointer[1]) < self::VERIFY_EVERY_SECONDS)) {
            return $pointer[0];
        }
        if ($read === null) {
            return null;
        }
        try {
            $id = $read();
        } catch (\Throwable $e) {
            error_log('The install id could not be read for the cache namespace (' . $e->getMessage()
                . '): this request does not use memcache.');

            return null;
        }
        try {
            $set($key, $id . ':' . $now);
        } catch (\Throwable) {
            // Not kept: the next request reads it again.
        }

        return $id;
    }

    /**
     * The key memcached holds the id under. Length-prefixed, so no two
     * places run together into one (CLAUDE.md #17).
     *
     * @param list<string> $location
     */
    public static function pointerKey(string $machine, array $location): string
    {
        $parts = '';
        foreach ([$machine, ...$location] as $part) {
            $parts .= strlen($part) . ':' . $part;
        }

        return 'p202:install-id:v1:' . hash('sha256', $parts);
    }

    /**
     * The bootstrap's memcache client as a get and an unexpiring set, written
     * at once (not held by p202WithHeldCacheWrites(): the id is no
     * transaction's). Null when memcache is off.
     *
     * @return array{0: Closure(string): mixed, 1: Closure(string, string): bool}|null
     */
    private static function client(): ?array
    {
        $client = $GLOBALS['memcache'] ?? null;
        if (($GLOBALS['memcacheWorking'] ?? false) !== true || !is_object($client)) {
            return null;
        }
        return match ($GLOBALS['whatCache'] ?? null) {
            'memcache' => [
                static fn (string $key): mixed => $client->get($key),
                static fn (string $key, string $value): bool => (bool) $client->set($key, $value, 0, 0),
            ],
            'memcached' => [
                static fn (string $key): mixed => $client->get($key),
                static fn (string $key, string $value): bool => (bool) $client->set($key, $value, 0),
            ],
            default => null,
        };
    }
}
