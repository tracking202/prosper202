package cmd

import (
	"strings"

	"github.com/spf13/cobra"
)

// dimensionAliases maps short/friendly breakdown dimension names to the API
// field the backend expects. Identity entries are accepted as-is.
var dimensionAliases = map[string]string{
	"lp":      "landing_page",
	"source":  "ppc_account",
	"network": "aff_network",
	"offer":   "campaign",
	"geo":     "country",
}

// resolveDimension normalizes a user-supplied breakdown dimension.
func resolveDimension(d string) string {
	d = strings.ToLower(strings.TrimSpace(d))
	if mapped, ok := dimensionAliases[d]; ok {
		return mapped
	}
	return d
}

// metricAliases maps friendly sort/metric names to the raw API column names,
// shared by analytics and every report subcommand so `--sort clicks` works
// everywhere, not just in analytics.
var metricAliases = map[string]string{
	"clicks":      "total_clicks",
	"conversions": "total_leads",
	"leads":       "total_leads",
	"revenue":     "total_income",
	"income":      "total_income",
	"profit":      "total_net",
	"net":         "total_net",
	"cost":        "total_cost",
	"roi":         "roi",
	"epc":         "epc",
	"conv_rate":   "conv_rate",
	"cpa":         "cpa",
	"avg_cpc":     "avg_cpc",
}

// resolveMetric normalizes a user-supplied sort/metric name to the API column.
func resolveMetric(m string) string {
	m = strings.ToLower(strings.TrimSpace(m))
	if mapped, ok := metricAliases[m]; ok {
		return mapped
	}
	return m
}

// applyReportSort reads --sort (honoring metric aliases) and the sort direction
// (--sort_dir or its --sort-dir alias) into params, so every report subcommand
// accepts the same friendly names regardless of casing.
func applyReportSort(cmd *cobra.Command, params map[string]string) {
	sort := getStringFlagOrDefault(cmd, "report", "sort")
	if sort != "" {
		params["sort"] = resolveMetric(sort)
	}
	dir := getStringFlagOrDefault(cmd, "report", "sort_dir")
	if dir == "" {
		dir, _ = cmd.Flags().GetString("sort-dir")
	}
	if dir != "" {
		params["sort_dir"] = dir
	}
}

// addSortFlags registers --sort / --sort_dir and the --sort-dir alias on a
// report subcommand so the sort vocabulary is identical everywhere.
func addSortFlags(cmd *cobra.Command, sortHelp string) {
	cmd.Flags().StringP("sort", "s", "", sortHelp)
	cmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC")
	cmd.Flags().String("sort-dir", "", "Alias for --sort_dir")
}
