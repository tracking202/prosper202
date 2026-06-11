<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Declarative description of a report that groups 202_dataengine rows by a
 * single dimension (keyword, country, device, ...). One generic runner in
 * DataEngine executes these; previously each dimension had its own ~50 line
 * copy of the same method.
 */
final class GroupedReportDefinition
{
    /**
     * @param string  $labelSelect       Dimension column(s) for the SELECT list,
     *                                   e.g. "country_name,country_code".
     * @param string  $joins             Report-specific JOIN clause against the
     *                                   `2st` (202_dataengine) alias.
     * @param string  $groupBy           GROUP BY expression.
     * @param ?string $countColumn       2st column counted (DISTINCT) for
     *                                   pagination, or null when the report
     *                                   uses the referer-specific count.
     * @param bool    $includeFilterJoin Whether the user-preference filter JOIN
     *                                   is prepended. The keyword report must
     *                                   not include it: it already joins
     *                                   202_keywords under the same `2k` alias
     *                                   the filter join would introduce.
     * @param bool    $usesRefererCount  Pagination count via the referer
     *                                   domain-grouping subquery.
     */
    public function __construct(
        public readonly string $labelSelect,
        public readonly string $joins,
        public readonly string $groupBy,
        public readonly ?string $countColumn,
        public readonly bool $includeFilterJoin = true,
        public readonly bool $usesRefererCount = false,
    ) {
    }
}
