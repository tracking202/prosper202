package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// The LTV writes: customer and company records, and what other systems push
// in (revenue, engagement events, subscriptions, products). Every one goes
// through ltvWrite, which refuses --staged before anything is read or sent:
// no /ltv write is in the server's stageable set, so a staged one would be
// answered 422 at best, and --staged promises the write is withheld
// (CLAUDE.md #14). A delete's --dry-run preview is a read, so it still runs
// under --staged, as every other delete's does.

// ltvWrite wraps a write command's RunE with the --staged refusal.
func ltvWrite(run func(cmd *cobra.Command, args []string) error) func(cmd *cobra.Command, args []string) error {
	return func(cmd *cobra.Command, args []string) error {
		if err := refuseLtvStaged(cmd); err != nil {
			return err
		}
		return run(cmd, args)
	}
}

func refuseLtvStaged(cmd *cobra.Command) error {
	if !api.StagedMode() {
		return nil
	}
	hint := "Drop --staged to perform the write directly."
	if cmd.Flags().Lookup("dry-run") != nil {
		if dryRun, _ := cmd.Flags().GetBool("dry-run"); dryRun {
			return nil
		}
		hint = "Drop --staged to perform it directly; --dry-run shows what it would remove first."
	}
	return validationError("--staged cannot apply to `%s`: LTV writes cannot be staged (the server records no proposal for any /ltv write), so nothing was sent", cmd.CommandPath()).
		WithHint("%s", hint)
}

// ltvAliasTypes is MysqlCustomerRepository::ALIAS_TYPES: the identifier
// kinds a customer reference or alias can be.
var ltvAliasTypes = []string{"email_md5", "email_sha256", "esp_id", "merchant_id", "subid", "custom"}

// ltvCRMFields maps each CRM flag to its API field. An empty value is a
// deliberate clear (the server stores NULL), so these flags allow it, and a
// flag that is not given sends nothing.
var ltvCRMFields = []struct{ flag, field, usage string }{
	{"first-name", "first_name", "First name"},
	{"last-name", "last_name", "Last name"},
	{"email", "email", "Email address"},
	{"phone", "phone", "Phone"},
	{"company", "company", "Company: attaches the customer to that company (created if new); \"\" detaches"},
	{"address-line1", "address_line1", "Address line 1"},
	{"address-line2", "address_line2", "Address line 2"},
	{"city", "city", "City"},
	{"region", "region", "Region or state"},
	{"postal-code", "postal_code", "Postal code"},
	{"country", "country", "Country"},
}

// ltvCRMKeys are the customer_crm keys the server applies when an ingest
// call creates the customer (MysqlCustomerRepository::insertCustomer); it
// ignores any other key without a word, so others are refused here.
var ltvCRMKeys = func() []string {
	keys := make([]string, 0, len(ltvCRMFields))
	for _, f := range ltvCRMFields {
		keys = append(keys, f.field)
	}
	return keys
}()

var (
	ltvDecimalPattern = regexp.MustCompile(`^-?(0|[1-9][0-9]{0,14})(\.[0-9]{1,6})?$`)
	ltvCurrencyRegexp = regexp.MustCompile(`^[A-Za-z]{3}$`)
	// MysqlCustomerRepository::RESERVED_IDEMPOTENCY_PREFIXES: keys the
	// server mints itself, refused from callers.
	ltvReservedKeyPrefixes = []string{"void:", "void-nc:", "reinstate:", "backfill:", "sub:"}
)

const ltvCustomerListHint = "`p202 ltv customers` lists customer ids (`--search` finds one by reference, email, company or name)."

// ltvNotFound adds the command that lists valid ids to an answer that the
// id names nothing: a 404, or the 422 the customer upsert answers for a
// customer_id the account does not have.
func ltvNotFound(err error, hint string) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && (apiErr.Status == 404 ||
		(apiErr.Status == 422 && strings.Contains(apiErr.Message, "not found for this account"))) {
		return withHint(err, "%s", hint)
	}
	return err
}

// ltvDecimal checks a money or metric flag and returns it as a JSON number,
// so it reaches the server exactly as written: the server reads these with
// is_numeric() and a float cast, which would take "1e3" as 1000 and an
// omitted check would let "abc" through as 0.
func ltvDecimal(cmd *cobra.Command, flag string, allowNegative bool) (json.Number, bool, error) {
	if !cmd.Flags().Changed(flag) {
		return "", false, nil
	}
	v, _ := cmd.Flags().GetString(flag)
	v = strings.TrimSpace(v)
	if !ltvDecimalPattern.MatchString(v) {
		return "", false, validationError("--%s must be a plain decimal number such as 49.99, got %q", flag, v).
			WithHint("Digits with an optional . and up to 6 decimals; no exponent, currency sign or thousands separator.")
	}
	if !allowNegative && strings.HasPrefix(v, "-") {
		return "", false, validationError("--%s must not be negative, got %s", flag, v)
	}
	return json.Number(v), true, nil
}

// ltvUnixTime checks a unix-seconds flag; the server casts it with (int), so
// "2026-01-01" would be stored as 2026.
func ltvUnixTime(cmd *cobra.Command, flag string) (int64, bool, error) {
	if !cmd.Flags().Changed(flag) {
		return 0, false, nil
	}
	v, _ := cmd.Flags().GetString(flag)
	n, err := strconv.ParseInt(v, 10, 64)
	if !unixTimePattern.MatchString(v) || err != nil {
		return 0, false, validationError("--%s must be a unix time in seconds, got %q", flag, v).
			WithHint("For example `date -d 2026-01-15 +%%s` prints one; omit the flag for now.")
	}
	return n, true, nil
}

// ltvWholeNumber checks a count flag (at least min); the server casts it
// with (int) and clamps, so a typo would become some other count.
func ltvWholeNumber(cmd *cobra.Command, flag string, min int) (int, bool, error) {
	if !cmd.Flags().Changed(flag) {
		return 0, false, nil
	}
	v, _ := cmd.Flags().GetString(flag)
	n, err := strconv.Atoi(v)
	if err != nil || n < min || strconv.Itoa(n) != v {
		return 0, false, validationError("--%s must be a whole number of at least %d, got %q", flag, min, v)
	}
	return n, true, nil
}

// ltvPositiveID checks a positive id flag.
func ltvPositiveID(cmd *cobra.Command, flag, hint string) (int64, bool, error) {
	if !cmd.Flags().Changed(flag) {
		return 0, false, nil
	}
	v, _ := cmd.Flags().GetString(flag)
	n, err := strconv.ParseInt(v, 10, 64)
	if !positiveIDPattern.MatchString(v) || err != nil {
		return 0, false, validationError("--%s must be a positive whole number, got %q", flag, v).WithHint("%s", hint)
	}
	return n, true, nil
}

// ltvPathID checks a positional id that becomes part of the request path.
func ltvPathID(raw, what, hint string) (string, error) {
	id, err := validateID(raw)
	if err != nil {
		return "", withHint(err, "%s", hint)
	}
	n, _ := strconv.Atoi(id)
	if n <= 0 {
		return "", validationError("invalid %s %q: must be a positive number", what, raw).WithHint("%s", hint)
	}
	return strconv.Itoa(n), nil
}

