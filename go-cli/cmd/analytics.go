package cmd

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var analyticsGroupByAliases = map[string]string{
	"lp": "landing_page",
}

var analyticsAllowedGroupBy = map[string]bool{
	"campaign":     true,
	"aff_network":  true,
	"ppc_account":  true,
	"ppc_network":  true,
	"landing_page": true,
	"keyword":      true,
	"country":      true,
	"city":         true,
	"browser":      true,
	"platform":     true,
	"device":       true,
	"isp":          true,
	"text_ad":      true,
}

var analyticsSortAliases = map[string]string{
	"clicks":      "total_clicks",
	"conversions": "total_leads",
	"revenue":     "total_income",
	"profit":      "total_net",
	"roi":         "roi",
	"epc":         "epc",
	"conv_rate":   "conv_rate",
	"cost":        "total_cost",
}

var analyticsAllowedSort = map[string]bool{
	"total_clicks": true,
	"total_leads":  true,
	"total_income": true,
	"total_cost":   true,
	"total_net":    true,
	"roi":          true,
	"epc":          true,
	"conv_rate":    true,
}

var analyticsCmd = &cobra.Command{
	Use:   "analytics",
	Short: "Query performance stats grouped by campaign, traffic source, country, etc. (shorthand for report breakdown)",
	RunE: func(cmd *cobra.Command, args []string) error {
		if !envFlagEnabled("CLI_ENABLE_ANALYTICS_SHORTHAND", true) {
			return fmt.Errorf("analytics shorthand is disabled (set CLI_ENABLE_ANALYTICS_SHORTHAND=1 to enable)")
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		groupBy, _ := cmd.Flags().GetString("group-by")
		groupBy = strings.ToLower(strings.TrimSpace(groupBy))
		if groupBy == "" {
			return validationError("--group-by is required")
		}
		if mapped, ok := analyticsGroupByAliases[groupBy]; ok {
			groupBy = mapped
		}
		if !analyticsAllowedGroupBy[groupBy] {
			return validationError("unsupported --group-by value %q", groupBy)
		}

		params := map[string]string{
			"breakdown": groupBy,
		}

		for _, filter := range []string{"aff_campaign_id", "ppc_account_id", "aff_network_id", "ppc_network_id", "landing_page_id", "country_id"} {
			if v, _ := cmd.Flags().GetString(filter); v != "" {
				params[filter] = v
			}
		}

		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			params["limit"] = v
		}
		if v, _ := cmd.Flags().GetString("offset"); v != "" {
			params["offset"] = v
		}

		period, _ := cmd.Flags().GetString("period")
		period = strings.TrimSpace(period)
		days, _ := cmd.Flags().GetInt("days")
		if days < 0 {
			return validationError("--days must be 0 or greater")
		}
		timeFrom, _ := cmd.Flags().GetString("time_from")
		timeTo, _ := cmd.Flags().GetString("time_to")

		if period != "" {
			params["period"] = period
		} else {
			if timeFrom != "" {
				params["time_from"] = timeFrom
			}
			if timeTo != "" {
				params["time_to"] = timeTo
			}
			if days > 0 && timeFrom == "" && timeTo == "" {
				now := time.Now().Unix()
				params["time_to"] = strconv.FormatInt(now, 10)
				params["time_from"] = strconv.FormatInt(now-int64(days*86400), 10)
			}
		}

		sortDir, _ := cmd.Flags().GetString("sort-dir")
		sortDir = strings.ToUpper(strings.TrimSpace(sortDir))
		if sortDir != "" && sortDir != "ASC" && sortDir != "DESC" {
			return validationError("--sort-dir must be ASC or DESC")
		}

		sortBy, _ := cmd.Flags().GetString("sort")
		sortBy = strings.ToLower(strings.TrimSpace(sortBy))
		if sortBy != "" {
			if mapped, ok := analyticsSortAliases[sortBy]; ok {
				sortBy = mapped
			}
			if !analyticsAllowedSort[sortBy] {
				return validationError("unsupported --sort value %q", sortBy)
			}
			params["sort"] = sortBy
			if sortDir == "" {
				sortDir = "DESC"
			}
			params["sort_dir"] = sortDir
		}

		data, err := c.Get("reports/breakdown", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	analyticsCmd.Flags().String("group-by", "", "Breakdown dimension (campaign, country, lp, etc.)")
	analyticsCmd.Flags().Int("days", 0, "Relative window in days (ignored when --period is provided)")
	analyticsCmd.Flags().String("period", "", "Period: today, yesterday, last7, last30, last90")
	analyticsCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	analyticsCmd.Flags().String("time_to", "", "End timestamp (unix)")
	analyticsCmd.Flags().String("sort", "", "Sort alias (clicks, conversions, revenue, profit, roi, epc, conv_rate, cost)")
	analyticsCmd.Flags().String("sort-dir", "", "Sort direction: ASC or DESC")
	analyticsCmd.Flags().StringP("limit", "l", "", "Max results")
	analyticsCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	analyticsCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	analyticsCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	analyticsCmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	analyticsCmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	analyticsCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	analyticsCmd.Flags().String("country_id", "", "Filter by country ID")

	rootCmd.AddCommand(analyticsCmd)
}
