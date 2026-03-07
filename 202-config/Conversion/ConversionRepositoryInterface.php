<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

interface ConversionRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters campaign_id, time_from, time_to
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $userId, array $filters, int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $userId): ?array;

    /**
     * Record a conversion and update the source click (click_lead=1, click_payout).
     *
     * @param array{click_id: int, transaction_id?: string, payout?: float, conv_time?: int} $data
     * @return int The new conversion ID
     */
    public function create(int $userId, array $data): int;

    /**
     * Soft-delete a conversion.
     */
    public function softDelete(int $id, int $userId): void;
}