// ltvIdempotencyKey reads --idempotency-key for the LTV ledger writes,
// refusing the prefixes the server mints itself.
func ltvIdempotencyKey(cmd *cobra.Command) (string, bool, error) {
	if !cmd.Flags().Changed("idempotency-key") {
		return "", false, nil
	}
	key, _ := cmd.Flags().GetString("idempotency-key")
	key = strings.TrimSpace(key)
	for _, prefix := range ltvReservedKeyPrefixes {
		if strings.HasPrefix(key, prefix) {
			return "", false, validationError("--idempotency-key %q starts with %q, which the server reserves for the events it records itself", key, prefix).
				WithHint("Use your own system's id for the event, e.g. an order or charge id.")
		}
	}
	return key, true, nil
}

// addLtvIdentityFlags registers the flags that name the customer an ingest
// write belongs to: an internal id, or an external reference the server
// resolves (and creates a customer for when none matches).
func addLtvIdentityFlags(cmd *cobra.Command, withCRM bool) {
	cmd.Flags().String("customer-id", "", "The customer's internal id (`p202 ltv customers`)")
	cmd.Flags().String("customer-ref", "", "Your system's id for the customer; the server finds it, or creates a customer for it")
	cmd.Flags().String("customer-ref-type", "", "What kind of reference --customer-ref is (default custom)")
	enumFlag(cmd, "customer-ref-type", newEnum(ltvAliasTypes))
	if withCRM {
		cmd.Flags().String("customer-crm", "", `CRM fields as a JSON object, applied only when this call creates the customer, e.g. '{"email":"a@b.co","first_name":"Ada"}'`)
	}
}

// ltvIdentity adds the customer identity flags to body: exactly one of
// --customer-id and --customer-ref (the server prefers the id and ignores
// the ref without a word), --customer-ref-type only with a ref.
func ltvIdentity(cmd *cobra.Command, body map[string]interface{}) error {
	id, hasID, err := ltvPositiveID(cmd, "customer-id", ltvCustomerListHint)
	if err != nil {
		return err
	}
	ref, _ := cmd.Flags().GetString("customer-ref")
	hasRef := cmd.Flags().Changed("customer-ref")
	switch {
	case hasID && hasRef:
		return validationError("--customer-id and --customer-ref both name the customer; give one").
			WithHint("--customer-id for a customer the account has (%s), --customer-ref for your system's id.", "`p202 ltv customers`")
	case !hasID && !hasRef:
		return validationError("name the customer with --customer-id or --customer-ref").
			WithHint("--customer-ref <your id> finds the customer, or creates one; --customer-id takes the id from `p202 ltv customers`.")
	case hasID:
		body["customer_id"] = id
	default:
		body["customer_ref"] = ref
	}
	if cmd.Flags().Changed("customer-ref-type") {
		if !hasRef {
			return validationError("--customer-ref-type describes --customer-ref, which was not given").
				WithHint("Add --customer-ref, or drop --customer-ref-type.")
		}
		body["customer_ref_type"] = enumValue(cmd, "customer-ref-type")
	}
	if cmd.Flags().Lookup("customer-crm") != nil && cmd.Flags().Changed("customer-crm") {
		if hasID {
			// The server resolves an explicit id and never reads the CRM.
			return validationError("--customer-crm is applied only when --customer-ref creates a customer; with --customer-id it would be ignored").
				WithHint("Change an existing customer's record with `p202 ltv customer update <customer-id>`.")
		}
		raw, _ := cmd.Flags().GetString("customer-crm")
		crm, err := ltvCRMObject(raw)
		if err != nil {
			return err
		}
		body["customer_crm"] = crm
	}
	return nil
}

// ltvCRMObject parses --customer-crm: one JSON object of string values whose
// keys are CRM fields.
func ltvCRMObject(raw string) (map[string]interface{}, error) {
	var crm map[string]interface{}
	if err := decodeOneJSON([]byte(raw), &crm); err != nil || crm == nil {
		return nil, validationError("--customer-crm must be one JSON object, got %q", raw).
			WithHint(`For example --customer-crm '{"email":"ada@example.com","first_name":"Ada"}'.`)
	}
	keys := make([]string, 0, len(crm))
	for key := range crm {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	for _, key := range keys {
		value := crm[key]
		if !containsString(ltvCRMKeys, key) {
			return nil, validationError("--customer-crm has a field %q, which the server would ignore", key).
				WithHint("CRM fields: %s.", strings.Join(ltvCRMKeys, ", "))
		}
		if _, ok := value.(string); !ok {
			return nil, validationError("--customer-crm field %q must be a JSON string", key).
				WithHint(`Quote it, e.g. {"postal_code":"02134"}.`)
		}
	}
	return crm, nil
}

// addLtvCRMFlags registers the customer record flags shared by upsert and
// update: the CRM fields, custom field values and extra aliases.
func addLtvCRMFlags(cmd *cobra.Command) {
	names := make([]string, 0, len(ltvCRMFields))
	for _, f := range ltvCRMFields {
		cmd.Flags().String(f.flag, "", f.usage+` ("" clears it)`)
		names = append(names, f.flag)
	}
	allowEmpty(cmd, names...)
	cmd.Flags().StringArray("field", nil, "A custom field value, key=value (repeatable; key= with nothing after = clears it; `p202 ltv fields list` shows the keys)")
	cmd.Flags().StringArray("alias", nil, "Another identifier for this customer, type=value (repeatable; type is one of "+strings.Join(ltvAliasTypes, ", ")+")")
}

// ltvCRMBody collects the record flags the caller gave. A flag given empty
// is sent empty, which clears the field; a flag not given is not sent.
func ltvCRMBody(cmd *cobra.Command, body map[string]interface{}) error {
	for _, f := range ltvCRMFields {
		if cmd.Flags().Changed(f.flag) {
			v, _ := cmd.Flags().GetString(f.flag)
			body[f.field] = strings.TrimSpace(v)
		}
	}
	if fields, _ := cmd.Flags().GetStringArray("field"); len(fields) > 0 {
		values := map[string]interface{}{}
		for _, raw := range fields {
			key, value, ok := strings.Cut(raw, "=")
			key = strings.TrimSpace(key)
			if !ok || !ltvFieldKeyPattern.MatchString(key) {
				return validationError("--field %q is not key=value with a custom field key", raw).
					WithHint("For example --field plan=pro (key= clears it). %s", ltvFieldKeyHint)
			}
			if _, dup := values[key]; dup {
				return validationError("--field %s is given twice; the server would keep only one", key).
					WithHint("Give each custom field once.")
			}
			values[key] = value
		}
		body["custom_fields"] = values
	}
	if aliases, _ := cmd.Flags().GetStringArray("alias"); len(aliases) > 0 {
		list := make([]interface{}, 0, len(aliases))
		for _, raw := range aliases {
			typ, value, ok := strings.Cut(raw, "=")
			typ = strings.TrimSpace(typ)
			if !ok || !containsString(ltvAliasTypes, typ) || strings.TrimSpace(value) == "" {
				return validationError("--alias %q is not type=value", raw).
					WithHint("For example --alias esp_id=48213; type is one of %s.", strings.Join(ltvAliasTypes, ", "))
			}
			list = append(list, map[string]interface{}{"type": typ, "value": value})
		}
		body["aliases"] = list
	}
	return nil
}

// ltvAliasConflict adds the merge command to the server's answer when an
// alias already names another customer.
func ltvAliasConflict(err error, customerID string) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 422 && strings.Contains(apiErr.Message, "already belongs to customer") {
		return withHint(err, "Two records are the same person: `p202 ltv customer merge %s --from <the other customer id>` combines them (the message names it).", customerID)
	}
	return err
}

