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
 * PREFIX_LENGTH characters (key_prefix); api_key holds a random placeholder
 * (placeholder()), so a copy of the database holds no usable key and a 1.9.79
 * reader put back over the upgrade, which matches api_key, finds no key. An AI connection's key also stores the
 * token its watchers are known by (watcher_token, AiConnect::watcherToken()),
 * which cannot be computed once the key is gone. A key is matched byte for
 * byte: the old lookup ran under utf8mb4_general_ci and folded case.
 *
 * A row the upgrade has not hashed yet (key_hash NULL) keeps its key in
 * api_key and is matched exactly; before the upgrade adds key_hash, keys are
 * written and matched as before, but exactly too. The 1.9.80 rung (the one
 * gated on 1.9.79) hashes every stored key (hashStoredKeys()).
 *
 * A key is named in pages and forms by its ref, the SHA-256 in hex, never by
 * the key itself.
 */
final class ApiKeyStore
{
    public const PREFIX_LENGTH = 8;

    /** A key's last use is written at most this often, in seconds. */
    public const USE_STAMP_INTERVAL = 300;

    /** How long a replaced key keeps working, in seconds. */
    public const REPLACED_KEY_GRACE = 86400;

    /** How long a key that has ended stays listed before it is removed, in seconds. */
    public const ENDED_KEY_KEPT = 604800;

    /**
     * What a name shown in a list or a terminal does not carry: control
     * characters (C0, DEL, C1) and the characters that reorder text.
     */
    private const UNPRINTED = '[\p{Cc}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]';

    /** label's width, in characters. */
    public const LABEL_LENGTH = 100;

    /** last_used_client's width, in characters. */
    public const CLIENT_LENGTH = 190;

    private const REF_PATTERN = '/^[0-9a-f]{64}$/D';

    /** Starts every placeholder; no key P202 makes contains it. */
    public const PLACEHOLDER_MARK = '!';

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

    /**
     * What a hashed row keeps in api_key: never '', which an older reader's
     * `api_key = '<sent key>'` matches when no key is sent.
     */
    public static function placeholder(): string
    {
        return self::PLACEHOLDER_MARK . bin2hex(random_bytes(32));
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
        if ((string) ($row['key_hash'] ?? '') !== '') {
            return (string) ($row['key_prefix'] ?? '');
        }
        return self::prefix((string) ($row['api_key'] ?? ''));
    }

    /**
     * The watcher token of an AI connection's key, or null when the row
     * holds neither the token nor the key.
     *
     * @param array<string, mixed> $row read with columns()
     */
    public static function watcherTokenOf(array $row): ?string
    {
        if ((string) ($row['key_hash'] ?? '') !== '') {
            $token = (string) ($row['watcher_token'] ?? '');
            return $token !== '' ? $token : null;
        }
        $key = (string) ($row['api_key'] ?? '');
        return $key !== '' ? AiConnect::watcherToken($key) : null;
    }

    /**
     * The condition that finds the row of $key, exactly. api_key is
     * converted first: a 202_api_keys made before 1.9.55 took the server's
     * default charset (latin1 or utf8mb3), and naming utf8mb4_bin on such a
     * column is an error, not a comparison.
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
            return ['CONVERT(' . $c . 'api_key USING utf8mb4) COLLATE utf8mb4_bin = ?', 's', [$key]];
        }
        return [
            '(' . $c . 'key_hash = ? OR (' . $c . 'key_hash IS NULL AND CONVERT(' . $c . 'api_key USING utf8mb4) COLLATE utf8mb4_bin = ?))',
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
     * A row not yet hashed is found by SHA2 of its api_key's stored bytes, so
     * a non-ASCII key in a pre-1.9.55 latin1 table cannot be named by ref
     * until the upgrade hashes it.
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
            $add('api_key', 's', self::placeholder());
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
     * The use and ending columns a key row is read with, where the 1.9.80
     * upgrade has added them; '' before it has.
     */
    public static function useColumns(\mysqli $db, string $alias = ''): string
    {
        if (!Auth::apiKeyUseColumnsExist($db)) {
            return '';
        }
        $c = $alias === '' ? '' : $alias . '.';
        return ', ' . $c . 'last_used_at, ' . $c . 'last_used_api, ' . $c . 'last_used_client, ' . $c . 'ends_at';
    }

