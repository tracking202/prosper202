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

// timeFromHelp and timeToHelp describe the forms the server reads
// (Api\V3\Support\TimeBound): a bare 2026-10-01 used to be read as 2026
// seconds, so the answer was every row.
const (
	timeFromHelp = "Start: unix seconds, a date (2026-10-01, from its first second in the account's timezone) or a time with its offset (2026-10-01T09:30:00Z)"
	timeToHelp   = "End, inclusive: unix seconds, a date (2026-10-01, through its last second in the account's timezone) or a time with its offset"
)

// reportWindowFlags are the window every report takes.
var reportWindowFlags = []string{"period", "time_from", "time_to"}

// reportFilterFlags are the filters every report endpoint takes besides the
// window (Api\V3\Support\ReportFilter), in its order: the Analyze pages'
// filters. A server refuses one it does not know with a 422 naming it.
var reportFilterFlags = []string{
	"aff_campaign_id", "aff_network_id", "ppc_account_id", "ppc_network_id", "landing_page_id", "country_id",
	"text_ad_id", "region_id", "isp_id", "browser_id", "platform_id", "device_type",
	"method_of_promotion", "show", "keyword", "ip", "referer",
}

// reportShowValues are the Analyze pages' "show" menu (ReportFilter::SHOW).
var reportShowValues = []string{"all", "real", "filtered", "filtered_bot", "leads"}

// promotionMethods are ReportFilter::METHODS_OF_PROMOTION.
var promotionMethods = []string{"directlink", "landingpage"}

// collectReportParams gathers the shared window and filter flags used across
// report subcommands; a flag the command does not have reads its configured
// default, if any.
func collectReportParams(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	for _, f := range append(append([]string{}, reportWindowFlags...), reportFilterFlags...) {
		if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
			params[f] = v
		}
	}
	return params
}

func addReportFilters(cmd *cobra.Command) {
	cmd.Flags().StringP("period", "p", "", "Period")
	enumFlag(cmd, "period", newEnum(reportPeriods))
	cmd.Flags().String("time_from", "", timeFromHelp)
	cmd.Flags().String("time_to", "", timeToHelp)
	addReportFilterFlags(cmd)
}

// addReportFilterFlags registers reportFilterFlags on a report command.
func addReportFilterFlags(cmd *cobra.Command) {
	cmd.Flags().String("aff_campaign_id", "", "Filter by INTERNAL campaign id (from `campaign list`), not the public id in tracking URLs")
	cmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	cmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	cmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID, or none for the clicks with no traffic source")
	cmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	cmd.Flags().String("country_id", "", "Filter by country ID")
	cmd.Flags().String("text_ad_id", "", "Filter by text ad ID")
	cmd.Flags().String("region_id", "", "Filter by region ID (the id of a `--breakdown region` row)")
	cmd.Flags().String("isp_id", "", "Filter by ISP/carrier ID (the id of a `--breakdown isp` row)")
	cmd.Flags().String("browser_id", "", "Filter by browser ID (the id of a `--breakdown browser` row)")
	cmd.Flags().String("platform_id", "", "Filter by platform (OS) ID (the id of a `--breakdown platform` row)")
	cmd.Flags().String("device_type", "", "Filter by device type ID: 1 Desktop, 2 Mobile, 3 Tablet, 4 Bot (the ids of `--breakdown device_type` rows)")
	cmd.Flags().String("method_of_promotion", "", "Only direct-link clicks or only landing-page clicks: {values}")
	enumFlag(cmd, "method_of_promotion", newEnum(promotionMethods))
	cmd.Flags().String("show", "", "Which clicks count (default all): {values}; real = not filtered, filtered_bot = filtered as bots, leads = converted")
	enumFlag(cmd, "show", newEnum(reportShowValues))
	cmd.Flags().String("keyword", "", "Only clicks whose keyword contains this text (case-insensitive)")
	cmd.Flags().String("ip", "", "Only clicks from this one IP address, IPv4 or IPv6")
	cmd.Flags().String("referer", "", "Only clicks whose referring URL contains this text (case-insensitive)")
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
			aggregated, err := aggregateNumericFields(profileData)
			if err != nil {
				return err
			}
			payload, err := buildMultiProfilePayload(profileData, aggregated, errorsOut)
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
		// A --having the filter cannot read is refused before any request.
		having, _ := cmd.Flags().GetString("having")
		if _, _, _, _, err := havingFilter(having); err != nil {
			return err
		}
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

	hint := "Narrow --time-from/--time-to (or use a shorter --period)"
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
	reportBreakdownCmd.Flags().StringP("breakdown", "b", "", "Dimension (rows are one per stored value; clicks without one, e.g. no referer, are in `report summary` only)")
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
	reportBreakdownCmd.Flags().String("having", "", "Post-filter rows: FIELD OP NUMBER, FIELD a metric column or its alias, OP one of >= <= != = > < (e.g. 'total_leads=0', 'roi<0')")

	addReportFilters(reportTimeseriesCmd)
	reportTimeseriesCmd.Flags().StringP("interval", "i", "", "Interval: hour, day, week, month")

	addReportFilters(reportDaypartCmd)
	addSortFlags(reportDaypartCmd, daypartSorts)

	addReportFilters(reportWeekpartCmd)
	addSortFlags(reportWeekpartCmd, weekpartSorts)

	reportCmd.AddCommand(reportSummaryCmd, reportBreakdownCmd, reportTimeseriesCmd, reportDaypartCmd, reportWeekpartCmd)
	rootCmd.AddCommand(reportCmd)
}
