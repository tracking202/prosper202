package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// The spend ledger (/api/v3/spend-ledger): what each ad platform billed per
// ad per hour, which the reports read as cost where present.

// spendMaxRows is SpendRow::MAX_ROWS, the most rows one write takes.
const spendMaxRows = 2000

// spendListMaxLimit is SpendLedgerController::READ_MAX_LIMIT.
const spendListMaxLimit = 5000

var spendIntervals = []string{"day", "hour"}

var spendCmd = &cobra.Command{
	Use:   "spend",
	Short: "What each ad platform billed, per ad per hour (the spend ledger)",
}

var spendListCmd = &cobra.Command{
	Use:   "list",
	Short: "Ad platform spend per ad per day or hour, with the clicks it matched",
	Long: "Lists the spend ledger summed per day (in the account's timezone) or per hour, and per\n" +
		"platform, ad account, campaign, ad group and ad, newest first: impressions, the platform's\n" +
		"clicks, spend, matched_clicks (this account's clicks that carried the ad) and\n" +
		"unallocated_spend (spend in hours no click carried it). reaches is the end of the latest\n" +
		"hour the ledger holds. Reports read this spend as cost.",
	Example: "  p202 spend list --period yesterday\n" +
		"  p202 spend list --period last7 --interval hour --platform openai_ads\n" +
		"  p202 spend list --time-from 2026-11-01 --time-to 2026-11-10",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v := enumValue(cmd, "period"); v != "" {
			params["period"] = v
		}
		for flag, param := range map[string]string{"time-from": "time_from", "time-to": "time_to", "platform": "platform"} {
			if v, _ := cmd.Flags().GetString(flag); v != "" {
				params[param] = strings.TrimSpace(v)
			}
		}
		if v := enumValue(cmd, "interval"); v != "" {
			params["interval"] = v
		}
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			if n, err := strconv.Atoi(v); err != nil || n < 1 || n > spendListMaxLimit || strconv.Itoa(n) != v {
				return validationError("--limit must be a whole number from 1 to %d, got %q", spendListMaxLimit, v).
					WithHint("Omit --limit for the newest 500 rows, or narrow the window with --period or --time-from/--time-to.")
			}
			params["limit"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("spend-ledger", params)
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) && apiErr.Status == 403 {
				return withHint(err, "Reading the spend ledger needs a key with the spend-ledger:read scope.")
			}
			if asAPIError(err, &apiErr) && apiErr.Status == 404 {
				return withHint(err, "This server has no spend ledger: upgrade Prosper202 to 1.9.80 or later (`p202 system version`).")
			}
			return err
		}
		render(data)
		var resp struct {
			Truncated bool `json:"truncated"`
			Limit     int  `json:"limit"`
		}
		if json.Unmarshal(data, &resp) == nil && resp.Truncated {
			noteForPeople(cmd, fmt.Sprintf("More rows than --limit %d: narrow the window or raise --limit (up to %d).", resp.Limit, spendListMaxLimit))
		}
		return nil
	},
}

var spendImportCmd = &cobra.Command{
	Use:   "import <file|->",
	Short: "Record ad platform spend per ad per hour from a JSON file",
	Long: "Sends the rows in a JSON file (- for stdin) to the spend ledger in one write: a list of\n" +
		"rows, or {\"rows\": [...]}, 1 to 2000 rows. Each row is one UTC hour of one ad:\n" +
		"platform, account_ref, campaign_ref, ad_group_ref, ad_ref, hour_start (the unix second\n" +
		"the hour starts at, a multiple of 3600), impressions, clicks (the platform's count),\n" +
		"spend (major units, e.g. 18.42) and currency (the account's). campaign_ref and\n" +
		"ad_group_ref may be null. Sending an hour again replaces it and the same rows again\n" +
		"change nothing, so a retry is safe. Any refused row (rows.3.spend counts from 0) refuses\n" +
		"the whole file and nothing is written. --dry-run answers the same counts and writes\n" +
		"nothing. Needs a role with the Update section and a key with spend-ledger:write.\n" +
		"Cannot be staged.",
	Example: "  p202 spend import spend.json --dry-run\n" +
		"  p202 spend import spend.json\n" +
		"  my-puller | p202 spend import -",
	Args: func(cmd *cobra.Command, args []string) error {
		if len(args) != 1 {
			return validationError("`p202 spend import` takes one file (or - for stdin), got %d argument(s)", len(args)).
				WithHint(`Pass a JSON file of rows, e.g. p202 spend import spend.json; it holds [{"platform": "openai_ads", …}] or {"rows": [...]}.`)
		}
		return nil
	},
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := refuseStagedUpdate(cmd); err != nil {
			return err
		}
		rows, err := readSpendRows(args[0])
		if err != nil {
			return err
		}
		dryRun, _ := cmd.Flags().GetBool("dry-run")
		var params map[string]string
		if dryRun {
			params = map[string]string{"dry_run": "1"}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.PostUpdate("spend-ledger", params, map[string]interface{}{"rows": rows})
		if err != nil {
			return spendImportError(err)
		}
		render(data)
		var resp struct {
			Data struct {
				DryRun    bool `json:"dry_run"`
				Received  int  `json:"received"`
				Inserted  int  `json:"inserted"`
				Replaced  int  `json:"replaced"`
				Unchanged int  `json:"unchanged"`
			} `json:"data"`
		}
		if err := json.Unmarshal(data, &resp); err != nil {
			return fmt.Errorf("reading the spend ledger's answer: %w", err)
		}
		d := resp.Data
		if d.DryRun {
			output.Success("Dry run: %d row(s) would be %d new, %d replaced, %d unchanged. Nothing was written; drop --dry-run to record them.",
				d.Received, d.Inserted, d.Replaced, d.Unchanged)
		} else {
			output.Success("Recorded %d row(s): %d new, %d replaced, %d unchanged. `p202 spend list` shows them.",
				d.Received, d.Inserted, d.Replaced, d.Unchanged)
		}
		return nil
	},
}

