package cmd

import (
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// The LTV account settings: customer custom-field definitions, outbound
// webhooks and inbound integration records. The writes go through ltvWrite
// (ltv_write.go), which refuses --staged before anything is sent.

// ltvFieldTypes is MysqlCustomerFieldRepository::FIELD_TYPES.
var ltvFieldTypes = []string{"text", "number", "date", "boolean", "select", "email", "url"}

// ltvWebhookEvents is MysqlWebhookRepository::KNOWN_EVENTS, plus the "*"
// wildcard (every event, including ones later versions add). The server
// accepts any well-formed name, so a typo would subscribe to an event that
// never fires; the CLI takes only the names it knows, and "*".
var ltvWebhookEvents = []string{"customer.updated", "revenue.recorded", "subscription.changed", "conversion.recorded", "engagement.recorded", "*"}

// ltvProviderPattern is MysqlIntegrationRepository::create's provider rule.
var ltvProviderPattern = regexp.MustCompile(`^[a-z0-9_-]{1,50}$`)

const ltvFieldListHint = "`p202 ltv fields list` lists the field ids and keys."

// ── Custom fields ───────────────────────────────────────────────────

var ltvFieldsCmd = &cobra.Command{
	Use:   "fields",
	Short: "Customer custom-field definitions: list, create, update, delete",
}

var ltvFieldsListCmd = &cobra.Command{
	Use:   "list",
	Short: "List the account's custom fields (key, type, options, required)",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return ltvGet("ltv/fields", nil)
	},
}

// ltvFieldOptions collects --option for a select field; the server ignores
// options on any other type at create time, so they are refused there.
func ltvFieldOptions(cmd *cobra.Command) ([]string, bool, error) {
	if !cmd.Flags().Changed("option") {
		return nil, false, nil
	}
	options, _ := cmd.Flags().GetStringArray("option")
	seen := map[string]bool{}
	for _, o := range options {
		if strings.TrimSpace(o) == "" {
			return nil, false, validationError("--option was given an empty value").
				WithHint("Each --option is one choice, e.g. --option gold --option silver.")
		}
		if seen[o] {
			return nil, false, validationError("--option %q is given twice", o).WithHint("Give each choice once.")
		}
		seen[o] = true
	}
	return options, true, nil
}

var ltvFieldsCreateCmd = &cobra.Command{
	Use:   "create --key <key>",
	Short: "Define a custom field customers can carry (filter by it with --cf)",
	Long: "Defines a field: its key (how writes and --cf filters name it), label, type, and for a select\n" +
		"field its choices. The key and type cannot change later.\n\n" +
		"  p202 ltv fields create --key plan --type select --option free --option pro\n" +
		"  p202 ltv fields create --key score --type number --label \"Lead score\"",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		key, _ := cmd.Flags().GetString("key")
		if key == "" {
			return validationError("required flag --key is missing").
				WithHint("1-64 lowercase letters, digits and _, e.g. --key plan.")
		}
		if !ltvFieldKeyPattern.MatchString(key) {
			return validationError("--key %q must be 1-64 of lowercase a-z, 0-9 and _", key).
				WithHint("For example --key lead_score.")
		}
		body := map[string]interface{}{"field_key": key}
		fieldType := enumValue(cmd, "type")
		if fieldType != "" {
			body["field_type"] = fieldType
		}
		options, hasOptions, err := ltvFieldOptions(cmd)
		if err != nil {
			return err
		}
		switch {
		case fieldType == "select" && !hasOptions:
			return validationError("a select field needs its choices").
				WithHint("Add one --option per choice, e.g. --option free --option pro.")
		case fieldType != "select" && hasOptions:
			shown := fieldType
			if shown == "" {
				shown = "text (the default)"
			}
			return validationError("--option applies to a select field; this one is %s", shown).
				WithHint("Add --type select, or drop --option.")
		case hasOptions:
			body["options"] = options
		}
		if cmd.Flags().Changed("label") {
			v, _ := cmd.Flags().GetString("label")
			body["label"] = v
		}
		if cmd.Flags().Changed("required") {
			v, _ := cmd.Flags().GetBool("required")
			body["is_required"] = v
		}
		if n, ok, err := ltvWholeNumber(cmd, "sort-order", 0); err != nil {
			return err
		} else if ok {
			body["sort_order"] = n
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/fields", body)
		if err != nil {
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 422 && strings.Contains(apiErr.Message, "already exists") {
				return withHint(err, "Field keys are unique: %s Change that one with `p202 ltv fields update <id>`.", ltvFieldListHint)
			}
			return err
		}
		render(data)
		return nil
	}),
}

