package cmd

import (
	"regexp"
	"strconv"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Ad spend: what each ad platform billed per ad per hour, as the spend ledger
// holds it (/spend-ledger), and the ChatGPT Ads spend sync that fills it from
// OpenAI every hour (/spend-ledger/sync, off until turned on).

var adSpendConnectionPattern = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)

const adSpendSyncListHint = "`p202 ad-spend sync list` shows your ChatGPT Ads connections and their ids."

var adSpendCmd = &cobra.Command{
	Use:   "ad-spend",
	Short: "What ChatGPT Ads (and any platform writing the spend ledger) billed per ad, by day or hour",
	Long: "Reads the spend ledger: per period (a day in your account's time zone, or an hour) and ad, the\n" +
		"platform's impressions, clicks and spend, how many of your clicks carried the ad (their ad_id\n" +
		"variable) and the spend no click carried (unallocated). Reports cost those clicks at their share\n" +
		"of each hour's spend. `reaches` says how far the spend data goes; later clicks keep their CPC.\n" +
		"`p202 ad-spend sync` turns the hourly ChatGPT Ads pull on or off per connection.",
	Example: "  p202 ad-spend --period yesterday\n" +
		"  p202 ad-spend --period last7 --platform openai_ads --json\n" +
		"  p202 ad-spend --time-from 2026-11-10 --time-to 2026-11-10 --interval hour",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if cmd.Flags().Changed("period") {
			params["period"] = enumValue(cmd, "period")
		}
		for _, name := range []string{"time_from", "time_to", "platform"} {
			if v, _ := cmd.Flags().GetString(name); v != "" {
				params[name] = v
			}
		}
		if cmd.Flags().Changed("interval") {
			params["interval"] = enumValue(cmd, "interval")
		}
		if cmd.Flags().Changed("limit") {
			limit, _ := cmd.Flags().GetInt("limit")
			if limit < 1 || limit > 5000 {
				return validationError("--limit must be from 1 to 5000, not %d", limit).WithHint("%s", "Narrow --period or --platform for fewer rows.")
			}
			params["limit"] = strconv.Itoa(limit)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("spend-ledger", params)
		if err != nil {
			return withHint(err, "%s", "A server before 1.9.80 has no spend ledger; `p202 ad-spend sync list` shows whether ChatGPT Ads spend is pulled.")
		}
		render(data)
		return nil
	},
}

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
			return validationError("--staged cannot apply to `ad-spend sync`: the route is not stageable").WithHint("Run it without --staged.")
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
	Example: "  p202 ad-spend sync enable 1",
	Args:    cobra.ExactArgs(1),
	RunE:    adSpendSyncSet(true),
}

var adSpendSyncDisableCmd = &cobra.Command{
	Use:     "disable <connection-id>",
	Short:   "Stop pulling this connection's spend; what the ledger holds stays",
	Example: "  p202 ad-spend sync disable 1",
	Args:    cobra.ExactArgs(1),
	RunE:    adSpendSyncSet(false),
}

var adSpendSyncRunCmd = &cobra.Command{
	Use:     "run <connection-id>",
	Short:   "Pull this connection's spend now, without waiting for the hourly run",
	Example: "  p202 ad-spend sync run 1",
	Args:    cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !adSpendConnectionPattern.MatchString(args[0]) {
			return validationError("connection id must be a whole number greater than 0: %s", args[0]).WithHint("%s", adSpendSyncListHint)
		}
		if api.StagedMode() {
			return validationError("--staged cannot apply to `ad-spend sync run`: the route is not stageable").WithHint("Run it without --staged.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("spend-ledger/sync/"+args[0]+"/run", map[string]interface{}{})
		if err != nil {
			return withHint(err, "%s", "Turn the sync on first: `p202 ad-spend sync enable "+args[0]+"`.")
		}
		render(data)
		return nil
	},
}

func init() {
	adSpendCmd.Flags().String("period", "", "Named window ({values})")
	enumFlag(adSpendCmd, "period", newEnum(reportPeriods))
	adSpendCmd.Flags().String("time_from", "", "First day or second (YYYY-MM-DD in your time zone, unix seconds, or a time with its offset)")
	adSpendCmd.Flags().String("time_to", "", "Last day or second, inclusive")
	adSpendCmd.Flags().String("interval", "", "Rows per day (your time zone) or per hour ({values}; default day)")
	enumFlag(adSpendCmd, "interval", newEnum([]string{"day", "hour"}))
	adSpendCmd.Flags().String("platform", "", "Only this platform's rows (e.g. openai_ads)")
	adSpendCmd.Flags().Int("limit", 500, "Rows to return (1 to 5000)")
	adSpendSyncCmd.AddCommand(adSpendSyncListCmd, adSpendSyncEnableCmd, adSpendSyncDisableCmd, adSpendSyncRunCmd)
	adSpendCmd.AddCommand(adSpendSyncCmd)
	rootCmd.AddCommand(adSpendCmd)
}
