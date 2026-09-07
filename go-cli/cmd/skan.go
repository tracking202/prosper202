package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strconv"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Apple SKAdNetwork: postbacks received by the server's public endpoint
// (/.well-known/skadnetwork/report-attribution/), the advertised-app
// registry, conversion-value decoding rules, the aggregate report, and
// ad-hoc signature verification. Servers advertise support via
// features.skan in /capabilities.

var skanCmd = &cobra.Command{
	Use:   "skan",
	Short: "Apple SKAdNetwork (SKAN) install attribution",
	Long: "Work with SKAdNetwork install-validation postbacks: the server receives them at\n" +
		"/.well-known/skadnetwork/report-attribution/, verifies Apple's signature, and\n" +
		"stores them. Register advertised apps (skan app), mirror the in-app\n" +
		"conversion-value schema as decoding rules (skan cv), then read postbacks and the\n" +
		"decoded report. Requires a server advertising features.skan in /capabilities.",
}

// skanFilterFlagDefs is the single source for the postback list/report
// filter flags: flag name (kebab-case; the normalizer also accepts
// snake_case), the API query parameter it feeds, and its help text. One
// table, so a flag cannot be registered without being collected or
// collected without being registered.
var skanFilterFlagDefs = []struct {
	flag  string
	param string
	help  string
}{
	{"time-from", "time_from", "Received-at range start (unix timestamp)"},
	{"time-to", "time_to", "Received-at range end (unix timestamp)"},
	{"app-id", "app_id", "Filter by advertised App Store id"},
	{"ad-network-id", "ad_network_id", "Filter by ad network id"},
	{"version", "version", "Filter by SKAN postback version (e.g. 4.0)"},
	{"transaction-id", "transaction_id", "Filter by Apple transaction id"},
	{"country-code", "country_code", "Filter by install country code"},
	{"source-identifier", "source_identifier", "Filter by SKAN 4 source identifier"},
	{"campaign-id", "campaign_id", "Filter by SKAN 2/3 campaign id"},
	{"fidelity-type", "fidelity_type", "Filter: 1=StoreKit-rendered/web ad, 0=view-through"},
	{"postback-sequence-index", "postback_sequence_index", "Filter by conversion window (0, 1, or 2)"},
	{"did-win", "did_win", "Filter: 1=winning postbacks, 0=losing"},
	{"redownload", "redownload", "Filter: 1=redownloads only, 0=first installs"},
	{"coarse-conversion-value", "coarse_conversion_value", "Filter by coarse value (low, medium, high)"},
	{"signature", "signature", "Filter by verification state: valid, invalid, unverifiable"},
}

func registerSkanFilterFlags(cmd *cobra.Command) {
	for _, def := range skanFilterFlagDefs {
		cmd.Flags().String(def.flag, "", def.help)
	}
}

func collectSkanFilters(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	for _, def := range skanFilterFlagDefs {
		if v, _ := cmd.Flags().GetString(def.flag); v != "" {
			params[def.param] = v
		}
	}
	return params
}

// skanAppBodyFields / skanCvBodyFields map each create/update flag to its
// API body field — the one place the flag↔field correspondence lives.
var skanAppBodyFields = map[string]string{
	"app-id":   "app_id",
	"app-name": "app_name",
	"notes":    "notes",
}

var skanCvBodyFields = map[string]string{
	"app-id":       "app_id",
	"fine-value":   "fine_value",
	"coarse-value": "coarse_value",
	"event-name":   "event_name",
	"revenue":      "revenue",
}

// collectSkanBody gathers flag values into an API body. changedOnly sends
// exactly the flags the caller set (updates: an explicitly empty value is a
// deliberate write); otherwise only non-empty values are sent (creates).
func collectSkanBody(cmd *cobra.Command, fields map[string]string, changedOnly bool) map[string]string {
	body := map[string]string{}
	for flag, field := range fields {
		if changedOnly {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				body[field] = v
			}
		} else if v, _ := cmd.Flags().GetString(flag); v != "" {
			body[field] = v
		}
	}
	return body
}

// listAllSkanRows fetches every page of a skan list endpoint and renders
// the rows in the list envelope shape, so --all output is structurally
// identical to a paged list.
func listAllSkanRows(c *api.Client, endpoint string, params map[string]string) error {
	rows, err := fetchAllRowsWithParams(c, endpoint, params)
	if err != nil {
		return err
	}
	encoded, err := json.Marshal(map[string]interface{}{
		"data": rows,
		"pagination": map[string]interface{}{
			"total": len(rows), "limit": len(rows), "offset": 0,
		},
	})
	if err != nil {
		return fmt.Errorf("encoding rows: %w", err)
	}
	render(encoded)
	return nil
}

