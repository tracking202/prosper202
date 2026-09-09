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

// Apple SKAdNetwork and AdAttributionKit: postbacks received by the server's
// public endpoints (/.well-known/skadnetwork/report-attribution/ and
// /.well-known/appattribution/report-attribution/), the advertised-app
// registry, conversion-value decoding rules, the aggregate report, and
// ad-hoc signature verification. Servers list the protocols they receive in
// features.attribution_postbacks in /capabilities.

// attributionFilterFlagDefs is the single source for the postback list/report
// filter flags: flag name (kebab-case; the normalizer also accepts
// snake_case), the API query parameter it feeds, and its help text. One
// table, so a flag cannot be registered without being collected or
// collected without being registered.
var attributionFilterFlagDefs = []struct {
	flag  string
	param string
	help  string
}{
	{"time-from", "time_from", "Received-at range start (unix timestamp)"},
	{"time-to", "time_to", "Received-at range end (unix timestamp)"},
	{"protocol", "protocol", "Filter by protocol: skadnetwork (skan) or adattributionkit (aak)"},
	{"conversion-type", "conversion_type", "Filter: download, redownload, re-engagement"},
	{"ad-interaction-type", "ad_interaction_type", "Filter: view (view-through) or click"},
	{"app-id", "app_id", "Filter by advertised App Store id"},
	{"ad-network-id", "ad_network_id", "Filter by ad network id"},
	{"version", "version", "Filter by SKAN postback version (e.g. 4.0)"},
	{"transaction-id", "transaction_id", "Filter by Apple transaction id"},
	{"country-code", "country_code", "Filter by install country code"},
	{"source-identifier", "source_identifier", "Filter by SKAN 4 source identifier"},
	{"campaign-id", "campaign_id", "Filter by SKAN 2/3 campaign id"},
	{"fidelity-type", "fidelity_type", "Filter: 1=click-through, 0=view-through (both protocols; prefer --ad-interaction-type)"},
	{"postback-sequence-index", "postback_sequence_index", "Filter by conversion window (0, 1, or 2)"},
	{"did-win", "did_win", "Filter: 1=winning postbacks, 0=losing"},
	{"redownload", "redownload", "Filter: 1=redownloads, 0=first installs (both protocols; prefer --conversion-type)"},
	{"coarse-conversion-value", "coarse_conversion_value", "Filter by coarse value (low, medium, high)"},
	{"signature", "signature", "Filter by verification state: valid, invalid, unverifiable, development"},
}

func registerAttributionFilterFlags(cmd *cobra.Command) {
	for _, def := range attributionFilterFlagDefs {
		cmd.Flags().String(def.flag, "", def.help)
	}
}

func collectAttributionFilters(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	for _, def := range attributionFilterFlagDefs {
		if v, _ := cmd.Flags().GetString(def.flag); v != "" {
			params[def.param] = v
		}
	}
	return params
}

// attributionAppBodyFields / attributionCvBodyFields map each create/update flag to its
// API body field — the one place the flag↔field correspondence lives.
var attributionAppBodyFields = map[string]string{
	"app-id":                       "app_id",
	"app-name":                     "app_name",
	"notes":                        "notes",
	"accept-development-postbacks": "accept_development_postbacks",
}

// validateAttributionAppBody refuses the flag values the server would reject,
// so the error names the flag rather than a JSON field.
func validateAttributionAppBody(body map[string]string) error {
	if v, ok := body["accept_development_postbacks"]; ok && v != "0" && v != "1" {
		return validationError("--accept-development-postbacks must be 0 or 1, got %q", v).
			WithHint("1 trusts postbacks signed with Apple's AdAttributionKit development keys for this app (integration testing only); 0 stores them flagged and uncounted.")
	}
	return nil
}

var attributionCvBodyFields = map[string]string{
	"app-id":       "app_id",
	"fine-value":   "fine_value",
	"coarse-value": "coarse_value",
	"event-name":   "event_name",
	"revenue":      "revenue",
}