// ltvItemKeys are the line-item fields the server reads
// (MysqlCustomerRepository::insertLineItems and upsertProduct).
var ltvItemKeys = []string{"external_product_id", "sku", "name", "quantity", "unit_price", "amount", "price"}

// addLtvItemFlags registers the line-item flags of a revenue write.
func addLtvItemFlags(cmd *cobra.Command) {
	cmd.Flags().StringArray("item", nil, `A line item as a JSON object (repeatable), e.g. '{"sku":"PRO-1","name":"Pro plan","quantity":1,"unit_price":49}'`)
	cmd.Flags().String("items-file", "", "Line items as a JSON array in a file (- for stdin), instead of --item")
}

// ltvItems collects --item / --items-file into the items array, checking
// each item the way the server would only after a round trip — or would
// not at all: an unknown key is ignored there, and a quantity of "two" is
// read as 0.
func ltvItems(cmd *cobra.Command) ([]interface{}, bool, error) {
	inline, _ := cmd.Flags().GetStringArray("item")
	file, _ := cmd.Flags().GetString("items-file")
	if len(inline) > 0 && file != "" {
		return nil, false, validationError("--item and --items-file are exclusive").
			WithHint("Put every line item in the file, or give each with --item.")
	}
	var items []interface{}
	switch {
	case file != "":
		data, err := readLtvInput("--items-file", file)
		if err != nil {
			return nil, false, err
		}
		if err := decodeOneJSON(data, &items); err != nil || items == nil {
			return nil, false, validationError("%s is not one JSON array of line items", file).
				WithHint(`The file holds [{"sku":"PRO-1","quantity":1,"unit_price":49}, …].`)
		}
	case len(inline) > 0:
		for _, raw := range inline {
			var item interface{}
			if err := decodeOneJSON([]byte(raw), &item); err != nil {
				return nil, false, validationError("--item %q is not one JSON object", raw).
					WithHint(`For example --item '{"sku":"PRO-1","quantity":1,"unit_price":49}'.`)
			}
			items = append(items, item)
		}
	default:
		return nil, false, nil
	}
	if len(items) == 0 {
		return nil, false, validationError("no line items given").WithHint("Give at least one item, or drop the flag.")
	}
	for i, raw := range items {
		item, ok := raw.(map[string]interface{})
		if !ok {
			return nil, false, validationError("line item %d is not a JSON object", i+1)
		}
		keys := make([]string, 0, len(item))
		for k := range item {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			if !containsString(ltvItemKeys, k) {
				return nil, false, validationError("line item %d has a field %q, which the server would ignore", i+1, k).
					WithHint("Line item fields: %s.", strings.Join(ltvItemKeys, ", "))
			}
			switch k {
			case "external_product_id", "sku", "name":
				if _, ok := item[k].(string); !ok {
					return nil, false, validationError("line item %d: %s must be a JSON string", i+1, k)
				}
			default:
				n, ok := item[k].(json.Number)
				if !ok {
					return nil, false, validationError("line item %d: %s must be a JSON number", i+1, k).
						WithHint(`Unquoted, e.g. "quantity": 2.`)
				}
				f, err := n.Float64()
				if err != nil {
					return nil, false, validationError("line item %d: %s is not a usable number: %s", i+1, k, n)
				}
				if k == "quantity" && f <= 0 {
					return nil, false, validationError("line item %d: quantity must be greater than 0, got %s", i+1, n).
						WithHint("A refund's or chargeback's items are stored negative by the server; send them positive.")
				}
				if f < 0 {
					return nil, false, validationError("line item %d: %s must not be negative, got %s", i+1, k, n).
						WithHint("A refund's or chargeback's items are stored negative by the server; send them positive.")
				}
			}
		}
		ext, _ := item["external_product_id"].(string)
		sku, _ := item["sku"].(string)
		if strings.TrimSpace(ext) == "" && strings.TrimSpace(sku) == "" {
			return nil, false, validationError("line item %d names no product: give it a sku or an external_product_id", i+1).
				WithHint("The item's product is found, or created, by external_product_id (or sku); `p202 ltv products` lists them.")
		}
	}
	return items, true, nil
}

// readLtvInput reads a file, or stdin for "-" (refusing a terminal, which
// would wait for input nobody is going to type).
func readLtvInput(flag, path string) ([]byte, error) {
	if path == "-" {
		if isTerminal(os.Stdin) {
			return nil, validationError("%s - reads stdin, and stdin is a terminal", flag).
				WithHint("Pipe the JSON in, or pass a file path.")
		}
		data, err := io.ReadAll(io.LimitReader(os.Stdin, 1<<20))
		if err != nil {
			return nil, validationError("reading stdin for %s: %v", flag, err)
		}
		return data, nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, validationError("reading %s: %v", path, err).WithHint("Pass the path of a JSON file, or - for stdin.")
	}
	return data, nil
}

// ── Customer records ────────────────────────────────────────────────

var ltvCustomerCmd = &cobra.Command{
	Use:   "customer",
	Short: "Write customer records: upsert, update, merge, erase (anonymize), aliases (reads: `p202 ltv customers`)",
}

var ltvCustomerUpsertCmd = &cobra.Command{
	Use:   "upsert",
	Short: "Create or update a customer by --customer-ref (your id) or --customer-id",
	Long: "Finds the customer by --customer-id, or by --customer-ref (+ --customer-ref-type), creating one\n" +
		"when no customer has that reference, then applies the record flags in one transaction.\n" +
		"A record flag given empty clears that field; a flag not given leaves it as it is.\n\n" +
		"  p202 ltv customer upsert --customer-ref ORD-CUST-77 --email ada@example.com --first-name Ada\n" +
		"  p202 ltv customer upsert --customer-ref 9f1c… --customer-ref-type email_md5 --field plan=pro",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		if err := ltvIdentity(cmd, body); err != nil {
			return err
		}
		if err := ltvCRMBody(cmd, body); err != nil {
			return err
		}
		if _, byID := body["customer_id"]; byID && len(body) == 1 {
			return validationError("nothing to change on customer %v", body["customer_id"]).
				WithHint("Add a record flag (--email, --field key=value, …), or read it with `p202 ltv customers <id>`.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/customers", body)
		if err != nil {
			return ltvAliasConflict(ltvNotFound(err, ltvCustomerListHint), "<customer-id>")
		}
		render(data)
		return nil
	}),
}

