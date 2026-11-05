<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * How far the install's database has come, as the API state store counts it:
 * a number in 202_deployment_secrets (api_state_epoch) that the store moves
 * on with every record a database restore can make wrong, and writes beside
 * those records (ServerStateStore). A database restored from an older dump
 * keeps its install id, and so the store's directory, but takes this number
 * back with it: a directory that has counted past its database's number was
 * written after the dump (P4QS).
 *
 * The number is not moved inside an open transaction: a rollback would take
 * it back while the record stayed, and read like a restore. A record written
 * then carries the number as it is, which a restore to that same point does
 * not tell apart -- a narrower window than before, and never a false alarm.
 */
class StateEpoch
{
    public const SECRET_NAME = 'api_state_epoch';

    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * The database's number; 0 when it has none (an install that never wrote
     * such a record, or a dump taken before it did).
     *
     * @throws \Prosper202\Database\Exceptions\QueryException when it cannot be read
     */
    public function current(): int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ?'
        );
        $conn->bind($stmt, 's', [self::SECRET_NAME]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? 0 : max(0, (int) $row['secret_value']);
    }

    /**
     * The number moved on by one, and returned; null inside an open
     * transaction, where it is left as it is (see the class).
     *
     * @throws \Prosper202\Database\Exceptions\QueryException when it cannot be moved
     */
    public function advance(): ?int
    {
        $conn = new Connection($this->db);
        if ($conn->inTransaction() || $this->serverSaysInTransaction()) {
            return null;
        }
        $stmt = $conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at)'
            . " VALUES (?, '1', ?)"
            . ' ON DUPLICATE KEY UPDATE secret_value = CAST(CAST(secret_value AS UNSIGNED) + 1 AS CHAR)'
        );
        $conn->bind($stmt, 'si', [self::SECRET_NAME, time()]);
        $conn->execute($stmt);

        return $this->current();
    }

    /** How this server says whether the session is in a transaction, in the order tried. */
    private const array TRANSACTION_PROBES = [
        // MariaDB
        'SELECT @@in_transaction AS t',
        // MySQL 8.0.16+, with transaction instrumentation (on by default)
        "SELECT COUNT(*) AS t FROM performance_schema.events_transactions_current"
            . " WHERE THREAD_ID = PS_CURRENT_THREAD_ID() AND STATE = 'ACTIVE'",
    ];

    /**
     * A transaction begun on the raw handle (mysqli::begin_transaction()),
     * which Connection::inTransaction() does not see, as the server tells it.
     * The first probe that answers is kept for the process; when none does,
     * the answer is Connection's alone, which is how the served tree opens
     * its transactions (Connection::transaction()).
     */
    private function serverSaysInTransaction(): bool
    {
        static $probe = null;
        foreach ($probe === null ? self::TRANSACTION_PROBES : ($probe === '' ? [] : [$probe]) as $sql) {
            $answer = $this->probe($sql);
            if ($answer !== null) {
                $probe = $sql;

                return $answer;
            }
        }
        $probe = '';

        return false;
    }

    /** $sql's answer (its column t above 0), or null when this server cannot answer it. */
    private function probe(string $sql): ?bool
    {
        try {
            $result = $this->db->query($sql);
        } catch (\Throwable) {
            // Refused, or a handle that cannot run it: either way this server does not answer it.
            return null;
        }
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) && (int) ($row['t'] ?? 0) > 0;
    }
}
