package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// refundAmountPattern is what POST /conversions/shopify-refunds/{id}/record
// reads as an amount: at most six whole digits and five decimals.
// No leading zero before another digit: 007 is no JSON number, and the body sends it as one.
var refundAmountPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,5})(\.[0-9]{1,5})?$`)

var currencyCodePattern = regexp.MustCompile(`^[A-Za-z]{3}$`)

var conversionShopifyRefundsCmd = &cobra.Command{
	Use:   "shopify-refunds",
	Short: "Shopify refunds that are not recorded yet: list, record or close them",
	Long: "A Shopify refund that is waiting for its transaction to settle, or that was refused for good,\n" +
		"is still counted in its order's sale. These commands list those refunds and record or close\n" +
		"one, as the panel above Setup › Inbound Log does.\n\n" +
		"  p202 conversion shopify-refunds list\n" +
		"  p202 conversion shopify-refunds record 9100001 --amount 12.50\n" +
		"  p202 conversion shopify-refunds close 9100001 --force",
}

var conversionShopifyRefundsListCmd = &cobra.Command{
	Use:   "list",
	Short: "List the Shopify refunds still counted in a sale, oldest first",
	Long: "Lists the account's Shopify refunds that are not recorded while their order's sale is: waiting\n" +
		"(Shopify has not said the transaction settled) or refused (not tried again), with the amount\n" +
		"each returns, less any transaction Shopify reported as failed, and the clicks the order's sale\n" +
		"is on. A waiting refund older than a day is marked stale.\n\n" +
		"  p202 conversion shopify-refunds list\n" +
		"  p202 conversion shopify-refunds list --json",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/shopify-refunds", nil)
		if err != nil {
			return shopifyRefundError(err)
		}
		return renderShopifyRefunds(data)
	},
}

var conversionShopifyRefundsRecordCmd = &cobra.Command{
	Use:   "record <refund-id>",
	Short: "Record a listed Shopify refund as a reversal of its order's sale",
	Long: "Takes --amount back from the sale of the refund's order, at most what is left of it, and closes\n" +
		"the refund. The amount is in its sale's currency (amount_currency in the list: the account's unless\n" +
		"the sale was recorded before a currency change); for a refund Shopify states in another currency\n" +
		"(other_currency), convert it yourself and pass --currency with amount_currency.\n" +
		"An order recorded on more than one click\n" +
		"needs --click-id (the list shows the clicks). Recorded once under the refund's id: running it\n" +
		"again, or Shopify settling the refund later, records nothing more.\n\n" +
		"  p202 conversion shopify-refunds record 9100001 --amount 12.50\n" +
		"  p202 conversion shopify-refunds record 9100001 --amount 12.50 --click-id 4821",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := shopifyRefundID(args[0])
		if err != nil {
			return err
		}
		amount, _ := cmd.Flags().GetString("amount")
		if !refundAmountPattern.MatchString(amount) || isZeroAmount(amount) {
			return validationError("--amount must be what the refund returned, such as 12.50 (more than 0, at most 999999.99999), got %q", amount).
				WithHint("`p202 conversion shopify-refunds list` shows the amount each refund states.")
		}
		body := map[string]interface{}{"amount": json.Number(amount)}
		if v, _ := cmd.Flags().GetString("click-id"); v != "" {
			if !positiveIDPattern.MatchString(v) {
				return validationError("--click-id must be a click id, got %q", v).
					WithHint("`p202 conversion shopify-refunds list` shows the clicks the order's sale is recorded on (click_ids).")
			}
			body["click_id"] = json.Number(v)
		}
		if v, _ := cmd.Flags().GetString("currency"); v != "" {
			if !currencyCodePattern.MatchString(v) {
				return validationError("--currency must be a three-letter currency code such as USD, got %q", v).
					WithHint("`p202 conversion shopify-refunds list --json` shows each refund's amount_currency, the currency --amount is stated in.")
			}
			body["currency"] = strings.ToUpper(v)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("conversions/shopify-refunds/"+id+"/record", body)
		if err != nil {
			return shopifyRefundError(err)
		}
		render(data)
		return nil
	},
}

var conversionShopifyRefundsCloseCmd = &cobra.Command{
	Use:   "close <refund-id>",
	Short: "Close a listed Shopify refund with nothing recorded",
	Long: "Removes the refund from the list without a reversal, for a refund that returned no money. Its\n" +
		"amount stays counted in the sale, and the refund is not recorded if Shopify settles it later.\n\n" +
		"  p202 conversion shopify-refunds close 9100001 --force",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := shopifyRefundID(args[0])
		if err != nil {
			return err
		}
		if force, _ := cmd.Flags().GetBool("force"); !force {
			ok, err := confirmAction(cmd, "Close Shopify refund %s with nothing recorded? Its amount stays counted in the sale.", id)
			if err != nil {
				return err
			}
			if !ok {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("conversions/shopify-refunds/"+id+"/close", map[string]interface{}{})
		if err != nil {
			return shopifyRefundError(err)
		}
		if jsonOutput || ndjsonOutput {
			render(data)
			return nil
		}
		fmt.Printf("Shopify refund %s was closed with nothing recorded.\n", id)
		return nil
	},
}

func shopifyRefundID(arg string) (string, error) {
	if !positiveIDPattern.MatchString(arg) {
		return "", validationError("the refund id must be a Shopify refund id (digits), got %q", arg).
			WithHint("`p202 conversion shopify-refunds list` shows each refund's refund_id.")
	}
	return arg, nil
}

func isZeroAmount(amount string) bool {
	for _, r := range amount {
		if r != '0' && r != '.' {
			return false
		}
	}
	return true
}

// shopifyRefundError adds the next step for the answers these routes give.
func shopifyRefundError(err error) error {
	var apiErr *api.APIError
	if !asAPIError(err, &apiErr) {
		return err
	}
	switch apiErr.Status {
	case 403:
		return withHint(err, "Listing needs a role with access to the Setup section or to Account > API Integrations; recording or closing needs API Integrations, or Setup and Update together. `p202 user role list` shows the roles; use a key of such a role.")
	case 404:
		return withHint(err, "The refund is not one waiting to be recorded (recorded or closed already, or another account's). `p202 conversion shopify-refunds list` shows the ones that are.")
	case 422:
		if _, named := apiErr.FieldErrors["currency"]; named {
			return withHint(err, "Prosper202 does not convert money. Pass --amount as what the refund returned in its sale's currency, with --currency set to that currency (amount_currency in `p202 conversion shopify-refunds list --json`).")
		}
		return err
	case 409:
		return withHint(err, "Nothing was changed. A refund waiting for its order's sale is recorded when the sale arrives: wait for it. One whose sale is gone, refused before its order arrived, or already on file at another amount, can be closed: `p202 conversion shopify-refunds close <refund-id>`.")
	}
	return err
}

// renderShopifyRefunds draws one line per refund. JSON and NDJSON pass the answer through.
func renderShopifyRefunds(data []byte) error {
	if jsonOutput || ndjsonOutput || quietOutput {
		render(data)
		return nil
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
		More bool                     `json:"more"`
		Seen *bool                    `json:"transaction_webhook_seen"`
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	if err := decoder.Decode(&resp); err != nil {
		return fmt.Errorf("reading the Shopify refunds: %w", err)
	}
	if len(resp.Data) == 0 && !csvOutput {
		fmt.Println("No Shopify refund is waiting to be recorded.")
		return nil
	}
	stale := 0
	for _, r := range resp.Data {
		if s, _ := r["stale"].(bool); s {
			stale++
		}
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": resp.Data})
	if err != nil {
		return fmt.Errorf("encoding the Shopify refunds: %w", err)
	}
	render(encoded)
	if csvOutput {
		return nil
	}
	if stale > 0 && resp.Seen != nil && !*resp.Seen {
		fmt.Println("Shopify's Transaction creation webhook has never arrived for this account, so a waiting refund is never settled by itself: add it in Shopify (Settings › Notifications › Webhooks) and record the waiting refunds.")
	}
	if resp.More {
		fmt.Println("More refunds are left than the 200 listed; they show as these are settled.")
	}
	fmt.Println("Record one: p202 conversion shopify-refunds record <refund-id> --amount <amount>")
	return nil
}

func init() {
	conversionShopifyRefundsRecordCmd.Flags().String("amount", "", "What the refund returned, in its sale's currency, amount_currency in the list (required)")
	conversionShopifyRefundsRecordCmd.Flags().String("currency", "", "The currency --amount is stated in: the refund's amount_currency, its sale's; needed for a refund Shopify states in another")
	conversionShopifyRefundsRecordCmd.Flags().String("click-id", "", "The click the refund takes back from, when the order is on more than one")
	conversionShopifyRefundsCloseCmd.Flags().Bool("force", false, "Close without asking")
	conversionShopifyRefundsCmd.AddCommand(conversionShopifyRefundsListCmd, conversionShopifyRefundsRecordCmd, conversionShopifyRefundsCloseCmd)
	conversionCmd.AddCommand(conversionShopifyRefundsCmd)
}
