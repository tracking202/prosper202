<?php

declare(strict_types=1);

namespace Prosper202\Click;

interface ClickRepositoryInterface
{
    /**
     * Atomically record a click across all related tables.
     *
     * @return int The auto-generated click_id
     */
    public function recordClick(ClickRecord $click): int;
}
