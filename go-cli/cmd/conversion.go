package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var conversionCmd = &cobra.Command{
	Use:   "conversion",
	Short: "Manage conversions (revenue events recorded via postback or pixel)",
	Long: "Manage conversions — revenue events recorded via postback or pixel.\n\n" +
		"Subcommands: list, get, create, delete.\n" +
		"Conversions are typically created automatically via postback, but can be logged manually.",
}

var conversionListCmd = &cobra.Command{
	Use:   "list",
	Short: "List conversions",
	Long: "List conversions with optional filters by campaign, time range, or payout.\n\n" +
		"Use --all to fetch every conversion across all pages.",
	Example: "  p202 conversion list --period today --json\n" +
		"  p202 conversion list --aff_campaign_id 5 --period last7 --json\n" +
		"  p202 conversion list --all --period last30 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		flags := []string{"campaign_id", "time_from", "time_to"}
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			rows, err := fetchAllRowsWithParams(c, "conversions", params)
			if err != nil {
				return err
			}
			encoded, _ := json.Marshal(map[string]interface{}{
				"data": rows,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  len(rows),
					"offset": 0,
				},
			})
			render(encoded)
			return nil
		}

		for _, f := range []string{"limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("conversions", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var conversionGetCmd = &cobra.Command{
	Use:     "get <id>",
	Short:   "Get a conversion by ID",
	Long:    "Retrieve full details of a single conversion by its numeric ID.",
	Example: "  p202 conversion get 456 --json",
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
		render(data)
		return nil
	},
}

var conversionCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a conversion",
	Long: "Manually log a conversion for a specific click.\n\n" +
		"Required: --click_id. Optionally override the payout amount and provide a transaction ID for dedup.",
	Example: "  p202 conversion create --click_id 12345 --json\n" +
		"  p202 conversion create --click_id 12345 --payout 4.50 --transaction_id TXN-001 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		clickIDStr, _ := cmd.Flags().GetString("click_id")
		if clickIDStr == "" {
			clickIDStr, _ = cmd.Flags().GetString("click_id_public")
		}
		if clickIDStr == "" {
			return fmt.Errorf("required flag --click_id (or --click_id_public) is missing")
		}
		clickID, err := strconv.Atoi(clickIDStr)
		if err != nil {
			return fmt.Errorf("--click_id must be an integer: %s", clickIDStr)
		}
		body := map[string]interface{}{
			"click_id": clickID,
		}
		if v, _ := cmd.Flags().GetString("payout"); v != "" {
			body["payout"] = v
		} else if v, _ := cmd.Flags().GetString("conversion_payout"); v != "" {
			body["payout"] = v
		}
		if v, _ := cmd.Flags().GetString("transaction_id"); v != "" {
			body["transaction_id"] = v
		}
		data, err := c.Post("conversions", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var conversionDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a conversion",
	Long: "Delete a single conversion by ID, or bulk-delete with --ids.\n\n" +
		"Prompts for confirmation unless --force is passed.",
	Example: "  p202 conversion delete 456 --force\n" +
		"  p202 conversion delete --ids 1,2,3 --force",
	Args: func(cmd *cobra.Command, args []string) error {
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			return cobra.MaximumNArgs(0)(cmd, args)
		}
		return cobra.ExactArgs(1)(cmd, args)
	},
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			idList, parseErr := parseIDList(idsFlag)
			if parseErr != nil {
				return parseErr
			}
			if len(idList) == 0 {
				return fmt.Errorf("--ids requires at least one ID")
			}

			force, _ := cmd.Flags().GetBool("force")
			if !force {
				fmt.Printf("Delete %d conversions? [y/N] ", len(idList))
				var answer string
				fmt.Scanln(&answer)
				answer = strings.ToLower(strings.TrimSpace(answer))
				if answer != "y" && answer != "yes" {
					fmt.Println("Cancelled.")
					return nil
				}
			}

			deleted := 0
			failed := 0
			for _, id := range idList {
				if err := c.Delete("conversions/" + id); err != nil {
					failed++
					fmt.Fprintf(os.Stderr, "Failed to delete conversion %s: %v\n", id, err)
					continue
				}
				deleted++
			}
			output.Success("Deleted %d of %d conversions.", deleted, len(idList))
			if failed > 0 {
				return partialFailureError("failed to delete %d conversions", failed)
			}
			return nil
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
	conversionListCmd.Flags().StringP("limit", "l", "", "Max results")
	conversionListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	conversionListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	conversionListCmd.Flags().String("campaign_id", "", "Filter by campaign ID")
	conversionListCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	conversionListCmd.Flags().String("time_to", "", "End timestamp (unix)")

	conversionCreateCmd.Flags().String("click_id", "", "Click ID (required)")
	conversionCreateCmd.Flags().String("click_id_public", "", "Legacy alias for --click_id")
	conversionCreateCmd.Flags().String("payout", "", "Payout amount")
	conversionCreateCmd.Flags().String("conversion_payout", "", "Legacy alias for --payout")
	conversionCreateCmd.Flags().String("transaction_id", "", "Transaction ID for deduplication")

	conversionDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	conversionDeleteCmd.Flags().String("ids", "", "Comma-separated conversion IDs to delete in bulk")

	conversionCmd.AddCommand(conversionListCmd, conversionGetCmd, conversionCreateCmd, conversionDeleteCmd)
	rootCmd.AddCommand(conversionCmd)

	convFields := []string{"conversion_id", "click_id", "payout", "transaction_id", "conversion_time", "aff_campaign_id"}
	registerMeta("conversion list", commandMeta{
		Examples:     []string{"p202 conversion list --period today --json", "p202 conversion list --aff_campaign_id 5 --period last7 --json"},
		OutputFields: convFields,
		Related:      []string{"conversion get", "conversion create", "click list"},
	})
	registerMeta("conversion get", commandMeta{
		Examples:     []string{"p202 conversion get 456 --json"},
		OutputFields: convFields,
		Related:      []string{"conversion list", "click get"},
	})
	registerMeta("conversion create", commandMeta{
		Examples:     []string{"p202 conversion create --click_id 12345 --json", "p202 conversion create --click_id 12345 --payout 4.50 --transaction_id TXN-001 --json"},
		OutputFields: convFields,
		Related:      []string{"click list", "conversion list"},
		Mutating:     true,
	})
	registerMeta("conversion delete", commandMeta{
		Examples:     []string{"p202 conversion delete 456 --force", "p202 conversion delete --ids 1,2,3 --force"},
		OutputFields: []string{},
		Related:      []string{"conversion list"},
		Mutating:     true,
	})
}