    /**
     * When the key of $row stopped working, or null while it works. A
     * replaced key carries ends_at; a row read before the upgrade added the
     * column has none and never ends. A value that cannot be read as a time
     * has ended.
     *
     * @param array<string, mixed> $row a 202_api_keys row
     */
    public static function endedAt(array $row, int $now): ?int
    {
        $endsAt = $row['ends_at'] ?? null;
        if ($endsAt === null) {
            return null;
        }
        if (!is_int($endsAt) && !(is_string($endsAt) && preg_match('/^\d{1,10}$/D', $endsAt) === 1)) {
            return 0;
        }
        return (int) $endsAt <= $now ? (int) $endsAt : null;
    }

    /**
     * The condition a key list adds, so a key that ended more than
     * ENDED_KEY_KEPT ago is not listed while it waits for removeEnded(); ''
     * before the upgrade adds the column.
     */
    public static function listedCondition(\mysqli $db, int $now): string
    {
        return Auth::apiKeyUseColumnsExist($db)
            ? ' AND (ends_at IS NULL OR ends_at > ' . ($now - self::ENDED_KEY_KEPT) . ')'
            : '';
    }

    /**
     * Removes, for every account, the replaced keys that ended more than
     * ENDED_KEY_KEPT before $now: the rows listedCondition() no longer
     * lists. A key that works has no ending or a later one, so none is
     * touched. The number removed.
     *
     * @throws QueryException when the rows could not be removed
     */
    public static function removeEnded(\mysqli $db, int $now): int
    {
        if (!Auth::apiKeyUseColumnsExist($db)) {
            return 0;
        }
        $conn = new Connection($db);
        $stmt = $conn->prepareWrite('DELETE FROM 202_api_keys WHERE ends_at IS NOT NULL AND ends_at <= ?');
        $conn->bind($stmt, 'i', [$now - self::ENDED_KEY_KEPT]);
        return $conn->executeUpdate($stmt);
    }

    /** What a refused request is told about a key that has ended. */
    public static function endedMessage(int $endedAt): string
    {
        return 'This API key was replaced and stopped working'
            . ($endedAt > 0 ? ' on ' . gmdate('Y-m-d H:i', $endedAt) . ' UTC' : '')
            . '. Use the key that replaced it, or make a new one in My Account.';
    }

    /**
     * The name a person gave a new key: null for none, false for one that
     * cannot be stored (too long, more than a line, not text) or that reads
     * as an AI connection's label, which decides what a key is.
     */
    public static function labelFrom(mixed $posted): string|false|null
    {
        if (!is_string($posted) || !mb_check_encoding($posted, 'UTF-8')) {
            return false;
        }
        $label = trim($posted);
        if ($label === '') {
            return null;
        }
        if (
            mb_strlen($label, 'UTF-8') > self::LABEL_LENGTH
            || preg_match('/' . self::UNPRINTED . '/u', $label) === 1
            || stripos($label, rtrim(AiConnect::LABEL_PREFIX)) === 0
        ) {
            return false;
        }
        return $label;
    }

    /** The client a request names itself as, cut to what last_used_client holds. */
    public static function clientName(mixed $userAgent): ?string
    {
        if (is_array($userAgent)) {
            $userAgent = $userAgent[0] ?? '';
        }
        if (!is_string($userAgent)) {
            return null;
        }
        $name = preg_replace('/' . self::UNPRINTED . '+/u', ' ', mb_scrub($userAgent, 'UTF-8'));
        $name = mb_substr(trim((string) $name), 0, self::CLIENT_LENGTH, 'UTF-8');
        return $name === '' ? null : $name;
    }

