<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Auth;
use Api\V3\Support\AiConnect;
use InvalidArgumentException;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * The REST API keys in 202_api_keys, written, found and shown in one place.
 *
 * From 1.9.80 a key is stored as its SHA-256 (key_hash) and its first
 * PREFIX_LENGTH characters (key_prefix); api_key is left empty, so a copy of
 * the database holds no usable key. An AI connection's key also stores the
 * token its watchers are known by (watcher_token, AiConnect::watcherToken()),
 * which cannot be computed once the key is gone. A key is matched byte for
 * byte: the old lookup ran under utf8mb4_general_ci and folded case.
 *
 * A row the upgrade has not hashed yet (key_hash NULL) keeps its key in
 * api_key and is matched exactly; before the upgrade adds key_hash, keys are
 * written and matched as before, but exactly too. The 1.9.79 rung hashes
 * every stored key (hashStoredKeys()).
 *
 * A key is named in pages and forms by its ref, the SHA-256 in hex, never by
 * the key itself.
 */
final class ApiKeyStore
{
    public const PREFIX_LENGTH = 8;

    private const REF_PATTERN = '/^[0-9a-f]{64}$/D';

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function prefix(string $key): string
    {
        return substr($key, 0, self::PREFIX_LENGTH);
    }

    /** A key as a list shows it: its prefix and a mask. */
    public static function masked(string $prefix): string
    {
        return $prefix . str_repeat('*', 24);
    }

    public static function isRef(string $ref): bool
    {
        return preg_match(self::REF_PATTERN, $ref) === 1;
    }

    /**
     * The columns a key row is read with, besides the caller's own; each row
     * then goes through ref(), prefixOf() and watcherTokenOf().
     */
    public static function columns(\mysqli $db, string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return Auth::apiKeyHashColumnExists($db)
            ? $c . 'api_key, ' . $c . 'key_hash, ' . $c . 'key_prefix, ' . $c . 'watcher_token'
            : $c . 'api_key';
    }

    /** @param array<string, mixed> $row read with columns() */
    public static function ref(array $row): string
    {
        $hash = (string) ($row['key_hash'] ?? '');
        return $hash !== '' ? $hash : self::hash((string) ($row['api_key'] ?? ''));
    }

    /** @param array<string, mixed> $row read with columns() */
    public static function prefixOf(array $row): string
    {
        $prefix = (string) ($row['key_prefix'] ?? '');
        return $prefix !== '' ? $prefix : self::prefix((string) ($row['api_key'] ?? ''));
    }

    /**
     * The watcher token of an AI connection's key, or null when the row
     * holds neither the token nor the key.
     *
     * @param array<string, mixed> $row read with columns()
     */
    public static function watcherTokenOf(array $row): ?string
    {
        $token = (string) ($row['watcher_token'] ?? '');
        if ($token !== '') {
            return $token;
        }
        $key = (string) ($row['api_key'] ?? '');
        return $key !== '' ? AiConnect::watcherToken($key) : null;
    }

