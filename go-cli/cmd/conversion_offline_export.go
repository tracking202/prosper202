package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// offlineExportPlatforms are the files GET /conversions/offline-export
// writes (OfflineConversionExport::PLATFORMS).
var offlineExportPlatforms = []string{"google", "microsoft"}

var conversionOfflineExportCmd = &cobra.Command{
	Use:   "offline-export",
	Short: "Export conversions on Google Ads or Microsoft Advertising clicks as the platform's offline conversion CSV",
	Long: `Writes the CSV that Google Ads or Microsoft Advertising imports as offline
conversions: every conversion that counts, on a click that carried a gclid
(google) or an msclkid (microsoft), at its value net of refunds. The file goes
to stdout, or to --output, and what was left out is said on stderr. The window
is --period, or --time-from and --time-to; the last 30 days by default.

  p202 conversion offline-export --platform google --conversion-name "Offline purchase" -O conversions.csv`,
	RunE: runConversionOfflineExport,
}

type offlineExportAnswer struct {
	Data struct {
		Rows    int `json:"rows"`
		Skipped struct {
			Reversed         int `json:"reversed"`
			ClickTooOld      int `json:"click_too_old"`
			MalformedClickID int `json:"malformed_click_id"`
		} `json:"skipped"`
		Truncated    bool   `json:"truncated"`
		NextTimeFrom *int64 `json:"next_time_from"`
		Filename     string `json:"filename"`
		CSV          string `json:"csv"`
	} `json:"data"`
}

func runConversionOfflineExport(cmd *cobra.Command, args []string) error {
	platform := enumValue(cmd, "platform")
	if platform == "" {
		return validationError("--platform is required: %s", strings.Join(offlineExportPlatforms, " or ")).
			WithHint("google for clicks from Google Ads (gclid), microsoft for Microsoft Advertising (msclkid).")
	}
	name, _ := cmd.Flags().GetString("conversion-name")
	if strings.TrimSpace(name) == "" {
		return validationError("--conversion-name is required").
			WithHint("Pass the conversion action's name exactly as it is spelled in the ad platform (Google Ads: Goals > Conversions).")
	}
	params := map[string]string{"platform": platform, "conversion_name": name}
	if v, _ := cmd.Flags().GetString("currency"); v != "" {
		params["currency"] = strings.ToUpper(v)
	}
	period := enumValue(cmd, "period")
	timeFrom, _ := cmd.Flags().GetString("time_from")
	timeTo, _ := cmd.Flags().GetString("time_to")
	if period != "" && (timeFrom != "" || timeTo != "") {
		return validationError("--period and --time-from/--time-to name the window twice").
			WithHint("Pass --period, or --time-from and --time-to.")
	}
	if period != "" {
		params["period"] = period
	}
	if timeFrom != "" {
		params["time_from"] = timeFrom
	}
	if timeTo != "" {
		params["time_to"] = timeTo
	}
	outputPath, _ := cmd.Flags().GetString("output")

	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Get("conversions/offline-export", params)
	if err != nil {
		return err
	}
	var answer offlineExportAnswer
	if err := json.Unmarshal(data, &answer); err != nil {
		return fmt.Errorf("reading the export: %w", err)
	}
	if outputPath != "" {
		if err := os.WriteFile(outputPath, []byte(answer.Data.CSV), 0600); err != nil {
			return withHint(err, "Check that the directory of --output exists and is writable.")
		}
	}
	// The CSV is what this command makes: only an explicit --json or
	// --ndjson answers with the JSON instead of it on stdout, not a default
	// an agent environment chose, so `> file.csv` always writes the file.
	if (jsonOutput || ndjsonOutput) && !outputImplicit {
		render(data)
		return nil
	}
	if outputPath == "" {
		if _, err := os.Stdout.WriteString(answer.Data.CSV); err != nil {
			return err
		}
	}

	d := answer.Data
	summary := fmt.Sprintf("%d conversion(s) exported", d.Rows)
	if outputPath != "" {
		summary += " to " + outputPath
	}
	if left := d.Skipped.Reversed + d.Skipped.ClickTooOld + d.Skipped.MalformedClickID; left > 0 {
		summary += fmt.Sprintf("; left out %d refunded to nothing, %d on a click more than 90 days old, %d with a click id the platform does not issue",
			d.Skipped.Reversed, d.Skipped.ClickTooOld, d.Skipped.MalformedClickID)
	}
	if outputPath != "" {
		output.Success("%s", summary)
	} else {
		fmt.Fprintln(os.Stderr, summary)
	}
	if d.Truncated && d.NextTimeFrom != nil {
		fmt.Fprintf(os.Stderr, "Stopped at 100,000 rows; export the rest with --time-from %d.\n", *d.NextTimeFrom)
	}
	return nil
}

func init() {
	f := conversionOfflineExportCmd.Flags()
	f.String("platform", "", "google (gclid) or microsoft (msclkid)")
	enumFlag(conversionOfflineExportCmd, "platform", newEnum(offlineExportPlatforms))
	f.String("conversion-name", "", "The conversion action's name, spelled exactly as in the ad platform")
	f.String("currency", "", "Three-letter currency code (default: the account currency)")
	f.StringP("period", "p", "", "Named window (default last30)")
	enumFlag(conversionOfflineExportCmd, "period", newEnum(reportPeriods))
	f.String("time_from", "", timeFromHelp)
	f.String("time_to", "", timeToHelp)
	f.StringP("output", "O", "", "Write the CSV to this file instead of stdout")
	conversionCmd.AddCommand(conversionOfflineExportCmd)
}