    /**
     * Records that the key of $row just authenticated a request on $api
     * ('v1', 'v2', 'v3'), at most once per USE_STAMP_INTERVAL for a key. It
     * never fails the request: a stamp that cannot be written is logged.
     * Nothing is written before the upgrade has added the columns.
     *
     * @param array<string, mixed> $row the key's row as the lookup read it (k.*)
     */
    public static function stampUse(\mysqli $db, array $row, string $key, string $api, mixed $userAgent, int $now): void
    {
        foreach (['last_used_at', 'last_used_api', 'last_used_client'] as $column) {
            if (!array_key_exists($column, $row)) {
                return;
            }
        }
        $last = $row['last_used_at'];
        if ($last !== null && is_numeric($last) && $now - (int) $last < self::USE_STAMP_INTERVAL) {
            return;
        }
        // One failure is enough for a process: a server that refuses the
        // write would refuse it for every request this process serves.
        static $refused = null;
        $refused ??= new \WeakMap();
        if (isset($refused[$db])) {
            return;
        }
        $client = self::clientName($userAgent);
        $write = static function (?string $client) use ($db, $row, $key, $api, $now): void {
            [$keyMatch, $keyTypes, $keyValues] = self::matchKey($db, $key);
            $conn = new Connection($db);
            $stmt = $conn->prepareWrite(
                'UPDATE 202_api_keys SET last_used_at = ?, last_used_api = ?, last_used_client = ?'
                . ' WHERE user_id = ? AND ' . $keyMatch . ' AND (last_used_at IS NULL OR last_used_at < ?)'
            );
            $conn->bind($stmt, 'issi' . $keyTypes . 'i', [
                $now,
                $api,
                $client,
                (int) ($row['user_id'] ?? 0),
                ...$keyValues,
                $now,
            ]);
            $conn->executeUpdate($stmt);
        };
        try {
            $write($client);
            return;
        } catch (\Throwable $e) {
            $failed = $e;
        }
        // The time matters more than the name: a name the column cannot hold
        // must not leave the key reading as unused, and stamped on every request.
        if ($client !== null) {
            try {
                $write(null);
                error_log('Prosper202: an API key\'s last use was recorded without its client\'s name: '
                    . $failed->getMessage());
                return;
            } catch (\Throwable $e) {
                $failed = $e;
            }
        }
        $refused[$db] = true;
        error_log('Prosper202: an API key\'s last use could not be recorded: ' . $failed->getMessage());
    }

    /**
     * Replaces the key $ref names: stores $newKey with the old key's scope
     * and label, and sets the old key to end REPLACED_KEY_GRACE from $now, in
     * one transaction. Null when $userId has no such key; a
     * key already ending, or an AI connection's, is refused.
     *
     * @return array{ends_at: int, scope: ?string, label: ?string}|null
     * @throws \DomainException naming why the key cannot be replaced
     * @throws QueryException when nothing was changed
     */
    public static function replace(\mysqli $db, int $userId, string $ref, string $newKey, int $now): ?array
    {
        if (!Auth::apiKeyUseColumnsExist($db)) {
            throw new \DomainException('Replacing a key needs the 1.9.80 database upgrade, which has not run yet.');
        }
        $hasLabel = Auth::apiKeyLabelColumnExists($db);
        [$refMatch, $refTypes, $refValues] = self::matchRef($db, $ref);
        $conn = new Connection($db);
        $refBind = ['i' . $refTypes, [$userId, ...$refValues]];
        $replace = static function () use ($conn, $db, $userId, $newKey, $now, $hasLabel, $refMatch, $refBind): ?array {
            $read = $conn->prepareWrite('SELECT scope, ends_at' . ($hasLabel ? ', label' : '')
                . ' FROM 202_api_keys WHERE user_id = ? AND ' . $refMatch . ' LIMIT 1 FOR UPDATE');
            $conn->bind($read, $refBind[0], $refBind[1]);
            $old = $conn->fetchOne($read);
            if ($old === null) {
                return null;
            }
            $label = isset($old['label']) ? (string) $old['label'] : null;
            if (AiConnect::isConnectionLabel($label)) {
                throw new \DomainException('An AI app\'s key is replaced by connecting the app again.');
            }
            if ($old['ends_at'] !== null) {
                throw new \DomainException('That key was already replaced.');
            }
            $scope = $old['scope'] === null ? null : (string) $old['scope'];
            self::insert($db, $userId, $newKey, $scope, $label, $now);
            $endsAt = $now + self::REPLACED_KEY_GRACE;
            $end = $conn->prepareWrite('UPDATE 202_api_keys SET ends_at = ? WHERE user_id = ? AND ' . $refMatch
                . ' AND ends_at IS NULL');
            $conn->bind($end, 'i' . $refBind[0], [$endsAt, ...$refBind[1]]);
            if ($conn->executeUpdate($end) !== 1) {
                throw new QueryException('The replaced API key could not be set to end');
            }
            return ['ends_at' => $endsAt, 'scope' => $scope, 'label' => $label];
        };
        return $conn->transaction($replace);
    }

