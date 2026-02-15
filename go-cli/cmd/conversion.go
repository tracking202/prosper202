package cmd

import (
	"fmt"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var conversionCmd = &cobra.Command{
	Use:   "conversion",
	Short: "Manage conversions",
}

var conversionListCmd = &cobra.Command{
	Use:   "list",
	Short: "List conversions",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		flags := []string{"limit", "offset", "campaign_id", "time_from", "time_to"}
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("conversions", params)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var conversionGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a conversion by ID",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/"+args[0], nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var conversionCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a conversion",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		clickID, _ := cmd.Flags().GetString("click_id")
		if clickID == "" {
			return fmt.Errorf("required flag --click_id is missing")
		}
		body := map[string]interface{}{
			"click_id": clickID,
		}
		if v, _ := cmd.Flags().GetString("payout"); v != "" {
			body["payout"] = v
		}
		if v, _ := cmd.Flags().GetString("transaction_id"); v != "" {
			body["transaction_id"] = v
		}
		data, err := c.Post("conversions", body)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var conversionDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a conversion",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete conversion %s? [y/N] ", args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("conversions/" + args[0]); err != nil {
			return err
		}
		output.Success("Conversion %s deleted.", args[0])
		return nil
	},
}

func init() {
	conversionListCmd.Flags().StringP("limit", "l", "50", "Max results")
	conversionListCmd.Flags().StringP("offset", "o", "0", "Pagination offset")
	conversionListCmd.Flags().String("campaign_id", "", "Filter by campaign ID")
	conversionListCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	conversionListCmd.Flags().String("time_to", "", "End timestamp (unix)")

	conversionCreateCmd.Flags().String("click_id", "", "Click ID (required)")
	conversionCreateCmd.Flags().String("payout", "", "Payout amount")
	conversionCreateCmd.Flags().String("transaction_id", "", "Transaction ID for deduplication")

	conversionDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	conversionCmd.AddCommand(conversionListCmd, conversionGetCmd, conversionCreateCmd, conversionDeleteCmd)
	rootCmd.AddCommand(conversionCmd)
}
