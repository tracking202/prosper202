<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function list(int $offset, int $limit): array
    {
        $countStmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS total FROM 202_users WHERE user_deleted = 0'
        );
        $countRow = $this->conn->fetchOne($countStmt);
        $total = (int) ($countRow['total'] ?? 0);

        $stmt = $this->conn->prepareRead(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone,
                    user_active, user_deleted, user_time_register
             FROM 202_users WHERE user_deleted = 0 ORDER BY user_id ASC LIMIT ? OFFSET ?'
        );
        $this->conn->bind($stmt, 'ii', [$limit, $offset]);
        $rows = $this->conn->fetchAll($stmt);

        return ['rows' => $rows, 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone,
                    user_active, user_deleted, user_time_register
             FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$id]);

        return $this->conn->fetchOne($stmt);
    }

    public function create(array $data): int
    {
        $hashedPass = hash_user_pass($data['password']);
        $now = time();

        return $this->conn->transaction(function () use ($data, $hashedPass, $now): int {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_users
                    (user_fname, user_lname, user_name, user_pass, user_email, user_timezone, user_time_register, user_active, user_deleted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $this->conn->bind($stmt, 'ssssssii', [
                $data['fname'], $data['lname'], $data['name'],
                $hashedPass, $data['email'], $data['timezone'] ?? 'UTC',
                $now, $data['active'] ?? 1,
            ]);
            $userId = $this->conn->executeInsert($stmt);

            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_users_pref (user_id) VALUES (?)'
            );
            $this->conn->bind($stmt, 'i', [$userId]);
            $this->conn->execute($stmt);
            $stmt->close();

            return $userId;
        });
    }

    public function update(int $id, array $data): void
    {
        $allowedFields = ['user_fname' => 's', 'user_lname' => 's', 'user_email' => 's', 'user_timezone' => 's', 'user_active' => 'i'];

        $sets = [];
        $values = [];
        $types = '';

        foreach ($allowedFields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
                $types .= $type;
            }
        }

        if (array_key_exists('user_pass', $data) && $data['user_pass'] !== '') {
            $sets[] = 'user_pass = ?';
            $values[] = hash_user_pass((string) $data['user_pass']);
            $types .= 's';
        }

        if (empty($sets)) {
            throw new RuntimeException('No fields to update');
        }

        $values[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_users SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
        );
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$id]);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function listRoles(): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT role_id, role_name FROM 202_roles ORDER BY role_id'
        );

        return $this->conn->fetchAll($stmt);
    }

    public function assignRole(int $userId, int $roleId): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT IGNORE INTO 202_user_role (user_id, role_id) VALUES (?, ?)'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $roleId]);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_user_role WHERE user_id = ? AND role_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $roleId]);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function listApiKeys(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT user_id, api_key, created_at, scope FROM 202_api_keys WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchAll($stmt);
    }

    public function createApiKey(int $userId, string $name): string
    {
        $key = bin2hex(random_bytes(32));
        $now = time();

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)'
        );
        $this->conn->bind($stmt, 'isi', [$userId, $key, $now]);
        $this->conn->execute($stmt);
        $stmt->close();

        return $key;
    }

    public function deleteApiKey(string $apiKey, int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_api_keys WHERE api_key = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'si', [$apiKey, $userId]);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function getPreferences(int $userId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT * FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchOne($stmt);
    }

    public function updatePreferences(int $userId, array $data): void
    {
        $allowedFields = [
            'user_pref_limit' => 'i', 'user_pref_time_predefined' => 's',
            'user_tracking_domain' => 's', 'user_cpc_or_cpv' => 's',
            'user_account_currency' => 's', 'user_slack_incoming_webhook' => 's',
            'user_pref_cloak_referer' => 's', 'user_daily_email' => 's',
            'ipqs_api_key' => 's', 'chart_time_range' => 's',
        ];

        $sets = [];
        $values = [];
        $types = '';

        foreach ($allowedFields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($sets)) {
            throw new RuntimeException('No valid fields to update');
        }

        $values[] = $userId;
        $types .= 'i';

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_users_pref SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
        );
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $stmt->close();
    }
}