var ltvFieldsUpdateCmd = &cobra.Command{
	Use:   "update <field-id>",
	Short: "Change a field's label, select choices, required flag or order (key and type are fixed)",
	Long: "Prints the account's fields afterwards. --required=false makes a required field optional.\n\n" +
		"  p202 ltv fields update 3 --label \"Plan tier\" --option free --option pro --option enterprise",
	Args: cobra.ExactArgs(1),
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "field id", ltvFieldListHint)
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("label") {
			v, _ := cmd.Flags().GetString("label")
			body["label"] = v
		}
		if options, ok, err := ltvFieldOptions(cmd); err != nil {
			return err
		} else if ok {
			body["options"] = options
		}
		if cmd.Flags().Changed("required") {
			v, _ := cmd.Flags().GetBool("required")
			body["is_required"] = v
		}
		if n, ok, err := ltvWholeNumber(cmd, "sort-order", 0); err != nil {
			return err
		} else if ok {
			body["sort_order"] = n
		}
		if len(body) == 0 {
			return validationError("no fields to update").
				WithHint("Give --label, --option (a select field's full new list of choices), --required or --sort-order.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Patch("ltv/fields/"+id, body)
		if err != nil {
			return ltvNotFound(err, ltvFieldListHint)
		}
		render(data)
		return nil
	}),
}

var ltvFieldsDeleteCmd = &cobra.Command{
	Use:   "delete <field-id>",
	Short: "Delete a custom field and every customer's value for it",
	Long: "--dry-run shows the field and how many customers' values go with it.\n\n" +
		"  p202 ltv fields delete 3 --dry-run",
	Args: deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/fields",
			noun:        "field",
			plural:      "fields",
			cascadeOne:  " and every customer's value for it",
			cascadeMany: " and every customer's values for them",
			idsHintText: "Comma-separate field ids, e.g. --ids 3,4 (" + ltvFieldListHint + ")",
			explain:     func(err error) error { return ltvNotFound(err, ltvFieldListHint) },
		})
	}),
}

// ── Webhooks ────────────────────────────────────────────────────────

const ltvWebhookListHint = "`p202 ltv webhooks list` lists the webhook ids."

var ltvWebhooksCmd = &cobra.Command{
	Use:   "webhooks",
	Short: "Outbound LTV webhooks (customer, revenue, subscription, conversion, engagement events): list, create, deliveries, delete",
}

var ltvWebhooksListCmd = &cobra.Command{
	Use:   "list",
	Short: "List the account's LTV webhooks (the secrets are never shown again)",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return ltvGet("ltv/webhooks", nil)
	},
}

var ltvWebhooksCreateCmd = &cobra.Command{
	Use:   "create --url https://…",
	Short: "Register an endpoint for signed LTV events; the response holds its secret, shown this once",
	Long: "Deliveries are signed: X-P202-Signature is sha256=HMAC-SHA256(body, secret). The secret is in\n" +
		"this command's output and nowhere else, ever: store it now. --events chooses what is sent\n" +
		"(default every event this version sends; * also takes events later versions add).\n" +
		"The URL must be https and must not point at a private, loopback or metadata address.\n\n" +
		"  p202 ltv webhooks create --url https://hooks.example.com/p202 --events revenue.recorded,subscription.changed",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		raw, _ := cmd.Flags().GetString("url")
		if raw == "" {
			return validationError("required flag --url is missing").
				WithHint("The https endpoint to deliver to, e.g. --url https://hooks.example.com/p202.")
		}
		// The server delivers over https only (OutboundUrlGuard), and also
		// refuses private, loopback and metadata hosts, which it alone
		// can judge.
		if u, err := url.Parse(raw); err != nil || u.Host == "" || u.Scheme != "https" {
			return validationError("--url %q is not an absolute https URL", raw).
				WithHint("Deliveries go over https only, e.g. --url https://hooks.example.com/p202.")
		}
		body := map[string]interface{}{"url": raw}
		if cmd.Flags().Changed("events") {
			// Each name was checked against the list in PersistentPreRunE;
			// what is left is the shape of the list as a whole. It is
			// always sent as an array: the server reads anything else as
			// "no events" and subscribes the hook to all of them.
			var events []string
			seen := map[string]bool{}
			for _, e := range strings.Split(enumValue(cmd, "events"), ",") {
				e = strings.TrimSpace(e)
				if e == "" {
					return validationError("--events has an empty name in it").
						WithHint("Comma-separate the names, e.g. --events revenue.recorded,subscription.changed.")
				}
				if !seen[e] {
					seen[e] = true
					events = append(events, e)
				}
			}
			if seen["*"] && len(events) > 1 {
				return validationError("--events * already means every event; it cannot be combined with names").
					WithHint("Give * alone, or list the names without it.")
			}
			body["events"] = events
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/webhooks", body)
		if err != nil {
			return err
		}
		render(data)
		fmt.Fprintln(cmd.ErrOrStderr(), "Note: store the secret now; it is shown only in this response and cannot be read back (`p202 ltv webhooks list` omits it). Delete and re-create the webhook to get a new one.")
		return nil
	}),
}

