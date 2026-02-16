package cmd

import (
	"encoding/json"
	"fmt"
	"p202/internal/api"
	"strconv"

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
		flags := []string{"time_from", "time_to",
			"aff_campaign_id", "ppc_account_id", "landing_page_id",
			"click_lead", "click_bot"}
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			rows, err := fetchAllRowsWithParams(c, "clicks", params)
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
		if pageStr, _ := cmd.Flags().GetString("page"); pageStr != "" {
			page, err := strconv.Atoi(pageStr)
			if err != nil || page <= 0 {
				return fmt.Errorf("--page must be a positive integer")
			}
			if _, hasOffset := params["offset"]; !hasOffset {
				limit := 50
				if limitStr, hasLimit := params["limit"]; hasLimit {
					parsedLimit, err := strconv.Atoi(limitStr)
					if err != nil || parsedLimit <= 0 {
						return fmt.Errorf("--limit must be a positive integer when --page is used")
					}
					limit = parsedLimit
				}
				params["offset"] = strconv.Itoa((page - 1) * limit)
			}
		}
		data, err := c.Get("clicks", params)
		if err != nil {
			return err
		}
		render(data)
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
		render(data)
		return nil
	},
}

func init() {
	clickListCmd.Flags().StringP("limit", "l", "", "Max results")
	clickListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	clickListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	clickListCmd.Flags().String("page", "", "Page number (maps to offset)")
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
