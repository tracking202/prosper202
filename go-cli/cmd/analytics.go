package cmd

import (
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var analyticsSortAliases = map[string]string{
	"clicks":      "total_clicks",
	"conversions": "total_leads",
	"revenue":     "total_income",
	"profit":      "total_net",
	"roi":         "roi",
	"epc":         "epc",
	"conv_rate":   "conv_rate",
	"cost":        "total_cost",
}

// reportPeriods are the named windows the server knows (TimeBound::PERIODS),
// in the report pages' order; it refuses any other value with a 422. The
// calendar ones (today, yesterday, this/last month, this/last year) start at a
// midnight in the account's timezone, and so do lastN's: today and the N
// whole days before it, as the report pages count them.
var reportPeriods = []string{"today", "yesterday", "last7", "last14", "last30", "last90", "thismonth", "lastmonth", "thisyear", "lastyear", "alltime"}

// applyReportWindow maps --period, --days and --time_from/--time_to onto report
// params: --period wins, and --days applies only without an explicit range.
func applyReportWindow(params map[string]string, period string, days int, timeFrom, timeTo string) error {
	if days < 0 {
		return validationError("--days must be 0 or greater").WithHint("Pass a number of days, e.g. --days 30, or use --period (%s).", strings.Join(reportPeriods, ", "))
	}
	if period != "" {
		params["period"] = period
		return nil
	}
	if timeFrom != "" {
		params["time_from"] = timeFrom
	}
	if timeTo != "" {
		params["time_to"] = timeTo
	}
	if days > 0 && timeFrom == "" && timeTo == "" {
		now := reportNow().Unix()
		params["time_to"] = strconv.FormatInt(now, 10)
		params["time_from"] = strconv.FormatInt(now-int64(days*86400), 10)
	}
	return nil
}

var analyticsCmd = &cobra.Command{
	Use:   "analytics",
	Short: "Query performance stats grouped by campaign, traffic source, country, etc. (shorthand for report breakdown)",
	Long: "Stats grouped by one dimension: the shorthand for `p202 report breakdown`.\n\n" +
		"With --split-at, compare before and after a date: each value's totals on both\n" +
		"sides, the change, and the daily rate on each side, so two windows of different\n" +
		"lengths compare fairly.",
	Example: "  p202 analytics --group-by country --period last30\n" +
		"  p202 analytics --group-by country --split-at 2026-09-04",
	RunE: func(cmd *cobra.Command, args []string) error {
		if !envFlagEnabled("CLI_ENABLE_ANALYTICS_SHORTHAND", true) {
			return validationError("analytics shorthand is disabled").WithHint("Set CLI_ENABLE_ANALYTICS_SHORTHAND=1 in the environment, or use `p202 report breakdown` directly.")
		}

		// The value was checked against its list in PersistentPreRunE.
		groupBy := enumValue(cmd, "group-by")
		if groupBy == "" {
			return validationError("--group-by is required; one of: %s", dimensionEnum(breakdownDimensions).describe()).WithHint("Example: --group-by country.")
		}
		// --split-at is validated before the client exists (nil without it).
		split, err := splitPlanFromFlags(cmd)
		if err != nil {
			return err
		}

		if sortBy, _ := cmd.Flags().GetString("sort"); split == nil && strings.HasSuffix(strings.ToLower(strings.TrimSpace(sortBy)), "_per_day") {
			return validationError("--sort %s ranks --split-at output; it needs --split-at", sortBy).
				WithHint("Add --split-at <YYYY-MM-DD>, or sort plain analytics by one of: %s.", strings.Join(breakdownSorts, ", "))
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		if split != nil {
			return runAnalyticsSplit(cmd, c, groupBy, split)
		}

		params := map[string]string{
			"breakdown": groupBy,
		}

		for _, filter := range analyticsFilterFlags {
			if v, _ := cmd.Flags().GetString(filter); v != "" {
				params[filter] = v
			}
		}

		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			params["limit"] = v
		}
		if v, _ := cmd.Flags().GetString("offset"); v != "" {
			params["offset"] = v
		}

		period, _ := cmd.Flags().GetString("period")
		days, _ := cmd.Flags().GetInt("days")
		timeFrom, _ := cmd.Flags().GetString("time_from")
		timeTo, _ := cmd.Flags().GetString("time_to")
		if err := applyReportWindow(params, strings.TrimSpace(period), days, timeFrom, timeTo); err != nil {
			return err
		}

		sortDir, _ := cmd.Flags().GetString("sort-dir")
		sortDir = strings.ToUpper(strings.TrimSpace(sortDir))

		sortBy, _ := cmd.Flags().GetString("sort")
		sortBy = strings.ToLower(strings.TrimSpace(sortBy))
		if sortBy != "" {
			if mapped, ok := analyticsSortAliases[sortBy]; ok {
				sortBy = mapped
			}
			params["sort"] = sortBy
			if sortDir == "" {
				sortDir = "DESC"
			}
			params["sort_dir"] = sortDir
		}

		data, err := c.Get("reports/breakdown", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	analyticsCmd.Flags().String("group-by", "", "Breakdown dimension")
	enumFlag(analyticsCmd, "group-by", dimensionEnum(breakdownDimensions))
	analyticsCmd.Flags().Int("days", 0, "Relative window: the last N×24 hours, ending now (ignored when --period is provided; --period lastN counts whole days from a midnight)")
	analyticsCmd.Flags().String("period", "", "Period")
	enumFlag(analyticsCmd, "period", newEnum(reportPeriods))
	analyticsCmd.Flags().String("time_from", "", timeFromHelp)
	analyticsCmd.Flags().String("time_to", "", timeToHelp)
	analyticsCmd.Flags().String("sort", "", "Sort by")
	// The *_per_day keys rank --split-at output only; plain analytics refuses them in RunE.
	enumFlag(analyticsCmd, "sort", newEnum(append(append([]string{}, breakdownSorts...), splitPerDaySorts...), enumAliases(analyticsSortAliases), enumFoldCase()))
	analyticsCmd.Flags().String("sort-dir", "", "Sort direction")
	enumFlag(analyticsCmd, "sort-dir", sortDirEnum())
	analyticsCmd.Flags().StringP("limit", "l", "", "Max results")
	analyticsCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	addReportFilterFlags(analyticsCmd)

	rootCmd.AddCommand(analyticsCmd)
}