// ltvDeliveryStatuses is MysqlWebhookRepository::DELIVERY_STATUSES.
var ltvDeliveryStatuses = []string{"pending", "delivered", "failed"}

// ltvMaxDeliveries is the most deliveries one request lists.
const ltvMaxDeliveries = 100

var ltvWebhooksDeliveriesCmd = &cobra.Command{
	Use:   "deliveries <webhook-id>",
	Short: "A webhook's delivery log, newest first: each event's status, attempts, next retry and last response or error",
	Long: "What the LTV Settings tab's Log shows (its last 25 by default; --limit up to 100), and the last\n" +
		"attempt's response body or error (\"curl: …\", \"blocked: …\", stored cut to 1,000 bytes). A\n" +
		"delivery is retried with backoff and fails after max_attempts (6), which marks the webhook\n" +
		"dead. Never the secret, and not the payload.\n\n" +
		"  p202 ltv webhooks deliveries 3 --status failed",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := ltvPathID(args[0], "webhook id", ltvWebhookListHint)
		if err != nil {
			return err
		}
		params := map[string]string{}
		if cmd.Flags().Changed("limit") {
			v, _ := cmd.Flags().GetString("limit")
			n, err := strconv.Atoi(v)
			if err != nil || n < 1 || n > ltvMaxDeliveries || strconv.Itoa(n) != v {
				return validationError("--limit must be a whole number from 1 to %d, got %q", ltvMaxDeliveries, v).
					WithHint("For example --limit 50; without it the last 25 deliveries are listed.")
			}
			params["limit"] = v
		}
		if v := enumValue(cmd, "status"); v != "" {
			params["status"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ltv/webhooks/"+id+"/deliveries", params)
		if err != nil {
			return ltvNotFound(err, ltvWebhookListHint)
		}
		render(data)
		return nil
	},
}

var ltvWebhooksDeleteCmd = &cobra.Command{
	Use:   "delete <webhook-id>",
	Short: "Delete a webhook and its delivery queue (pending deliveries are dropped, not sent)",
	Long:  "--dry-run shows the webhook and how many deliveries, by status, go with it.",
	Args:  deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/webhooks",
			noun:        "webhook",
			plural:      "webhooks",
			cascadeOne:  " and its queued deliveries",
			cascadeMany: " and their queued deliveries",
			idsHintText: "Comma-separate webhook ids, e.g. --ids 3,4 (" + ltvWebhookListHint + ")",
			explain:     func(err error) error { return ltvNotFound(err, ltvWebhookListHint) },
		})
	}),
}

// ── Integrations ────────────────────────────────────────────────────

const ltvIntegrationListHint = "`p202 ltv integrations list` lists the integration ids."

var ltvIntegrationsCmd = &cobra.Command{
	Use:   "integrations",
	Short: "Inbound integration records (an ESP, membership or billing system pushing LTV data): list, create, delete",
}

var ltvIntegrationsListCmd = &cobra.Command{
	Use:   "list",
	Short: "List the account's integration records with their config",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return ltvGet("ltv/integrations", nil)
	},
}

var ltvIntegrationsCreateCmd = &cobra.Command{
	Use:   "create --provider <name>",
	Short: "Record an integration: its provider, a name, and provider settings as a JSON object",
	Long: "  p202 ltv integrations create --provider klaviyo --name \"Main list\" --config '{\"list_id\":\"XyZ\"}'\n" +
		"  p202 ltv integrations create --provider stripe --config-file stripe.json",
	Args: cobra.NoArgs,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		provider, _ := cmd.Flags().GetString("provider")
		if provider == "" {
			return validationError("required flag --provider is missing").
				WithHint("The system it is, e.g. --provider klaviyo (lowercase a-z, 0-9, - and _).")
		}
		if !ltvProviderPattern.MatchString(provider) {
			return validationError("--provider %q must be 1-50 of lowercase a-z, 0-9, - and _", provider).
				WithHint("For example --provider klaviyo.")
		}
		body := map[string]interface{}{"provider": provider}
		if cmd.Flags().Changed("name") {
			v, _ := cmd.Flags().GetString("name")
			body["name"] = v
		}
		inline, file := cmd.Flags().Changed("config"), cmd.Flags().Changed("config-file")
		if inline && file {
			return validationError("--config and --config-file are exclusive").
				WithHint("Give the JSON object inline, or in a file.")
		}
		if inline || file {
			var data []byte
			source := "--config"
			if inline {
				v, _ := cmd.Flags().GetString("config")
				data = []byte(v)
			} else {
				path, _ := cmd.Flags().GetString("config-file")
				source = path
				var err error
				if data, err = readLtvInput("--config-file", path); err != nil {
					return err
				}
			}
			// One object: the server refuses anything else, and json.Decoder
			// alone would take the first of two values and drop the rest.
			var config map[string]interface{}
			if err := decodeOneJSON(data, &config); err != nil || config == nil {
				return validationError("%s is not one JSON object", source).
					WithHint(`Provider settings as an object, e.g. --config '{"list_id":"XyZ"}'.`)
			}
			body["config"] = config
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("ltv/integrations", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	}),
}

