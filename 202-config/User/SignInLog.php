<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use Prosper202\Repository\LookupRepositoryFactory;

/**
 * The sign-in audit log (202_users_log): one row per attempt whose
 * credentials were checked, which Account › Settings and GET
 * /system/login-log list and AUTH::is_rate_limited() counts the failures of.
 * Neither the password typed nor the session is stored.
 */
final readonly class SignInLog
{
    public function __construct(private Connection $conn)
    {
    }

    public static function on(\mysqli $db): self
    {
        return new self(LookupRepositoryFactory::connection($db));
    }

    /**
     * @param array<string, string> $errors what the form answered the attempt
     * @param string $requestSnapshot AUTH::login_audit_snapshot()
     * @return int the row's login_id
     * @throws \RuntimeException when the row is not written
     */
    public function append(
        string $userName,
        string $address,
        SignInOutcome $outcome,
        array $errors,
        string $requestSnapshot,
    ): int {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_users_log (user_name, user_pass, ip_address, login_time, login_success, login_error,'
                . ' login_server, login_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'sssiisss', [
            $userName, '[filtered]', $address, time(), $outcome->value, serialize($errors), $requestSnapshot, '',
        ]);

        return $this->conn->executeInsert($stmt);
    }
}
