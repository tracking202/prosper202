package cmd

import (
	"encoding/json"
	"fmt"
	"sort"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// dimensionFilterParam maps a breakdown dimension to the entity-filter param
// used to scope a second breakdown to one of its rows.
var dimensionFilterParam = map[string]string{
	"campaign":     "aff_campaign_id",
	"aff_network":  "aff_network_id",
	"ppc_account":  "ppc_account_id",
	"ppc_network":  "ppc_network_id",
	"landing_page": "landing_page_id",
	"country":      "country_id",
}

var reportCrosstabCmd = &cobra.Command{
	Use:   "crosstab",
	Short: "Two-dimension breakdown (e.g. traffic source × country) for one metric",
	Long: "Pivots one metric across two dimensions by fanning out a breakdown per row.\n" +
		"Example: report crosstab --rows ppc_account --cols country --metric profit --aff_campaign_id 90008",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		rowDim := resolveDimension(mustString(cmd, "rows"))
		colDim := resolveDimension(mustString(cmd, "cols"))
		metric := resolveMetric(mustString(cmd, "metric"))
		if rowDim == "" || colDim == "" {
			return validationError("--rows and --cols are required")
		}
		if metric == "" {
			metric = "total_net"
		}
		rowFilter := dimensionFilterParam[rowDim]
		if rowFilter == "" {
			return validationError("--rows %q can't be used as a crosstab axis (no entity filter)", rowDim)
		}
		limitRows, _ := cmd.Flags().GetInt("limit-rows")

		base := collectReportParams(cmd)
		base["breakdown"] = rowDim
		rowRows, err := fetchBreakdownRows(c, base)
		if err != nil {
			return err
		}
		// Rank rows by the metric so the most significant appear first.
		sortRowsBy(rowRows, metric, false)
		if limitRows > 0 && len(rowRows) > limitRows {
			rowRows = rowRows[:limitRows]
		}

		colSet := map[string]bool{}
		matrix := make([]map[string]interface{}, 0, len(rowRows))
		for _, rr := range rowRows {
			rowID := fmt.Sprintf("%v", normalizeID(rr["id"]))
			params := collectReportParams(cmd)
			params["breakdown"] = colDim
			params[rowFilter] = rowID
			colRows, err := fetchBreakdownRows(c, params)
			if err != nil {
				continue
			}
			out := map[string]interface{}{rowDim: rr["name"]}
			for _, cr := range colRows {
				colName := fmt.Sprintf("%v", cr["name"])
				colSet[colName] = true
				out[colName] = round(toFloat(cr[metric]), 2)
			}
			matrix = append(matrix, out)
		}

		// Stable column order for the pivot.
		cols := make([]string, 0, len(colSet))
		for c := range colSet {
			cols = append(cols, c)
		}
		sort.Strings(cols)
		opts := renderOpts()
		if len(opts.Fields) == 0 {
			opts.Fields = append([]string{rowDim}, cols...)
		}
		out, _ := json.Marshal(map[string]interface{}{"data": matrix})
		output.RenderWith(out, opts)
		return nil
	},
}

func init() {
	addReportFilters(reportCrosstabCmd)
	reportCrosstabCmd.Flags().String("rows", "", "Row dimension (ppc_account, country, campaign, ...)")
	reportCrosstabCmd.Flags().String("cols", "", "Column dimension (country, device, ...)")
	reportCrosstabCmd.Flags().String("metric", "total_net", "Metric to pivot (profit, roi, revenue, cost, clicks, conversions)")
	reportCrosstabCmd.Flags().Int("limit-rows", 20, "Cap the number of rows fanned out")
	reportCmd.AddCommand(reportCrosstabCmd)
}