var ltvCustomerUpdateCmd = &cobra.Command{
	Use:   "update <customer-id>",
	Short: "Change a customer's CRM fields, custom fields or aliases (a flag given empty clears that field)",
	Long: "Changes only what the flags name. A flag given empty clears that field (--phone \"\");\n" +
		"--field key= clears a custom field; a flag not given is not sent.\n\n" +
		"  p202 ltv customer update 42 --email ada@example.com --phone \"\" --field plan=pro",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "customer id", ltvCustomerListHint)
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if err := ltvCRMBody(cmd, body); err != nil {
			return err
		}
		if len(body) == 0 {
			return validationError("no fields to update").
				WithHint("Name what changes, e.g. --email ada@example.com, --phone \"\" to clear it, or --field plan=pro.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Patch("ltv/customers/"+id, body)
		if err != nil {
			return ltvAliasConflict(ltvNotFound(err, ltvCustomerListHint), id)
		}
		render(data)
		return nil
	}),
}

var ltvCustomerMergeCmd = &cobra.Command{
	Use:   "merge <target-customer-id> --from <source-customer-id>",
	Short: "Merge one customer into another: the source's aliases, revenue, subscriptions and fields move to the target (irreversible)",
	Long: "Moves everything of --from (aliases, revenue events, conversions, subscriptions, custom field\n" +
		"values, engagement) onto the target, recomputes the target's totals, and retires the source\n" +
		"(it points at the target from then on). The target's own custom field values win a conflict.\n" +
		"It cannot be undone, so it asks first; --force skips the question.\n\n" +
		"  p202 ltv customer merge 42 --from 57",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		target, err := ltvPathID(args[0], "customer id", ltvCustomerListHint)
		if err != nil {
			return err
		}
		source, hasSource, err := ltvPositiveID(cmd, "from", ltvCustomerListHint)
		if err != nil {
			return err
		}
		if !hasSource {
			return validationError("required flag --from is missing: the customer to merge into %s", target).
				WithHint("%s", ltvCustomerListHint)
		}
		if strconv.FormatInt(source, 10) == target {
			return validationError("--from %d is the target itself; a customer cannot be merged into itself", source).
				WithHint("%s", ltvCustomerListHint)
		}
		if force, _ := cmd.Flags().GetBool("force"); !force {
			ok, err := confirmAction(cmd, "Merge customer %d into customer %s? Its aliases, revenue, subscriptions and fields move to %s and customer %d is retired; this cannot be undone", source, target, target, source)
			if err != nil {
				return err
			}
			if !ok {
				fmt.Fprintln(os.Stderr, "Cancelled.")
				return nil
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/customers/"+target+"/merge", map[string]interface{}{"source_customer_id": source})
		if err != nil {
			return ltvNotFound(err, ltvCustomerListHint)
		}
		render(data)
		return nil
	}),
}

var ltvCustomerEraseCmd = &cobra.Command{
	Use:   "erase <customer-id>",
	Short: "Erase a customer's personal data (GDPR-style): CRM fields, aliases and field values go, revenue history stays",
	Long: "Anonymizes the customer: name, email, phone, company, address, aliases (and their identity-graph\n" +
		"signals), custom field values and personalization tokens are removed, the reference becomes\n" +
		"erased:<id> and the status anonymized. The customer row and its revenue events and subscriptions\n" +
		"are kept, so LTV totals do not change. Not reversible: it asks first (--force skips the\n" +
		"question), and --dry-run shows what would be removed and what kept.\n\n" +
		"  p202 ltv customer erase 42 --dry-run\n" +
		"  p202 ltv customer erase 42 --force",
	Args: deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/customers",
			noun:        "customer",
			plural:      "customers",
			verb:        "erase",
			past:        "erased",
			cascadeOne:  " (its personal data is removed for good; its revenue history is kept)",
			cascadeMany: " (their personal data is removed for good; their revenue history is kept)",
			idsHintText: "Comma-separate customer ids, e.g. --ids 12,13 (" + ltvCustomerListHint + ")",
			explain:     func(err error) error { return ltvNotFound(err, ltvCustomerListHint) },
		})
	}),
}

var ltvCustomerAliasCmd = &cobra.Command{
	Use:   "alias",
	Short: "Map another identifier (email hash, ESP id, merchant id, subid) to a customer, or remove one",
}

var ltvCustomerAliasAddCmd = &cobra.Command{
	Use:   "add <customer-id> --value <identifier>",
	Short: "Add an identifier that resolves to this customer from then on",
	Long: "Later events carrying this identifier resolve to the customer. An identifier that already\n" +
		"belongs to another customer is refused (the two records are one person: merge them).\n\n" +
		"  p202 ltv customer alias add 42 --type esp_id --value 48213",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "customer id", ltvCustomerListHint)
		if err != nil {
			return err
		}
		value, _ := cmd.Flags().GetString("value")
		if strings.TrimSpace(value) == "" {
			return validationError("required flag --value is missing: the identifier to map to customer %s", id).
				WithHint("For example --type esp_id --value 48213.")
		}
		body := map[string]interface{}{"value": value}
		if t := enumValue(cmd, "type"); t != "" {
			body["type"] = t
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/customers/"+id+"/aliases", body)
		if err != nil {
			return ltvAliasConflict(ltvNotFound(err, ltvCustomerListHint), id)
		}
		render(data)
		return nil
	}),
}

var ltvCustomerAliasRemoveCmd = &cobra.Command{
	Use:   "remove <customer-id> <alias-id>",
	Short: "Remove one identifier from a customer (the customer and its revenue stay)",
	Long: "Only the mapping goes: a later event carrying the identifier no longer resolves to this\n" +
		"customer. Alias ids are in `p202 ltv customers <customer-id>` (aliases[].alias_id).\n\n" +
		"  p202 ltv customer alias remove 42 7 --dry-run",
	Args: deleteArgsValidatorN(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "customer id", ltvCustomerListHint)
		if err != nil {
			return err
		}
		aliasHint := "Alias ids are in `p202 ltv customers " + id + "` (aliases[].alias_id)."
		return runBulkOrSingleDelete(cmd, args[1:], deleteSpec{
			endpoint:    "ltv/customers/" + id + "/aliases",
			noun:        "alias",
			plural:      "aliases",
			context:     " from customer " + id,
			idsHintText: "Comma-separate alias ids, e.g. --ids 3,4. " + aliasHint,
			explain:     func(err error) error { return ltvNotFound(err, aliasHint) },
		})
	}),
}

// ── Companies ───────────────────────────────────────────────────────

const ltvCompanyListHint = "`p202 ltv companies` lists company ids."

var ltvCompanyCmd = &cobra.Command{
	Use:   "company",
	Short: "Write company (ABM account) records: create, update, merge, delete (reads: `p202 ltv companies`)",
}

// ltvCompanyConflict names the next step for a duplicate name or domain.
func ltvCompanyConflict(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 409 {
		return withHint(err, "The message names the company that has it: change that one with `p202 ltv company update <id>`, or combine the two with `p202 ltv company merge <id> --from <other id>`.")
	}
	return ltvNotFound(err, ltvCompanyListHint)
}

var ltvCompanyCreateCmd = &cobra.Command{
	Use:   "create --name <name>",
	Short: "Create a company; --domain makes customers with that email domain join it automatically",
	Args:  cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		name, _ := cmd.Flags().GetString("name")
		if strings.TrimSpace(name) == "" {
			return validationError("required flag --name is missing").WithHint("For example --name \"Acme Corp\" --domain acme.com.")
		}
		body := map[string]interface{}{"name": name}
		if cmd.Flags().Changed("domain") {
			domain, _ := cmd.Flags().GetString("domain")
			body["domain"] = domain
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/companies", body)
		if err != nil {
			return ltvCompanyConflict(err)
		}
		render(data)
		return nil
	}),
}

