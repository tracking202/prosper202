package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var ltvCmd = &cobra.Command{
	Use:   "ltv",
	Short: "Customer lifetime value — realized LTV, per-customer detail, product/acquisition breakdowns, MRR/churn, and predictive LTV",
	Long: "Customer lifetime value: the reads (summary, customers, breakdown, cohorts, mrr, predict, abm,\n" +
		"engagement, subscriptions, companies, products) and the writes the API offers — customer and\n" +
		"company records (`ltv customer`, `ltv company`), revenue, engagement events, subscriptions and\n" +
		"products pushed from other systems, custom fields, outbound webhooks and integrations.\n\n" +
		"LTV writes cannot be staged: under --staged they are refused before anything is sent.",
}

// Values the LTV endpoints accept: MysqlLtvRepository's breakdowns(),
// CUSTOMER_SORTS and CUSTOMER_SEGMENTS, and MysqlSubscriptionRepository's
// STATUSES. The server refuses any other value naming the parameter, and
// TestLtvListsAreTheServers holds these lists to its.
var (
	ltvDimensions           = []string{"campaign", "ppc_account", "landing_page", "product"}
	ltvCustomerSorts        = []string{"total_revenue", "order_count", "last_activity_time", "first_seen_time", "mrr"}
	ltvCustomerSegments     = []string{"repeat", "subscribers", "at_risk"}
	ltvSubscriptionStatuses = []string{"trialing", "active", "past_due", "paused", "canceled"}
)

// ltvMaxPage is the most rows one LTV list page returns; the server clamps a
// larger --limit to it without saying so, so a larger one is refused here.
const ltvMaxPage = 500

// ltvMaxFieldFilters is LtvQuery::MAX_CUSTOM_FIELD_FILTERS: each --cf (an
// equality, a .min or a .max) is one join on the server, and it refuses a
// fourth.
const ltvMaxFieldFilters = 3

// ltvFieldKeyPattern is a custom field's key as the server stores it
// (MysqlCustomerFieldRepository::create: 1-64 of a-z, 0-9 and _).
var ltvFieldKeyPattern = regexp.MustCompile(`^[a-z0-9_]{1,64}$`)

// ltvFieldKeyHint points at the command that lists the field keys.
const ltvFieldKeyHint = "Custom field keys are lowercase a-z, 0-9 and _; `p202 ltv fields list` shows this account's."

// collectLtvParams gathers the shared filter flags used across ltv
// subcommands, checked here: the server reads each with an (int) cast or a
// clamp, so "abc" or 900 would be answered as some other page without a
// word.
func collectLtvParams(cmd *cobra.Command) (map[string]string, error) {
	return collectLtvFilters(cmd, true)
}

// collectLtvFilters is collectLtvParams with the paging flags left out when
// paging is false (--all sets its own).
func collectLtvFilters(cmd *cobra.Command, paging bool) (map[string]string, error) {
	params := map[string]string{}
	for _, f := range []string{"period", "time_from", "time_to", "sort", "dir"} {
		if cmd.Flags().Lookup(f) == nil {
			continue
		}
		// time_from/time_to go as given: the server reads unix seconds, a
		// date or a time with its offset (TimeBound), and refuses anything
		// else naming the field.
		if v := getStringFlagOrDefault(cmd, "ltv", f); v != "" {
			params[f] = v
		}
	}
	if paging {
		if err := collectLtvPaging(cmd, params); err != nil {
			return nil, err
		}
	}
	if err := collectLtvFieldFilters(cmd, params); err != nil {
		return nil, err
	}
	return params, nil
}

// collectLtvPaging adds --limit (1-500) and --offset (0 or more) when the
// command has them and they are set.
func collectLtvPaging(cmd *cobra.Command, params map[string]string) error {
	for _, f := range []string{"limit", "offset"} {
		if cmd.Flags().Lookup(f) == nil {
			continue
		}
		v := getStringFlagOrDefault(cmd, "ltv", f)
		if v == "" {
			continue
		}
		n, err := strconv.Atoi(v)
		if f == "limit" && (err != nil || n < 1 || n > ltvMaxPage) {
			return validationError("--limit must be a whole number from 1 to %d, got %q", ltvMaxPage, v).
				WithHint("The server returns at most %d rows a page; page with --offset, or use --all where the command has it.", ltvMaxPage)
		}
		if f == "offset" && (err != nil || n < 0) {
			return validationError("--offset must be a whole number of rows to skip (0 or more), got %q", v).
				WithHint("For example --offset 50.")
		}
		params[f] = strconv.Itoa(n)
	}
	return nil
}

