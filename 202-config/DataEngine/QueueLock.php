<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Database\Connection;

/**
 * A server lock (GET_LOCK) a run of one of the data engine's queues holds
 * while it works, on the connection it works on. The server drops it when
 * that connection ends, however it ends, so "is the lock free" is "has the
 * run that held it gone", which no column of either queue can say.
 */
final class QueueLock
{
    private function __construct()
    {
    }

    /**
     * Names are server-wide and at most 64 characters: the database and
     * $what, hashed with a separator neither can hold.
     */
    public static function name(Connection $conn, string $what): string
    {
        $row = $conn->fetchOne($conn->prepareWrite('SELECT DATABASE() AS db'));
        $db = $row['db'] ?? null;
        if (!is_string($db) || $db === '') {
            throw new \RuntimeException('The data engine\'s queues need a selected database to name their locks.');
        }

        return 'p202_de_' . sha1($db . "\0" . $what);
    }

    /**
     * Whether this call took $name. False when any connection holds it,
     * this one included: GET_LOCK alone would hand a connection its own
     * lock a second time, and a run would read its own work as abandoned.
     */
    public static function tryTake(Connection $conn, string $name): bool
    {
        $got = self::ask($conn, 'SELECT IF(IS_USED_LOCK(?) IS NULL, GET_LOCK(?, 0), 0) AS answer', [$name, $name]);
        if ($got === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . $name);
        }

        return $got === 1;
    }

    /** Whether this connection holds $name. */
    public static function isHeldHere(Connection $conn, string $name): bool
    {
        return self::ask($conn, 'SELECT IS_USED_LOCK(?) <=> CONNECTION_ID() AS answer', [$name]) === 1;
    }

    public static function release(Connection $conn, string $name): void
    {
        self::ask($conn, 'SELECT RELEASE_LOCK(?) AS answer', [$name]);
    }

    /** @param list<string> $names */
    private static function ask(Connection $conn, string $sql, array $names): ?int
    {
        $stmt = $conn->prepareWrite($sql);
        $conn->bind($stmt, str_repeat('s', count($names)), $names);
        $row = $conn->fetchOne($stmt);

        return $row === null || $row['answer'] === null ? null : (int) $row['answer'];
    }
}
