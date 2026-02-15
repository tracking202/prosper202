<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;

class UsersController
{
    private \mysqli $db;
    private int $userId;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    public function list(): array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_deleted = 0 ORDER BY user_id ASC'
        );
        $stmt->execute();
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
        $stmt = $this->db->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('User not found', 404);
        }

        // Get roles
        $stmt = $this->db->prepare('SELECT r.role_id, r.role_name FROM 202_user_role ur INNER JOIN 202_roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
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
        $username = $payload['user_name'] ?? '';
        $email = $payload['user_email'] ?? '';
        $password = $payload['user_pass'] ?? '';

        if ($username === '' || $email === '' || $password === '') {
            throw new \RuntimeException('user_name, user_email, and user_pass are required', 422);
        }

        // Check unique username
        $stmt = $this->db->prepare('SELECT user_id FROM 202_users WHERE user_name = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new \RuntimeException('Username already exists', 409);
        }
        $stmt->close();

        // Hash password with bcrypt
        $hashedPass = password_hash($password, PASSWORD_BCRYPT);

        $fname = $payload['user_fname'] ?? '';
        $lname = $payload['user_lname'] ?? '';
        $tz = $payload['user_timezone'] ?? 'UTC';
        $now = time();
        $active = (int)($payload['user_active'] ?? 1);

        $stmt = $this->db->prepare(
            'INSERT INTO 202_users (user_fname, user_lname, user_name, user_pass, user_email, user_timezone, user_time_register, user_active, user_deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->bind_param('ssssssii', $fname, $lname, $username, $hashedPass, $email, $tz, $now, $active);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Create failed', 500);
        }
        $newId = $stmt->insert_id;
        $stmt->close();

        // Create user preferences row
        $stmt = $this->db->prepare('INSERT INTO 202_users_pref (user_id) VALUES (?)');
        $stmt->bind_param('i', $newId);
        $stmt->execute();
        $stmt->close();

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

        if (array_key_exists('user_pass', $payload) && $payload['user_pass'] !== '') {
            $sets[] = 'user_pass = ?';
            $binds[] = password_hash($payload['user_pass'], PASSWORD_BCRYPT);
            $types .= 's';
        }

        if (empty($sets)) {
            throw new \RuntimeException('No fields to update', 422);
        }

        $binds[] = $id;
        $types .= 'i';

        $stmt = $this->db->prepare('UPDATE 202_users SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $stmt = $this->db->prepare('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    // --- Roles ---

    public function listRoles(): array
    {
        $result = $this->db->query('SELECT * FROM 202_roles ORDER BY role_id');
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
            throw new \RuntimeException('role_id is required', 422);
        }

        $stmt = $this->db->prepare('INSERT IGNORE INTO 202_user_role (user_id, role_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $userId, $roleId);
        $stmt->execute();
        $stmt->close();

        return $this->get($userId);
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->db->prepare('DELETE FROM 202_user_role WHERE user_id = ? AND role_id = ?');
        $stmt->bind_param('ii', $userId, $roleId);
        $stmt->execute();
        $stmt->close();
    }

    // --- API Keys ---

    public function listApiKeys(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT user_id, api_key, created_at FROM 202_api_keys WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return ['data' => $rows];
    }

    public function createApiKey(int $userId): array
    {
        $key = bin2hex(random_bytes(16));
        $now = time();
        $stmt = $this->db->prepare('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)');
        $stmt->bind_param('isi', $userId, $key, $now);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to create API key', 500);
        }
        $stmt->close();

        return ['data' => ['user_id' => $userId, 'api_key' => $key, 'created_at' => $now]];
    }

    public function deleteApiKey(int $userId, string $apiKey): void
    {
        $stmt = $this->db->prepare('DELETE FROM 202_api_keys WHERE user_id = ? AND api_key = ?');
        $stmt->bind_param('is', $userId, $apiKey);
        $stmt->execute();
        $stmt->close();
    }

    // --- Preferences ---

    public function getPreferences(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('User preferences not found', 404);
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
            throw new \RuntimeException('No valid fields to update', 422);
        }

        $binds[] = $userId;
        $types .= 'i';

        $stmt = $this->db->prepare('UPDATE 202_users_pref SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $stmt->close();

        return $this->getPreferences($userId);
    }
}