var ltvIntegrationsDeleteCmd = &cobra.Command{
	Use:   "delete <integration-id>",
	Short: "Delete an integration record",
	Args:  deleteArgsValidator,
	RunE: ltvWrite(func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "ltv/integrations",
			noun:        "integration",
			plural:      "integrations",
			idsHintText: "Comma-separate integration ids, e.g. --ids 3,4 (" + ltvIntegrationListHint + ")",
			explain:     func(err error) error { return ltvNotFound(err, ltvIntegrationListHint) },
		})
	}),
}

func init() {
	ltvFieldsCreateCmd.Flags().String("key", "", "The field's key: 1-64 of a-z, 0-9 and _ (required; fixed once created)")
	ltvFieldsCreateCmd.Flags().String("label", "", "Display label (default the key)")
	ltvFieldsCreateCmd.Flags().String("type", "", "Value type (default text; fixed once created)")
	enumFlag(ltvFieldsCreateCmd, "type", newEnum(ltvFieldTypes))
	ltvFieldsCreateCmd.Flags().StringArray("option", nil, "A select field's choice (repeatable; required for --type select)")
	ltvFieldsCreateCmd.Flags().Bool("required", false, "Every customer save must carry a value for it")
	ltvFieldsCreateCmd.Flags().String("sort-order", "", "Position among the fields (0 or more; default 0)")

	ltvFieldsUpdateCmd.Flags().String("label", "", "New display label")
	ltvFieldsUpdateCmd.Flags().StringArray("option", nil, "A select field's choice (repeatable; the full new list replaces the old)")
	ltvFieldsUpdateCmd.Flags().Bool("required", false, "Make it required (--required=false makes it optional)")
	ltvFieldsUpdateCmd.Flags().String("sort-order", "", "New position among the fields (0 or more)")

	registerDeleteFlags(ltvFieldsDeleteCmd, "field")
	ltvFieldsCmd.AddCommand(ltvFieldsListCmd, ltvFieldsCreateCmd, ltvFieldsUpdateCmd, ltvFieldsDeleteCmd)

	ltvWebhooksCreateCmd.Flags().String("url", "", "The https endpoint to deliver to (required)")
	ltvWebhooksCreateCmd.Flags().String("events", "", "Events to send, comma-separated (default every event): {values}")
	enumFlag(ltvWebhooksCreateCmd, "events", newEnum(ltvWebhookEvents, enumList(),
		enumHint("Give * alone for every event, including ones later versions add.")))
	registerDeleteFlags(ltvWebhooksDeleteCmd, "webhook")
	ltvWebhooksDeliveriesCmd.Flags().String("limit", "", "How many deliveries to list, 1-100 (default 25)")
	ltvWebhooksDeliveriesCmd.Flags().String("status", "", "Only deliveries in this state")
	enumFlag(ltvWebhooksDeliveriesCmd, "status", newEnum(ltvDeliveryStatuses))
	ltvWebhooksCmd.AddCommand(ltvWebhooksListCmd, ltvWebhooksCreateCmd, ltvWebhooksDeliveriesCmd, ltvWebhooksDeleteCmd)

	ltvIntegrationsCreateCmd.Flags().String("provider", "", "The system: lowercase a-z, 0-9, - and _ (required)")
	ltvIntegrationsCreateCmd.Flags().String("name", "", "A name for it (default the provider)")
	ltvIntegrationsCreateCmd.Flags().String("config", "", `Provider settings as a JSON object, e.g. '{"list_id":"XyZ"}'`)
	ltvIntegrationsCreateCmd.Flags().String("config-file", "", "Provider settings as a JSON object in a file (- for stdin)")
	registerDeleteFlags(ltvIntegrationsDeleteCmd, "integration")
	ltvIntegrationsCmd.AddCommand(ltvIntegrationsListCmd, ltvIntegrationsCreateCmd, ltvIntegrationsDeleteCmd)

	ltvCmd.AddCommand(ltvFieldsCmd, ltvWebhooksCmd, ltvIntegrationsCmd)
}
