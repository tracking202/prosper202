package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var dashboardCmd = &cobra.Command{
	Use:   "dashboard",
	Short: "Get dashboard summary metrics",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		data, err := c.Get("reports/summary", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	dashboardCmd.Flags().StringP("period", "p", "today", "Period: today, yesterday, last7, last30, last90")
	dashboardCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	dashboardCmd.Flags().String("time_to", "", "End timestamp (unix)")
	dashboardCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	dashboardCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	dashboardCmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	dashboardCmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	dashboardCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	dashboardCmd.Flags().String("country_id", "", "Filter by country ID")

	rootCmd.AddCommand(dashboardCmd)
}
