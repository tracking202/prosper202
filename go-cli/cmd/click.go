package cmd

import (
	"encoding/json"
	"fmt"
	"p202/internal/api"
	"regexp"
	"strconv"
	"time"

	"github.com/spf13/cobra"
)

var clickCmd = &cobra.Command{
	Use:   "click",
	Short: "View tracked clicks (inbound visitor events from traffic sources), and set what past clicks cost",
}

var clickListCmd = &cobra.Command{
	Use:   "list",
	Short: "List tracked clicks with optional filters by campaign, time range, or bot status",
	Long: "Lists clicks newest first, with what the Visitors page shows for each: campaign,\n" +
		"traffic source, keyword, IP, location, device, referrer and landing URLs. It takes\n" +
		"the Visitors page's filters, as `p202 report` does: --keyword and --referer\n" +
		"(contains), --ip, --device_type, --show real|filtered|filtered_bot|leads, location,\n" +
		"browser and platform ids, and --period.\n\n" +
		"--follow is the Spy page: it prints the newest --limit clicks (default 10), then\n" +
		"each new click as it arrives, polling every --interval, until interrupted or\n" +
		"--stop-after elapses. Under --json or --ndjson it writes one JSON object per\n" +
		"click per line.\n\n" +
		"  p202 click list --aff_campaign_id 12 --time_from 2026-10-01 --all --csv\n" +
		"  p202 click list --keyword shoes --show real --period last7\n" +
		"  p202 click list --follow --ndjson --stop-after 10m",
	RunE: func(cmd *cobra.Command, args []string) error {
		follow, _ := cmd.Flags().GetBool("follow")
		if follow {
			for _, f := range []string{"all", "offset", "page", "period", "time_from", "time_to"} {
				if cmd.Flags().Changed(f) {
					return validationError("--%s does not apply to --follow, which shows the newest clicks and then each new one", f).
						WithHint("Drop --%s, or drop --follow to list a fixed range.", f)
				}
			}
		} else {
			for _, f := range []string{"interval", "stop-after"} {
				if cmd.Flags().Changed(f) {
					return validationError("--%s only applies with --follow", f)
				}
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		// The Visitors page's filters: the reports' window and filters
		// (ReportFilter on the server), and the lead and bot switches.
		flags := append(append(append([]string{}, reportWindowFlags...), reportFilterFlags...), "click_lead", "click_bot")
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		if follow {
			return followClicks(cmd, c, params)
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			// The server's masked flag goes with the rows it hid money in.
			rows, masked, err := fetchAllRowsMasked(c, "clicks", params)
			if err != nil {
				return err
			}
			encoded, err := json.Marshal(listEnvelope(rows, masked))
			if err != nil {
				return fmt.Errorf("encoding %d clicks: %w", len(rows), err)
			}
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
				return validationError("--page must be a positive integer")
			}
			if _, hasOffset := params["offset"]; !hasOffset {
				limit := 50
				if limitStr, hasLimit := params["limit"]; hasLimit {
					parsedLimit, err := strconv.Atoi(limitStr)
					if err != nil || parsedLimit <= 0 {
						return validationError("--limit must be a positive integer when --page is used")
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

// positiveIDPattern is an id the API can name: digits, no sign, no leading zero.
var positiveIDPattern = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)

var clickConversionsCmd = &cobra.Command{
	Use:   "conversions <click-id>",
	Short: "Explain a click's value: every conversion on it, whether it counts, and why not",
	Long: `Lists every conversion recorded on the click — counted, unpaid, superseded,
deleted and reversals alike — with its amount, what produced it (source), what
that is (a goal and version, an upload, the conversion a reversal nets, the API
key that wrote it), its transaction id, and whether it counts toward the
click's value, with the reason when it does not.

The table shows one line per conversion and ends with the click's value; --json
and --ndjson return the API's rows unchanged, and --json adds the "click"
summary (value, payout mode, whether the rows add up to what the reports show).`,
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveIDPattern.MatchString(args[0]) {
			return validationError("click id must be a positive integer, got %q", args[0]).
				WithHint("Use the internal click id from `p202 click list` (the `click_id` column).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("clicks/"+args[0]+"/conversions", nil)
		if err != nil {
			return withHint(err, "Check the id with `p202 click get %s`; a key needs clicks:read and conversions:read.", args[0])
		}
		return renderClickConversions(data)
	},
}

// clickBreakdown is the part of GET /clicks/{id}/conversions the table reads.
type clickBreakdown struct {
	Data  []map[string]interface{} `json:"data"`
	Click struct {
		ClickID      json.Number  `json:"click_id"`
		PayoutMode   string       `json:"payout_mode"`
		Lead         bool         `json:"lead"`
		ClickPayout  json.Number  `json:"click_payout"` // a number; older servers sent a numeric string, which json.Number also reads
		LedgerState  string       `json:"ledger_state"`
		LedgerValue  *json.Number `json:"ledger_value"`
		MatchesClick bool         `json:"matches_click"`
		Rows         json.Number  `json:"rows"`
		CountedRows  json.Number  `json:"counted_rows"`
	} `json:"click"`
}

// renderClickConversions draws the breakdown. JSON and NDJSON pass the API's
// answer through untouched, so an agent reads every field; the table and CSV
// show one line per conversion with the reason flattened into one column,
// and the table ends with the click's value.
func renderClickConversions(data []byte) error {
	if jsonOutput || ndjsonOutput || quietOutput {
		render(data)
		return nil
	}
	var b clickBreakdown
	if err := json.Unmarshal(data, &b); err != nil {
		return fmt.Errorf("reading the click's conversions: %w", err)
	}
	rows := make([]map[string]interface{}, 0, len(b.Data))
	for _, r := range b.Data {
		counts := "counted"
		if counted, _ := r["counted"].(bool); !counted {
			counts, _ = r["not_counted_reason"].(string)
			if reason, ok := r["superseded_reason"].(string); ok && reason != "" {
				counts += " (" + reason + ")"
			}
		}
		linked := ""
		if l, ok := r["linked_to"].(map[string]interface{}); ok {
			linked, _ = l["label"].(string)
		}
		rows = append(rows, map[string]interface{}{
			"conv_id":        r["conv_id"],
			"amount":         r["amount"],
			"counts":         counts,
			"source":         r["source"],
			"linked_to":      linked,
			"transaction_id": r["transaction_id"],
			"event_name":     r["event_name"],
			"conv_time":      r["conv_time"],
		})
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		return fmt.Errorf("encoding the click's conversions: %w", err)
	}
	render(encoded)
	if csvOutput {
		return nil
	}

	value := "not converted"
	if b.Click.Lead {
		value = b.Click.ClickPayout.String()
	}
	fmt.Printf("\nClick %s: %s (%s mode), %s of %s conversions counted.\n",
		b.Click.ClickID, value, b.Click.PayoutMode, b.Click.CountedRows, b.Click.Rows)
	switch {
	case b.Click.LedgerState == "pre_ledger":
		fmt.Println("It converted before the conversion ledger: its value is the click's own figure until its next conversion carries it in as a row.")
	case !b.Click.MatchesClick:
		ledger := "no value"
		if b.Click.LedgerValue != nil {
			ledger = b.Click.LedgerValue.String()
		}
		fmt.Printf("Warning: the counted conversions add up to %s, which is not the click's %s. The next conversion on this click recomputes it.\n", ledger, value)
	}
	return nil
}

func init() {
	clickListCmd.Flags().StringP("limit", "l", "", "Max results")
	clickListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	clickListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	clickListCmd.Flags().String("page", "", "Page number (maps to offset)")
	// The window and the Visitors page's filters, as the reports take them.
	addReportFilters(clickListCmd)
	clickListCmd.Flags().String("click_lead", "", "Filter: 0=clicks only, 1=conversions only")
	enumFlag(clickListCmd, "click_lead", newEnum(binaryValues))
	clickListCmd.Flags().String("click_bot", "", "Filter: 0=human, 1=bot")
	enumFlag(clickListCmd, "click_bot", newEnum(binaryValues))
	clickListCmd.Flags().Bool("follow", false, "Keep printing new clicks as they arrive (the Spy page)")
	clickListCmd.Flags().Duration("interval", 5*time.Second, "With --follow: how often to poll (at least 1s)")
	clickListCmd.Flags().Duration("stop-after", 0, "With --follow: stop after this long, e.g. 10m (0 follows until interrupted)")

	clickCmd.AddCommand(clickListCmd, clickGetCmd, clickConversionsCmd)
	rootCmd.AddCommand(clickCmd)
}
