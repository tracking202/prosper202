package cmd

import (
	"encoding/json"
	"fmt"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var reportCmd = &cobra.Command{
	Use:   "report",
	Short: "Generate performance reports — summary, breakdown by dimension, time series, and day/week parting",
}

// collectReportParams gathers the shared filter flags used across report subcommands.
func collectReportParams(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	flags := []string{"period", "time_from", "time_to",
		"aff_campaign_id", "ppc_account_id", "aff_network_id",
		"ppc_network_id", "landing_page_id", "country_id"}
	for _, f := range flags {
		if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
			params[f] = v
		}
	}
	return params
}

func addReportFilters(cmd *cobra.Command) {
	cmd.Flags().StringP("period", "p", "", "Period")
	enumFlag(cmd, "period", newEnum(reportPeriods))
	cmd.Flags().String("time_from", "", "Start timestamp (unix)")
	cmd.Flags().String("time_to", "", "End timestamp (unix)")
	cmd.Flags().String("aff_campaign_id", "", "Filter by INTERNAL campaign id (from `campaign list`), not the public id in tracking URLs")
	cmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	cmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	cmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	cmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	cmd.Flags().String("country_id", "", "Filter by country ID")
}

var reportSummaryCmd = &cobra.Command{
	Use:   "summary",
	Short: "Get aggregate totals — clicks, conversions, revenue, cost, profit, ROI for a period",
	RunE: func(cmd *cobra.Command, args []string) error {
		profiles, err := resolveMultiProfiles(cmd)
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		if len(profiles) > 0 {
			profileData, errorsOut, err := fetchMultiProfileObjects("reports/summary", params, profiles)
			if err != nil {
				return err
			}
			payload, err := buildMultiProfilePayload(profileData, aggregateNumericFields(profileData), errorsOut)
			if err != nil {
				return err
			}
			render(payload)
			return nil
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("reports/summary", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var reportBreakdownCmd = &cobra.Command{
	Use:   "breakdown",
	Short: "Get stats broken down by a dimension (campaign, traffic source, country, landing page, etc.)",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if err := rejectMultiProfile(cmd); err != nil {
			return err
		}
		params := collectReportParams(cmd)

		// Accept the analytics shorthand's friendly flags for consistency:
		// --group-by aliases --breakdown, --sort-dir aliases --sort_dir, and
		// dimension aliases (e.g. lp -> landing_page) are honored. An explicitly
		// passed flag (canonical or alias) must win over a configured default,
		// so the aliases are read before falling back to getConfigDefault.
		breakdown, _ := cmd.Flags().GetString("breakdown")
		if breakdown == "" {
			breakdown, _ = cmd.Flags().GetString("group-by")
		}
		if breakdown == "" {
			breakdown = getConfigDefault("report", "breakdown")
		}
		breakdown = resolveDimension(breakdown)
		if breakdown != "" {
			params["breakdown"] = breakdown
		}

		// --sort-dir is accepted via the global flag normalizer (- == _).
		sortDir, _ := cmd.Flags().GetString("sort_dir")
		if sortDir == "" {
			sortDir = getConfigDefault("report", "sort_dir")
		}
		if sortDir != "" {
			params["sort_dir"] = sortDir
		}

		if v := getStringFlagOrDefault(cmd, "report", "sort"); v != "" {
			params["sort"] = resolveMetric(v)
		}
		for _, f := range []string{"limit", "offset"} {
			if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("reports/breakdown", params)
		if err != nil {
			return err
		}
		filtered, err := applyBreakdownFilters(cmd, data)
		if err != nil {
			return err
		}
		render(filtered)
		return nil
	},
}

var reportTimeseriesCmd = &cobra.Command{
	Use:   "timeseries",
	Short: "Get stats over time — daily/hourly buckets of clicks, conversions, revenue, etc.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if err := rejectMultiProfile(cmd); err != nil {
			return err
		}
		params := collectReportParams(cmd)
		if v := getStringFlagOrDefault(cmd, "report", "interval"); v != "" {
			params["interval"] = v
		}
		data, err := c.Get("reports/timeseries", params)
		if err != nil {
			return err
		}
		render(data)
		if w := timeseriesTruncationWarning(data, params["interval"]); w != "" {
			fmt.Fprintln(cmd.ErrOrStderr(), w)
		}
		return nil
	},
}

// timeseriesMaxRows is the bucket cap of servers that predate the
// `truncated` flag: from one of those, exactly this many buckets may be a cut.
const timeseriesMaxRows = 2000

// timeseriesTruncationWarning returns a stderr warning when a timeseries
// response was cut at the server's bucket cap (or, from a server too old to
// say, may have been), else "". Buckets run oldest first, so a cut drops the
// most recent ones.
func timeseriesTruncationWarning(data []byte, interval string) string {
	var resp struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
	}
	if json.Unmarshal(data, &resp) != nil || len(resp.Data) == 0 {
		return ""
	}
	limit := timeseriesMaxRows
	if resp.Limit != nil && *resp.Limit > 0 {
		limit = *resp.Limit
	}
	last := "the last bucket returned"
	if p, ok := resp.Data[len(resp.Data)-1]["period"].(string); ok && p != "" {
		last = p
	}

	var lead string
	switch {
	case resp.Truncated != nil && *resp.Truncated:
		lead = fmt.Sprintf("Warning: the series was cut at the server's %d-bucket limit; buckets after %s are missing.", limit, last)
	case resp.Truncated == nil && len(resp.Data) >= timeseriesMaxRows:
		lead = fmt.Sprintf("Warning: %d buckets is this server's limit and it does not report truncation; buckets after %s may be missing.", len(resp.Data), last)
	default:
		return ""
	}

	hint := "Narrow --time_from/--time_to (or use a shorter --period)"
	if coarser := coarserIntervals(interval); coarser != "" {
		hint += ", or use --interval " + coarser + ", which the server buckets into fewer rows"
	}
	return lead + "\nHint: " + hint + "."
}

// coarserIntervals lists the intervals above interval (the server default is
// day), joined for a hint; "" for month.
func coarserIntervals(interval string) string {
	order := []string{"hour", "day", "week", "month"}
	if interval == "" {
		interval = "day"
	}
	for i, name := range order {
		if name == interval {
			return strings.Join(order[i+1:], "|")
		}
	}
	return "week|month"
}

var reportWeekpartCmd = &cobra.Command{
	Use:   "weekpart",
	Short: "Get stats grouped by day of week (Mon-Sun) to find best-performing days",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if err := rejectMultiProfile(cmd); err != nil {
			return err
		}
		params := collectReportParams(cmd)
		applyReportSort(cmd, params)
		data, err := c.Get("reports/weekpart", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var reportDaypartCmd = &cobra.Command{
	Use:   "daypart",
	Short: "Get stats grouped by hour of day (0-23) to find best-performing hours",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if err := rejectMultiProfile(cmd); err != nil {
			return err
		}
		params := collectReportParams(cmd)
		applyReportSort(cmd, params)
		data, err := c.Get("reports/daypart", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	addReportFilters(reportSummaryCmd)
	addMultiProfileFlags(reportSummaryCmd)

	addReportFilters(reportBreakdownCmd)
	reportBreakdownCmd.Flags().StringP("breakdown", "b", "", "Dimension")
	enumFlag(reportBreakdownCmd, "breakdown", dimensionEnum(breakdownDimensions))
	reportBreakdownCmd.Flags().String("group-by", "", "Alias for --breakdown (matches `analytics --group-by`)")
	enumFlag(reportBreakdownCmd, "group-by", dimensionEnum(breakdownDimensions))
	reportBreakdownCmd.Flags().StringP("sort", "s", "", "Sort by")
	enumFlag(reportBreakdownCmd, "sort", metricEnum(breakdownSorts))
	reportBreakdownCmd.Flags().String("sort_dir", "", "Sort direction")
	enumFlag(reportBreakdownCmd, "sort_dir", sortDirEnum())
	reportBreakdownCmd.Flags().StringP("limit", "l", "", "Max results")
	reportBreakdownCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	reportBreakdownCmd.Flags().Float64("min-clicks", 0, "Only rows with at least N clicks")
	reportBreakdownCmd.Flags().Float64("min-cost", 0, "Only rows with at least $N cost")
	reportBreakdownCmd.Flags().Bool("zero-leads", false, "Only rows with cost > 0 and zero conversions (pure waste)")
	reportBreakdownCmd.Flags().String("having", "", "Post-filter rows: FIELD OP VALUE (e.g. 'total_leads=0', 'roi<0')")

	addReportFilters(reportTimeseriesCmd)
	reportTimeseriesCmd.Flags().StringP("interval", "i", "", "Interval: hour, day, week, month")

	addReportFilters(reportDaypartCmd)
	addSortFlags(reportDaypartCmd, daypartSorts)

	addReportFilters(reportWeekpartCmd)
	addSortFlags(reportWeekpartCmd, weekpartSorts)

	reportCmd.AddCommand(reportSummaryCmd, reportBreakdownCmd, reportTimeseriesCmd, reportDaypartCmd, reportWeekpartCmd)
	rootCmd.AddCommand(reportCmd)
}
