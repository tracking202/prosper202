package cmd

import (
	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var reportCmd = &cobra.Command{
	Use:   "report",
	Short: "Generate reports",
}

// collectReportParams gathers the shared filter flags used across report subcommands.
func collectReportParams(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	flags := []string{"period", "time_from", "time_to",
		"aff_campaign_id", "ppc_account_id", "aff_network_id",
		"ppc_network_id", "landing_page_id", "country_id"}
	for _, f := range flags {
		if v, _ := cmd.Flags().GetString(f); v != "" {
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
	Short: "Get summary report",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("reports/summary", collectReportParams(cmd))
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var reportBreakdownCmd = &cobra.Command{
	Use:   "breakdown",
	Short: "Get breakdown report by dimension",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		for _, f := range []string{"breakdown", "sort", "sort_dir", "limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("reports/breakdown", params)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var reportTimeseriesCmd = &cobra.Command{
	Use:   "timeseries",
	Short: "Get time series report",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		if v, _ := cmd.Flags().GetString("interval"); v != "" {
			params["interval"] = v
		}
		data, err := c.Get("reports/timeseries", params)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

func init() {
	addReportFilters(reportSummaryCmd)

	addReportFilters(reportBreakdownCmd)
	reportBreakdownCmd.Flags().StringP("breakdown", "b", "", "Dimension: campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, browser, platform, device, isp, text_ad")
	reportBreakdownCmd.Flags().StringP("sort", "s", "", "Sort by: total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate")
	reportBreakdownCmd.Flags().String("sort_dir", "", "Sort direction: ASC or DESC")
	reportBreakdownCmd.Flags().StringP("limit", "l", "", "Max results")
	reportBreakdownCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	addReportFilters(reportTimeseriesCmd)
	reportTimeseriesCmd.Flags().StringP("interval", "i", "", "Interval: hour, day, week, month")

	reportCmd.AddCommand(reportSummaryCmd, reportBreakdownCmd, reportTimeseriesCmd)
	rootCmd.AddCommand(reportCmd)
}