var ltvCompanyUpdateCmd = &cobra.Command{
	Use:   "update <company-id>",
	Short: "Rename a company or change its auto-attach domain (--domain \"\" clears it)",
	Args:  cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "company id", ltvCompanyListHint)
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("name") {
			name, _ := cmd.Flags().GetString("name")
			body["name"] = name
		}
		if cmd.Flags().Changed("domain") {
			domain, _ := cmd.Flags().GetString("domain")
			body["domain"] = strings.TrimSpace(domain)
		}
		if len(body) == 0 {
			return validationError("no fields to update").WithHint("Give --name, --domain, or both (--domain \"\" clears the domain).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Patch("ltv/companies/"+id, body)
		if err != nil {
			return ltvCompanyConflict(err)
		}
		render(data)
		return nil
	}),
}

var ltvCompanyMergeCmd = &cobra.Command{
	Use:   "merge <target-company-id> --from <source-company-id>",
	Short: "Merge one company into another: its customers move to the target and it is deleted (irreversible)",
	Long: "Moves every customer of --from onto the target, gives the target --from's domain when it has\n" +
		"none, and deletes --from. It cannot be undone, so it asks first; --force skips the question.\n\n" +
		"  p202 ltv company merge 3 --from 9",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		target, err := ltvPathID(args[0], "company id", ltvCompanyListHint)
		if err != nil {
			return err
		}
		source, hasSource, err := ltvPositiveID(cmd, "from", ltvCompanyListHint)
		if err != nil {
			return err
		}
		if !hasSource {
			return validationError("required flag --from is missing: the company to merge into %s", target).
				WithHint("%s", ltvCompanyListHint)
		}
		if strconv.FormatInt(source, 10) == target {
			return validationError("--from %d is the target itself; a company cannot be merged into itself", source).
				WithHint("%s", ltvCompanyListHint)
		}
		if force, _ := cmd.Flags().GetBool("force"); !force {
			ok, err := confirmAction(cmd, "Merge company %d into company %s? Its customers move to %s and company %d is deleted; this cannot be undone", source, target, target, source)
			if err != nil {
				return err
			}
			if !ok {
				fmt.Fprintln(os.Stderr, "Cancelled.")
				return nil
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/companies/"+target+"/merge", map[string]interface{}{"source_company_id": source})
		if err != nil {
			return ltvNotFound(err, ltvCompanyListHint)
		}
		render(data)
		return nil
	}),
}

var ltvCompanyDeleteCmd = &cobra.Command{
	Use:   "delete <company-id>",
	Short: "Delete a company with no customers attached (one with customers is merged instead)",
	Long: "Refused while customers are attached: merge it into another company with\n" +
		"`p202 ltv company merge <target> --from <id>`. --dry-run shows the company and, under\n" +
		"refused, why the delete would be turned down.",
	Args: deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/companies",
			noun:        "company",
			plural:      "companies",
			idsHintText: "Comma-separate company ids, e.g. --ids 3,4 (" + ltvCompanyListHint + ")",
			explain: func(err error) error {
				var apiErr *api.APIError
				if errors.As(err, &apiErr) && apiErr.Status == 422 && strings.Contains(apiErr.Message, "attached customer") {
					return withHint(err, "Move its customers with `p202 ltv company merge <target-company-id> --from <this id>`, which also deletes it.")
				}
				return ltvNotFound(err, ltvCompanyListHint)
			},
		})
	}),
}

// ── Ingest: revenue, engagement events, subscriptions, products ─────

var ltvRevenueEventTypes = []string{"purchase", "one_time", "refund", "chargeback", "adjustment"}

var ltvRevenueCmd = &cobra.Command{
	Use:   "revenue",
	Short: "Record clickless revenue (an ESP order, a membership charge) on a customer's ledger",
}

var ltvRevenueRecordCmd = &cobra.Command{
	Use:   "record --amount <n> (--customer-ref <id> | --customer-id <id>)",
	Short: "Record a purchase, one-time charge, refund, chargeback or adjustment on a customer",
	Long: "Appends one event to the customer's revenue ledger (source api) and updates their totals.\n" +
		"Refunds and chargebacks are sent positive and stored negative; a negative amount is only an\n" +
		"adjustment. Renewals go through `p202 ltv subscription event`. --idempotency-key makes a retry\n" +
		"safe: the same key again records nothing and answers with the first event (duplicate: true).\n\n" +
		"  p202 ltv revenue record --customer-ref CUST-77 --amount 49.00 --idempotency-key ORD-1001 \\\n" +
		"      --item '{\"sku\":\"PRO-1\",\"quantity\":1,\"unit_price\":49}'",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		amount, hasAmount, err := ltvDecimal(cmd, "amount", true)
		if err != nil {
			return err
		}
		if !hasAmount {
			return validationError("required flag --amount is missing").
				WithHint("The event's money in the account currency, e.g. --amount 49.00.")
		}
		eventType := enumValue(cmd, "event-type")
		if eventType == "" {
			eventType = "purchase"
		}
		if strings.HasPrefix(string(amount), "-") && (eventType == "purchase" || eventType == "one_time") {
			return validationError("--amount %s is negative, which a %s cannot be", amount, eventType).
				WithHint("Send money back as --event-type refund or chargeback (with a positive amount), or a correction as --event-type adjustment.")
		}
		body["amount"] = amount
		if cmd.Flags().Changed("event-type") {
			body["event_type"] = eventType
		}
		if err := ltvIdentity(cmd, body); err != nil {
			return err
		}
		if cmd.Flags().Changed("currency") {
			cur, _ := cmd.Flags().GetString("currency")
			if !ltvCurrencyRegexp.MatchString(cur) {
				return validationError("--currency must be a 3-letter code such as USD, got %q", cur).
					WithHint("It must be the account currency; omit it to use that.")
			}
			body["currency"] = strings.ToUpper(cur)
		}
		if t, ok, err := ltvUnixTime(cmd, "occurred-at"); err != nil {
			return err
		} else if ok {
			body["occurred_at"] = t
		}
		if items, ok, err := ltvItems(cmd); err != nil {
			return err
		} else if ok {
			body["items"] = items
		}
		if key, ok, err := ltvIdempotencyKey(cmd); err != nil {
			return err
		} else if ok {
			body["idempotency_key"] = key
		}
		for flag, field := range map[string]string{"external-ref": "external_ref", "transaction-id": "transaction_id"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				body[field] = v
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/revenue", body)
		if err != nil {
			return ltvNotFound(err, ltvCustomerListHint)
		}
		render(data)
		var resp struct {
			Data struct {
				EventID   json.Number `json:"event_id"`
				Duplicate bool        `json:"duplicate"`
			} `json:"data"`
		}
		if json.Unmarshal(data, &resp) == nil && resp.Data.Duplicate {
			fmt.Fprintf(cmd.ErrOrStderr(), "Note: this --idempotency-key was already recorded; the server answered with event %s and recorded nothing new.\n", resp.Data.EventID)
		}
		return nil
	}),
}

var ltvEngagementEventCmd = &cobra.Command{
	Use:   "engagement-event",
	Short: "Record server-side ABM engagement events on a customer (not `p202 event send`, which reports a click's web events to its goals)",
}