// collectAttributionBody gathers flag values into an API body. changedOnly sends
// exactly the flags the caller set (updates: an explicitly empty value is a
// deliberate write); otherwise only non-empty values are sent (both
// creates, so an empty --fine-value never counts as "set" for the
// one-kind check).
func collectAttributionBody(cmd *cobra.Command, fields map[string]string, changedOnly bool) map[string]string {
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

// ── Shared list plumbing ────────────────────────────────────────────
//
// The three list commands and the report used to carry hand-copied RunE
// bodies, and the copies had drifted: only the report checked that --limit
// was a number, so the same typo was a CLI validation error (exit 1) on one
// command and a server 422 on the next. One registrar, one paging validator
// and one runner keep them answering the same way.

// registerAttributionListFlags registers the paging trio every attribution
// list command carries. Deliberately no --page (which the generated CRUD
// list commands offer): neither the postbacks controller nor the generic
// list reads a page parameter, so the flag would promise paging the server
// ignores.
func registerAttributionListFlags(cmd *cobra.Command) {
	cmd.Flags().StringP("limit", "l", "", "Max results")
	cmd.Flags().StringP("offset", "o", "", "Pagination offset")
	cmd.Flags().Bool("all", false, "Fetch all rows across pages")
}

// attributionPagingValue returns a validated paging flag's value, or "" when
// the flag was not given. The server rejects a non-numeric value too, but
// only after a round trip and with a different category; a mistyped flag is
// the caller's mistake on every command that accepts it.
func attributionPagingValue(cmd *cobra.Command, flag string) (string, error) {
	v, _ := cmd.Flags().GetString(flag)
	if v == "" {
		return "", nil
	}
	smallest, requirement := 1, "a positive integer"
	hint := "Pass a whole number, e.g. `--limit 50`; the server caps it at 500."
	if flag == "offset" {
		smallest, requirement = 0, "a non-negative integer"
		hint = "Pass a whole number of rows to skip, e.g. `--offset 50`, or `--all` to fetch every page."
	}
	if n, err := strconv.Atoi(v); err != nil || n < smallest {
		return "", validationError("--%s must be %s", flag, requirement).WithHint("%s", hint)
	}
	return v, nil
}

// collectAttributionPaging validates --limit/--offset and adds the ones that
// were given to params.
func collectAttributionPaging(cmd *cobra.Command, params map[string]string) error {
	for _, flag := range []string{"limit", "offset"} {
		v, err := attributionPagingValue(cmd, flag)
		if err != nil {
			return err
		}
		if v != "" {
			params[flag] = v
		}
	}
	return nil
}

// runAttributionList is the body every attribution list command shares.
// Paging is validated before the client is built, so a bad --limit is a
// validation error even where no server URL is configured. params carries
// the command's own filters (the two registries have none).
func runAttributionList(cmd *cobra.Command, endpoint string, params map[string]string) error {
	if err := collectAttributionPaging(cmd, params); err != nil {
		return err
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	if allRows, _ := cmd.Flags().GetBool("all"); allRows {
		// --all drives its own paging; sending the caller's limit/offset
		// too would fight the traversal, so they are dropped (they are
		// still validated above — a typo is a typo either way).
		delete(params, "limit")
		delete(params, "offset")
		return listAllAttributionRows(c, endpoint, params)
	}
	data, err := c.Get(endpoint, params)
	if err != nil {
		return err
	}
	render(data)
	return nil
}

// newAttributionGetCmd builds the `get <id>` command for one attribution
// endpoint: the three read a row the same way and differ only in the path.
func newAttributionGetCmd(endpoint, short string) *cobra.Command {
	return &cobra.Command{
		Use:   "get <id>",
		Short: short,
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			data, err := c.Get(endpoint+"/"+args[0], nil)
			if err != nil {
				return err
			}
			render(data)
			return nil
		},
	}
}

// listAllAttributionRows fetches every page of an attribution list endpoint and renders
// the rows in the list envelope shape, so --all output is structurally
// identical to a paged list.
func listAllAttributionRows(c *api.Client, endpoint string, params map[string]string) error {
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

var attributionPostbacksCmd = &cobra.Command{
	Use:     "postbacks",
	Aliases: []string{"postback", "pb"},
	Short:   "Received SKAdNetwork and AdAttributionKit postbacks (read-only)",
}

var attributionPostbacksListCmd = &cobra.Command{
	Use:   "list",
	Short: "List received postbacks with filters (protocol, signature state, app, network, window)",
	RunE: func(cmd *cobra.Command, args []string) error {
		return runAttributionList(cmd, "attribution/postbacks", collectAttributionFilters(cmd))
	},
}

var attributionPostbacksGetCmd = newAttributionGetCmd("attribution/postbacks",
	"Get one postback, including its attribution signature")

// ── Report ──────────────────────────────────────────────────────────

var attributionReportCmd = &cobra.Command{
	Use:   "report",
	Short: "Aggregate attribution report with conversion-value decoding",
	Long: "Groups postbacks by day (UTC), app, ad-network, source, country, version, protocol,\n" +
		"or conversion-type and decodes winning postbacks' conversion values through the rules\n" +
		"in `p202 attribution cv`. Every metric counts unique postbacks, so a replay counts once.\n" +
		"installs = winning first-window downloads; redownloads and re-engagements\n" +
		"(AdAttributionKit) are reported beside them.",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := collectAttributionFilters(cmd)
		groupBy, _ := cmd.Flags().GetString("group-by")
		if groupBy != "" {
			params["group_by"] = groupBy
		}
		limit, err := attributionPagingValue(cmd, "limit")
		if err != nil {
			return err
		}
		if limit != "" {
			params["limit"] = limit
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/report", params)
		if err != nil {
			return err
		}
		render(reshapeAttributionReport(data))
		return nil
	},
}

// reshapeAttributionReport lifts data.groups to the top-level data array — the
// shape every list command renders — moving group_by into meta. Applied in
// every output mode so --json and the table agree on structure. Anything
// unexpected is passed through untouched.
func reshapeAttributionReport(data []byte) []byte {
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

var attributionVerifyCmd = &cobra.Command{
	Use:   "verify",
	Short: "Verify a postback payload's Apple signature (nothing is stored)",
	Long: "Reads a postback JSON object from --file (or piped stdin) and asks the server to\n" +
		"verify Apple's signature. A body with a jws-string is verified as AdAttributionKit\n" +
		"(the response decodes the JWS header and payload and names the signing key); anything\n" +
		"else as SKAdNetwork (the response carries the exact signed message, base64, for\n" +
		"diffing against another implementation). The verdict is valid / invalid /\n" +
		"unverifiable / development.",
	RunE: func(cmd *cobra.Command, args []string) error {
		file, _ := cmd.Flags().GetString("file")
		var raw []byte
		var err error
		if file == "" || file == "-" {
			// io.ReadAll on a terminal blocks forever with no output and
			// no error envelope, so `p202 attribution verify --json` run
			// without piping anything hung instead of telling the caller
			// what to pass. isTerminal (shell.go) is the character-device
			// test, which also covers stdin redirected from /dev/null.
			if isTerminal(os.Stdin) {
				return validationError("no postback JSON to read: stdin is not a pipe or a file").
					WithHint("Pass --file <postback.json>, or pipe the postback in: `cat postback.json | p202 attribution verify`.")
			}
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
		data, err := c.Post("attribution/verify", payload)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Apps ────────────────────────────────────────────────────────────

var attributionAppCmd = &cobra.Command{
	Use:     "app",
	Aliases: []string{"apps"},
	Short:   "Advertised App Store apps (registration claims their postbacks)",
}

var attributionAppListCmd = &cobra.Command{
	Use:   "list",
	Short: "List registered apps",
	RunE: func(cmd *cobra.Command, args []string) error {
		return runAttributionList(cmd, "attribution/apps", map[string]string{})
	},
}

var attributionAppGetCmd = newAttributionGetCmd("attribution/apps",
	"Get a registered app by its internal id (from `attribution app list`)")

var attributionAppCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Register an advertised app (claims its stored postbacks)",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAttributionBody(cmd, attributionAppBodyFields, false)
		if body["app_id"] == "" {
			return validationError("required flag --app-id is missing").
				WithHint("Pass the numeric App Store id of the advertised app (the number in its App Store URL).")
		}
		if body["app_name"] == "" {
			return validationError("required flag --app-name is missing")
		}
		if err := validateAttributionAppBody(body); err != nil {
			return err
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
		data, err := c.Post("attribution/apps", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attributionAppUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a registered app (re-runs the postback claim)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAttributionBody(cmd, attributionAppBodyFields, true)
		if len(body) == 0 {
			// Same sentence the generated CRUD update commands use, so an
			// agent scripting against one wording works on both surfaces.
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("Pass at least one of --app-id, --app-name, --notes, --accept-development-postbacks.")
		}
		if err := validateAttributionAppBody(body); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("attribution/apps/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attributionAppDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete an app registration (already-claimed postbacks keep their owner)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "attribution/apps", "attribution app")
	},
}

var attributionAppRotateTokenCmd = &cobra.Command{
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
		data, err := c.Post("attribution/apps/"+args[0]+"/schema-token/rotate", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attributionSchemaCmd = &cobra.Command{
	Use:   "schema <app-registration-id>",
	Short: "Show the conversion-value schema exactly as devices fetch it",
	Long: "Reads the app registration (for its schema token), then fetches the public\n" +
		"GET /attribution/schema endpoint with it — the same request the P202Attribution helper in the\n" +
		"iOS app makes — so what you see is byte-for-byte what shipped builds decode.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		appData, err := c.Get("attribution/apps/"+args[0], nil)
		if err != nil {
			return err
		}
		var envelope struct {
			Data struct {
				SchemaToken string `json:"schema_token"`
			} `json:"data"`
		}
		// An unreadable response and a registration without a token are
		// different failures with different remedies; folding them together
		// told the caller "no schema token" when a proxy had returned an
		// error page, and pointed them at the wrong fix.
		if err := json.Unmarshal(appData, &envelope); err != nil {
			return withHint(
				fmt.Errorf("reading app %s: could not decode the server's response: %w", args[0], err),
				"The server returned something that is not the expected JSON envelope; check the configured URL with `p202 config show` and that it reaches the API and not a proxy error page.",
			)
		}
		if envelope.Data.SchemaToken == "" {
			return validationError("this app registration has no schema token").
				WithHint("The server must advertise features.attribution_postbacks in /capabilities; `p202 attribution app get " + args[0] + "` shows the registration.")
		}
		// The token travels as a header, exactly as the P202Attribution helper
		// sends it — never as a query parameter, which request logging
		// would capture.
		data, err := c.GetWithHeaders("attribution/schema", nil,
			map[string]string{"X-P202-Schema-Token": envelope.Data.SchemaToken})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Conversion values ───────────────────────────────────────────────

var attributionCvCmd = &cobra.Command{
	Use:     "cv",
	Aliases: []string{"conversion-values", "conversion-value"},
	Short:   "Conversion-value decoding rules (mirror of the in-app conversion-value schema)",
}

var attributionCvListCmd = &cobra.Command{
	Use:   "list",
	Short: "List conversion-value rules",
	RunE: func(cmd *cobra.Command, args []string) error {
		return runAttributionList(cmd, "attribution/conversion-values", map[string]string{})
	},
}

var attributionCvGetCmd = newAttributionGetCmd("attribution/conversion-values",
	"Get a conversion-value rule")

var attributionCvCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a decoding rule (one fine value 0-63 OR one coarse value)",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAttributionBody(cmd, attributionCvBodyFields, false)
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
		data, err := c.PostIdempotent("attribution/conversion-values", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attributionCvUpdateCmd = &cobra.Command{
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
		for field, v := range collectAttributionBody(cmd, attributionCvBodyFields, true) {
			body[field] = v
		}
		if clearFine {
			body["fine_value"] = nil
		}
		if clearCoarse {
			body["coarse_value"] = nil
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("Pass at least one of --app-id, --fine-value, --coarse-value, --event-name, --revenue, or a --clear-* flag with its replacement value.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("attribution/conversion-values/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attributionCvDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete a decoding rule",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "attribution/conversion-values", "conversion-value rule")
	},
}

func init() {
	registerAttributionListFlags(attributionPostbacksListCmd)
	registerAttributionFilterFlags(attributionPostbacksListCmd)
	attributionPostbacksCmd.AddCommand(attributionPostbacksListCmd, attributionPostbacksGetCmd)

	attributionReportCmd.Flags().String("group-by", "day", "Group results by: day, app, ad-network, source, country, version, protocol, conversion-type")
	attributionReportCmd.Flags().StringP("limit", "l", "", "Max groups to return (default 100)")
	registerAttributionFilterFlags(attributionReportCmd)

	attributionVerifyCmd.Flags().StringP("file", "f", "", "Path to the postback JSON (default: read piped stdin)")

	registerAttributionListFlags(attributionAppListCmd)
	for _, cmd := range []*cobra.Command{attributionAppCreateCmd, attributionAppUpdateCmd} {
		cmd.Flags().String("app-id", "", "Numeric App Store id of the advertised app")
		cmd.Flags().String("app-name", "", "Display name for reports")
		cmd.Flags().String("notes", "", "Free-form notes")
		cmd.Flags().String("accept-development-postbacks", "", "1 = trust AdAttributionKit development-signed postbacks for this app (integration testing), 0 = store them flagged (default)")
	}
	registerDeleteFlags(attributionAppDeleteCmd, "attribution app")
	attributionAppCmd.AddCommand(attributionAppListCmd, attributionAppGetCmd, attributionAppCreateCmd, attributionAppUpdateCmd, attributionAppDeleteCmd, attributionAppRotateTokenCmd)

	registerAttributionListFlags(attributionCvListCmd)
	for _, cmd := range []*cobra.Command{attributionCvCreateCmd, attributionCvUpdateCmd} {
		cmd.Flags().String("app-id", "", "App Store id this rule applies to (0 = account-wide default)")
		cmd.Flags().String("fine-value", "", "Fine conversion value 0-63")
		cmd.Flags().String("coarse-value", "", "Coarse conversion value: low, medium, high")
		cmd.Flags().String("event-name", "", "Event the value decodes to")
		cmd.Flags().String("revenue", "", "Revenue attributed per decoded postback")
	}
	attributionCvUpdateCmd.Flags().Bool("clear-fine-value", false, "Set fine_value to null (pair with --coarse-value to switch kinds)")
	attributionCvUpdateCmd.Flags().Bool("clear-coarse-value", false, "Set coarse_value to null (pair with --fine-value to switch kinds)")
	registerIdempotencyKeyFlag(attributionCvCreateCmd)
	registerDeleteFlags(attributionCvDeleteCmd, "conversion-value rule")
	attributionCvCmd.AddCommand(attributionCvListCmd, attributionCvGetCmd, attributionCvCreateCmd, attributionCvUpdateCmd, attributionCvDeleteCmd)

	attributionCmd.AddCommand(attributionPostbacksCmd, attributionReportCmd, attributionVerifyCmd, attributionAppCmd, attributionCvCmd, attributionSchemaCmd)
}
