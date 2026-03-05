<?php

declare(strict_types=1);

namespace Prosper202\Click;

interface ClickRepositoryInterface
{
    /**
     * Allocate a click_id from the counter table (outside of transaction).
     *
     * Use this when the hot path needs click_id before calling recordClick()
     * (e.g. to generate click_id_public and compute site URLs).
     */
    public function allocateClickId(): int;

    /**
     * Atomically record a click across all related tables.
     *
     * If $click->clickId is set (> 0), that pre-allocated ID is used
     * instead of generating a new one from the counter table.
     *
     * @return int The click_id (auto-generated or pre-allocated)
     */
    public function recordClick(ClickRecord $click): int;
}
