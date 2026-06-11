<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Shared SQL fragments for the reporting metric columns (clicks, CTR, EPC,
 * ROI, ...). Every report against 202_dataengine selects this same set of
 * aggregates; previously the fragment was duplicated in every report method.
 *
 * The expressions are kept byte-for-byte semantically identical to the
 * legacy queries, including their quirks (e.g. CTR uses the bare `clicks`
 * and `click_out` columns of the first row in the group, not SUM()).
 */
final class MetricsSql
{
    /**
     * Metric SELECT list for reports grouped by a dimension. The table alias
     * for 202_dataengine is `2st`.
     */
    public const GROUPED_SELECT = '
sum(clicks) as clicks, sum(click_out) as click_out,
(clicks/click_out)*100 as ctr,
SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout,
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi';

    private function __construct()
    {
    }
}
