package cmd

import "strings"

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
