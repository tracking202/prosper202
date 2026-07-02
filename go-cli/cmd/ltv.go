package cmd

import (
	"fmt"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var ltvCmd = &cobra.Command{
	Use:   "ltv",
	Short: "Customer lifetime value — realized LTV, per-customer detail, product/acquisition breakdowns, MRR/churn, and predictive LTV",
}

// collectLtvParams gathers the shared filter flags used across ltv subcommands.
func collectLtvParams(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	flags := []string{"period", "time_from", "time_to", "sort", "dir", "limit", "offset"}
	for _, f := range flags {
		if v := getStringFlagOrDefault(cmd, "ltv", f); v != "" {
			params[f] = v
		}
	}
	return params
}

func addLtvTimeFilters(cmd *cobra.Command) {
	cmd.Flags().StringP("period", "p", "", "Period: today, yesterday, last7, last30, last90")
	cmd.Flags().String("time_from", "", "Acquisition window start (unix timestamp)")
	cmd.Flags().String("time_to", "", "Acquisition window end (unix timestamp)")
}

var ltvSummaryCmd = &cobra.Command{
	Use:   "summary",
	Short: "Realized LTV totals — customers, revenue, avg LTV, AOV, repeat rate, MRR",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ltv/summary", collectLtvParams(cmd))
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var ltvCustomersCmd = &cobra.Command{
	Use:   "customers [id]",
	Short: "List customers with LTV rollups, or show one customer in full (CRM, aliases, custom fields, recent revenue)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if len(args) == 1 {
			data, err := c.Get(fmt.Sprintf("ltv/customers/%s", args[0]), nil)
			if err != nil {
				return err
			}
			render(data)
			return nil
		}
		data, err := c.Get("ltv/customers", collectLtvParams(cmd))
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var ltvBreakdownCmd = &cobra.Command{
	Use:   "breakdown",
	Short: "LTV by acquisition source (campaign, ppc_account, landing_page) or by product",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectLtvParams(cmd)
		if v := getStringFlagOrDefault(cmd, "ltv", "by"); v != "" {
			params["by"] = v
		}
		data, err := c.Get("ltv/breakdown", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var ltvMrrCmd = &cobra.Command{
	Use:   "mrr",
	Short: "Subscription economics — active MRR/ARR, status counts, monthly churn with its inputs",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ltv/mrr", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var ltvPredictCmd = &cobra.Command{
	Use:   "predict",
	Short: "Predictive LTV — deterministic projection with guards; every number ships with its inputs",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectLtvParams(cmd)
		if v := getStringFlagOrDefault(cmd, "ltv", "by"); v != "" {
			params["by"] = v
		}
		data, err := c.Get("ltv/predict", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	addLtvTimeFilters(ltvSummaryCmd)

	addLtvTimeFilters(ltvCustomersCmd)
	ltvCustomersCmd.Flags().StringP("sort", "s", "", "Sort: total_revenue, order_count, last_activity_time, first_seen_time, mrr")
	ltvCustomersCmd.Flags().String("dir", "", "Sort direction: ASC or DESC")
	ltvCustomersCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvCustomersCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	addLtvTimeFilters(ltvBreakdownCmd)
	ltvBreakdownCmd.Flags().StringP("by", "b", "", "Dimension: campaign, ppc_account, landing_page, product")
	ltvBreakdownCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvBreakdownCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	addLtvTimeFilters(ltvPredictCmd)
	ltvPredictCmd.Flags().StringP("by", "b", "", "Also project per cohort: campaign, ppc_account, landing_page")

	ltvCmd.AddCommand(ltvSummaryCmd, ltvCustomersCmd, ltvBreakdownCmd, ltvMrrCmd, ltvPredictCmd)
	rootCmd.AddCommand(ltvCmd)
}
