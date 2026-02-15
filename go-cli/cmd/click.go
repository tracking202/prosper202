package cmd

import (
	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var clickCmd = &cobra.Command{
	Use:   "click",
	Short: "View clicks",
}

var clickListCmd = &cobra.Command{
	Use:   "list",
	Short: "List clicks",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		flags := []string{"limit", "offset", "time_from", "time_to",
			"aff_campaign_id", "ppc_account_id", "landing_page_id",
			"click_lead", "click_bot"}
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("clicks", params)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var clickGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a click by ID",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("clicks/"+args[0], nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

func init() {
	clickListCmd.Flags().StringP("limit", "l", "", "Max results")
	clickListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	clickListCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	clickListCmd.Flags().String("time_to", "", "End timestamp (unix)")
	clickListCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	clickListCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	clickListCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	clickListCmd.Flags().String("click_lead", "", "Filter: 0=clicks only, 1=conversions only")
	clickListCmd.Flags().String("click_bot", "", "Filter: 0=human, 1=bot")

	clickCmd.AddCommand(clickListCmd, clickGetCmd)
	rootCmd.AddCommand(clickCmd)
}
