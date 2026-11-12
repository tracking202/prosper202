package cmd

import (
	"regexp"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// `p202 spend sync` and `p202 spend conversions`: the ChatGPT Ads spend sync
// that fills the spend ledger from OpenAI every hour (/spend-ledger/sync, off
// until turned on), and the conversions OpenAI reports per ad per day
// (/spend-ledger/conversions). spend.go has `spend list` and `spend import`.

var adSpendConnectionPattern = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)

const adSpendSyncListHint = "`p202 spend sync list` shows your ChatGPT Ads connections and their ids."

var adSpendSyncCmd = &cobra.Command{
	Use:   "sync",
	Short: "The hourly ChatGPT Ads spend pull, per connection: list, enable, disable, run",
	Long: "Each ChatGPT Ads connection (`p202 ad-connection list`) can pull what OpenAI billed per ad per\n" +
		"hour into the spend ledger every hour. It is off until enabled. The first run reads the last 30\n" +
		"days; later runs re-read the trailing 72 hours, since spend settles after the clicks. An ad\n" +
		"account billed in another currency than your Prosper202 account is refused by name.\n" +
		"Needs a role with access_to_setup_section.",
}

var adSpendSyncListCmd = &cobra.Command{
	Use:   "list",
	Short: "Each ChatGPT Ads connection's spend sync: on or off, its last run and error",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("spend-ledger/sync", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func adSpendSyncSet(enabled bool) func(cmd *cobra.Command, args []string) error {
	return func(cmd *cobra.Command, args []string) error {
		if !adSpendConnectionPattern.MatchString(args[0]) {
			return validationError("connection id must be a whole number greater than 0: %s", args[0]).WithHint("%s", adSpendSyncListHint)
		}
		if api.StagedMode() {
			return validationError("--staged cannot apply to `spend sync`: the route is not stageable").WithHint("Run it without --staged.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("spend-ledger/sync/"+args[0], map[string]interface{}{"enabled": enabled})
		if err != nil {
			return withHint(err, "%s", adSpendSyncListHint)
		}
		render(data)
		return nil
	}
}

var adSpendSyncEnableCmd = &cobra.Command{
	Use:     "enable <connection-id>",
	Short:   "Pull this ChatGPT Ads connection's spend every hour (the first run reads 30 days)",
	Example: "  p202 spend sync enable 1",
	Args:    cobra.ExactArgs(1),
	RunE:    adSpendSyncSet(true),
}

var adSpendSyncDisableCmd = &cobra.Command{
	Use:     "disable <connection-id>",
	Short:   "Stop pulling this connection's spend; what the ledger holds stays",
	Example: "  p202 spend sync disable 1",
	Args:    cobra.ExactArgs(1),
	RunE:    adSpendSyncSet(false),
}

var adSpendSyncRunCmd = &cobra.Command{
	Use:     "run <connection-id>",
	Short:   "Pull this connection's spend now, without waiting for the hourly run",
	Example: "  p202 spend sync run 1",
	Args:    cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !adSpendConnectionPattern.MatchString(args[0]) {
			return validationError("connection id must be a whole number greater than 0: %s", args[0]).WithHint("%s", adSpendSyncListHint)
		}
		if api.StagedMode() {
			return validationError("--staged cannot apply to `spend sync run`: the route is not stageable").WithHint("Run it without --staged.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("spend-ledger/sync/"+args[0]+"/run", map[string]interface{}{})
		if err != nil {
			return withHint(err, "%s", "`p202 spend sync list` shows the connection's last error and retry_after; if the sync is off, `p202 spend sync enable "+args[0]+"` first.")
		}
		render(data)
		return nil
	},
}

var adSpendConversionsCmd = &cobra.Command{
	Use:   "conversions",
	Short: "ChatGPT Ads' reported conversions per ad per day, beside Prosper202's for the same ads",
	Long: "Per day (in the ad account's time zone, as OpenAI reports it) and ad: OpenAI's goal conversions,\n" +
		"click-through and view-through, and the purchase value it attributed, beside prosper202_conversions,\n" +
		"the conversions of your clicks that carried the ad that day. Filled by `p202 spend sync`.",
	Example: "  p202 spend conversions --period last7",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if cmd.Flags().Changed("period") {
			params["period"] = enumValue(cmd, "period")
		}
		for flag, param := range map[string]string{"time-from": "time_from", "time-to": "time_to", "platform": "platform"} {
			if v, _ := cmd.Flags().GetString(flag); v != "" {
				params[param] = strings.TrimSpace(v)
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("spend-ledger/conversions", params)
		if err != nil {
			return withHint(err, "%s", "`p202 spend sync list` shows whether ChatGPT Ads spend and conversions are pulled.")
		}
		render(data)
		return nil
	},
}

func init() {
	adSpendConversionsCmd.Flags().String("period", "", "Named window ({values})")
	enumFlag(adSpendConversionsCmd, "period", newEnum(reportPeriods))
	adSpendConversionsCmd.Flags().String("time-from", "", "First day (YYYY-MM-DD in your time zone) or second")
	adSpendConversionsCmd.Flags().String("time-to", "", "Last day or second, inclusive")
	adSpendConversionsCmd.Flags().String("platform", "", "Only this platform's rows (e.g. openai_ads)")
	adSpendSyncCmd.AddCommand(adSpendSyncListCmd, adSpendSyncEnableCmd, adSpendSyncDisableCmd, adSpendSyncRunCmd)
	spendCmd.AddCommand(adSpendSyncCmd, adSpendConversionsCmd)
}
