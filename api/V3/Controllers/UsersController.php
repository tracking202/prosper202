<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

class UsersController
{
    private \mysqli $db;
    private int $userId;

    public function __construct(\mysqli $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }

    public function list(): array
    {
        $stmt = $this->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_deleted = 0 ORDER BY user_id ASC'
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('List query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return ['data' => $rows];
    }

    public function get(int $id): array
    {
        $stmt = $this->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('User not found');
        }

        $stmt = $this->prepare('SELECT r.role_id, r.role_name FROM 202_user_role ur INNER JOIN 202_roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Roles query failed');
        }
        $roles = [];
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $roles[] = $r;
        }
        $stmt->close();

        $row['roles'] = $roles;
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $username = trim((string)($payload['user_name'] ?? ''));
        $email = trim((string)($payload['user_email'] ?? ''));
        $password = (string)($payload['user_pass'] ?? '');

        $errors = [];
        if ($username === '') { $errors['user_name'] = 'Required'; }
        if ($email === '') { $errors['user_email'] = 'Required'; }
        if ($password === '') { $errors['user_pass'] = 'Required'; }
        if (strlen($password) > 0 && strlen($password) < 8) { $errors['user_pass'] = 'Must be at least 8 characters'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['user_email'] = 'Invalid email format'; }
        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }

        $stmt = $this->prepare('SELECT user_id FROM 202_users WHERE user_name = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new ConflictException('Username already exists');
        }
        $stmt->close();

        $hashedPass = password_hash($password, PASSWORD_BCRYPT);

        $fname = trim((string)($payload['user_fname'] ?? ''));
        $lname = trim((string)($payload['user_lname'] ?? ''));
        $tz = trim((string)($payload['user_timezone'] ?? 'UTC'));
        $now = time();
        $active = (int)($payload['user_active'] ?? 1);

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare(
                'INSERT INTO 202_users (user_fname, user_lname, user_name, user_pass, user_email, user_timezone, user_time_register, user_active, user_deleted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->bind_param('ssssssii', $fname, $lname, $username, $hashedPass, $email, $tz, $now, $active);
            if (!$stmt->execute()) {
                throw new DatabaseException('Create failed');
            }
            $newId = $stmt->insert_id;
            $stmt->close();

            $stmt = $this->prepare('INSERT INTO 202_users_pref (user_id) VALUES (?)');
            $stmt->bind_param('i', $newId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Failed to create user preferences'); }
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->get($newId);
    }

    public function update(int $id, array $payload): array
    {
        $this->get($id);

        $sets = [];
        $binds = [];
        $types = '';

        foreach (['user_fname' => 's', 'user_lname' => 's', 'user_email' => 's', 'user_timezone' => 's', 'user_active' => 'i'] as $f => $t) {
            if (array_key_exists($f, $payload)) {
                $sets[] = "$f = ?";
                $binds[] = $payload[$f];
                $types .= $t;
            }
        }

        if (array_key_exists('user_email', $payload) && !filter_var($payload['user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email', ['user_email' => 'Invalid email format']);
        }

        if (array_key_exists('user_pass', $payload) && $payload['user_pass'] !== '') {
            if (strlen($payload['user_pass']) < 8) {
                throw new ValidationException('Password too short', ['user_pass' => 'Must be at least 8 characters']);
            }
            $sets[] = 'user_pass = ?';
            $binds[] = password_hash($payload['user_pass'], PASSWORD_BCRYPT);
            $types .= 's';
        }

        if (empty($sets)) {
            throw new ValidationException('No fields to update');
        }

        $binds[] = $id;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_users SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Update failed');
        }
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $stmt = $this->prepare('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Delete failed');
        }
        $stmt->close();
    }

    // --- Roles ---

    public function listRoles(): array
    {
        $result = $this->db->query('SELECT role_id, role_name FROM 202_roles ORDER BY role_id');
        if (!$result) {
            throw new DatabaseException('Roles query failed');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return ['data' => $rows];
    }

    public function assignRole(int $userId, array $payload): array
    {
        $roleId = (int)($payload['role_id'] ?? 0);
        if ($roleId <= 0) {
            throw new ValidationException('role_id is required', ['role_id' => 'Must be a positive integer']);
        }

        $stmt = $this->prepare('INSERT IGNORE INTO 202_user_role (user_id, role_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $userId, $roleId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Failed to assign role');
        }
        $stmt->close();

        return $this->get($userId);
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->prepare('DELETE FROM 202_user_role WHERE user_id = ? AND role_id = ?');
        $stmt->bind_param('ii', $userId, $roleId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Failed to remove role');
        }
        $stmt->close();
    }

    // --- API Keys ---

    public function listApiKeys(int $userId): array
    {
        $stmt = $this->prepare('SELECT user_id, api_key, created_at FROM 202_api_keys WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // Mask key: show first 8 chars only
            if (isset($row['api_key']) && strlen($row['api_key']) > 8) {
                $row['api_key'] = substr($row['api_key'], 0, 8) . str_repeat('*', 24);
            }
            $rows[] = $row;
        }
        $stmt->close();
        return ['data' => $rows];
    }

    public function createApiKey(int $userId): array
    {
        $key = bin2hex(random_bytes(32));
        $now = time();

        $stmt = $this->prepare('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)');
        $stmt->bind_param('isi', $userId, $key, $now);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Failed to create API key');
        }
        $stmt->close();

        // Return the full key only on creation — it cannot be retrieved later.
        return ['data' => ['user_id' => $userId, 'api_key' => $key, 'created_at' => $now]];
    }

    public function deleteApiKey(int $userId, string $apiKey): void
    {
        $stmt = $this->prepare('DELETE FROM 202_api_keys WHERE user_id = ? AND api_key = ?');
        $stmt->bind_param('is', $userId, $apiKey);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Failed to delete API key');
        }
        $stmt->close();
    }

    // --- Preferences ---

    public function getPreferences(int $userId): array
    {
        $stmt = $this->prepare('SELECT * FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('User preferences not found');
        }
        return ['data' => $row];
    }

    public function updatePreferences(int $userId, array $payload): array
    {
        $this->getPreferences($userId);

        $allowedFields = [
            'user_pref_limit' => 'i', 'user_pref_time_predefined' => 's',
            'user_tracking_domain' => 's', 'user_cpc_or_cpv' => 's',
            'user_account_currency' => 's', 'user_slack_incoming_webhook' => 's',
            'user_pref_cloak_referer' => 's', 'user_daily_email' => 's',
            'ipqs_api_key' => 's', 'chart_time_range' => 's',
        ];

        $sets = [];
        $binds = [];
        $types = '';

        foreach ($allowedFields as $f => $t) {
            if (array_key_exists($f, $payload)) {
                $sets[] = "$f = ?";
                $binds[] = $payload[$f];
                $types .= $t;
            }
        }

        if (empty($sets)) {
            throw new ValidationException('No valid fields to update');
        }

        $binds[] = $userId;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_users_pref SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Preferences update failed');
        }
        $stmt->close();

        return $this->getPreferences($userId);
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }
}
