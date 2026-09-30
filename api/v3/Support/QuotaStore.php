<?php

declare(strict_types=1);

namespace Api\V3\Support;

/**
 * The store behind the Android intake's abuse caps (plan §7.1): take a cost
 * from a fixed-window quota, and give back what an admitted request did not
 * end up recording. ServerStateStore is the one the intakes use; tests plant
 * their own to see what is asked.
 */
interface QuotaStore
{
    /**
     * Take $cost units of the quota, or refuse without taking any.
     *
     * @return array{retry_after: int|null, window_start: int} retry_after null when admitted
     * @throws \RuntimeException when the quota cannot be read or written
     */
    public function reserveQuotaWindow(string $bucket, int $limit, int $windowSeconds, int $cost = 1): array;

    /**
     * Give back $cost units charged to the window that starts at $windowStart.
     *
     * @return bool whether they went back (false: that window has closed)
     * @throws \RuntimeException when the quota cannot be read or written
     */
    public function refundQuota(string $bucket, int $windowSeconds, int $cost, int $windowStart): bool;
}