var ltvEngagementEventRecordCmd = &cobra.Command{
	Use:   "record --event <name> (--customer-ref <id> | --customer-id <id>)",
	Short: "Record an engagement event (demo_requested, pricing_viewed, …) on a customer for the ABM and engagement views",
	Long: "Adds one event to the customer's engagement history (`p202 ltv engagement <id>`, `p202 ltv abm`).\n" +
		"It is not a conversion and pays nothing; a click's web events for its goals go through\n" +
		"`p202 event send`.\n\n" +
		"  p202 ltv engagement-event record --customer-ref CUST-77 --event demo_requested",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		name, _ := cmd.Flags().GetString("event")
		if strings.TrimSpace(name) == "" {
			return validationError("required flag --event is missing").
				WithHint("The event's name, e.g. --event demo_requested.")
		}
		body := map[string]interface{}{"event": strings.TrimSpace(name)}
		if err := ltvIdentity(cmd, body); err != nil {
			return err
		}
		if v, ok, err := ltvDecimal(cmd, "value", true); err != nil {
			return err
		} else if ok {
			body["value"] = v
		}
		if t, ok, err := ltvUnixTime(cmd, "occurred-at"); err != nil {
			return err
		} else if ok {
			body["occurred_at"] = t
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/events", body)
		if err != nil {
			return ltvNotFound(err, ltvCustomerListHint)
		}
		render(data)
		return nil
	}),
}

var (
	ltvBillingIntervals       = []string{"day", "week", "month", "year"}
	ltvSubscriptionEventTypes = []string{"renewal", "cancel", "refund"}
)

var ltvSubscriptionCmd = &cobra.Command{
	Use:   "subscription",
	Short: "Create or update a subscription, and record its renewals, refunds and cancellation (reads: `p202 ltv subscriptions`)",
}

var ltvSubscriptionUpsertCmd = &cobra.Command{
	Use:   "upsert --external-sub-id <id> --amount <n> (--customer-ref <id> | --customer-id <id>)",
	Short: "Create or update a subscription keyed by your billing system's id; MRR follows from amount and interval",
	Long: "Keyed by --external-sub-id: the same id again updates it (and may move it to another customer).\n" +
		"MRR is the amount normalized to a month (a trial carries none).\n\n" +
		"  p202 ltv subscription upsert --external-sub-id sub_123 --customer-ref CUST-77 --amount 29 --interval month",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		subID, _ := cmd.Flags().GetString("external-sub-id")
		if strings.TrimSpace(subID) == "" {
			return validationError("required flag --external-sub-id is missing").
				WithHint("Your billing system's id for the subscription, e.g. --external-sub-id sub_123.")
		}
		amount, hasAmount, err := ltvDecimal(cmd, "amount", false)
		if err != nil {
			return err
		}
		if !hasAmount {
			return validationError("required flag --amount is missing").
				WithHint("What one billing interval charges, e.g. --amount 29 --interval month.")
		}
		body := map[string]interface{}{"external_sub_id": strings.TrimSpace(subID), "amount": amount}
		if err := ltvIdentity(cmd, body); err != nil {
			return err
		}
		if cmd.Flags().Changed("plan-name") {
			v, _ := cmd.Flags().GetString("plan-name")
			body["plan_name"] = v
		}
		if cmd.Flags().Changed("currency") {
			cur, _ := cmd.Flags().GetString("currency")
			if !ltvCurrencyRegexp.MatchString(cur) {
				return validationError("--currency must be a 3-letter code such as USD, got %q", cur).
					WithHint("It must be the account currency; omit it to use that.")
			}
			body["currency"] = strings.ToUpper(cur)
		}
		if v := enumValue(cmd, "interval"); v != "" {
			body["billing_interval"] = v
		}
		if v := enumValue(cmd, "status"); v != "" {
			body["status"] = v
		}
		if n, ok, err := ltvWholeNumber(cmd, "interval-count", 1); err != nil {
			return err
		} else if ok {
			body["billing_interval_count"] = n
		}
		if n, ok, err := ltvWholeNumber(cmd, "grace-days", 0); err != nil {
			return err
		} else if ok {
			body["grace_days"] = n
		}
		for flag, field := range map[string]string{"started-at": "started_at", "period-start": "current_period_start", "period-end": "current_period_end"} {
			if t, ok, err := ltvUnixTime(cmd, flag); err != nil {
				return err
			} else if ok {
				body[field] = t
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/subscriptions", body)
		if err != nil {
			return ltvNotFound(err, ltvCustomerListHint)
		}
		render(data)
		return nil
	}),
}

// ltvMoneyEventOnly lists the flags that mean nothing for a cancel.
var ltvMoneyEventOnly = []string{"amount", "idempotency-key", "transaction-id", "currency"}

var ltvSubscriptionEventCmd = &cobra.Command{
	Use:   "event <external-sub-id> --type renewal|cancel|refund",
	Short: "Record a subscription's renewal (extends it, adds revenue), refund (negative revenue) or cancellation",
	Long: "renewal adds a renewal to the ledger (the subscription's amount unless --amount), extends the\n" +
		"paid-through period and reactivates it; refund adds negative revenue; cancel marks it canceled\n" +
		"and moves no money. --idempotency-key (or --transaction-id) makes a retried renewal or refund\n" +
		"safe; a repeat answers changed: false.\n\n" +
		"  p202 ltv subscription event sub_123 --type renewal --transaction-id ch_889",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		ref := strings.TrimSpace(args[0])
		if ref == "" {
			return validationError("the external subscription id is empty").
				WithHint("`p202 ltv subscriptions` lists them (external_sub_id).")
		}
		eventType := enumValue(cmd, "type")
		if eventType == "" {
			return validationError("required flag --type is missing: renewal, cancel or refund").
				WithHint("For example --type renewal.")
		}
		body := map[string]interface{}{"event_type": eventType}
		if eventType == "cancel" {
			for _, f := range append(append([]string{}, ltvMoneyEventOnly...), "period-end") {
				if cmd.Flags().Changed(f) {
					return validationError("--%s does nothing for a cancel, which moves no money", f).
						WithHint("Drop --%s, or record a renewal or refund instead.", f)
				}
			}
		}
		if eventType != "renewal" && cmd.Flags().Changed("period-end") {
			return validationError("--period-end applies to a renewal only").WithHint("Drop it for a %s.", eventType)
		}
		if v, ok, err := ltvDecimal(cmd, "amount", false); err != nil {
			return err
		} else if ok {
			body["amount"] = v
		}
		if cmd.Flags().Changed("currency") {
			cur, _ := cmd.Flags().GetString("currency")
			if !ltvCurrencyRegexp.MatchString(cur) {
				return validationError("--currency must be a 3-letter code such as USD, got %q", cur).
					WithHint("It must be the account currency; omit it to use that.")
			}
			body["currency"] = strings.ToUpper(cur)
		}
		if key, ok, err := ltvIdempotencyKey(cmd); err != nil {
			return err
		} else if ok {
			body["idempotency_key"] = key
		}
		if cmd.Flags().Changed("transaction-id") {
			v, _ := cmd.Flags().GetString("transaction-id")
			body["transaction_id"] = v
		}
		for flag, field := range map[string]string{"occurred-at": "occurred_at", "period-end": "current_period_end"} {
			if t, ok, err := ltvUnixTime(cmd, flag); err != nil {
				return err
			} else if ok {
				body[field] = t
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		// Escaped as one path segment; the server decodes it.
		data, err := c.Post("ltv/subscriptions/"+url.PathEscape(ref)+"/events", body)
		if err != nil {
			return ltvNotFound(err, "`p202 ltv subscriptions` lists the subscriptions (external_sub_id); `p202 ltv subscription upsert` creates one.")
		}
		render(data)
		var resp struct {
			Data struct {
				Changed *bool `json:"changed"`
			} `json:"data"`
		}
		if json.Unmarshal(data, &resp) == nil && resp.Data.Changed != nil && !*resp.Data.Changed {
			fmt.Fprintln(cmd.ErrOrStderr(), "Note: nothing changed — this event was already recorded (the same key or transaction id), or the subscription was already canceled.")
		}
		return nil
	}),
}

