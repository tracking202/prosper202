package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var dashboardCmd = &cobra.Command{
	Use:   "dashboard",
	Short: "Get dashboard overview — total clicks, conversions, revenue, cost, profit, and ROI for a period",
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

func init() {
	dashboardCmd.Flags().StringP("period", "p", "", "Period (default today)")
	enumFlag(dashboardCmd, "period", newEnum(reportPeriods))
	dashboardCmd.Flags().String("time_from", "", timeFromHelp)
	dashboardCmd.Flags().String("time_to", "", timeToHelp)
	addReportFilterFlags(dashboardCmd)
	addMultiProfileFlags(dashboardCmd)

	rootCmd.AddCommand(dashboardCmd)
}
