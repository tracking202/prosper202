package cmd

import (
	"encoding/json"
	"fmt"
	"math"
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
// bytes unchanged when no filter is set. A payload that can't be parsed came
// from the server (a success status with a body that isn't a breakdown), so it
// is a server error, not a flag problem.
func applyBreakdownFilters(cmd *cobra.Command, data []byte) ([]byte, error) {
	minClicks, _ := cmd.Flags().GetFloat64("min-clicks")
	minCost, _ := cmd.Flags().GetFloat64("min-cost")
	zeroLeads, _ := cmd.Flags().GetBool("zero-leads")
	having, _ := cmd.Flags().GetString("having")
	if minClicks == 0 && minCost == 0 && !zeroLeads && strings.TrimSpace(having) == "" {
		return data, nil
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return nil, &CLIError{
			Category: "server",
			ExitCode: ExitServer,
			Message:  fmt.Sprintf("applying --min-clicks/--min-cost/--zero-leads/--having: the server's breakdown response isn't valid JSON (%v)", err),
			Hint: "The flags are fine: the server (or a proxy in front of it) answered with something other than a breakdown. " +
				"Run the same command without the filter flags to see the raw response, and `p202 system health` to check the server.",
			Cause: err,
		}
	}
	field, op, want, hasHaving, err := havingFilter(having)
	if err != nil {
		return nil, err
	}

	// A masked answer's clicks, leads and money are null, and toFloat reads
	// null as 0: --min-clicks 10 answered {"data": []} and --having
	// total_leads=0 every row, exit 0, as if they had been read. A filter on
	// a hidden figure is refused as `report losers` refuses one; a filter on
	// a ratio the role does see still runs.
	masked := maskedFigures(data)
	if masked {
		var hidden []string
		if minClicks > 0 {
			hidden = append(hidden, "--min-clicks")
		}
		if minCost > 0 {
			hidden = append(hidden, "--min-cost")
		}
		if zeroLeads {
			hidden = append(hidden, "--zero-leads")
		}
		if hasHaving && containsString(maskedReportFigures, field) {
			hidden = append(hidden, "--having "+strings.TrimSpace(having))
		}
		if len(hidden) > 0 {
			refusal := errMaskedFigures("report breakdown " + strings.Join(hidden, " "))
			refusal.Hint = "Drop " + strings.Join(hidden, " and ") + " to see every row with the ratios this role does see, " +
				"or filter on one of those: --having 'roi<0' (epc, avg_cpc, conv_rate, roi, cpa). " + refusal.Hint
			return nil, refusal
		}
	}

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
	filtered := map[string]interface{}{"data": out}
	// The rows kept still hold nulls for hidden figures: the flag goes with
	// them, or --ndjson rows and the table's note lose it.
	if masked {
		filtered["masked"] = true
	}
	b, err := json.Marshal(filtered)
	if err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	return b, nil
}

// havingFilter reads --having, refusing one it cannot read: a filter that
// does not parse was dropped (every row answered, exit 0), and one on a field
// no breakdown row has read that field as 0 ({"data": []}, exit 0) -- each
// an answer to a question nobody asked (CLAUDE.md #4). The breakdown command
// asks it before building a client, so a bad filter costs no request.
func havingFilter(having string) (field, op string, want float64, has bool, err error) {
	if strings.TrimSpace(having) == "" {
		return "", "", 0, false, nil
	}
	field, op, want, ok := parseHaving(having)
	if !ok || math.IsNaN(want) || math.IsInf(want, 0) {
		return "", "", 0, false, validationError("--having %q is not FIELD OP NUMBER", having).
			WithHint("Write one comparison, e.g. --having 'roi<0' or --having 'total_leads=0'; OP is one of >=, <=, !=, =, >, <.")
	}
	if !containsString(metricColumns, field) {
		return "", "", 0, false, validationError("--having names %q, which no breakdown row has; valid: %s (or clicks, leads, conversions, revenue, income, cost, profit, net)",
			field, strings.Join(metricColumns, ", ")).
			WithHint("Use one of the fields above, e.g. --having 'roi<0'.")
	}
	return field, op, want, true, nil
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
	"lp":           "landing_page",
	"source":       "ppc_account",
	"network":      "aff_network",
	"offer":        "campaign",
	"geo":          "country",
	"referrer":     "referer",
	"referrer_url": "referer_url",
	"rule":         "rotator_rule",
}

// breakdownDimensions are the dimensions ReportsController::BREAKDOWNS
// knows, in its order. Servers advertise their own list as
// features.report_breakdowns in /capabilities; dimensionEnum prefers it for
// a value missing here.
var breakdownDimensions = []string{
	"campaign", "aff_network", "ppc_account", "ppc_network", "landing_page", "keyword",
	"country", "city", "region", "browser", "platform", "device", "isp", "text_ad",
	"ip", "referer", "referer_url", "device_type", "c1", "c2", "c3", "c4",
	"utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "rotator", "rotator_rule",
}

// dimensionEnum is a --breakdown/--group-by flag's accepted values.
func dimensionEnum(values []string) *enumSpec {
	return newEnum(values, enumAliases(dimensionAliases), enumFoldCase(),
		enumServerList("features", "report_breakdowns"),
		enumHint("Reports break down by these dimensions only; `p202 search <what you want to do>` finds other commands."))
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

// Sort columns each report endpoint accepts (ReportsController's
// ALLOWED_SORTS, DAYPART_ALLOWED_SORTS, WEEKPART_ALLOWED_SORTS) and the
// metric columns a breakdown row carries (METRIC_FIELDS). A breakdown sorts by
// any metric column its rows carry.
var (
	metricColumns  = []string{"total_clicks", "total_click_throughs", "total_leads", "total_income", "total_cost", "total_net", "epc", "avg_cpc", "conv_rate", "roi", "cpa"}
	breakdownSorts = metricColumns
	daypartSorts   = append([]string{"hour_of_day"}, metricColumns...)
	weekpartSorts  = append([]string{"day_of_week"}, metricColumns...)
	sortDirections = []string{"ASC", "DESC"}
)

// metricEnum is a --sort/--metric flag's accepted columns, with the
// friendly aliases (clicks, revenue, ...) that name one of them.
func metricEnum(columns []string) *enumSpec {
	return newEnum(columns, enumAliases(metricAliases), enumFoldCase())
}

func sortDirEnum() *enumSpec { return newEnum(sortDirections, enumFoldCase()) }

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

// addSortFlags registers --sort (one of columns) and --sort-dir on a report
// subcommand. The global flag normalizer makes --sort-dir an accepted
// spelling automatically.
func addSortFlags(cmd *cobra.Command, columns []string) {
	cmd.Flags().StringP("sort", "s", "", "Sort by")
	enumFlag(cmd, "sort", metricEnum(columns))
	cmd.Flags().String("sort_dir", "", "Sort direction (also --sort-dir)")
	enumFlag(cmd, "sort_dir", sortDirEnum())
}
