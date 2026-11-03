<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/** Deletes ordering records only after their pageview proofs have expired. */
final class PurLinkExitRetention
{
    public const BATCH = 1000;

    public function __construct(private readonly Connection $conn)
    {
    }

    /** Delete at most one bounded batch and return how many rows were removed. */
    public function prune(int $now): int
    {
        if ($now < 1) {
            throw new \InvalidArgumentException('Retention needs a positive time');
        }
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_purlink_exit_order WHERE expires_at <= ? ORDER BY expires_at LIMIT ' . self::BATCH
        );
        $this->conn->bind($stmt, 'i', [$now]);
        return $this->conn->executeUpdate($stmt);
    }
}
