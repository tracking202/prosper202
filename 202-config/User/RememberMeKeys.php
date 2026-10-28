<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * The stored keys of "Keep me signed in" (202_auth_keys): one row per
 * remembered browser, which its remember_me cookie names and signs.
 *
 * A key outlives everything that ends a sign-in unless it is deleted: the
 * sign-out cleared the cookie and kept the row, so a copy of the cookie went
 * on signing in for up to 14 days; switching a user off or removing them kept
 * every row, so a remembered browser that made no request while the user was
 * off signed back in once they were on again. Sign-out deletes the one key its
 * cookie names (revokeOne()); every writer that leaves a user unable to act
 * deletes all of theirs in the same transaction (revokeAll(),
 * RememberMeKeysRevokedTest).
 *
 * A delete that fails throws: a key read as revoked that is not signs in.
 */
final class RememberMeKeys
{
    public const string REVOKE_ALL = 'DELETE FROM 202_auth_keys WHERE user_id = ?';

    /** As the cookie check reads the key (AUTH::remember_me_on_logged_out()), so whatever it accepts is deleted. */
    public const string REVOKE_ONE = 'DELETE FROM 202_auth_keys WHERE user_id = ? AND auth_key = ?';

    private function __construct()
    {
    }

    /**
     * Delete every key of the user, on $conn (inside the caller's
     * transaction when it has one); the number deleted.
     *
     * @throws QueryException
     */
    public static function revokeAll(Connection $conn, int $userId): int
    {
        return self::run($conn, self::REVOKE_ALL, 'i', [$userId], 'Revoking the remember-me keys of user ' . $userId);
    }

    /**
     * Delete the one key a browser's cookie names; the number deleted (0 for
     * a key that is not stored, already expired and swept, or never was).
     *
     * @throws QueryException
     */
    public static function revokeOne(Connection $conn, int $userId, string $authKey): int
    {
        return self::run(
            $conn,
            self::REVOKE_ONE,
            'is',
            [$userId, $authKey],
            'Revoking a remember-me key of user ' . $userId
        );
    }

    /**
     * The user and key a remember_me cookie names, as the cookie check splits
     * it (`user_id-auth_key-hash`), or null for a cookie that names none.
     *
     * @return array{int, string}|null
     */
    public static function namedBy(mixed $cookie): ?array
    {
        if (!is_string($cookie)) {
            return null;
        }
        $parts = explode('-', $cookie);
        if (count($parts) !== 3 || $parts[1] === '' || preg_match('/^[1-9][0-9]*$/D', $parts[0]) !== 1) {
            return null;
        }

        return [(int) $parts[0], $parts[1]];
    }

    /**
     * @param list<int|string> $values
     * @throws QueryException
     */
    private static function run(Connection $conn, string $sql, string $types, array $values, string $what): int
    {
        try {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, $types, $values);

            return $conn->executeUpdate($stmt);
        } catch (QueryException $e) {
            throw new QueryException($what . ' failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        } catch (\mysqli_sql_exception $e) {
            throw new QueryException(
                $what . ' failed: ' . $e->getMessage() . ' [errno ' . (int) $e->getCode() . ']',
                (int) $e->getCode(),
                $e
            );
        }
    }
}