// ── Postbacks (read-only) ───────────────────────────────────────────

var skanPostbacksCmd = &cobra.Command{
	Use:     "postbacks",
	Aliases: []string{"postback", "pb"},
	Short:   "Received SKAdNetwork postbacks (read-only)",
}

var skanPostbacksListCmd = &cobra.Command{
	Use:   "list",
	Short: "List received postbacks with filters (signature state, app, network, window)",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := collectSkanFilters(cmd)
		if allRows, _ := cmd.Flags().GetBool("all"); allRows {
			return listAllSkanRows(c, "skan/postbacks", params)
		}
		for _, f := range []string{"limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("skan/postbacks", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanPostbacksGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get one postback, including its attribution signature",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("skan/postbacks/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Report ──────────────────────────────────────────────────────────

var skanReportCmd = &cobra.Command{
	Use:   "report",
	Short: "Aggregate SKAN report with conversion-value decoding",
	Long: "Groups postbacks by day (UTC), app, ad-network, source, country, or version and\n" +
		"decodes winning postbacks' conversion values through the rules in `p202 skan cv`.\n" +
		"installs = winning first-window postbacks excluding redownloads.",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := collectSkanFilters(cmd)
		groupBy, _ := cmd.Flags().GetString("group-by")
		if groupBy != "" {
			params["group_by"] = groupBy
		}
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			if n, err := strconv.Atoi(v); err != nil || n <= 0 {
				return validationError("--limit must be a positive integer")
			}
			params["limit"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("skan/report", params)
		if err != nil {
			return err
		}
		render(reshapeSkanReport(data))
		return nil
	},
}

// reshapeSkanReport lifts data.groups to the top-level data array — the
// shape every list command renders — moving group_by into meta. Applied in
// every output mode so --json and the table agree on structure. Anything
// unexpected is passed through untouched.
func reshapeSkanReport(data []byte) []byte {
	var envelope struct {
		Data struct {
			GroupBy string            `json:"group_by"`
			Groups  []json.RawMessage `json:"groups"`
		} `json:"data"`
		Meta map[string]interface{} `json:"meta"`
	}
	if err := json.Unmarshal(data, &envelope); err != nil || envelope.Data.Groups == nil {
		return data
	}
	meta := envelope.Meta
	if meta == nil {
		meta = map[string]interface{}{}
	}
	meta["group_by"] = envelope.Data.GroupBy
	reshaped, err := json.Marshal(map[string]interface{}{
		"data": envelope.Data.Groups,
		"meta": meta,
	})
	if err != nil {
		return data
	}
	return reshaped
}

// ── Verify ──────────────────────────────────────────────────────────

var skanVerifyCmd = &cobra.Command{
	Use:   "verify",
	Short: "Verify a postback payload's Apple signature (nothing is stored)",
	Long: "Reads a SKAdNetwork postback JSON object from --file (or stdin) and asks the\n" +
		"server to verify its attribution-signature against Apple's key. The response\n" +
		"carries the verdict (valid / invalid / unverifiable) and the exact signed\n" +
		"message (base64) for diffing against another implementation.",
	RunE: func(cmd *cobra.Command, args []string) error {
		file, _ := cmd.Flags().GetString("file")
		var raw []byte
		var err error
		if file == "" || file == "-" {
			raw, err = io.ReadAll(os.Stdin)
			if err != nil {
				return validationError("reading postback JSON from stdin: %v", err).
					WithHint("Pipe the postback JSON in, or pass --file <postback.json>.")
			}
		} else {
			raw, err = os.ReadFile(file)
			if err != nil {
				return validationError("reading %s: %v", file, err).
					WithHint("Pass the path of a file containing the postback JSON object, or omit --file to read stdin.")
			}
		}

		// Decode with UseNumber so integers round-trip as integers: the
		// server's verifier refuses coerced types (an app-id serialized as
		// 5.25463029e8 would be judged, wrongly, unverifiable).
		decoder := json.NewDecoder(bytes.NewReader(raw))
		decoder.UseNumber()
		var payload map[string]interface{}
		if err := decoder.Decode(&payload); err != nil {
			return validationError("the input is not a JSON object: %v", err).
				WithHint("Provide the postback exactly as received — a single JSON object with its attribution-signature.")
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("skan/verify", payload)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Apps ────────────────────────────────────────────────────────────

var skanAppCmd = &cobra.Command{
	Use:     "app",
	Aliases: []string{"apps"},
	Short:   "Advertised App Store apps (registration claims their postbacks)",
}

var skanAppListCmd = &cobra.Command{
	Use:   "list",
	Short: "List registered apps",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if allRows, _ := cmd.Flags().GetBool("all"); allRows {
			return listAllSkanRows(c, "skan/apps", map[string]string{})
		}
		params := map[string]string{}
		for _, f := range []string{"limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("skan/apps", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanAppGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a registered app by its internal id (from `skan app list`)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("skan/apps/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanAppCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Register an advertised app (claims its stored postbacks)",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectSkanBody(cmd, skanAppBodyFields, false)
		if body["app_id"] == "" {
			return validationError("required flag --app-id is missing").
				WithHint("Pass the numeric App Store id of the advertised app (the number in its App Store URL).")
		}
		if body["app_name"] == "" {
			return validationError("required flag --app-name is missing")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		// Deliberately no --idempotency-key: the server does not record a
		// replayable response for app creates (the response carries the
		// schema token, which must never persist in the server-state
		// store). Retries are safe anyway — the App Store id is globally
		// unique, so a duplicate create answers 409 naming the
		// registration.
		data, err := c.Post("skan/apps", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanAppUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a registered app (re-runs the postback claim)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectSkanBody(cmd, skanAppBodyFields, true)
		if len(body) == 0 {
			return validationError("nothing to update").
				WithHint("Pass at least one of --app-id, --app-name, --notes.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("skan/apps/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanAppDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete an app registration (already-claimed postbacks keep their owner)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "skan/apps", "SKAN app")
	},
}

var skanAppRotateTokenCmd = &cobra.Command{
	Use:   "rotate-token <id>",
	Short: "Replace the app's schema token (the old token stops working immediately)",
	Long: "Mints a new schema token for the registered app and invalidates the old one —\n" +
		"the remedy when a token shipped in an app binary has leaked. Builds configured\n" +
		"with the old token can no longer fetch the schema until updated.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("skan/apps/"+args[0]+"/schema-token/rotate", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanSchemaCmd = &cobra.Command{
	Use:   "schema <app-registration-id>",
	Short: "Show the conversion-value schema exactly as devices fetch it",
	Long: "Reads the app registration (for its schema token), then fetches the public\n" +
		"GET /skan/schema endpoint with it — the same request the P202SKAN helper in the\n" +
		"iOS app makes — so what you see is byte-for-byte what shipped builds decode.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		appData, err := c.Get("skan/apps/"+args[0], nil)
		if err != nil {
			return err
		}
		var envelope struct {
			Data struct {
				SchemaToken string `json:"schema_token"`
			} `json:"data"`
		}
		if err := json.Unmarshal(appData, &envelope); err != nil || envelope.Data.SchemaToken == "" {
			return validationError("this app registration has no schema token").
				WithHint("The server must advertise features.skan in /capabilities; `p202 skan app get " + args[0] + "` shows the registration.")
		}
		// The token travels as a header, exactly as the P202SKAN helper
		// sends it — never as a query parameter, which request logging
		// would capture.
		data, err := c.GetWithHeaders("skan/schema", nil,
			map[string]string{"X-P202-Schema-Token": envelope.Data.SchemaToken})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Conversion values ───────────────────────────────────────────────

var skanCvCmd = &cobra.Command{
	Use:     "cv",
	Aliases: []string{"conversion-values", "conversion-value"},
	Short:   "Conversion-value decoding rules (mirror of the in-app SKAN schema)",
}

var skanCvListCmd = &cobra.Command{
	Use:   "list",
	Short: "List conversion-value rules",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if allRows, _ := cmd.Flags().GetBool("all"); allRows {
			return listAllSkanRows(c, "skan/conversion-values", map[string]string{})
		}
		params := map[string]string{}
		for _, f := range []string{"limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("skan/conversion-values", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanCvGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a conversion-value rule",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("skan/conversion-values/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanCvCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a decoding rule (one fine value 0-63 OR one coarse value)",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectSkanBody(cmd, skanCvBodyFields, true)
		if body["event_name"] == "" {
			return validationError("required flag --event-name is missing")
		}
		_, hasFine := body["fine_value"]
		_, hasCoarse := body["coarse_value"]
		if hasFine == hasCoarse {
			return validationError("a rule maps exactly one conversion value").
				WithHint("Pass --fine-value 0..63 (the 6-bit value) or --coarse-value low|medium|high — one of them, not both.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("skan/conversion-values", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanCvUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a decoding rule (switch kinds with --clear-fine-value/--clear-coarse-value)",
	Long: "Updates fields of a decoding rule. A rule always maps exactly ONE conversion\n" +
		"value, so switching a rule between kinds takes the clear and the replacement in\n" +
		"one command: `update 5 --clear-fine-value --coarse-value high` turns a fine rule\n" +
		"into a coarse one. The clear flags send an explicit JSON null.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		clearFine, _ := cmd.Flags().GetBool("clear-fine-value")
		clearCoarse, _ := cmd.Flags().GetBool("clear-coarse-value")
		if clearFine && cmd.Flags().Changed("fine-value") {
			return validationError("--clear-fine-value and --fine-value are mutually exclusive")
		}
		if clearCoarse && cmd.Flags().Changed("coarse-value") {
			return validationError("--clear-coarse-value and --coarse-value are mutually exclusive")
		}

		// interface{} values so the clear flags can send true JSON nulls —
		// the server distinguishes "field: null" (clear it) from an absent
		// field (keep it).
		body := map[string]interface{}{}
		for field, v := range collectSkanBody(cmd, skanCvBodyFields, true) {
			body[field] = v
		}
		if clearFine {
			body["fine_value"] = nil
		}
		if clearCoarse {
			body["coarse_value"] = nil
		}
		if len(body) == 0 {
			return validationError("nothing to update").
				WithHint("Pass at least one of --app-id, --fine-value, --coarse-value, --event-name, --revenue, or a --clear-* flag with its replacement value.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("skan/conversion-values/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var skanCvDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete a decoding rule",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "skan/conversion-values", "conversion-value rule")
	},
}

func init() {
	skanPostbacksListCmd.Flags().StringP("limit", "l", "", "Max results")
	skanPostbacksListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	skanPostbacksListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	registerSkanFilterFlags(skanPostbacksListCmd)
	skanPostbacksCmd.AddCommand(skanPostbacksListCmd, skanPostbacksGetCmd)

	skanReportCmd.Flags().String("group-by", "day", "Group results by: day, app, ad-network, source, country, version")
	skanReportCmd.Flags().StringP("limit", "l", "", "Max groups to return (default 100)")
	registerSkanFilterFlags(skanReportCmd)

	skanVerifyCmd.Flags().StringP("file", "f", "", "Path to the postback JSON (default: read stdin)")

	skanAppListCmd.Flags().StringP("limit", "l", "", "Max results")
	skanAppListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	skanAppListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	for _, cmd := range []*cobra.Command{skanAppCreateCmd, skanAppUpdateCmd} {
		cmd.Flags().String("app-id", "", "Numeric App Store id of the advertised app")
		cmd.Flags().String("app-name", "", "Display name for reports")
		cmd.Flags().String("notes", "", "Free-form notes")
	}
	registerDeleteFlags(skanAppDeleteCmd, "SKAN app")
	skanAppCmd.AddCommand(skanAppListCmd, skanAppGetCmd, skanAppCreateCmd, skanAppUpdateCmd, skanAppDeleteCmd, skanAppRotateTokenCmd)

	skanCvListCmd.Flags().StringP("limit", "l", "", "Max results")
	skanCvListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	skanCvListCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	for _, cmd := range []*cobra.Command{skanCvCreateCmd, skanCvUpdateCmd} {
		cmd.Flags().String("app-id", "", "App Store id this rule applies to (0 = account-wide default)")
		cmd.Flags().String("fine-value", "", "Fine conversion value 0-63")
		cmd.Flags().String("coarse-value", "", "Coarse conversion value: low, medium, high")
		cmd.Flags().String("event-name", "", "Event the value decodes to")
		cmd.Flags().String("revenue", "", "Revenue attributed per decoded postback")
	}
	skanCvUpdateCmd.Flags().Bool("clear-fine-value", false, "Set fine_value to null (pair with --coarse-value to switch kinds)")
	skanCvUpdateCmd.Flags().Bool("clear-coarse-value", false, "Set coarse_value to null (pair with --fine-value to switch kinds)")
	registerIdempotencyKeyFlag(skanCvCreateCmd)
	registerDeleteFlags(skanCvDeleteCmd, "conversion-value rule")
	skanCvCmd.AddCommand(skanCvListCmd, skanCvGetCmd, skanCvCreateCmd, skanCvUpdateCmd, skanCvDeleteCmd)

	skanCmd.AddCommand(skanPostbacksCmd, skanReportCmd, skanVerifyCmd, skanAppCmd, skanCvCmd, skanSchemaCmd)
	rootCmd.AddCommand(skanCmd)
}
