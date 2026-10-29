<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use RuntimeException;

/**
 * The count of changes that end every session of a user
 * (202_users.user_session_generation): a new password, a switch-off, a
 * removal. A session holds the value it began with (AUTH::begin_user_session());
 * connect.php reads the row's with the user it already reads (UserStanding)
 * and ends a session that holds another, so a browser signed in before the
 * change is signed out at its next request. A session from before 1.9.79
 * holds none, which is read as 0, the value every user starts with.
 *
 * A counter, not a time: a time compared across PHP's clock and the
 * database's, or within one second, can call a session begun before the
 * change one begun after it.
 */
final class SessionGeneration
{
    public const string BUMP
        = 'UPDATE 202_users SET user_session_generation = user_session_generation + 1 WHERE user_id = ?';

    public const string READ = 'SELECT user_session_generation FROM 202_users WHERE user_id = ?';

    private function __construct()
    {
    }

    /**
     * Count up the user's generation, on $conn (in the caller's transaction,
     * with the change that ends the sessions), and answer the new value. A
     * write that fails or finds no user throws.
     *
     * @throws QueryException|RuntimeException
     */
    public static function bump(Connection $conn, int $userId): int
    {
        try {
            $stmt = $conn->prepareWrite(self::BUMP);
            $conn->bind($stmt, 'i', [$userId]);
            if ($conn->executeUpdate($stmt) !== 1) {
                throw new RuntimeException('Ending the sessions of user ' . $userId . ' changed no row');
            }
            $stmt = $conn->prepareWrite(self::READ);
            $conn->bind($stmt, 'i', [$userId]);
            $row = $conn->fetchOne($stmt);
        } catch (QueryException $e) {
            throw new QueryException(
                'Ending the sessions of user ' . $userId . ' failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        } catch (\mysqli_sql_exception $e) {
            throw new QueryException(
                'Ending the sessions of user ' . $userId . ' failed: ' . $e->getMessage()
                    . ' [errno ' . (int) $e->getCode() . ']',
                (int) $e->getCode(),
                $e
            );
        }
        $generation = $row['user_session_generation'] ?? null;
        if (!is_numeric($generation)) {
            throw new RuntimeException('Ending the sessions of user ' . $userId . ': the new generation was not read');
        }

        return (int) $generation;
    }
}
