package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var dashboardCmd = &cobra.Command{
	Use:   "dashboard",
	Short: "Get dashboard overview — total clicks, conversions, revenue, cost, profit, and ROI for a period",
	Long: "Get a dashboard overview with aggregate totals for a time period.\n\n" +
		"Supports multi-profile mode via --profiles, --all-profiles, or --group to aggregate\n" +
		"across multiple Prosper202 instances.",
	Example: "  p202 dashboard --period today --json\n" +
		"  p202 dashboard --period last7 --json\n" +
		"  p202 dashboard --period last30 --all-profiles --json\n" +
		"  p202 dashboard --period today --group production --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		profiles, err := resolveMultiProfiles(cmd)
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		if _, exists := params["period"]; !exists {
			params["period"] = "today"
		}
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

func init() {
	dashboardCmd.Flags().StringP("period", "p", "", "Period: today, yesterday, last7, last30, last90")
	dashboardCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	dashboardCmd.Flags().String("time_to", "", "End timestamp (unix)")
	dashboardCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	dashboardCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	dashboardCmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	dashboardCmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	dashboardCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	dashboardCmd.Flags().String("country_id", "", "Filter by country ID")
	addMultiProfileFlags(dashboardCmd)

	rootCmd.AddCommand(dashboardCmd)

	registerMeta("dashboard", commandMeta{
		Examples:     []string{"p202 dashboard --period today --json", "p202 dashboard --period last7 --all-profiles --json"},
		OutputFields: []string{"total_clicks", "total_leads", "total_income", "total_cost", "total_net", "roi", "epc", "conv_rate"},
		Related:      []string{"report summary", "report breakdown"},
	})
}
