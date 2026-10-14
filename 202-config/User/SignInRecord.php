<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\LookupRepositoryFactory;

/**
 * What a sign-in writes on its user: the address it came from, which the
 * own-clicks filter compares clicks with (FILTER::checkUserIP), and the two
 * survey flags. A sign-in the database cannot record is refused, as one it
 * cannot check is: record() throws, and 202-login.php answers the form with
 * its sign-in error.
 */
final readonly class SignInRecord
{
    public function __construct(
        private Connection $conn,
        private LocationRepositoryInterface $locations,
    ) {
    }

    public static function on(\mysqli $db): self
    {
        return new self(LookupRepositoryFactory::connection($db), LookupRepositoryFactory::location($db));
    }

    /** @throws \RuntimeException when the address or the user's row is not written */
    public function record(int $userId, string $address, int $modalStatus, int $vipPerksStatus): void
    {
        $ipId = $this->locations->findOrCreateIp($address);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_users SET user_last_login_ip_id = ?, modal_status = ?, vip_perks_status = ? WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'iiii', [$ipId, $modalStatus, $vipPerksStatus, $userId]);
        $this->conn->executeUpdate($stmt);
    }
}
