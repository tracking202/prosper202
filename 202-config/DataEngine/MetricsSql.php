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
CASE WHEN SUM(clicks) > 0 THEN (SUM(click_out)/SUM(clicks))*100 ELSE 0 END as ctr,
SUM(leads) AS leads,
CASE WHEN SUM(clicks) > 0 THEN (SUM(2st.click_lead)/SUM(clicks))*100 ELSE 0 END as su_ratio,
CASE WHEN SUM(leads) > 0 THEN (SUM(income) / SUM(leads)) ELSE 0 END AS payout,
SUM(2st.income) AS income,
CASE WHEN SUM(clicks) > 0 THEN SUM(2st.income)/SUM(clicks) ELSE 0 END as epc,
SUM(2st.cost) AS cost,
CASE WHEN SUM(clicks) > 0 THEN SUM(2st.cost)/SUM(clicks) ELSE 0 END AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
CASE WHEN SUM(2st.cost) > 0 THEN ((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100) ELSE 0 END as roi';

    private function __construct()
    {
    }
}
