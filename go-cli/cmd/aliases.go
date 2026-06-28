package cmd

import (
	"encoding/json"
	"strconv"
	"strings"

	"github.com/spf13/cobra"
)

// rejectMultiProfile errors when --group/--profiles is set on a command that
// does not fan out across profiles, instead of silently returning one profile's
// data (which would be wrong numbers in a multi-profile/client report).
func rejectMultiProfile(cmd *cobra.Command) error {
	if groupName != "" {
		return validationError("--group is not supported here (only `report summary` aggregates across profiles); run per-profile with --profile, or use `exec`")
	}
	if cmd.Flags().Lookup("profiles") != nil {
		if v, _ := cmd.Flags().GetString("profiles"); v != "" {
			return validationError("--profiles is not supported by this command; use `report summary` or `exec`")
		}
	}
	return nil
}

// applyBreakdownFilters post-filters breakdown rows client-side by --min-clicks,
// --min-cost, --zero-leads, and --having (FIELD OP VALUE). Returns the original
// bytes unchanged when no filter is set or the payload can't be parsed.
func applyBreakdownFilters(cmd *cobra.Command, data []byte) []byte {
	minClicks, _ := cmd.Flags().GetFloat64("min-clicks")
	minCost, _ := cmd.Flags().GetFloat64("min-cost")
	zeroLeads, _ := cmd.Flags().GetBool("zero-leads")
	having, _ := cmd.Flags().GetString("having")
	if minClicks == 0 && minCost == 0 && !zeroLeads && strings.TrimSpace(having) == "" {
		return data
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if json.Unmarshal(data, &resp) != nil {
		return data
	}
	field, op, want, hasHaving := parseHaving(having)

	out := make([]map[string]interface{}, 0, len(resp.Data))
	for _, r := range resp.Data {
		if minClicks > 0 && toFloat(r["total_clicks"]) < minClicks {
			continue
		}
		if minCost > 0 && toFloat(r["total_cost"]) < minCost {
			continue
		}
		if zeroLeads && !(toFloat(r["total_cost"]) > 0 && toFloat(r["total_leads"]) == 0) {
			continue
		}
		if hasHaving && !compareNum(toFloat(r[field]), op, want) {
			continue
		}
		out = append(out, r)
	}
	b, _ := json.Marshal(map[string]interface{}{"data": out})
	return b
}

func parseHaving(s string) (field, op string, value float64, ok bool) {
	s = strings.TrimSpace(s)
	if s == "" {
		return "", "", 0, false
	}
	for _, o := range []string{">=", "<=", "!=", "=", ">", "<"} {
		if i := strings.Index(s, o); i > 0 {
			field = strings.TrimSpace(s[:i])
			v, err := strconv.ParseFloat(strings.TrimSpace(s[i+len(o):]), 64)
			if err != nil {
				return "", "", 0, false
			}
			return resolveMetric(field), o, v, true
		}
	}
	return "", "", 0, false
}

func compareNum(a float64, op string, b float64) bool {
	switch op {
	case ">":
		return a > b
	case "<":
		return a < b
	case ">=":
		return a >= b
	case "<=":
		return a <= b
	case "!=":
		return a != b
	default: // "="
		return a == b
	}
}

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
	// --sort_dir and --sort-dir resolve to the same flag via the global name
	// normalizer (- and _ are interchangeable everywhere).
	dir := getStringFlagOrDefault(cmd, "report", "sort_dir")
	if dir != "" {
		params["sort_dir"] = dir
	}
}

// addSortFlags registers --sort and --sort_dir on a report subcommand. The
// global flag normalizer makes --sort-dir an accepted spelling automatically.
func addSortFlags(cmd *cobra.Command, sortHelp string) {
	cmd.Flags().StringP("sort", "s", "", sortHelp)
	cmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC (also --sort-dir)")
}