    /**
     * The 1.9.80 rung's step for a key's name: label holds any text. A table
     * made before 1.9.55 has the server's default charset, and the column
     * 1.9.78 added to it took that; it is converted, values and all. True
     * when the column is utf8mb4.
     */
    public static function widenLabel(\mysqli $db): bool
    {
        $conn = new Connection($db);
        try {
            $column = $conn->fetchOne($conn->prepareRead(
                "SELECT CHARACTER_SET_NAME AS charset FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_api_keys' AND COLUMN_NAME = 'label'"
            ));
            if ($column === null) {
                error_log('Prosper202 upgrade: 202_api_keys has no label column to convert');
                return false;
            }
            if (strtolower((string) $column['charset']) === 'utf8mb4') {
                return true;
            }
            $conn->execute($conn->prepareWrite(
                'ALTER TABLE 202_api_keys MODIFY `label` varchar(100)'
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL'
            ));
        } catch (\Throwable $e) {
            error_log('Prosper202 upgrade: the API keys\' label column could not be converted: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Whether a write failed because a column cannot hold a character sent:
     * 1366 on MariaDB and for a value MySQL converts, 3988 where MySQL 8
     * refuses the conversion of a bound value outright.
     */
    public static function isUnstorableText(\Throwable $e): bool
    {
        return Connection::isMysqlError($e, 1366, 'Incorrect string value')
            || Connection::isMysqlError($e, 3988, 'Conversion from collation');
    }

    /** What a person is told when a key's name cannot be stored. */
    public const UNSTORABLE_NAME = 'That name holds a character this install cannot store in a key\'s name yet.'
        . ' Run the upgrade at /202-config/upgrade.php, or use a name of plain letters and digits.';

    /**
     * The 1.9.80 rung's step: hashes every key still stored whole, row by
     * row, and puts a placeholder in its api_key. A run that stops part way is picked up by
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
                    "UPDATE 202_api_keys SET key_hash = ?, key_prefix = ?, watcher_token = ?, api_key = ?"
                    . ' WHERE user_id = ? AND key_hash IS NULL AND CONVERT(api_key USING utf8mb4) COLLATE utf8mb4_bin = ?'
                );
                $conn->bind($update, 'ssssis', [
                    self::hash($key),
                    self::prefix($key),
                    AiConnect::isConnectionLabel($label) ? AiConnect::watcherToken($key) : null,
                    self::placeholder(),
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
        if (!$ok || !self::fillEmptiedKeys($conn)) {
            return false;
        }
        // An UPDATE that matched no row (an odd legacy byte sequence) is not
        // an error, so count what is left whole rather than trust the loop.
        try {
            $left = (int) ($conn->fetchOne($conn->prepareRead(
                "SELECT COUNT(*) AS n FROM 202_api_keys WHERE key_hash IS NULL AND api_key <> ''"
            ))['n'] ?? 0);
        } catch (QueryException $e) {
            error_log('Prosper202 upgrade: the API keys left whole could not be counted: ' . $e->getMessage());
            return false;
        }
        if ($left > 0) {
            error_log('Prosper202 upgrade: ' . $left . ' API key(s) are still stored whole');
            return false;
        }
        return true;
    }

    /** A row hashed by a pre-release build kept api_key ''; give it a placeholder. */
    private static function fillEmptiedKeys(Connection $conn): bool
    {
        try {
            $rows = $conn->fetchAll($conn->prepareRead(
                "SELECT user_id, key_hash FROM 202_api_keys WHERE key_hash IS NOT NULL AND CHAR_LENGTH(api_key) = 0"
            ));
            foreach ($rows as $row) {
                $update = $conn->prepareWrite(
                    "UPDATE 202_api_keys SET api_key = ? WHERE user_id = ? AND key_hash = ? AND api_key = ''"
                );
                $conn->bind($update, 'sis', [self::placeholder(), (int) $row['user_id'], (string) $row['key_hash']]);
                $conn->executeUpdate($update);
            }
        } catch (QueryException $e) {
            error_log('Prosper202 upgrade: an emptied API key could not be given a placeholder: ' . $e->getMessage());
            return false;
        }
        return true;
    }
}
