package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var conversionCmd = &cobra.Command{
	Use:   "conversion",
	Short: "Manage conversions (revenue events recorded via postback or pixel)",
}

var conversionListCmd = &cobra.Command{
	Use:   "list",
	Short: "List conversions",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		// The ledger filters, checked before the client is built: a value the
		// server would refuse is refused with the command that finds a
		// right one.
		if v, _ := cmd.Flags().GetString("click_id"); v != "" {
			if !positiveIDPattern.MatchString(v) {
				return validationError("--click_id must be a positive integer, got %q", v).
					WithHint("Use the internal click id from `p202 click list`; `p202 click conversions <id>` explains that click's value.")
			}
			params["click_id"] = v
		}
		if v, _ := cmd.Flags().GetString("source"); v != "" {
			params["source"] = v
		}
		if v, _ := cmd.Flags().GetString("goal"); v != "" {
			if !positiveIDPattern.MatchString(v) {
				return validationError("--goal must be a positive integer goal id, got %q", v).
					WithHint("Find the goal's id with `p202 goal list`.")
			}
			params["goal"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		// Accept --aff_campaign_id (the name every other command uses); fall back
		// to the legacy --campaign_id spelling.
		if v, _ := cmd.Flags().GetString("aff_campaign_id"); v != "" {
			params["campaign_id"] = v
		} else if v, _ := cmd.Flags().GetString("campaign_id"); v != "" {
			params["campaign_id"] = v
		}
		for _, f := range []string{"time_from", "time_to"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			rows, err := fetchAllRowsWithParams(c, "conversions", params)
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
				return fmt.Errorf("encoding %d conversions: %w", len(rows), err)
			}
			render(encoded)
			return nil
		}

		for _, f := range []string{"limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("conversions", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// conversionSources is what a ledger row's source can be
// (ConversionSource in 202-config/Conversion/Ledger), in the server's order.
var conversionSources = []string{"pixel", "postback", "universal_pixel", "api", "subid_upload", "revenue_upload",
	"legacy_pixel", "clickbank", "app_install", "goal", "legacy_baseline"}

var conversionGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a conversion by ID",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var conversionCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a conversion",
	Long: "Records a conversion on a click. --status reversed records a reversal of the click's earlier\n" +
		"conversion with the same --transaction-id instead (--reversal-id is the network's id for it).\n" +
		"--customer-id or --customer-ref (+ --customer-ref-type, --customer-crm) links it to an LTV\n" +
		"customer; --item / --items-file add product line items to that customer's revenue event, so\n" +
		"they need a customer: one named here, one already linked to the click, or the account's\n" +
		"customer c-param. The server refuses items that find none, and records nothing.\n\n" +
		"  p202 conversion create --click-id 123 --payout 49 --transaction-id ORD-1 --customer-ref CUST-77 \\\n" +
		"      --item '{\"sku\":\"PRO-1\",\"quantity\":1,\"unit_price\":49}'\n" +
		"  p202 conversion create --click-id 123 --status reversed --transaction-id ORD-1",
	RunE: func(cmd *cobra.Command, args []string) error {
		clickIDStr, _ := cmd.Flags().GetString("click_id")
		if clickIDStr == "" {
			clickIDStr, _ = cmd.Flags().GetString("click_id_public")
		}
		if clickIDStr == "" {
			return validationError("required flag --click_id (or --click_id_public) is missing").
				WithHint("Use the internal click id from `p202 click list`.")
		}
		clickID, err := strconv.Atoi(clickIDStr)
		if err != nil || clickID <= 0 {
			return validationError("--click_id must be a positive integer: %s", clickIDStr).
				WithHint("Use the internal click id from `p202 click list`.")
		}
		body := map[string]interface{}{
			"click_id": clickID,
		}
		if v, _ := cmd.Flags().GetString("payout"); v != "" {
			body["payout"] = v
		} else if v, _ := cmd.Flags().GetString("conversion_payout"); v != "" {
			body["payout"] = v
		}
		if v, _ := cmd.Flags().GetString("transaction_id"); v != "" {
			body["transaction_id"] = v
		}
		if t, ok, err := ltvUnixTime(cmd, "conv-time"); err != nil {
			return err
		} else if ok {
			body["conv_time"] = t
		}
		if status := enumValue(cmd, "status"); status != "" {
			body["status"] = status
			if _, hasTx := body["transaction_id"]; !hasTx {
				return validationError("--status reversed needs --transaction-id: the reversal nets the click's conversion with that transaction id").
					WithHint("`p202 click conversions %d` shows the click's conversions and their transaction ids.", clickID)
			}
		}
		if cmd.Flags().Changed("reversal-id") {
			if _, reversal := body["status"]; !reversal {
				return validationError("--reversal-id names a reversal; add --status reversed").
					WithHint("A plain conversion carries no reversal id; drop --reversal-id, or record the reversal with --status reversed.")
			}
			v, _ := cmd.Flags().GetString("reversal-id")
			body["reversal_id"] = v
		}
		if err := conversionCustomer(cmd, body); err != nil {
			return err
		}
		items, hasItems, err := ltvItems(cmd)
		if err != nil {
			return err
		}
		if hasItems {
			// The server decides whether a customer resolves: one named here,
			// one already linked to the click, or the account's customer
			// c-param. It refuses items that find none (422 naming items).
			body["items"] = items
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("conversions", body, idemKey)
		if err != nil {
			return hintConversionCreateError(err)
		}
		render(data)
		if n := conversionCreateNote(data); n != "" {
			fmt.Fprintln(cmd.ErrOrStderr(), n)
		}
		return nil
	},
}

// hintConversionCreateError names the flags that supply a customer when the
// server refuses line items or CRM fields because none resolved: the click is
// linked to no customer and none was named. Only that refusal: the same field
// is also refused for its shape ("items" that is not a list), where naming a
// customer would not help.
func hintConversionCreateError(err error) error {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 422 {
		return err
	}
	for _, field := range []string{"items", "customer_crm"} {
		if msg, ok := apiErr.FieldErrors[field]; ok && strings.Contains(msg, "no customer is linked") {
			return withHint(err, "Name the customer with --customer-ref <your id> (and --customer-ref-type), or --customer-id from `p202 ltv customers`.")
		}
	}
	return err
}

// conversionCreateIdentityFlags name the LTV customer of a conversion.
var conversionCreateIdentityFlags = []string{"customer-id", "customer-ref", "customer-ref-type", "customer-crm"}

// conversionCustomer adds the optional customer identity to a conversion.
// Unlike an LTV write a conversion needs none (a click already linked to a
// customer, or the account's c-param, can name it), so only what is given
// is checked, as ltvIdentity checks it. --customer-crm alone is the CRM the
// c-param fallback creates its customer with.
func conversionCustomer(cmd *cobra.Command, body map[string]interface{}) error {
	given := false
	for _, f := range conversionCreateIdentityFlags {
		given = given || cmd.Flags().Changed(f)
	}
	if !given {
		return nil
	}
	if cmd.Flags().Changed("customer-id") || cmd.Flags().Changed("customer-ref") {
		return ltvIdentity(cmd, body)
	}
	if cmd.Flags().Changed("customer-ref-type") {
		return validationError("--customer-ref-type describes --customer-ref, which was not given").
			WithHint("Add --customer-ref, or drop --customer-ref-type.")
	}
	raw, _ := cmd.Flags().GetString("customer-crm")
	crm, err := ltvCRMObject(raw)
	if err != nil {
		return err
	}
	body["customer_crm"] = crm
	return nil
}

// conversionCreateNote says when a 201 wrote nothing new: the server matched a
// conversion already on the click, or replayed an earlier Idempotency-Key. The
// row printed is then that existing conversion. "" otherwise, and for an older
// server, which sends neither field.
func conversionCreateNote(data []byte) string {
	var resp struct {
		Data      map[string]interface{} `json:"data"`
		Replay    bool                   `json:"idempotent_replay"`
		Duplicate bool                   `json:"duplicate"`
	}
	if json.Unmarshal(data, &resp) != nil {
		return ""
	}
	which := "an existing conversion"
	if id, ok := extractIntField(resp.Data, "conv_id"); ok {
		which = fmt.Sprintf("conversion %d", id)
	}
	switch {
	case resp.Replay:
		return fmt.Sprintf("Note: this Idempotency-Key was already used; the server answered with %s and recorded nothing new.", which)
	case resp.Duplicate:
		return fmt.Sprintf("Note: the click already has this conversion; the server answered with %s and recorded nothing new.", which)
	}
	return ""
}

var conversionDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a conversion",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint: "conversions",
			noun:     "conversion",
			plural:   "conversions",
		})
	},
}