// addLtvFieldFilterFlag registers --cf on a read the server filters by custom
// field (summary, customers, breakdown, predict: every read that builds an
// LtvQuery).
func addLtvFieldFilterFlag(cmd *cobra.Command) {
	cmd.Flags().StringArray("cf", nil, "Only customers whose custom field matches: key=value, or key.min=value / key.max=value "+
		"for a number or date field (repeatable, at most 3; `p202 ltv fields list` shows the keys)")
}

// collectLtvFieldFilters turns each --cf into the dotted query key the server
// reads from the raw query string (cf.<key>, cf.<key>.min, cf.<key>.max;
// LtvController::customFieldFilterParams). Everything the server would
// refuse after a round trip, or would quietly read as something else, is
// refused here first: a malformed pair, a bound other than min/max, an empty
// value, a fourth filter, and the same key twice (the server keeps only the
// last).
func collectLtvFieldFilters(cmd *cobra.Command, params map[string]string) error {
	if cmd.Flags().Lookup("cf") == nil {
		return nil
	}
	filters, _ := cmd.Flags().GetStringArray("cf")
	if len(filters) > ltvMaxFieldFilters {
		return validationError("--cf was given %d times; the server takes at most %d custom-field filters a query", len(filters), ltvMaxFieldFilters).
			WithHint("Keep the %d that narrow the most (a .min and a .max on one field count as two).", ltvMaxFieldFilters)
	}
	example := "For example --cf plan=pro, --cf score.min=50 or --cf renewal.max=2026-12-31."
	for _, raw := range filters {
		key, value, ok := strings.Cut(raw, "=")
		key = strings.TrimSpace(key)
		if !ok || key == "" {
			return validationError("--cf %q is not key=value", raw).WithHint("%s %s", example, ltvFieldKeyHint)
		}
		field, bound, hasBound := strings.Cut(key, ".")
		if !ltvFieldKeyPattern.MatchString(field) {
			return validationError("--cf %q: %q is not a custom field key", raw, field).WithHint("%s", ltvFieldKeyHint)
		}
		if hasBound && bound != "min" && bound != "max" {
			return validationError("--cf %q: only .min or .max may follow the key, got .%s", raw, bound).WithHint("%s", example)
		}
		if strings.TrimSpace(value) == "" {
			return validationError("--cf %q has no value to match", raw).WithHint("%s", example)
		}
		name := "cf." + key
		if _, dup := params[name]; dup {
			return validationError("--cf %s is given twice; the server would use only the last", key).
				WithHint("Give each key once; for a range use %s.min and %s.max.", field, field)
		}
		params[name] = value
	}
	return nil
}

func addLtvTimeFilters(cmd *cobra.Command) {
	cmd.Flags().StringP("period", "p", "", "Period")
	enumFlag(cmd, "period", newEnum(reportPeriods))
	cmd.Flags().String("time_from", "", "Acquisition window start: "+timeFromHelp[len("Start: "):])
	cmd.Flags().String("time_to", "", "Acquisition window end, inclusive: "+timeToHelp[len("End, inclusive: "):])
}

// ltvGet is the shape of every plain LTV read: validated params, one GET,
// the response rendered.
func ltvGet(path string, params map[string]string) error {
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Get(path, params)
	if err != nil {
		return ltvFilterError(err)
	}
	render(data)
	return nil
}

// ltvFilterError names where the field keys and types are when the server
// refuses a --cf filter (an unknown key, a range on a text field, a value
// that is not a number or date).
func ltvFilterError(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 422 {
		for field := range apiErr.FieldErrors {
			if strings.HasPrefix(field, "cf.") {
				return withHint(err, "`p202 ltv fields list` shows each key and its type; .min and .max apply to number and date fields only.")
			}
		}
	}
	return err
}

var ltvSummaryCmd = &cobra.Command{
	Use:   "summary",
	Short: "Realized LTV totals — customers, revenue, avg LTV, AOV, repeat rate, MRR",
	RunE: func(cmd *cobra.Command, args []string) error {
		params, err := collectLtvParams(cmd)
		if err != nil {
			return err
		}
		return ltvGet("ltv/summary", params)
	},
}

// ltvCustomerListFlags are the flags that shape the customer LIST; with an id
// they would be ignored, so they are refused.
var ltvCustomerListFlags = []string{"period", "time_from", "time_to", "sort", "dir", "limit", "offset", "search", "segment", "cf", "all"}

