package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var reportCmd = &cobra.Command{
	Use:   "report",
	Short: "Generate performance reports — summary, breakdown by dimension, time series, and day/week parting",
	Long: "Generate performance reports for Prosper202 tracking data.\n\n" +
		"Subcommands: summary, breakdown, timeseries, daypart, weekpart.\n" +
		"All support --json output and common filters: --period, --time_from, --time_to,\n" +
		"--aff_campaign_id, --ppc_account_id, --aff_network_id, etc.\n\n" +
		"Period values: today, yesterday, last7, last30, last90.\n" +
		"Sort values: total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate.",
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
	cmd.Flags().StringP("period", "p", "", "Period: today, yesterday, last7, last30, last90")
	cmd.Flags().String("time_from", "", "Start timestamp (unix)")
	cmd.Flags().String("time_to", "", "End timestamp (unix)")
	cmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	cmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	cmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	cmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	cmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	cmd.Flags().String("country_id", "", "Filter by country ID")
}

var reportSummaryCmd = &cobra.Command{
	Use:   "summary",
	Short: "Get aggregate totals — clicks, conversions, revenue, cost, profit, ROI for a period",
	Long: "Get aggregate totals across all campaigns for a time period.\n\n" +
		"Returns: total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate.\n" +
		"Supports multi-profile aggregation via --profiles or --all-profiles.",
	Example: "  p202 report summary --period last7 --json\n" +
		"  p202 report summary --time_from 1704067200 --time_to 1704153600 --json\n" +
		"  p202 report summary --period today --aff_campaign_id 5 --json\n" +
		"  p202 report summary --period last7 --all-profiles --json",
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
			payload := buildMultiProfilePayload(profileData, aggregateNumericFields(profileData), errorsOut)
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
	Long: "Get performance stats broken down by a dimension.\n\n" +
		"Breakdown values: campaign, aff_network, ppc_account, ppc_network, landing_page,\n" +
		"keyword, country, city, browser, platform, device, isp, text_ad.\n\n" +
		"Each row includes: the dimension value plus total_clicks, total_leads, total_income,\n" +
		"total_cost, total_net, roi, epc, conv_rate.",
	Example: "  p202 report breakdown --breakdown campaign --period last7 --json\n" +
		"  p202 report breakdown --breakdown country --sort total_net --sort_dir DESC --limit 20 --json\n" +
		"  p202 report breakdown --breakdown landing_page --aff_campaign_id 5 --period last30 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		for _, f := range []string{"breakdown", "sort", "sort_dir", "limit", "offset"} {
			if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("reports/breakdown", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var reportTimeseriesCmd = &cobra.Command{
	Use:   "timeseries",
	Short: "Get stats over time — daily/hourly buckets of clicks, conversions, revenue, etc.",
	Long: "Get stats over time in daily or hourly buckets.\n\n" +
		"Use --interval to set granularity (day or hour). Each bucket includes\n" +
		"total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate.",
	Example: "  p202 report timeseries --period last7 --json\n" +
		"  p202 report timeseries --period last30 --interval hour --aff_campaign_id 5 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
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
		return nil
	},
}

var reportWeekpartCmd = &cobra.Command{
	Use:   "weekpart",
	Short: "Get stats grouped by day of week (Mon-Sun) to find best-performing days",
	Long: "Get stats grouped by day of week to identify which days perform best.\n\n" +
		"Returns 7 rows (Monday through Sunday), each with total_clicks, total_leads,\n" +
		"total_income, total_cost, total_net, roi, epc, conv_rate.",
	Example: "  p202 report weekpart --period last30 --json\n" +
		"  p202 report weekpart --period last90 --aff_campaign_id 5 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		for _, f := range []string{"sort", "sort_dir"} {
			if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
				params[f] = v
			}
		}
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
	Long: "Get stats grouped by hour of day to identify which hours perform best.\n\n" +
		"Returns 24 rows (0-23), each with total_clicks, total_leads, total_income,\n" +
		"total_cost, total_net, roi, epc, conv_rate.",
	Example: "  p202 report daypart --period last30 --json\n" +
		"  p202 report daypart --period last90 --aff_campaign_id 5 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		for _, f := range []string{"sort", "sort_dir"} {
			if v := getStringFlagOrDefault(cmd, "report", f); v != "" {
				params[f] = v
			}
		}
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
	reportBreakdownCmd.Flags().StringP("breakdown", "b", "", "Dimension: campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, browser, platform, device, isp, text_ad")
	reportBreakdownCmd.Flags().StringP("sort", "s", "", "Sort by: total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate")
	reportBreakdownCmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC")
	reportBreakdownCmd.Flags().StringP("limit", "l", "", "Max results")
	reportBreakdownCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	addReportFilters(reportTimeseriesCmd)
	reportTimeseriesCmd.Flags().StringP("interval", "i", "", "Interval: hour, day, week, month")

	addReportFilters(reportDaypartCmd)
	reportDaypartCmd.Flags().StringP("sort", "s", "", "Sort by: hour_of_day, total_clicks, total_click_throughs, total_leads, total_income, total_cost, total_net, epc, avg_cpc, conv_rate, roi, cpa")
	reportDaypartCmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC")

	addReportFilters(reportWeekpartCmd)
	reportWeekpartCmd.Flags().StringP("sort", "s", "", "Sort by: day_of_week, total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate")
	reportWeekpartCmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC")

	reportCmd.AddCommand(reportSummaryCmd, reportBreakdownCmd, reportTimeseriesCmd, reportDaypartCmd, reportWeekpartCmd)
	rootCmd.AddCommand(reportCmd)

	reportFields := []string{"total_clicks", "total_leads", "total_income", "total_cost", "total_net", "roi", "epc", "conv_rate"}
	periodValues := []string{"today", "yesterday", "last7", "last30", "last90"}
	breakdownValues := []string{"campaign", "aff_network", "ppc_account", "ppc_network", "landing_page", "keyword", "country", "city", "browser", "platform", "device", "isp", "text_ad"}
	sortDirValues := []string{"ASC", "DESC"}

	registerMeta("report summary", commandMeta{
		Examples:     []string{"p202 report summary --period last7 --json", "p202 report summary --period today --aff_campaign_id 5 --json", "p202 report summary --period last7 --all-profiles --json"},
		OutputFields: reportFields,
		Related:      []string{"report breakdown", "report timeseries", "dashboard"},
		AllowedValues: map[string][]string{
			"period": periodValues,
		},
	})
	registerMeta("report breakdown", commandMeta{
		Examples:     []string{"p202 report breakdown --breakdown campaign --period last7 --json", "p202 report breakdown --breakdown country --sort total_net --sort_dir DESC --limit 20 --json"},
		OutputFields: append([]string{"dimension"}, reportFields...),
		Related:      []string{"report summary", "report timeseries", "analytics"},
		AllowedValues: map[string][]string{
			"breakdown": breakdownValues,
			"sort":      reportFields,
			"sort_dir":  sortDirValues,
			"period":    periodValues,
		},
	})
	registerMeta("report timeseries", commandMeta{
		Examples:     []string{"p202 report timeseries --period last7 --json", "p202 report timeseries --period last30 --interval hour --aff_campaign_id 5 --json"},
		OutputFields: append([]string{"date", "hour"}, reportFields...),
		Related:      []string{"report summary", "report daypart"},
		AllowedValues: map[string][]string{
			"interval": {"hour", "day", "week", "month"},
			"period":   periodValues,
		},
	})
	registerMeta("report daypart", commandMeta{
		Examples:     []string{"p202 report daypart --period last30 --json", "p202 report daypart --period last90 --aff_campaign_id 5 --json"},
		OutputFields: append([]string{"hour_of_day"}, reportFields...),
		Related:      []string{"report weekpart", "report timeseries"},
		AllowedValues: map[string][]string{
			"sort":     append([]string{"hour_of_day"}, reportFields...),
			"sort_dir": sortDirValues,
			"period":   periodValues,
		},
	})
	registerMeta("report weekpart", commandMeta{
		Examples:     []string{"p202 report weekpart --period last30 --json", "p202 report weekpart --period last90 --aff_campaign_id 5 --json"},
		OutputFields: append([]string{"day_of_week"}, reportFields...),
		Related:      []string{"report daypart", "report timeseries"},
		AllowedValues: map[string][]string{
			"sort":     append([]string{"day_of_week"}, reportFields...),
			"sort_dir": sortDirValues,
			"period":   periodValues,
		},
	})
}