func init() {
	conversionListCmd.Flags().StringP("limit", "l", "", "Max results")
	conversionListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	conversionListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	conversionListCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	conversionListCmd.Flags().String("campaign_id", "", "Legacy alias for --aff_campaign_id")
	_ = conversionListCmd.Flags().MarkHidden("campaign_id")
	conversionListCmd.Flags().String("time_from", "", timeFromHelp)
	conversionListCmd.Flags().String("time_to", "", timeToHelp)
	conversionListCmd.Flags().String("click_id", "", "Only this click's conversions (see also `p202 click conversions <id>`)")
	conversionListCmd.Flags().String("source", "", "Only conversions from this source")
	enumFlag(conversionListCmd, "source", newEnum(conversionSources))
	conversionListCmd.Flags().String("goal", "", "Only this goal's outcomes, every version (goal id from `p202 goal list`)")
	// An empty filter is refused by name (empty_flags.go): read as "not
	// given" it would list every conversion as though filtered.
	emptyHint(conversionListCmd, "click_id", "Omit --click_id to list every click's conversions, or pass an internal click id from `p202 click list`.")
	emptyHint(conversionListCmd, "source", "Omit --source to list every source, or pass one of: "+strings.Join(conversionSources, ", ")+".")
	emptyHint(conversionListCmd, "goal", "Omit --goal to list every goal's conversions, or pass a goal id from `p202 goal list`.")
	emptyHint(conversionListCmd, "aff_campaign_id", "Omit --aff_campaign_id to list every campaign's conversions, or pass a campaign id from `p202 campaign list`.")

	conversionCreateCmd.Flags().String("click_id", "", "Click ID (required)")
	conversionCreateCmd.Flags().String("click_id_public", "", "Legacy alias for --click_id")
	conversionCreateCmd.Flags().String("payout", "", "Payout amount")
	conversionCreateCmd.Flags().String("conversion_payout", "", "Legacy alias for --payout")
	conversionCreateCmd.Flags().String("transaction_id", "", "Transaction ID for deduplication")
	conversionCreateCmd.Flags().String("conv-time", "", "When it converted, unix seconds (default now)")
	conversionCreateCmd.Flags().String("status", "", "Record a reversal of the click's conversion with this --transaction-id")
	enumFlag(conversionCreateCmd, "status", newEnum([]string{"reversed"}))
	conversionCreateCmd.Flags().String("reversal-id", "", "The network's id for the reversal (with --status reversed)")
	addLtvIdentityFlags(conversionCreateCmd, true)
	addLtvItemFlags(conversionCreateCmd)
	registerIdempotencyKeyFlag(conversionCreateCmd)

	registerDeleteFlags(conversionDeleteCmd, "conversion")

	conversionCmd.AddCommand(conversionListCmd, conversionGetCmd, conversionCreateCmd, conversionDeleteCmd)
	rootCmd.AddCommand(conversionCmd)
}