var ltvCustomersCmd = &cobra.Command{
	Use:   "customers [id]",
	Short: "List customers with LTV rollups, or show one customer in full (CRM, aliases, custom fields, recent revenue)",
	Long: "List customers with their LTV rollups, newest-valued first, or show one in full.\n\n" +
		"  p202 ltv customers --search acme --segment repeat\n" +
		"  p202 ltv customers --cf plan=pro --cf score.min=50 --all\n" +
		"  p202 ltv customers 42\n\n" +
		"--search matches the customer reference, email, company or name. --cf filters by custom\n" +
		"field: key=value, or key.min/key.max for number and date fields (at most 3). Writes are\n" +
		"`p202 ltv customer` (upsert, update, merge, erase, alias).",
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if len(args) == 1 {
			for _, f := range ltvCustomerListFlags {
				if cmd.Flags().Changed(f) {
					return validationError("--%s filters the customer list; it does nothing with one customer's id", f).
						WithHint("Drop the id to list customers, or drop --%s to show customer %s.", f, args[0])
				}
			}
			id, err := validateID(args[0])
			if err != nil {
				return withHint(err, "Customer ids are the customer_id column of `p202 ltv customers`.")
			}
			return ltvGet("ltv/customers/"+id, nil)
		}

		all, _ := cmd.Flags().GetBool("all")
		if all && (cmd.Flags().Changed("limit") || cmd.Flags().Changed("offset")) {
			return validationError("--all fetches every page; it does not combine with --limit or --offset").
				WithHint("Drop --all to read one page, or drop --limit/--offset to read them all.")
		}
		// Under --all no --limit/--offset (nor their config defaults) reach
		// the request; fetchAllRowsPaged sets its own.
		params, err := collectLtvFilters(cmd, !all)
		if err != nil {
			return err
		}
		if v, _ := cmd.Flags().GetString("search"); v != "" {
			params["q"] = v
		}
		if v := enumValue(cmd, "segment"); v != "" {
			params["segment"] = v
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if !all {
			data, err := c.Get("ltv/customers", params)
			if err != nil {
				return ltvFilterError(err)
			}
			render(data)
			return nil
		}
		rows, err := fetchAllRowsPaged(c, "ltv/customers", params, ltvMaxPage)
		if err != nil {
			return ltvFilterError(err)
		}
		encoded, err := json.Marshal(map[string]interface{}{
			"data":       rows,
			"pagination": map[string]interface{}{"total": len(rows), "limit": len(rows), "offset": 0},
		})
		if err != nil {
			return fmt.Errorf("encoding %d customers: %w", len(rows), err)
		}
		render(encoded)
		return nil
	},
}

var ltvBreakdownCmd = &cobra.Command{
	Use:   "breakdown",
	Short: "LTV by acquisition source (campaign, ppc_account, landing_page) or by product",
	RunE: func(cmd *cobra.Command, args []string) error {
		params, err := collectLtvParams(cmd)
		if err != nil {
			return err
		}
		if v := getStringFlagOrDefault(cmd, "ltv", "by"); v != "" {
			params["by"] = v
		}
		return ltvGet("ltv/breakdown", params)
	},
}

var ltvCohortsCmd = &cobra.Command{
	Use:   "cohorts",
	Short: "LTV maturation by acquisition month — each cohort's revenue by months since first seen (m0..m4, m5_plus)",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("months"); v != "" {
			// The server clamps to 1-24 without saying so; 36 would come
			// back as 24 cohorts that look like the whole answer.
			n, err := strconv.Atoi(v)
			if err != nil || n < 1 || n > 24 {
				return validationError("--months must be a whole number from 1 to 24, got %q", v).
					WithHint("The newest N acquisition months are shown; the server keeps at most 24.")
			}
			params["months"] = strconv.Itoa(n)
		}
		return ltvGet("ltv/cohorts", params)
	},
}

var ltvMrrCmd = &cobra.Command{
	Use:   "mrr",
	Short: "Subscription economics — active MRR/ARR, status counts, monthly churn with its inputs",
	RunE: func(cmd *cobra.Command, args []string) error {
		return ltvGet("ltv/mrr", nil)
	},
}

var ltvPredictCmd = &cobra.Command{
	Use:   "predict",
	Short: "Predictive LTV — deterministic projection with guards; every number ships with its inputs",
	RunE: func(cmd *cobra.Command, args []string) error {
		params, err := collectLtvParams(cmd)
		if err != nil {
			return err
		}
		if v := getStringFlagOrDefault(cmd, "ltv", "by"); v != "" {
			params["by"] = v
		}
		return ltvGet("ltv/predict", params)
	},
}

var ltvProductsCmd = &cobra.Command{
	Use:   "products",
	Short: "The product catalog revenue line items and `ltv product upsert` write to, newest first",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if err := collectLtvPaging(cmd, params); err != nil {
			return err
		}
		return ltvGet("ltv/products", params)
	},
}

var ltvAbmCmd = &cobra.Command{
	Use:   "abm",
	Short: "ABM account view — companies ranked by engagement with scores, depth metrics, revenue and MRR",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if err := collectLtvPaging(cmd, params); err != nil {
			return err
		}
		if v := getStringFlagOrDefault(cmd, "ltv", "days"); v != "" {
			params["days"] = v
		}
		path := "ltv/abm"
		if company := getStringFlagOrDefault(cmd, "ltv", "company"); company != "" {
			params["name"] = company
			path = "ltv/abm/company"
		}
		return ltvGet(path, params)
	},
}

