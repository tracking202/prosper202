package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"strconv"
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
		"days; later runs re-read the trailing 72 hours, since spend settles after the clicks. A run reads\n" +
		"a day at a time and the next run goes on where one stopped; a row the ledger cannot take keeps\n" +
		"the run not complete and is named in left_out. An ad account billed in another currency than\n" +
		"your Prosper202 account is refused by name. OpenAI's rate limit answers exit 7, its outage or\n" +
		"an answer that cannot be used exit 4, a key it refused exit 1 (replace the key).\n" +
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
			return withStatusHint(err, statusHint{http.StatusNotFound, "", adSpendSyncListHint})
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
			return adSpendSyncRunError(err, args[0])
		}
		render(data)
		var resp struct {
			Data struct {
				Run struct {
					Complete bool   `json:"complete"`
					Note     string `json:"note"`
				} `json:"run"`
			} `json:"data"`
		}
		if json.Unmarshal(data, &resp) == nil && !resp.Data.Run.Complete {
			noteForPeople(cmd, "Not complete: "+resp.Data.Run.Note+". What was read is written; the next run (hourly, or `p202 spend sync run "+args[0]+"`) goes on.")
		}
		return nil
	},
}

// adSpendSyncRunError gives each refusal of a run its own next step; OpenAI's
// limit (429), outage (503) and unusable answer (502) keep the hint api.HintFor
// has for their class (nothing in the request is wrong, run it again later),
// unless the run wrote rows first: then the hint says so, since the class's
// "nothing was done" would be false (CLAUDE.md #13).
func adSpendSyncRunError(err error, id string) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status != http.StatusUnprocessableEntity {
		details, _ := apiErr.Raw["details"].(map[string]interface{})
		if rows, _ := details["rows_written"].(float64); rows >= 1 {
			wait := "Wait a minute"
			if apiErr.Status == http.StatusTooManyRequests && apiErr.RetryAfter > 0 {
				wait = fmt.Sprintf("Wait %d seconds (the server's Retry-After)", apiErr.RetryAfter)
			}
			return withHint(err, "This run wrote %d hour row(s) before it stopped, and they stand; the ledger replaces each hour, so running it again adds nothing twice. %s, then run `p202 spend sync run %s` again, or leave it to the hourly run.", int(rows), wait, id)
		}
	}
	return withStatusHint(err,
		statusHint{http.StatusNotFound, "", adSpendSyncListHint},
		statusHint{http.StatusUnprocessableEntity, "enabled", "The sync is off: `p202 spend sync enable " + id + "` turns it on (the first run reads 30 days)."},
		statusHint{http.StatusUnprocessableEntity, "api_key", "Replace the key: `p202 ad-connection update " + id + " --api-key-file <file>`; that also ends the hourly run's wait. " + advertiserKeyWhere},
		statusHint{http.StatusUnprocessableEntity, "connection", "Spend is not converted between currencies: the ad account's currency must be your account's (`p202 user prefs update <user-id> --user-account-currency <code>`), or record the spend with `p202 spend import`."},
		statusHint{http.StatusUnprocessableEntity, "spend", "The spend ledger refused a page OpenAI sent (the message names why); the pages before it stand. Running it again gets the same page: record those hours with `p202 spend import`, or report the page."},
		statusHint{http.StatusUnprocessableEntity, "request", "OpenAI refused the request itself (its words are in the message); running it again sends the same request. Check the ad account in Ads Manager, then `p202 ad-connection check " + id + "`."},
	)
}

// adSpendConversionsMaxLimit is PlatformConversionsController's limit bound.
const adSpendConversionsMaxLimit = 5000

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
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			if n, err := strconv.Atoi(v); err != nil || n < 1 || n > adSpendConversionsMaxLimit || strconv.Itoa(n) != v {
				return validationError("--limit must be a whole number from 1 to %d, got %q", adSpendConversionsMaxLimit, v).
					WithHint("Omit --limit for the newest 500 rows, or narrow the window with --period or --time-from/--time-to.")
			}
			params["limit"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("spend-ledger/conversions", params)
		if err != nil {
			window := "--time-from and --time-to take a day (YYYY-MM-DD, in your time zone) or a unix second, --time-to not before --time-from; or use --period."
			return withStatusHint(err,
				statusHint{http.StatusUnprocessableEntity, "time_from", window},
				statusHint{http.StatusUnprocessableEntity, "time_to", window},
				statusHint{http.StatusUnprocessableEntity, "period", "--period takes one of the values `p202 spend conversions --help` lists."},
				statusHint{http.StatusUnprocessableEntity, "platform", "--platform takes a platform name such as openai_ads, or leave it out for every platform."},
				statusHint{http.StatusUnprocessableEntity, "limit", fmt.Sprintf("--limit takes a whole number from 1 to %d.", adSpendConversionsMaxLimit)},
			)
		}
		render(data)
		var resp struct {
			Truncated bool `json:"truncated"`
			Limit     int  `json:"limit"`
		}
		if json.Unmarshal(data, &resp) == nil && resp.Truncated {
			noteForPeople(cmd, fmt.Sprintf("More rows than --limit %d: narrow the window or raise --limit (up to %d).", resp.Limit, adSpendConversionsMaxLimit))
		}
		return nil
	},
}

func init() {
	adSpendConversionsCmd.Flags().String("period", "", "Named window ({values})")
	enumFlag(adSpendConversionsCmd, "period", newEnum(reportPeriods))
	adSpendConversionsCmd.Flags().String("time-from", "", "First day (YYYY-MM-DD in your time zone) or second")
	adSpendConversionsCmd.Flags().String("time-to", "", "Last day or second, inclusive")
	adSpendConversionsCmd.Flags().String("platform", "", "Only this platform's rows (e.g. openai_ads)")
	adSpendConversionsCmd.Flags().String("limit", "", "How many rows, 1 to 5000 (default 500)")
	adSpendSyncCmd.AddCommand(adSpendSyncListCmd, adSpendSyncEnableCmd, adSpendSyncDisableCmd, adSpendSyncRunCmd)
	spendCmd.AddCommand(adSpendSyncCmd, adSpendConversionsCmd)
}
