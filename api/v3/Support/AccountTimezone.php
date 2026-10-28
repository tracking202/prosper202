<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Prosper202\Report\AccountZone;

/**
 * The account's timezone (202_users.user_timezone), as the report pages use
 * it; UTC when it is unset or not a timezone PHP knows (AccountZone, the one
 * rule). For a class with StatementHelpers, a $db and a $userId.
 */
trait AccountTimezone
{
    private function accountTimezone(): string
    {
        $stmt = $this->prepare('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Failed to resolve timezone');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Failed to resolve timezone');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return AccountZone::normalize(isset($row['user_timezone']) ? (string) $row['user_timezone'] : null);
    }
}
