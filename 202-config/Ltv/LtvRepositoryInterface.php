<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

interface LtvRepositoryInterface
{
    /**
     * Account-level realized LTV totals (customers, revenue, avg LTV, AOV,
     * repeat rate, MRR).
     *
     * @return array<string, mixed>
     */
    public function summary(LtvQuery $query): array;

    /**
     * Per-customer listing with rollups.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function customers(LtvQuery $query, string $sortBy, string $sortDir, int $limit, int $offset): array;

    /**
     * Realized LTV grouped by acquisition dimension (campaign, ppc_account,
     * landing_page — via the customer's first click) or by product (via
     * ledger line items).
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(LtvQuery $query, string $breakdownType, int $limit, int $offset): array;

    /**
     * Subscription economics: active MRR/ARR, status counts, and the
     * documented churn computation with its inputs.
     *
     * @return array<string, mixed>
     */
    public function mrr(int $userId): array;

    /**
     * Deterministic predictive LTV with guards (churn floor, projection cap,
     * minimum cohort size with account-level fallback). Every response
     * carries its inputs so the numbers are reproducible.
     *
     * @return array<string, mixed>
     */
    public function predict(LtvQuery $query, ?string $breakdownType = null): array;
}