var ltvProductCmd = &cobra.Command{
	Use:   "product",
	Short: "Catalog products: upsert by your product id, update or delete by id (reads: `p202 ltv products`)",
}

var ltvProductUpsertCmd = &cobra.Command{
	Use:   "upsert (--external-product-id <id> | --sku <sku>)",
	Short: "Create or update a product keyed by your product id (or sku); revenue line items name it the same way",
	Long: "Keyed by --external-product-id, or by --sku when there is none. A field not given keeps its\n" +
		"stored value.\n\n" +
		"  p202 ltv product upsert --sku PRO-1 --name \"Pro plan\" --price 49",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		for flag, field := range map[string]string{"external-product-id": "external_product_id", "sku": "sku", "name": "name"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				body[field] = strings.TrimSpace(v)
			}
		}
		if body["external_product_id"] == nil && body["sku"] == nil {
			return validationError("name the product with --external-product-id or --sku").
				WithHint("The product is found, or created, by that id; `p202 ltv products` lists them.")
		}
		if v, ok, err := ltvDecimal(cmd, "price", false); err != nil {
			return err
		} else if ok {
			body["price"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/products", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	}),
}

const ltvProductListHint = "`p202 ltv products` lists the product ids."

var ltvProductUpdateCmd = &cobra.Command{
	Use:   "update <product-id>",
	Short: "Edit a catalog product's name, sku or list price by its id (the LTV Products tab's Edit)",
	Long: "Changes only the fields given. The name cannot be blank; --sku \"\" and --price \"\" clear them.\n" +
		"Past order line items keep the name they were sold under. The external product id is the\n" +
		"product's key and does not change (`ltv product upsert` writes by it).\n\n" +
		"  p202 ltv product update 12 --name \"Pro plan (annual)\" --price 490\n" +
		"  p202 ltv product update 12 --sku \"\"",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "product id", ltvProductListHint)
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("name") {
			v, _ := cmd.Flags().GetString("name")
			v = strings.TrimSpace(v)
			if v == "" {
				return validationError("--name cannot be blank").WithHint("Give the product's new name, e.g. --name \"Pro plan\".")
			}
			body["name"] = v
		}
		if cmd.Flags().Changed("sku") {
			v, _ := cmd.Flags().GetString("sku")
			body["sku"] = strings.TrimSpace(v)
		}
		if cmd.Flags().Changed("price") {
			v, _ := cmd.Flags().GetString("price")
			if strings.TrimSpace(v) == "" {
				body["price"] = nil
			} else if p, ok, err := ltvDecimal(cmd, "price", false); err != nil {
				return err
			} else if ok {
				body["price"] = p
			}
		}
		if len(body) == 0 {
			return validationError("no fields to update").
				WithHint("Give --name, --sku or --price (--sku \"\" and --price \"\" clear them).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Patch("ltv/products/"+id, body)
		if err != nil {
			return ltvNotFound(err, ltvProductListHint)
		}
		render(data)
		return nil
	}),
}

// ltvProductDeleteRefused explains the server's refusal to delete a product
// that order line items name.
func ltvProductDeleteRefused(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 409 {
		if strings.Contains(apiErr.Message, "cannot be deleted") {
			return withHint(err, "A product sold on an order stays in the catalog (its line items name it). Rename it with `p202 ltv product update <id> --name ...` instead.")
		}
		return withHint(err, "Nothing was deleted. Run the same delete again; `--dry-run` says first whether it would be refused.")
	}
	return ltvNotFound(err, ltvProductListHint)
}

var ltvProductDeleteCmd = &cobra.Command{
	Use:   "delete <product-id>",
	Short: "Delete a catalog product no order line item names (refused while one does)",
	Long: "--dry-run shows the product and, as refused, why the delete would be refused.\n\n" +
		"  p202 ltv product delete 12 --dry-run",
	Args: deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/products",
			noun:        "product",
			plural:      "products",
			idsHintText: "Comma-separate product ids, e.g. --ids 3,4 (" + ltvProductListHint + ")",
			explain:     ltvProductDeleteRefused,
		})
	}),
}

var ltvNextOfferCmd = &cobra.Command{
	Use:   "next-offer",
	Short: "Report that you delivered a customer's next-offer recommendation (read it with `p202 ltv engagement`)",
}

var ltvNextOfferImpressionCmd = &cobra.Command{
	Use:   "impression <customer-id>",
	Short: "Log that the customer was shown an offer (an email send, an external CRM), for the fatigue rule",
	Long: "Records that the offer reached the customer through a channel the tracker cannot see. Without\n" +
		"--campaign-id the customer's current recommendation is recorded (`p202 ltv engagement <id>`\n" +
		"shows it); when there is none, pass the campaign you delivered.\n\n" +
		"  p202 ltv next-offer impression 42 --campaign-id 7",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "customer id", ltvCustomerListHint)
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if campaign, ok, err := ltvPositiveID(cmd, "campaign-id", "`p202 campaign list` lists campaign ids."); err != nil {
			return err
		} else if ok {
			body["campaign_id"] = campaign
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/customers/"+id+"/next-offer/impression", body)
		if err != nil {
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 422 && strings.Contains(apiErr.Message, "No current recommendation") {
				return withHint(err, "Pass the offer you delivered with --campaign-id (`p202 campaign list`); `p202 ltv engagement %s` shows whether one is recommended.", id)
			}
			return ltvNotFound(err, ltvCustomerListHint)
		}
		render(data)
		return nil
	}),
}

