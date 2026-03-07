<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

interface RotatorRepositoryInterface
{
    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $userId, int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null Rotator with nested rules, criteria, and redirects
     */
    public function findById(int $id, int $userId): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, int $userId, array $data): void;

    /**
     * Cascade-delete rotator and all rules, criteria, and redirects.
     */
    public function delete(int $id, int $userId): void;

    /**
     * @param array<string, mixed> $data Must include criteria and redirects arrays
     */
    public function createRule(int $rotatorId, array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function updateRule(int $ruleId, int $rotatorId, array $data): void;

    /**
     * Cascade-delete rule and its criteria and redirects.
     */
    public function deleteRule(int $ruleId, int $rotatorId): void;
}
