<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Shared SQL fragments for the reporting metric columns (clicks, CTR, EPC,
 * ROI, ...). Every report against 202_dataengine selects this same set of
 * aggregates; previously the fragment was duplicated in every report method.
 *
 * CTR is click_out/clicks over the aggregated group — the orientation and
 * aggregation the variable report and the display formatter always used.
 * The legacy fragment computed (clicks/click_out) from the bare columns of
 * an arbitrary row in the group, which only affected ORDER BY `ctr` because
 * the formatter recomputes CTR for display.
 */
final class MetricsSql
{
    /**
     * Metric SELECT list for reports grouped by a dimension. The table alias
     * for 202_dataengine is `2st`.
     */
    public const GROUPED_SELECT = '
sum(clicks) as clicks, sum(click_out) as click_out,
(SUM(click_out)/sum(clicks))*100 as ctr,
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
