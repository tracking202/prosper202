package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// offlineExportFormats are the import files GET /conversions/offline-export
// writes (Prosper202\OfflineConversion\Format), in the server's order.
var offlineExportFormats = []string{"google", "google_uploads", "microsoft"}

// offlineExportConsent is what a Google file may state in its consent columns.
var offlineExportConsent = []string{"granted", "denied"}

var offlineExportDaysPattern = regexp.MustCompile(`^[1-9][0-9]?$`)

const offlineExportFormatHint = "Pass --format google (Google Ads Data Manager: gclid, gbraid and wbraid), google_uploads " +
	"(Google's Conversions › Uploads template: gclid) or microsoft (Microsoft Advertising: msclkid)."

var conversionOfflineExportCmd = &cobra.Command{
	Use:   "offline-export",
	Short: "Conversions as Google Ads' or Microsoft Advertising's offline conversion import CSV",
	Long: "Writes the account's conversions as an ad platform's offline conversion import file, for Google\n" +
		"Ads (--format google: Data Manager, gclid, gbraid and wbraid; google_uploads: the Uploads\n" +
		"template, gclid) or Microsoft Advertising (--format microsoft: msclkid). Only conversions whose\n" +
		"click was named (by the postback, pixel, API call, upload or a tracking cookie) are in it, at the\n" +
		"value they count for in the account's currency, under each campaign's conversion name (set on\n" +
		"Setup › Offline Conversions; --conversion-name names every line). --summary says how many\n" +
		"conversions the file holds and why each other one is left out. A schedule that fetches the file\n" +
		"by itself is a fetch URL made on that Setup page.\n\n" +
		"  p202 conversion offline-export --format google --output google-ads.csv\n" +
		"  p202 conversion offline-export --format microsoft --days 7 --summary",
	RunE: func(cmd *cobra.Command, args []string) error {
		params, summary, err := offlineExportParams(cmd)
		if err != nil {
			return err
		}
		outputPath, _ := cmd.Flags().GetString("output")
		if summary && outputPath != "" {
			return validationError("--summary prints the counts and writes no file, so --output has nothing to write").
				WithHint("Drop --summary to write the file to --output, or drop --output to see the counts.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if summary {
			data, err := c.Get("conversions/offline-export/summary", params)
			if err != nil {
				return offlineExportError(err)
			}
			return renderOfflineExportSummary(data)
		}
		data, err := c.DownloadWith("conversions/offline-export", params)
		if err != nil {
			return offlineExportError(err)
		}
		if outputPath == "" {
			if _, err := os.Stdout.Write(data); err != nil {
				return fmt.Errorf("writing the file to stdout: %w", err)
			}
			return nil
		}
		if err := os.WriteFile(outputPath, data, 0600); err != nil {
			return withHint(err, "Check that the directory of --output exists and is writable.")
		}
		output.Success("%d conversions written to %s. `p202 conversion offline-export --format %s --summary` says what was left out and why.",
			offlineExportRows(data, params["format"]), outputPath, params["format"])
		return nil
	},
}

// offlineExportParams reads the flags into the query, refusing what the
// server would refuse with the command that finds a right value.
func offlineExportParams(cmd *cobra.Command) (map[string]string, bool, error) {
	params := map[string]string{}
	format, _ := cmd.Flags().GetString("format")
	if format == "" {
		return nil, false, validationError("--format is required").WithHint(offlineExportFormatHint)
	}
	params["format"] = format
	if v, _ := cmd.Flags().GetString("days"); v != "" {
		if n, err := strconv.Atoi(v); !offlineExportDaysPattern.MatchString(v) || err != nil || n > 90 {
			return nil, false, validationError("--days must be a whole number from 1 to 90, got %q", v).
				WithHint("Neither platform imports a conversion more than 90 days after its click; omit --days for all 90.")
		}
		params["days"] = v
	}
	if v, _ := cmd.Flags().GetString("aff_campaign_id"); v != "" {
		if !positiveIDPattern.MatchString(v) {
			return nil, false, validationError("--aff-campaign-id must be a positive integer, got %q", v).
				WithHint("Find the campaign's id with `p202 campaign list`.")
		}
		params["campaign_id"] = v
	}
	if v, _ := cmd.Flags().GetString("conversion_name"); v != "" {
		params["conversion_name"] = v
	}
	for _, f := range []string{"ad_user_data", "ad_personalization"} {
		v, _ := cmd.Flags().GetString(f)
		if v == "" {
			continue
		}
		if format == "microsoft" {
			flag := strings.ReplaceAll(f, "_", "-")
			return nil, false, validationError("--%s is a Google column; Microsoft Advertising's file has none", flag).
				WithHint("Drop --%s, or pass --format google or google_uploads.", flag)
		}
		params[f] = v
	}
	summary, _ := cmd.Flags().GetBool("summary")
	return params, summary, nil
}

// offlineExportError adds the next step for the two refusals this command meets.
func offlineExportError(err error) error {
	var apiErr *api.APIError
	if asAPIError(err, &apiErr) {
		switch apiErr.Status {
		case 403:
			return withHint(err, "The file carries every conversion's value, which this key's role does not read. Use a key of a role with access to campaign data.")
		case 422:
			if _, ok := apiErr.FieldErrors["conversion_name"]; ok {
				return withHint(err, "A conversion name is the conversion action's (Google) or goal's (Microsoft) exactly as spelled there, at most 100 characters, not starting with =, +, - or @.")
			}
		}
	}
	return err
}

// offlineExportRows counts a file's conversion lines: its lines less the
// header and, for a template with one, the Parameters line above it.
func offlineExportRows(data []byte, format string) int {
	lines := bytes.Count(data, []byte("\r\n")) - 1
	if format != "google" {
		lines--
	}
	if lines < 0 {
		return 0
	}
	return lines
}

// renderOfflineExportSummary draws the left-out reasons with their counts and
// sentences, then one line of totals. JSON and NDJSON pass the answer through.
func renderOfflineExportSummary(data []byte) error {
	if jsonOutput || ndjsonOutput || quietOutput {
		render(data)
		return nil
	}
	var resp struct {
		Data struct {
			Format      string            `json:"format"`
			Days        int               `json:"days"`
			Timezone    string            `json:"timezone"`
			Currency    string            `json:"currency"`
			Conversions int               `json:"conversions"`
			Rows        int               `json:"rows"`
			LeftOut     map[string]int    `json:"left_out"`
			Reasons     map[string]string `json:"reasons"`
		} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return fmt.Errorf("reading the offline export summary: %w", err)
	}
	reasons := make([]string, 0, len(resp.Data.LeftOut))
	for reason, n := range resp.Data.LeftOut {
		if n > 0 {
			reasons = append(reasons, reason)
		}
	}
	sort.Strings(reasons)
	rows := make([]map[string]interface{}, 0, len(reasons))
	for _, reason := range reasons {
		rows = append(rows, map[string]interface{}{
			"left_out":    reason,
			"conversions": resp.Data.LeftOut[reason],
			"why":         resp.Data.Reasons[reason],
		})
	}
	fmt.Printf("%d of %d conversions from the last %d days are in the %s file (values in %s, times in %s).\n",
		resp.Data.Rows, resp.Data.Conversions, resp.Data.Days, resp.Data.Format, resp.Data.Currency, resp.Data.Timezone)
	if len(rows) == 0 {
		return nil
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		return fmt.Errorf("encoding the offline export summary: %w", err)
	}
	render(encoded)
	return nil
}

func init() {
	conversionOfflineExportCmd.Flags().String("format", "", "The import file: {values}")
	enumFlag(conversionOfflineExportCmd, "format", newEnum(offlineExportFormats, enumHint(offlineExportFormatHint)))
	conversionOfflineExportCmd.Flags().String("days", "", "Conversions from the last this many days, 1 to 90 (default 90)")
	conversionOfflineExportCmd.Flags().String("aff_campaign_id", "", "Only this campaign's conversions (an id from `p202 campaign list`)")
	conversionOfflineExportCmd.Flags().String("conversion_name", "", "Send every conversion under this conversion action (Google) or goal (Microsoft) name")
	conversionOfflineExportCmd.Flags().String("ad_user_data", "", "Google's Ad User Data column on every line, as your consent banner collected it: {values}")
	enumFlag(conversionOfflineExportCmd, "ad_user_data", newEnum(offlineExportConsent))
	conversionOfflineExportCmd.Flags().String("ad_personalization", "", "Google's Ad Personalization column on every line: {values}")
	enumFlag(conversionOfflineExportCmd, "ad_personalization", newEnum(offlineExportConsent))
	conversionOfflineExportCmd.Flags().String("output", "", "Write the file here instead of to stdout")
	conversionOfflineExportCmd.Flags().Bool("summary", false, "Print how many conversions the file holds and why each other one is left out, instead of the file")
	emptyHint(conversionOfflineExportCmd, "aff_campaign_id", "Omit --aff-campaign-id for every campaign, or pass a campaign id from `p202 campaign list`.")
	emptyHint(conversionOfflineExportCmd, "conversion_name", "Omit --conversion-name to send each conversion under its campaign's name (Setup › Offline Conversions).")
	conversionCmd.AddCommand(conversionOfflineExportCmd)
}