    /**
     * The condition that finds the row of $key, exactly.
     *
     * @return array{0: string, 1: string, 2: list<string>} SQL, bind types, values
     */
    public static function matchKey(\mysqli $db, string $key, string $alias = ''): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('An API key to find is not empty');
        }
        $c = $alias === '' ? '' : $alias . '.';
        if (!Auth::apiKeyHashColumnExists($db)) {
            return [$c . 'api_key COLLATE utf8mb4_bin = ?', 's', [$key]];
        }
        return [
            '(' . $c . 'key_hash = ? OR (' . $c . 'key_hash IS NULL AND ' . $c . 'api_key COLLATE utf8mb4_bin = ?))',
            'ss',
            [self::hash($key), $key],
        ];
    }

    /**
     * matchKey() with its values written in, for the legacy APIs' unprepared
     * queries.
     */
    public static function matchKeyLiteral(\mysqli $db, string $key, string $alias = ''): string
    {
        [$sql, , $values] = self::matchKey($db, $key, $alias);
        return self::fillPlaceholders($sql, $values, static fn (string $v): string => $db->real_escape_string($v));
    }

    /**
     * $sql with each ? replaced by the next value, escaped and quoted, in one
     * pass, so a ? inside a value is never taken for a placeholder.
     *
     * @param list<string> $values
     * @param callable(string): string $escape
     */
    public static function fillPlaceholders(string $sql, array $values, callable $escape): string
    {
        $parts = explode('?', $sql);
        if (count($parts) - 1 !== count($values)) {
            throw new InvalidArgumentException('One value per placeholder');
        }
        $out = array_shift($parts);
        foreach ($parts as $i => $part) {
            $out .= "'" . $escape($values[$i]) . "'" . $part;
        }
        return $out;
    }

    /**
     * The condition that finds the row a ref names.
     *
     * @return array{0: string, 1: string, 2: list<string>} SQL, bind types, values
     */
    public static function matchRef(\mysqli $db, string $ref, string $alias = ''): array
    {
        if (!self::isRef($ref)) {
            throw new InvalidArgumentException('An API key ref is 64 lower-case hex digits');
        }
        $c = $alias === '' ? '' : $alias . '.';
        $unhashed = $c . "api_key <> '' AND SHA2(" . $c . 'api_key, 256) = ?';
        if (!Auth::apiKeyHashColumnExists($db)) {
            return ['(' . $unhashed . ')', 's', [$ref]];
        }
        return [
            '(' . $c . 'key_hash = ? OR (' . $c . 'key_hash IS NULL AND ' . $unhashed . '))',
            'ss',
            [$ref, $ref],
        ];
    }

    /**
     * Stores a new key of $userId's. A $scope is always written, so an
     * install without the column refuses the key rather than storing it with
     * full access; a $label is written where the column exists.
     *
     * @throws QueryException when the key could not be stored
     */
    public static function insert(\mysqli $db, int $userId, string $key, ?string $scope, ?string $label, int $createdAt): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('An API key to store is not empty');
        }
        $columns = ['user_id'];
        $types = 'i';
        $values = [$userId];
        $add = static function (string $column, string $type, mixed $value) use (&$columns, &$types, &$values): void {
            $columns[] = $column;
            $types .= $type;
            $values[] = $value;
        };

        if (Auth::apiKeyHashColumnExists($db)) {
            $add('api_key', 's', '');
            $add('key_hash', 's', self::hash($key));
            $add('key_prefix', 's', self::prefix($key));
            $add('watcher_token', 's', AiConnect::isConnectionLabel($label) ? AiConnect::watcherToken($key) : null);
        } else {
            $add('api_key', 's', $key);
        }
        // A scope is always written: on an install without the column the
        // insert fails, rather than storing a full-access key.
        if ($scope !== null || Auth::apiKeyScopeColumnExists($db)) {
            $add('scope', 's', $scope);
        }
        if ($label !== null && Auth::apiKeyLabelColumnExists($db)) {
            $add('label', 's', $label);
        }
        $add('created_at', 'i', $createdAt);

        $conn = new Connection($db);
        $stmt = $conn->prepareWrite('INSERT INTO 202_api_keys (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $conn->bind($stmt, $types, $values);
        $conn->executeInsert($stmt);
    }

    /**
     * The 1.9.79 rung's step: hashes every key still stored whole, row by
     * row, and empties its api_key. A run that stops part way is picked up by
     * the next, since only rows with no key_hash are read. True when no key
     * is left whole.
     */
    public static function hashStoredKeys(\mysqli $db): bool
    {
        $conn = new Connection($db);
        try {
            $stmt = $conn->prepareRead(
                "SELECT user_id, api_key, label FROM 202_api_keys WHERE key_hash IS NULL AND api_key <> ''"
            );
            $rows = $conn->fetchAll($stmt);
        } catch (QueryException $e) {
            error_log('Prosper202 upgrade: the API keys to hash could not be read: ' . $e->getMessage());
            return false;
        }

        $ok = true;
        foreach ($rows as $row) {
            $key = (string) $row['api_key'];
            $label = isset($row['label']) ? (string) $row['label'] : null;
            try {
                $update = $conn->prepareWrite(
                    "UPDATE 202_api_keys SET key_hash = ?, key_prefix = ?, watcher_token = ?, api_key = ''"
                    . ' WHERE user_id = ? AND key_hash IS NULL AND api_key COLLATE utf8mb4_bin = ?'
                );
                $conn->bind($update, 'sssis', [
                    self::hash($key),
                    self::prefix($key),
                    AiConnect::isConnectionLabel($label) ? AiConnect::watcherToken($key) : null,
                    (int) $row['user_id'],
                    $key,
                ]);
                $conn->executeUpdate($update);
            } catch (QueryException $e) {
                error_log('Prosper202 upgrade: an API key of user ' . (int) $row['user_id']
                    . ' could not be hashed: ' . $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }
}
