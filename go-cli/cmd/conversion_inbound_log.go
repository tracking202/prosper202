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
		"requests no account could be found for. The newest 5,000 per account are kept (Setup › Inbound Log).\n" +
		"--search finds the requests for one subid (click id), conversion id or transaction id, given\n" +
		"whole. Each refused row says what to do about it (what_to_do), and can_record says whether\n" +
		"`p202 conversion inbound-log record <inbound_id>` can record its conversion now.\n\n" +
		"  p202 conversion inbound-log --outcome refused\n" +
		"  p202 conversion inbound-log --search ORD-20391\n" +
		"  p202 conversion inbound-log --endpoint jvzoo --limit 20",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("outcome"); v != "" {
			params["outcome"] = v
		}
		if v, _ := cmd.Flags().GetString("endpoint"); v != "" {
			params["endpoint"] = v
		}
		if v, _ := cmd.Flags().GetString("search"); v != "" {
			params["search"] = v
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
		whatToDo := ""
		if fix, ok := r["fix"].(map[string]interface{}); ok {
			whatToDo, _ = fix["text"].(string)
		}
		rows = append(rows, map[string]interface{}{
			"inbound_id":    r["inbound_id"],
			"received_at":   r["received_at"],
			"endpoint":      r["endpoint"],
			"http_status":   r["http_status"],
			"outcome":       r["outcome"],
			"reason":        r["reason"],
			"click_id":      r["click_id"],
			"conversion_id": r["conversion_id"],
			"what_to_do":    whatToDo,
			"can_record":    r["can_record"],
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

var conversionInboundLogRecordCmd = &cobra.Command{
	Use:   "record <inbound_id>",
	Short: "Record the conversion of a refused postback from the log",
	Long: "Records the conversion a refused server postback (pb.php or gpb.php) would have recorded,\n" +
		"from the request the log kept: the same click, amount and transaction id, dated when it\n" +
		"arrived, with the checks a postback meets (the click and campaign must be the account's, a\n" +
		"transaction id on file is a duplicate, and without one a click converts once). For a request\n" +
		"refused for its token or signature, or by an error on the server; rows that qualify show\n" +
		"can_record in `p202 conversion inbound-log --outcome refused`. Running it twice records once:\n" +
		"the second run answers already_recorded with the same conversion. Needs a role that may open\n" +
		"Setup and Update.",
	Example: "  p202 conversion inbound-log --outcome unauthenticated\n" +
		"  p202 conversion inbound-log record 4812",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveIDPattern.MatchString(args[0]) {
			return validationError("the inbound_id must be a positive integer, got %q", args[0]).
				WithHint("`p202 conversion inbound-log --outcome refused` lists each request's inbound_id.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("conversions/inbound-log/"+args[0]+"/record", nil)
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) {
				switch apiErr.Status {
				case 422:
					return withHint(err, "%s", "This request cannot be recorded from the log; the message says why. `p202 conversion inbound-log --outcome refused` shows can_record for the ones that can; for the rest, ask the sender to send it again or use `p202 conversion create`.")
				case 404:
					return withHint(err, "%s", "No request of this account has that inbound_id (the log keeps the newest 5,000 refused). List them with `p202 conversion inbound-log --outcome refused`.")
				case 409:
					return withHint(err, "%s", "Another run is recording this request. Wait a moment, then check it with `p202 conversion inbound-log --search <subid>`.")
				case 403:
					return withHint(err, "%s", "Recording needs a key of a role that may open both Setup and Update.")
				}
			}
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	conversionInboundLogCmd.AddCommand(conversionInboundLogRecordCmd)
	conversionInboundLogCmd.Flags().String("search", "", "Only the requests for this subid (click id), conversion id or transaction id, given whole")
	conversionInboundLogCmd.Flags().String("outcome", "", "refused (recorded nothing), accepted, or one outcome name such as unknown_click")
	conversionInboundLogCmd.Flags().String("endpoint", "", "Only this endpoint's requests: {values}")
	enumFlag(conversionInboundLogCmd, "endpoint", newEnum(inboundLogEndpoints))
	conversionInboundLogCmd.Flags().String("limit", "", "How many to list, 1 to 500 (default 50)")
	conversionInboundLogCmd.Flags().String("before", "", "Only requests older than this inbound_id (the previous page's next_before)")
	emptyHint(conversionInboundLogCmd, "outcome", "Omit --outcome for every request, or pass refused to see what recorded nothing.")
	conversionCmd.AddCommand(conversionInboundLogCmd)
}