// readSpendRows reads a JSON list of spend rows, or an object whose only
// field "rows" is one, from a file or stdin (-). It checks only what is
// certain without the server: one JSON value, a list of 1 to spendMaxRows
// objects. Numbers keep their digits (spend is exact to six decimals).
func readSpendRows(file string) ([]interface{}, error) {
	var data []byte
	var err error
	if file == "-" {
		if isTerminal(os.Stdin) {
			return nil, validationError("- reads the rows from piped stdin, and nothing is piped").
				WithHint("Pipe the JSON in (`cat spend.json | p202 spend import -`) or pass a file path.")
		}
		data, err = io.ReadAll(os.Stdin)
	} else {
		data, err = os.ReadFile(file)
	}
	if err != nil {
		return nil, validationError("reading %s: %v; nothing was sent", file, err).
			WithHint("Pass the path of a JSON file of spend rows, or - to read them from stdin.")
	}
	const shape = `The file is a list of rows, [{"platform": "openai_ads", "account_ref": "…", "ad_ref": "…", "hour_start": 1794297600, …}], or {"rows": [...]}.`
	var raw interface{}
	if err := decodeOneJSON(data, &raw); errors.Is(err, errTrailingJSON) {
		return nil, validationError("%s holds more than one JSON value; nothing was sent", file).
			WithHint("Put every row in one list, or one {\"rows\": [...]}.")
	} else if err != nil {
		return nil, validationError("%s is not JSON: %v; nothing was sent", file, err).WithHint("%s", shape)
	}
	if obj, ok := raw.(map[string]interface{}); ok {
		for k := range obj {
			if k != "rows" {
				return nil, validationError("%s has a field %q; the file holds only rows; nothing was sent", file, k).
					WithHint("%s", shape)
			}
		}
		raw = obj["rows"]
	}
	list, ok := raw.([]interface{})
	if !ok || len(list) == 0 {
		return nil, validationError("%s holds no list of spend rows; nothing was sent", file).WithHint("%s", shape)
	}
	if len(list) > spendMaxRows {
		return nil, validationError("%s holds %d rows; one import takes at most %d; nothing was sent", file, len(list), spendMaxRows).
			WithHint("Split the rows into files of up to %d and import each (a row sent twice changes nothing, so overlapping files are safe).", spendMaxRows)
	}
	for i, row := range list {
		if _, ok := row.(map[string]interface{}); !ok {
			return nil, validationError("row %d of %s (rows.%d) is not a JSON object; nothing was sent", i+1, file, i).
				WithHint("%s", shape)
		}
	}
	return list, nil
}

func spendImportError(err error) error {
	var reqErr *api.RequestError
	if errors.As(err, &reqErr) && reqErr.Kind == "network" {
		return withHint(err, "Check the server URL (`p202 config show`) and run `p202 config test`. If the request reached the server it may have been written; a write is all rows or none and sending the same rows again changes nothing, so run the same import again once the server answers.")
	}
	var apiErr *api.APIError
	if !asAPIError(err, &apiErr) {
		return updateRequestError(err, "A write is all rows or none, and sending the same rows again changes nothing: run the same import again.")
	}
	switch apiErr.Status {
	case 403:
		return withHint(err, "Recording spend needs a key whose role has the Update section (access_to_update_section) and the spend-ledger:write scope.")
	case 404:
		return withHint(err, "This server has no spend ledger: upgrade Prosper202 to 1.9.80 or later (`p202 system version`).")
	case 422:
		if len(apiErr.FieldErrors) > 0 {
			return withHint(err, "Nothing was written. rows.N is the file's row N counted from 0 (rows.0 is the first); fix those rows and import again, or check with --dry-run first.")
		}
	}
	return updateRequestError(err, "A write is all rows or none, and sending the same rows again changes nothing: run the same import again.")
}

func init() {
	spendListCmd.Flags().StringP("period", "p", "", "Period: {values}")
	enumFlag(spendListCmd, "period", newEnum(reportPeriods))
	spendListCmd.Flags().String("time-from", "", timeFromHelp)
	spendListCmd.Flags().String("time-to", "", timeToHelp)
	spendListCmd.Flags().String("interval", "", "Sum per {values} (default day, in the account's timezone)")
	enumFlag(spendListCmd, "interval", newEnum(spendIntervals))
	spendListCmd.Flags().String("platform", "", "Only this platform's rows, e.g. openai_ads")
	spendListCmd.Flags().String("limit", "", "How many rows, 1 to 5000 (default 500)")

	spendImportCmd.Flags().Bool("dry-run", false, "Answer what would be written, and write nothing")

	spendCmd.AddCommand(spendListCmd, spendImportCmd)
	rootCmd.AddCommand(spendCmd)
}
