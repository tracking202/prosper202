package cmd

import (
	"encoding/json"
	"fmt"
	"p202/internal/api"
	"regexp"
	"strconv"

	"github.com/spf13/cobra"
)

var clickCmd = &cobra.Command{
	Use:   "click",
	Short: "View tracked clicks (inbound visitor events from traffic sources)",
}

var clickListCmd = &cobra.Command{
	Use:   "list",
	Short: "List tracked clicks with optional filters by campaign, time range, or bot status",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		flags := []string{"time_from", "time_to",
			"aff_campaign_id", "ppc_account_id", "landing_page_id",
			"click_lead", "click_bot"}
		for _, f := range flags {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			rows, err := fetchAllRowsWithParams(c, "clicks", params)
			if err != nil {
				return err
			}
			encoded, err := json.Marshal(map[string]interface{}{
				"data": rows,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  len(rows),
					"offset": 0,
				},
			})
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
		ClickID      json.Number `json:"click_id"`
		PayoutMode   string      `json:"payout_mode"`
		Lead         bool        `json:"lead"`
		ClickPayout  string      `json:"click_payout"`
		LedgerState  string      `json:"ledger_state"`
		LedgerValue  *string     `json:"ledger_value"`
		MatchesClick bool        `json:"matches_click"`
		Rows         json.Number `json:"rows"`
		CountedRows  json.Number `json:"counted_rows"`
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
		value = b.Click.ClickPayout
	}
	fmt.Printf("\nClick %s: %s (%s mode), %s of %s conversions counted.\n",
		b.Click.ClickID, value, b.Click.PayoutMode, b.Click.CountedRows, b.Click.Rows)
	switch {
	case b.Click.LedgerState == "pre_ledger":
		fmt.Println("It converted before the conversion ledger: its value is the click's own figure until its next conversion carries it in as a row.")
	case !b.Click.MatchesClick:
		ledger := "no value"
		if b.Click.LedgerValue != nil {
			ledger = *b.Click.LedgerValue
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
	clickListCmd.Flags().String("time_from", "", "Start timestamp (unix)")
	clickListCmd.Flags().String("time_to", "", "End timestamp (unix)")
	clickListCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	clickListCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	clickListCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	clickListCmd.Flags().String("click_lead", "", "Filter: 0=clicks only, 1=conversions only")
	clickListCmd.Flags().String("click_bot", "", "Filter: 0=human, 1=bot")

	clickCmd.AddCommand(clickListCmd, clickGetCmd, clickConversionsCmd)
	rootCmd.AddCommand(clickCmd)
}
