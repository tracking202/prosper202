<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * "No traffic source": the clicks whose account names no traffic source.
 *
 * The rollup writes the LEFT JOIN's NULL for them (ClickRollupSql), but
 * ppc_network_id is DEFAULT 0 and the writer before it, DataEngine::doSummary(),
 * wrote '' -- 0 in an int column under the app's empty sql_mode -- so rows
 * rolled up before the INSERT … SELECT carry 0 until they are re-rolled. A
 * filter that asked for `IS NULL` alone left them out of the "[No traffic
 * source]" report on the pages and in the API (CLAUDE.md #25: on a column a
 * LEFT JOIN fills, ask for `IS NULL OR = 0`). Every reader of the rollup asks
 * through here, and so does the API's click list, whose column is the
 * account's own (`pa.ppc_network_id`, NULL for a click with no account). The
 * groups report already agrees: it joins 202_ppc_networks, which has no row
 * 0, so 0 and NULL are one "none" group there.
 */
final class NoTrafficSource
{
    private function __construct()
    {
    }

    /** The condition for $column, a ppc_network_id column ("de.ppc_network_id"). */
    public static function condition(string $column): string
    {
        if (preg_match('/^(?:[A-Za-z0-9_]+\.)?ppc_network_id$/D', $column) !== 1) {
            throw new \InvalidArgumentException('not a ppc_network_id column: ' . $column);
        }

        return "($column IS NULL OR $column = 0)";
    }
}
