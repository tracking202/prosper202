<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Whether a user may act: sign in, by password or by remember-me cookie, and
 * keep a session signed in. One rule for all three. The sign-in and the
 * remember-me read asked for an active user that was not deleted; a session
 * already signed in was never asked again, so a user switched off in Account ›
 * Users, or removed, kept every page for as long as they kept using the
 * browser. AUTH asks this of the session's row on every signed-in request
 * (SessionEndsWithUserIntegrationTest).
 *
 * Only 1 is active and only 0 is not deleted: any other value (none is
 * written) is a user who may not act, never one who may.
 */
enum UserStanding
{
    case Active;
    case SwitchedOff;
    case Deleted;
    case Missing;

    /** The row of the user, read from the primary: a replica's copy can predate the switch-off. */
    public const string READ = 'SELECT user_active, user_deleted FROM 202_users WHERE user_id = ? LIMIT 1';

    /** @param array<string, mixed>|null $row the user's row (user_active, user_deleted), null for none */
    public static function ofRow(?array $row): self
    {
        if ($row === null) {
            return self::Missing;
        }
        if (!self::is($row['user_deleted'] ?? null, 0)) {
            return self::Deleted;
        }
        if (!self::is($row['user_active'] ?? null, 1)) {
            return self::SwitchedOff;
        }

        return self::Active;
    }

    /** A column's value is $want, as the driver hands it back: an int from a prepared read, a string from a text one. */
    private static function is(mixed $value, int $want): bool
    {
        return $value === $want || $value === (string) $want;
    }

    /**
     * The user's standing, read now. A read that fails throws: read as
     * "missing" it would sign the user out over a database hiccup, and read
     * as "active" it would let a switched-off user through.
     *
     * @throws QueryException with the server's errno as its code where it has one
     */
    public static function read(\mysqli $db, int $userId): self
    {
        if ($userId <= 0) {
            return self::Missing;
        }
        $failed = 'Reading whether user ' . $userId . ' may act failed';
        try {
            $conn = new Connection($db);
            $stmt = $conn->prepareWrite(self::READ);
            $conn->bind($stmt, 'i', [$userId]);
            $row = $conn->fetchOne($stmt);
        } catch (QueryException $e) {
            throw new QueryException($failed . ': ' . $e->getMessage(), self::errno($e), $e);
        } catch (\mysqli_sql_exception $e) {
            // Strict reporting throws MySQL's sentence; name whose read it was.
            throw new QueryException(
                $failed . ': ' . $e->getMessage() . ' [errno ' . (int) $e->getCode() . ']',
                (int) $e->getCode(),
                $e
            );
        }

        return self::ofRow($row);
    }

    public function canAct(): bool
    {
        return $this === self::Active;
    }

    private static function errno(QueryException $e): int
    {
        return preg_match('/ \[errno (\d+)\]\z/', $e->getMessage(), $tag) === 1 ? (int) $tag[1] : 0;
    }
}
