<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\LookupRepositoryFactory;

/**
 * What a sign-in writes on its user: the address it came from, which the
 * own-clicks filter compares clicks with (FILTER::checkUserIP), and the two
 * survey flags, which stay as stored when null (surveyFlags()). A sign-in
 * the database cannot record is refused, as one it
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

    /**
     * The two flags from the survey service's answer (getSurveyData()): each
     * is read only from a boolean under its own key, and is null (kept as
     * stored) otherwise: a failed fetch (null), an error answer, or a body
     * without it ({} or {"message": ...} from a server error, which the
     * fetch does not check the status of). Read as "no modal, no perks",
     * both were reset on every sign-in the service could not answer
     * (measured).
     *
     * @return array{?int, ?int} modal_status, vip_perks_status
     */
    public static function surveyFlags(mixed $survey): array
    {
        if (!is_array($survey) || ($survey['error'] ?? false) === true) {
            return [null, null];
        }
        $modal = $survey['modal'] ?? null;
        $perks = $survey['vip_perks'] ?? null;

        return [is_bool($modal) ? ($modal ? 0 : 1) : null, is_bool($perks) ? ($perks ? 1 : 0) : null];
    }

    /** @throws \RuntimeException when the address or the user's row is not written */
    public function record(int $userId, string $address, ?int $modalStatus, ?int $vipPerksStatus): void
    {
        $ipId = $this->locations->findOrCreateIp($address);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_users SET user_last_login_ip_id = ?, modal_status = COALESCE(?, modal_status),'
                . ' vip_perks_status = COALESCE(?, vip_perks_status) WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'iiii', [$ipId, $modalStatus, $vipPerksStatus, $userId]);
        $this->conn->executeUpdate($stmt);
    }
}
