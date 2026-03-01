<?php

declare(strict_types=1);

namespace Prosper202\Tracker;

interface TrackerRepositoryInterface
{
    /**
     * Fetch full tracker detail by public ID (for click redirect hot path).
     *
     * Joins: 202_trackers, 202_users_pref, 202_users, 202_aff_campaigns,
     *        202_ppc_accounts, 202_ppc_network_variables.
     *
     * @return array<string, mixed>|null Tracker row or null if not found
     */
    public function findByPublicId(string $publicId): ?array;
}
