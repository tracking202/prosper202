<?php

declare(strict_types=1);

namespace Prosper202\Report;

final class ReportQuery
{
    /** @var array<string, int> Entity filters like ['aff_campaign_id' => 5] */
    public readonly array $entityFilters;

    /**
     * @param array<string, int> $entityFilters
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $timeFrom = null,
        public readonly ?int $timeTo = null,
        array $entityFilters = [],
    ) {
        $allowed = ['aff_campaign_id', 'aff_network_id', 'ppc_account_id', 'ppc_network_id', 'landing_page_id', 'country_id'];
        $this->entityFilters = array_intersect_key($entityFilters, array_flip($allowed));
    }
}
