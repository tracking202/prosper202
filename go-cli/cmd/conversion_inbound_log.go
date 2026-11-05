package cmd

import (
	"encoding/json"
	"fmt"
	"strconv"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// inboundLogEndpoints are the endpoints GET /conversions/inbound-log
// records (Prosper202\InboundLog\InboundLog::ENDPOINTS), in the server's order.
var inboundLogEndpoints = []string{"pb", "gpb", "px", "gpx", "upx", "clickbank", "jvzoo", "paykickstart", "shopify", "zaxaa"}

var conversionInboundLogCmd = &cobra.Command{
	Use:   "inbound-log",
	Short: "Postbacks, pixels and payment notifications received, and why each was refused",
	Long: "Lists the postbacks, pixels and IPN/INS payment notifications the install answered, newest\n" +
		"first: when, which endpoint, the HTTP status the sender got, the outcome (recorded, duplicate,\n" +
		"unknown_click, unverified, ...) and why. --outcome refused shows only the ones that recorded\n" +
		"nothing. Signatures, keys and visitor details are never kept. An admin's key also lists the\n" +
		"requests no account could be found for. The newest 5,000 per account are kept (Setup › Inbound Log).\n\n" +
		"  p202 conversion inbound-log --outcome refused\n" +
		"  p202 conversion inbound-log --endpoint jvzoo --limit 20",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("outcome"); v != "" {
			params["outcome"] = v
		}
		if v, _ := cmd.Flags().GetString("endpoint"); v != "" {
			params["endpoint"] = v
		}
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			if n, err := strconv.Atoi(v); err != nil || n < 1 || n > 500 {
				return validationError("--limit must be a whole number from 1 to 500, got %q", v).
					WithHint("Omit --limit for the newest 50, or page with --before.")
			}
			params["limit"] = v
		}
		if v, _ := cmd.Flags().GetString("before"); v != "" {
			if !positiveIDPattern.MatchString(v) {
				return validationError("--before must be a positive integer, got %q", v).
					WithHint("Pass the next_before value of the previous page (`p202 conversion inbound-log --json`).")
			}
			params["before"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/inbound-log", params)
		if err != nil {
			return inboundLogError(err)
		}
		return renderInboundLog(data)
	},
}

func inboundLogError(err error) error {
	var apiErr *api.APIError
	if asAPIError(err, &apiErr) && apiErr.Status == 403 {
		return withHint(err, "The inbound log is part of Setup, which this key's role cannot open. Use a key of a role with access to the Setup section.")
	}
	return err
}

// renderInboundLog draws one line per request. JSON and NDJSON pass the answer through.
func renderInboundLog(data []byte) error {
	if jsonOutput || ndjsonOutput || quietOutput {
		render(data)
		return nil
	}
	var resp struct {
		Data       []map[string]interface{} `json:"data"`
		NextBefore *int64                   `json:"next_before"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return fmt.Errorf("reading the inbound log: %w", err)
	}
	rows := make([]map[string]interface{}, 0, len(resp.Data))
	for _, r := range resp.Data {
		rows = append(rows, map[string]interface{}{
			"inbound_id":    r["inbound_id"],
			"received_at":   r["received_at"],
			"endpoint":      r["endpoint"],
			"http_status":   r["http_status"],
			"outcome":       r["outcome"],
			"reason":        r["reason"],
			"click_id":      r["click_id"],
			"conversion_id": r["conversion_id"],
		})
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		return fmt.Errorf("encoding the inbound log: %w", err)
	}
	render(encoded)
	if resp.NextBefore != nil && !csvOutput {
		fmt.Printf("Older requests: p202 conversion inbound-log --before %d\n", *resp.NextBefore)
	}
	return nil
}

func init() {
	conversionInboundLogCmd.Flags().String("outcome", "", "refused (recorded nothing), accepted, or one outcome name such as unknown_click")
	conversionInboundLogCmd.Flags().String("endpoint", "", "Only this endpoint's requests: {values}")
	enumFlag(conversionInboundLogCmd, "endpoint", newEnum(inboundLogEndpoints))
	conversionInboundLogCmd.Flags().String("limit", "", "How many to list, 1 to 500 (default 50)")
	conversionInboundLogCmd.Flags().String("before", "", "Only requests older than this inbound_id (the previous page's next_before)")
	emptyHint(conversionInboundLogCmd, "outcome", "Omit --outcome for every request, or pass refused to see what recorded nothing.")
	conversionCmd.AddCommand(conversionInboundLogCmd)
}
