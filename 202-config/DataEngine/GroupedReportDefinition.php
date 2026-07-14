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
     * Pagination counts the report's own GROUP BY over these same joins, so a report
     * needs no separate count strategy — see DataEngine::countReportGroups(). The old
     * $countColumn / $usesRefererCount pair described a count that ran against a
     * different set of rows than the report itself, which is what made totalRows
     * disagree with the rows returned.
     *
     * @param string  $labelSelect       Dimension column(s) for the SELECT list,
     *                                   e.g. "country_name,country_code".
     * @param string  $joins             Report-specific JOIN clause against the
     *                                   `2st` (202_dataengine) alias.
     * @param string  $groupBy           GROUP BY expression. May name an alias
     *                                   defined in $labelSelect.
     * @param bool    $includeFilterJoin Whether the user-preference filter JOIN
     *                                   is prepended. The keyword report must
     *                                   not include it: it already joins
     *                                   202_keywords under the same `2k` alias
     *                                   the filter join would introduce.
     */
    public function __construct(
        public readonly string $labelSelect,
        public readonly string $joins,
        public readonly string $groupBy,
        public readonly bool $includeFilterJoin = true,
    ) {
    }
}