var ltvEngagementCmd = &cobra.Command{
	Use:   "engagement <customer-id>",
	Short: "One customer's engagement — browsing, instrumented events, and the suggested next offer",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		if v := getStringFlagOrDefault(cmd, "ltv", "days"); v != "" {
			params["days"] = v
		}
		engagement, err := c.Get(fmt.Sprintf("ltv/customers/%s/engagement", args[0]), params)
		if err != nil {
			return err
		}
		render(engagement)
		nextOffer, err := c.Get(fmt.Sprintf("ltv/customers/%s/next-offer", args[0]), nil)
		if err != nil {
			return err
		}
		render(nextOffer)
		return nil
	},
}

var ltvSubscriptionsCmd = &cobra.Command{
	Use:   "subscriptions",
	Short: "Account-wide subscription list joined to customers, filterable by lifecycle status",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if err := collectLtvPaging(cmd, params); err != nil {
			return err
		}
		if v := getStringFlagOrDefault(cmd, "ltv", "status"); v != "" {
			params["status"] = v
		}
		return ltvGet("ltv/subscriptions", params)
	},
}

var ltvCompaniesCmd = &cobra.Command{
	Use:   "companies",
	Short: "Company (ABM account) entities with live contact/revenue rollups (writes: `p202 ltv company`)",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if err := collectLtvPaging(cmd, params); err != nil {
			return err
		}
		return ltvGet("ltv/companies", params)
	},
}

func init() {
	addLtvTimeFilters(ltvSummaryCmd)
	addLtvFieldFilterFlag(ltvSummaryCmd)

	addLtvTimeFilters(ltvCustomersCmd)
	ltvCustomersCmd.Flags().StringP("sort", "s", "", "Sort")
	enumFlag(ltvCustomersCmd, "sort", newEnum(ltvCustomerSorts))
	ltvCustomersCmd.Flags().String("dir", "", "Sort direction")
	enumFlag(ltvCustomersCmd, "dir", sortDirEnum())
	ltvCustomersCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvCustomersCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	ltvCustomersCmd.Flags().Bool("all", false, "Fetch every page (500 rows a request)")
	ltvCustomersCmd.Flags().String("search", "", "Only customers whose reference, email, company or name contains this text (the API's q)")
	ltvCustomersCmd.Flags().String("segment", "", "Only this segment (repeat: 2+ orders; subscribers: an active subscription; at_risk: a past_due subscription)")
	enumFlag(ltvCustomersCmd, "segment", newEnum(ltvCustomerSegments))
	addLtvFieldFilterFlag(ltvCustomersCmd)

	addLtvTimeFilters(ltvBreakdownCmd)
	ltvBreakdownCmd.Flags().StringP("by", "b", "", "Dimension")
	enumFlag(ltvBreakdownCmd, "by", newEnum(ltvDimensions))
	ltvBreakdownCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvBreakdownCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	addLtvFieldFilterFlag(ltvBreakdownCmd)

	ltvCohortsCmd.Flags().String("months", "", "How many acquisition months, newest first (1-24, default 6)")

	addLtvTimeFilters(ltvPredictCmd)
	ltvPredictCmd.Flags().StringP("by", "b", "", "Also project per cohort")
	enumFlag(ltvPredictCmd, "by", newEnum(ltvDimensions))
	addLtvFieldFilterFlag(ltvPredictCmd)

	ltvProductsCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvProductsCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	ltvAbmCmd.Flags().StringP("company", "c", "", "Drill into one company by name")
	ltvAbmCmd.Flags().StringP("days", "d", "", "Engagement window in days (default 90, max 365)")
	ltvAbmCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvAbmCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	ltvEngagementCmd.Flags().StringP("days", "d", "", "Engagement window in days (default 90, max 365)")

	ltvSubscriptionsCmd.Flags().StringP("status", "s", "", "Only this status")
	enumFlag(ltvSubscriptionsCmd, "status", newEnum(ltvSubscriptionStatuses))
	ltvSubscriptionsCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvSubscriptionsCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	ltvCompaniesCmd.Flags().StringP("limit", "l", "", "Rows per page (max 500)")
	ltvCompaniesCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	ltvCmd.AddCommand(ltvSummaryCmd, ltvCustomersCmd, ltvBreakdownCmd, ltvCohortsCmd, ltvMrrCmd, ltvPredictCmd,
		ltvProductsCmd, ltvAbmCmd, ltvEngagementCmd, ltvSubscriptionsCmd, ltvCompaniesCmd)
	rootCmd.AddCommand(ltvCmd)
}