func init() {
	addLtvIdentityFlags(ltvCustomerUpsertCmd, false)
	addLtvCRMFlags(ltvCustomerUpsertCmd)
	addLtvCRMFlags(ltvCustomerUpdateCmd)

	ltvCustomerMergeCmd.Flags().String("from", "", "The customer merged INTO the target and retired (required)")
	ltvCustomerMergeCmd.Flags().BoolP("force", "f", false, "Skip the confirmation prompt")

	registerDeleteFlags(ltvCustomerEraseCmd, "customer")

	ltvCustomerAliasAddCmd.Flags().String("type", "", "What kind of identifier (default custom)")
	enumFlag(ltvCustomerAliasAddCmd, "type", newEnum(ltvAliasTypes))
	ltvCustomerAliasAddCmd.Flags().String("value", "", "The identifier (required); email_md5/email_sha256 take the hex digest")
	registerDeleteFlags(ltvCustomerAliasRemoveCmd, "alias")
	ltvCustomerAliasCmd.AddCommand(ltvCustomerAliasAddCmd, ltvCustomerAliasRemoveCmd)

	ltvCustomerCmd.AddCommand(ltvCustomerUpsertCmd, ltvCustomerUpdateCmd, ltvCustomerMergeCmd, ltvCustomerEraseCmd, ltvCustomerAliasCmd)

	ltvCompanyCreateCmd.Flags().String("name", "", "Company name (required; unique in the account)")
	ltvCompanyCreateCmd.Flags().String("domain", "", "Email domain, e.g. acme.com: customers with it join the company automatically")
	ltvCompanyUpdateCmd.Flags().String("name", "", "New name")
	ltvCompanyUpdateCmd.Flags().String("domain", "", `New auto-attach domain ("" clears it)`)
	allowEmpty(ltvCompanyUpdateCmd, "domain")
	ltvCompanyMergeCmd.Flags().String("from", "", "The company merged INTO the target and deleted (required)")
	ltvCompanyMergeCmd.Flags().BoolP("force", "f", false, "Skip the confirmation prompt")
	registerDeleteFlags(ltvCompanyDeleteCmd, "company")
	ltvCompanyCmd.AddCommand(ltvCompanyCreateCmd, ltvCompanyUpdateCmd, ltvCompanyMergeCmd, ltvCompanyDeleteCmd)

	ltvRevenueRecordCmd.Flags().String("amount", "", "The amount, in the account currency (required)")
	ltvRevenueRecordCmd.Flags().String("event-type", "", "What it is (default purchase)")
	enumFlag(ltvRevenueRecordCmd, "event-type", newEnum(ltvRevenueEventTypes))
	addLtvIdentityFlags(ltvRevenueRecordCmd, true)
	ltvRevenueRecordCmd.Flags().String("currency", "", "Currency code; must be the account currency (default it)")
	ltvRevenueRecordCmd.Flags().String("occurred-at", "", "When it happened, unix seconds (default now)")
	addLtvItemFlags(ltvRevenueRecordCmd)
	ltvRevenueRecordCmd.Flags().String("idempotency-key", "", "Your id for this event: the same key again records nothing and answers with the first event")
	ltvRevenueRecordCmd.Flags().String("external-ref", "", "Your reference for the event (an order number)")
	ltvRevenueRecordCmd.Flags().String("transaction-id", "", "The payment's transaction id")
	ltvRevenueCmd.AddCommand(ltvRevenueRecordCmd)

	ltvEngagementEventRecordCmd.Flags().String("event", "", "The event's name, e.g. demo_requested (required)")
	ltvEngagementEventRecordCmd.Flags().String("value", "", "A number that goes with it: seconds, a percentage, a score")
	ltvEngagementEventRecordCmd.Flags().String("occurred-at", "", "When it happened, unix seconds (default now)")
	addLtvIdentityFlags(ltvEngagementEventRecordCmd, true)
	ltvEngagementEventCmd.AddCommand(ltvEngagementEventRecordCmd)

	ltvSubscriptionUpsertCmd.Flags().String("external-sub-id", "", "Your billing system's subscription id (required)")
	ltvSubscriptionUpsertCmd.Flags().String("amount", "", "What one billing interval charges (required)")
	addLtvIdentityFlags(ltvSubscriptionUpsertCmd, true)
	ltvSubscriptionUpsertCmd.Flags().String("plan-name", "", "Plan name")
	ltvSubscriptionUpsertCmd.Flags().String("currency", "", "Currency code; must be the account currency (default it)")
	ltvSubscriptionUpsertCmd.Flags().String("interval", "", "Billing interval (default month)")
	enumFlag(ltvSubscriptionUpsertCmd, "interval", newEnum(ltvBillingIntervals))
	ltvSubscriptionUpsertCmd.Flags().String("interval-count", "", "Intervals per charge, e.g. 3 with --interval month for quarterly (default 1)")
	ltvSubscriptionUpsertCmd.Flags().String("status", "", "Lifecycle status (default active)")
	enumFlag(ltvSubscriptionUpsertCmd, "status", newEnum(ltvSubscriptionStatuses))
	ltvSubscriptionUpsertCmd.Flags().String("started-at", "", "When it started, unix seconds (default now)")
	ltvSubscriptionUpsertCmd.Flags().String("period-start", "", "Current period start, unix seconds (default --started-at)")
	ltvSubscriptionUpsertCmd.Flags().String("period-end", "", "Current period end, unix seconds (default one interval after the start)")
	ltvSubscriptionUpsertCmd.Flags().String("grace-days", "", "Days past the period end before it counts as past_due (default 3)")

	ltvSubscriptionEventCmd.Flags().String("type", "", "What happened (required)")
	enumFlag(ltvSubscriptionEventCmd, "type", newEnum(ltvSubscriptionEventTypes))
	ltvSubscriptionEventCmd.Flags().String("amount", "", "Renewal or refund amount (default the subscription's amount)")
	ltvSubscriptionEventCmd.Flags().String("currency", "", "Currency code; must be the account currency (default it)")
	ltvSubscriptionEventCmd.Flags().String("occurred-at", "", "When it happened, unix seconds (default now)")
	ltvSubscriptionEventCmd.Flags().String("idempotency-key", "", "Your id for this renewal or refund: the same key again records nothing")
	ltvSubscriptionEventCmd.Flags().String("transaction-id", "", "The payment's transaction id (also makes a retry safe when there is no key)")
	ltvSubscriptionEventCmd.Flags().String("period-end", "", "Renewal only: the new paid-through time, unix seconds (default one interval on)")
	ltvSubscriptionCmd.AddCommand(ltvSubscriptionUpsertCmd, ltvSubscriptionEventCmd)

	ltvProductUpsertCmd.Flags().String("external-product-id", "", "Your product id (the key)")
	ltvProductUpsertCmd.Flags().String("sku", "", "SKU (the key when there is no --external-product-id)")
	ltvProductUpsertCmd.Flags().String("name", "", "Product name")
	ltvProductUpsertCmd.Flags().String("price", "", "List price in the account currency")
	ltvProductUpdateCmd.Flags().String("name", "", "New product name (cannot be blank)")
	ltvProductUpdateCmd.Flags().String("sku", "", `New SKU ("" clears it)`)
	ltvProductUpdateCmd.Flags().String("price", "", `New list price in the account currency ("" clears it)`)
	allowEmpty(ltvProductUpdateCmd, "sku", "price")
	registerDeleteFlags(ltvProductDeleteCmd, "product")
	ltvProductCmd.AddCommand(ltvProductUpsertCmd, ltvProductUpdateCmd, ltvProductDeleteCmd)

	ltvNextOfferImpressionCmd.Flags().String("campaign-id", "", "The campaign you delivered (default the current recommendation)")
	ltvNextOfferCmd.AddCommand(ltvNextOfferImpressionCmd)

	ltvCmd.AddCommand(ltvCustomerCmd, ltvCompanyCmd, ltvRevenueCmd, ltvEngagementEventCmd, ltvSubscriptionCmd, ltvProductCmd, ltvNextOfferCmd)
}
