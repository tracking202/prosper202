<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

use Closure;
use JsonException;

/**
 * The bootstrap's getCache()/setCache() for setup rows, as JSON: an entry is
 * `{"row": {...}}` or `{"row": null}`. memcache_mysql_fetch_assoc() kept
 * these rows serialize()d for three minutes; the lifetime is kept, so an
 * edit in Setup reaches the click path as soon as it did.
 *
 * An entry that is not exactly that shape (written by other code, cut
 * short, a value that is not text) is a miss, never a row: the read that
 * follows asks the database.
 */
final readonly class MemcacheRowCache implements RowCache
{
    /**
     * @param Closure(string): mixed $read
     * @param Closure(string, string, int): mixed $write
     */
    public function __construct(
        private Closure $read,
        private Closure $write,
        private int $ttl = 180,
    ) {
    }

    #[\Override]
    public function get(string $key): ?CachedRow
    {
        $entry = ($this->read)($key);
        if (!is_string($entry)) {
            return null;
        }
        try {
            $decoded = json_decode($entry, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['row']) {
            return null;
        }
        $row = $decoded['row'];
        if ($row === null) {
            return new CachedRow(null);
        }
        if (!is_array($row) || $row === [] || array_is_list($row)) {
            return null;
        }
        $kept = [];
        foreach ($row as $column => $value) {
            if (!is_string($column) || ($value !== null && !is_string($value))) {
                return null;
            }
            $kept[$column] = $value;
        }

        return new CachedRow($kept);
    }

    #[\Override]
    public function set(string $key, ?array $row): void
    {
        try {
            $entry = json_encode(['row' => $row], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Text JSON cannot hold (a connection whose character set is not
            // UTF-8 hands bytes back as stored): the row is read from the
            // database every time rather than kept changed or not served.
            return;
        }
        // A write the cache refuses costs the next read a trip to the
        // database; it is not an answer to check.
        ($this->write)($key, $entry, $this->ttl);
    }
}
