<?php

declare(strict_types=1);

namespace Prosper202\Crud;

/**
 * Generic CRUD repository for simple single-table entities.
 *
 * Covers: aff_networks, ppc_networks, ppc_accounts, aff_campaigns,
 *         landing_pages, text_ads, trackers — any entity that follows
 *         the pattern: user-scoped rows with soft-delete.
 */
interface CrudRepositoryInterface
{
    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $userId, int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null
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

    public function softDelete(int $id, int $userId): void;
}
