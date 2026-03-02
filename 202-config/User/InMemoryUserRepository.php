<?php

declare(strict_types=1);

namespace Prosper202\User;

use RuntimeException;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $users = [];
    private int $nextUserId = 1;

    /** @var list<array<string, mixed>> */
    public array $roles = [];

    /** @var list<array{user_id: int, role_id: int}> */
    public array $userRoles = [];

    /** @var array<int, array<string, mixed>> */
    public array $apiKeys = [];
    private int $nextApiKeyId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $preferences = [];

    public function list(int $offset, int $limit): array
    {
        $active = array_filter($this->users, fn(array $u) => empty($u['user_deleted']));
        $total = count($active);
        $rows = array_slice(array_values($active), $offset, $limit);

        return ['rows' => $rows, 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $user = $this->users[$id] ?? null;
        if ($user === null || !empty($user['user_deleted'])) {
            return null;
        }

        return $user;
    }

    public function create(array $data): int
    {
        $id = $this->nextUserId++;
        $this->users[$id] = [
            'user_id' => $id,
            'user_fname' => $data['fname'],
            'user_lname' => $data['lname'],
            'user_name' => $data['name'],
            'user_email' => $data['email'],
            'user_timezone' => $data['timezone'] ?? 'UTC',
            'user_active' => $data['active'] ?? 1,
            'user_deleted' => 0,
            'user_time_register' => time(),
        ];
        $this->preferences[$id] = ['user_id' => $id];

        return $id;
    }

    public function update(int $id, array $data): void
    {
        if (!isset($this->users[$id])) {
            throw new RuntimeException("User $id not found");
        }

        $allowedFields = ['user_fname', 'user_lname', 'user_email', 'user_timezone', 'user_active'];
        $updated = false;

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->users[$id][$field] = $data[$field];
                $updated = true;
            }
        }

        if (array_key_exists('user_pass', $data) && $data['user_pass'] !== '') {
            $this->users[$id]['user_pass'] = hash_user_pass((string) $data['user_pass']);
            $updated = true;
        }

        if (!$updated) {
            throw new RuntimeException('No fields to update');
        }
    }

    public function softDelete(int $id): void
    {
        if (isset($this->users[$id])) {
            $this->users[$id]['user_deleted'] = 1;
        }
    }

    public function listRoles(): array
    {
        return $this->roles;
    }

    public function assignRole(int $userId, int $roleId): void
    {
        foreach ($this->userRoles as $ur) {
            if ($ur['user_id'] === $userId && $ur['role_id'] === $roleId) {
                return; // Already assigned (INSERT IGNORE equivalent)
            }
        }
        $this->userRoles[] = ['user_id' => $userId, 'role_id' => $roleId];
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $this->userRoles = array_values(array_filter(
            $this->userRoles,
            fn(array $ur) => !($ur['user_id'] === $userId && $ur['role_id'] === $roleId),
        ));
    }

    public function listApiKeys(int $userId): array
    {
        return array_values(array_filter(
            $this->apiKeys,
            fn(array $k) => $k['user_id'] === $userId,
        ));
    }

    public function createApiKey(int $userId, string $name): string
    {
        $id = $this->nextApiKeyId++;
        $key = bin2hex(random_bytes(32));
        $this->apiKeys[$id] = [
            'user_id' => $userId,
            'api_key' => $key,
            'created_at' => time(),
            'scope' => null,
        ];

        return $key;
    }

    public function deleteApiKey(string $apiKey, int $userId): void
    {
        foreach ($this->apiKeys as $id => $k) {
            if ($k['api_key'] === $apiKey && $k['user_id'] === $userId) {
                unset($this->apiKeys[$id]);
                return;
            }
        }
    }

    public function getPreferences(int $userId): ?array
    {
        return $this->preferences[$userId] ?? null;
    }

    public function updatePreferences(int $userId, array $data): void
    {
        $allowedFields = [
            'user_pref_limit', 'user_pref_time_predefined',
            'user_tracking_domain', 'user_cpc_or_cpv',
            'user_account_currency', 'user_slack_incoming_webhook',
            'user_pref_cloak_referer', 'user_daily_email',
            'ipqs_api_key', 'chart_time_range',
        ];

        $updated = false;
        if (!isset($this->preferences[$userId])) {
            $this->preferences[$userId] = ['user_id' => $userId];
        }

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->preferences[$userId][$field] = $data[$field];
                $updated = true;
            }
        }

        if (!$updated) {
            throw new RuntimeException('No valid fields to update');
        }
    }
}
