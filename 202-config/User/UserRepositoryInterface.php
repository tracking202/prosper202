<?php

declare(strict_types=1);

namespace Prosper202\User;

interface UserRepositoryInterface
{
    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * @param array{fname: string, lname: string, name: string, password: string, email: string, timezone?: string, active?: int} $data
     */
    public function create(array $data): int;

    /**
     * @param array<string, mixed> $data Partial update — only provided keys are updated
     */
    public function update(int $id, array $data): void;

    public function softDelete(int $id): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listRoles(): array;

    public function assignRole(int $userId, int $roleId): void;

    public function removeRole(int $userId, int $roleId): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listApiKeys(int $userId): array;

    public function createApiKey(int $userId, string $name): string;

    public function deleteApiKey(string $apiKey, int $userId): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getPreferences(int $userId): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function updatePreferences(int $userId, array $data): void;
}
