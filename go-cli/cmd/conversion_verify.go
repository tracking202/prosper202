package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/config"

	"github.com/spf13/cobra"
)

var subidRe = regexp.MustCompile(`[?&](?:s|subid|sid)=([0-9]+)`)

// trackingBaseURL returns the scheme://host of the active profile for hitting
// the (non-API) redirect and postback endpoints.
func trackingBaseURL() (string, error) {
	prof, _, err := config.LoadProfileWithName(profileName)
	if err != nil {
		return "", err
	}
	if prof.URL == "" {
		return "", validationError("no URL configured. Run: p202 config set-url <url>")
	}
	return strings.TrimRight(prof.URL, "/"), nil
}

var conversionPostbackURLCmd = &cobra.Command{
	Use:   "postback-url",
	Short: "Print the server-to-server postback URL to give your affiliate network",
	Long:  "Outputs the gpb.php postback template. The network calls it with the conversion payout and the click subid.",
	RunE: func(cmd *cobra.Command, args []string) error {
		base, err := trackingBaseURL()
		if err != nil {
			return err
		}
		url := base + "/tracking202/static/gpb.php?amount={payout}&subid={subid}"
		fmt.Fprintln(os.Stderr, "Server-to-server postback URL (give this to your network):")
		fmt.Fprintln(os.Stderr, "  Replace {payout} with the conversion amount macro and {subid} with the network's subid macro")
		fmt.Fprintln(os.Stderr, "  (use ?sid= instead of ?subid= if the network only supports sid).")
		fmt.Println(url)
		return nil
	},
}

var conversionSimulateCmd = &cobra.Command{
	Use:   "simulate",
	Short: "Prove the click -> subid -> postback -> conversion loop works for a tracker",
	Long: "Fires a synthetic click through the tracker, reads back the subid, fires the\n" +
		"real S2S postback (gpb.php), and reports whether it was accepted — so you can\n" +
		"validate the loop before pointing a network's postback at this instance.",
	RunE: func(cmd *cobra.Command, args []string) error {
		trackerID, _ := cmd.Flags().GetString("tracker")
		if trackerID == "" {
			return validationError("--tracker is required (the internal tracker_id from `tracker list`)")
		}
		payout, _ := cmd.Flags().GetString("payout")
		if payout == "" {
			payout = "1.00"
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		base, err := trackingBaseURL()
		if err != nil {
			return err
		}

		// 1. Resolve the tracker link.
		urlData, err := c.Get("trackers/"+trackerID+"/url", nil)
		if err != nil {
			return err
		}
		var resp struct {
			Data struct {
				DirectURL string `json:"direct_url"`
			} `json:"data"`
		}
		if json.Unmarshal(urlData, &resp) != nil || resp.Data.DirectURL == "" {
			return validationError("tracker has no resolvable link")
		}
		link := resp.Data.DirectURL
		if !strings.HasPrefix(link, "http") {
			link = scheme(base) + "://" + link
		}

		// 2. Fire a click and capture the subid from the redirect.
		sep := "?"
		if strings.Contains(link, "?") {
			sep = "&"
		}
		clickRes, err := probeClient().Get(link + sep + "t202kw=simulate")
		if err != nil {
			return fmt.Errorf("click request failed: %w", err)
		}
		loc := clickRes.Header.Get("Location")
		clickRes.Body.Close()
		m := subidRe.FindStringSubmatch(loc)
		if m == nil {
			return fmt.Errorf("could not parse a subid from the redirect (%q) — the link may be misconfigured", loc)
		}
		subid := m[1]

		// 3. Fire the real S2S postback (gpb.php).
		pbURL := fmt.Sprintf("%s/tracking202/static/gpb.php?amount=%s&subid=%s", base, payout, subid)
		pbRes, err := (&http.Client{Timeout: 15 * time.Second}).Get(pbURL)
		if err != nil {
			return fmt.Errorf("postback request failed: %w", err)
		}
		body, _ := io.ReadAll(pbRes.Body)
		pbRes.Body.Close()

		pass := pbRes.StatusCode >= 200 && pbRes.StatusCode < 300
		rows := []map[string]interface{}{{
			"tracker_id":      trackerID,
			"subid":           subid,
			"postback_status": fmt.Sprintf("%d", pbRes.StatusCode),
			"result":          map[bool]string{true: "PASS", false: "FAIL"}[pass],
			"detail":          strings.TrimSpace(string(body)),
		}}
		render(rowsToJSON(rows))
		if !pass {
			return partialFailureError("postback returned HTTP %d — conversions from this network would not record", pbRes.StatusCode)
		}
		return nil
	},
}

func scheme(base string) string {
	if strings.HasPrefix(base, "https") {
		return "https"
	}
	return "http"
}

func init() {
	conversionSimulateCmd.Flags().String("tracker", "", "Internal tracker_id to simulate a conversion through")
	conversionSimulateCmd.Flags().String("payout", "1.00", "Conversion payout amount to post")
	conversionCmd.AddCommand(conversionSimulateCmd, conversionPostbackURLCmd)
}
