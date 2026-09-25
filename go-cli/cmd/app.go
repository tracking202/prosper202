package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// App measurement: the registry of apps (iOS and Android, one registration
// per app), Apple's SKAdNetwork and AdAttributionKit postbacks received by
// the server's public endpoints (/.well-known/skadnetwork/report-attribution/
// and /.well-known/appattribution/report-attribution/), the SKAN encodings
// that decode their conversion values, the aggregate report, and ad-hoc
// signature verification. Everything lives under /apps on the server.
// Servers list the platforms they register in features.app_platforms and
// the postback protocols they receive in features.app_postbacks in
// /capabilities.

var appCmd = &cobra.Command{
	Use:     "app",
	Aliases: []string{"apps"},
	Short:   "App measurement: registered apps, SKAN encodings, postbacks and the report",
	Long: "Register the apps you advertise (from a store link or an app key), decode their\n" +
		"SKAdNetwork and AdAttributionKit postbacks, and read the report.\n\n" +
		"  p202 app create --store-link https://apps.apple.com/us/app/summit-run/id990077001\n" +
		"  p202 goal create --registration-id 1 --name purchase --event purchase\n" +
		"  p202 app encoding create --registration-id 1 --fine-value 40 --goal-id 12 --revenue-override 4.99\n" +
		"  p202 app report --group-by registration",
}

// appFilterFlagDefs is the single source for the postback list/report filter
// flags: flag name (kebab-case; the normalizer also accepts snake_case), the
// API query parameter it feeds, and its help text. One table, so a flag
// cannot be registered without being collected or collected without being
// registered.
var appFilterFlagDefs = []struct {
	flag  string
	param string
	help  string
}{
	{"time-from", "time_from", "Received-at range start (unix timestamp)"},
	{"time-to", "time_to", "Received-at range end (unix timestamp)"},
	{"registration-id", "registration_id", "Filter by registration (from `p202 app list`)"},
	{"registration-ids", "registration_ids", "Filter by several registrations, comma-separated"},
	{"protocol", "protocol", "Filter by protocol: skadnetwork (skan) or adattributionkit (aak)"},
	{"conversion-type", "conversion_type", "Filter: download, redownload, re-engagement"},
	{"ad-interaction-type", "ad_interaction_type", "Filter: view (view-through) or click"},
	{"app-id", "app_id", "Filter by the App Store id the postback itself named (forensic; prefer --registration-id)"},
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

func registerAppFilterFlags(cmd *cobra.Command) {
	for _, def := range appFilterFlagDefs {
		cmd.Flags().String(def.flag, "", def.help)
	}
}

func collectAppFilters(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	for _, def := range appFilterFlagDefs {
		if v, _ := cmd.Flags().GetString(def.flag); v != "" {
			params[def.param] = v
		}
	}
	return params
}

// appRegistrationBodyFields / appEncodingBodyFields map each create/update
// flag to its API body field — the one place the flag↔field correspondence
// lives.
var appRegistrationBodyFields = map[string]string{
	"store-link":          "store_link",
	"platform":            "platform",
	"app-key":             "app_key",
	"app-name":            "app_name",
	"notes":               "notes",
	"accept-test-signals": "accept_test_signals",
}

// validateAppRegistrationBody refuses the flag values the server would
// reject, so the error names the flag rather than a JSON field.
func validateAppRegistrationBody(body map[string]string) error {
	if v, ok := body["accept_test_signals"]; ok && v != "0" && v != "1" {
		return validationError("--accept-test-signals must be 0 or 1, got %q", v).
			WithHint("1 trusts test signals for this app (AdAttributionKit development-signed postbacks; Android test installs) — integration testing only; 0 stores them flagged and uncounted.")
	}
	if v, ok := body["platform"]; ok && v != "ios" && v != "android" {
		return validationError("--platform must be one of: ios, android, got %q", v).
			WithHint("Leave --platform out to let the server read it from --app-key or --store-link.")
	}
	return nil
}

var appEncodingBodyFields = map[string]string{
	"registration-id":  "registration_id",
	"fine-value":       "fine_value",
	"coarse-value":     "coarse_value",
	"goal-id":          "goal_id",
	"revenue-override": "revenue_override",
}

// collectAppBody gathers flag values into an API body. changedOnly sends
// exactly the flags the caller set (updates: an explicitly empty value is a
// deliberate write); otherwise only non-empty values are sent (both creates,
// so an empty --fine-value never counts as "set" for the one-kind check).
func collectAppBody(cmd *cobra.Command, fields map[string]string, changedOnly bool) map[string]string {
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
// The list commands and the report share one registrar, one paging
// validator and one runner, so the same typo is the same error on every
// one of them.

// registerPagedListFlags registers the paging trio every app list command
// carries. Deliberately no --page (which the generated CRUD list commands
// offer): neither the postbacks controller nor the generic list reads a
// page parameter, so the flag would promise paging the server ignores.
func registerPagedListFlags(cmd *cobra.Command) {
	cmd.Flags().StringP("limit", "l", "", "Max results")
	cmd.Flags().StringP("offset", "o", "", "Pagination offset")
	cmd.Flags().Bool("all", false, "Fetch all rows across pages")
}

// pagingFlagValue returns a validated paging flag's value, or "" when the
// flag was not given. The server rejects a non-numeric value too, but only
// after a round trip and with a different category; a mistyped flag is the
// caller's mistake on every command that accepts it.
func pagingFlagValue(cmd *cobra.Command, flag string) (string, error) {
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

// collectPagingFlags validates --limit/--offset and adds the ones that were
// given to params.
func collectPagingFlags(cmd *cobra.Command, params map[string]string) error {
	for _, flag := range []string{"limit", "offset"} {
		v, err := pagingFlagValue(cmd, flag)
		if err != nil {
			return err
		}
		if v != "" {
			params[flag] = v
		}
	}
	return nil
}

// runPagedList is the body every app list command shares. Paging is validated
// before the client is built, so a bad --limit is a validation error even
// where no server URL is configured. params carries the command's own
// filters.
func runPagedList(cmd *cobra.Command, endpoint string, params map[string]string) error {
	if err := collectPagingFlags(cmd, params); err != nil {
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
		return listAllPagedRows(c, endpoint, params)
	}
	data, err := c.Get(endpoint, params)
	if err != nil {
		return err
	}
	render(data)
	return nil
}

// newAppGetCmd builds the `get <id>` command for one app endpoint: they read
// a row the same way and differ only in the path.
func newAppGetCmd(endpoint, short string) *cobra.Command {
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

// listAllPagedRows fetches every page of an app list endpoint and renders the
// rows in the list envelope shape, so --all output is structurally identical
// to a paged list.
func listAllPagedRows(c *api.Client, endpoint string, params map[string]string) error {
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

// ── Registrations ───────────────────────────────────────────────────

var appListCmd = &cobra.Command{
	Use:   "list",
	Short: "List registered apps (both platforms)",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if platform, _ := cmd.Flags().GetString("platform"); platform != "" {
			if platform != "ios" && platform != "android" {
				return validationError("--platform must be one of: ios, android, got %q", platform)
			}
			params["filter[platform]"] = platform
		}
		return runPagedList(cmd, "apps", params)
	},
}

var appGetCmd = newAppGetCmd("apps",
	"Get a registered app by its registration id (from `p202 app list`)")

var appCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Register an app from a store link or an app key (claims its stored postbacks)",
	Long: "Registers one app. Name it with --store-link (an App Store or Google Play link, a\n" +
		"market:// link, a numeric App Store id or a package name) or with --app-key (the App\n" +
		"Store id for iOS, the package name for Android; --platform is read from the key's\n" +
		"shape when left out). The server reads the link, so every client parses it the same way.",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAppBody(cmd, appRegistrationBodyFields, false)
		_, hasLink := body["store_link"]
		_, hasKey := body["app_key"]
		if hasLink == hasKey {
			return validationError("name the app with exactly one of --store-link or --app-key").
				WithHint("e.g. `--store-link https://apps.apple.com/us/app/summit-run/id990077001`, or `--app-key com.example.app`.")
		}
		if body["app_name"] == "" {
			return validationError("required flag --app-name is missing").
				WithHint("Pass the display name reports should show, e.g. `--app-name \"Summit Run\"`.")
		}
		if err := validateAppRegistrationBody(body); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		// Deliberately no --idempotency-key: the server does not record a
		// replayable response for app creates (the response carries the app
		// token). Retries are safe anyway — (platform, app_key) is globally
		// unique, so a duplicate create answers 409.
		data, err := c.Post("apps", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a registered app's name, notes or test-signal policy (re-runs the postback claim)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAppBody(cmd, appRegistrationBodyFields, true)
		if len(body) == 0 {
			// Same sentence the generated CRUD update commands use, so an
			// agent scripting against one wording works on both surfaces.
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("Pass at least one of --app-name, --notes, --accept-test-signals.")
		}
		if err := validateAppRegistrationBody(body); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("apps/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete an app registration (its SKAN encodings go with it; claimed postbacks keep their owner)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "apps", "app registration")
	},
}

var appRotateTokenCmd = &cobra.Command{
	Use:   "rotate-token <id>",
	Short: "Replace the app token (the old token stops working immediately)",
	Long: "Mints a new app token for the registration and invalidates the old one — the\n" +
		"remedy when builds should no longer reach the server with the token they shipped.\n" +
		"The token is an identifier, not a secret: it ships inside every copy of the app.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("apps/"+args[0]+"/app-token/rotate", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appSchemaCmd = &cobra.Command{
	Use:   "schema <registration-id>",
	Short: "Show the app's schema document exactly as devices fetch it",
	Long: "Reads the registration (for its app token), then fetches the public GET /apps/schema\n" +
		"endpoint with it in the X-P202-App-Token header — the same request the SDK in the app\n" +
		"makes — so what you see is byte-for-byte what shipped builds receive.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		appData, err := c.Get("apps/"+args[0], nil)
		if err != nil {
			return err
		}
		var envelope struct {
			Data struct {
				AppToken string `json:"app_token"`
			} `json:"data"`
		}
		// An unreadable response and a registration without a token are
		// different failures with different remedies.
		if err := json.Unmarshal(appData, &envelope); err != nil {
			return withHint(
				fmt.Errorf("reading registration %s: could not decode the server's response: %w", args[0], err),
				"The server returned something that is not the expected JSON envelope; check the configured URL with `p202 config show` and that it reaches the API and not a proxy error page.",
			)
		}
		if envelope.Data.AppToken == "" {
			return validationError("this registration has no app token").
				WithHint("The server must advertise features.app_platforms in /capabilities; `p202 app get " + args[0] + "` shows the registration.")
		}
		// The token travels as a header, exactly as the SDKs send it — never
		// as a query parameter, which request logging would capture.
		data, err := c.GetWithHeaders("apps/schema", nil,
			map[string]string{"X-P202-App-Token": envelope.Data.AppToken})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── Postbacks (read-only) ───────────────────────────────────────────

var appPostbacksCmd = &cobra.Command{
	Use:     "postbacks",
	Aliases: []string{"postback", "pb"},
	Short:   "Received SKAdNetwork and AdAttributionKit postbacks (read-only)",
}

var appPostbacksListCmd = &cobra.Command{
	Use:   "list",
	Short: "List received postbacks with filters (registration, protocol, signature state, network, window)",
	RunE: func(cmd *cobra.Command, args []string) error {
		return runPagedList(cmd, "apps/postbacks", collectAppFilters(cmd))
	},
}

var appPostbacksGetCmd = newAppGetCmd("apps/postbacks",
	"Get one postback, including its attribution signature")

// ── Report ──────────────────────────────────────────────────────────

var appReportCmd = &cobra.Command{
	Use:   "report",
	Short: "Aggregate postback report with SKAN decoding",
	Long: "Groups postbacks by day (UTC), registration, ad-network, source, country, version,\n" +
		"protocol, or conversion-type and decodes winning postbacks' conversion values through\n" +
		"the encodings in `p202 app encoding`. Every metric counts unique postbacks, so a replay\n" +
		"counts once, and counts trusted postbacks only unless --signature picks a class;\n" +
		"trusted_count, refuted_count, unvouched_count and test_count show every class beside them.\n" +
		"installs = winning first-window downloads; redownloads and re-engagements\n" +
		"(AdAttributionKit) are reported beside them.",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := collectAppFilters(cmd)
		groupBy, _ := cmd.Flags().GetString("group-by")
		if groupBy != "" {
			params["group_by"] = groupBy
		}
		limit, err := pagingFlagValue(cmd, "limit")
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
		data, err := c.Get("apps/report", params)
		if err != nil {
			return err
		}
		render(reshapeAppReport(data))
		return nil
	},
}

// reshapeAppReport lifts data.groups to the top-level data array — the shape
// every list command renders — moving group_by into meta. Applied in every
// output mode so --json and the table agree on structure. Anything
// unexpected is passed through untouched.
func reshapeAppReport(data []byte) []byte {
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

var appVerifyCmd = &cobra.Command{
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
			// no error envelope, so `p202 app verify --json` run without
			// piping anything hung instead of telling the caller what to
			// pass. isTerminal (shell.go) is the character-device test,
			// which also covers stdin redirected from /dev/null.
			if isTerminal(os.Stdin) {
				return validationError("no postback JSON to read: stdin is not a pipe or a file").
					WithHint("Pass --file <postback.json>, or pipe the postback in: `cat postback.json | p202 app verify`.")
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
		data, err := c.Post("apps/verify", payload)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// ── SKAN encodings ──────────────────────────────────────────────────

// hintRegistrationID names the command that produces a valid
// --registration-id or --goal-id when the server refused the one given;
// every other failure keeps the hint its class already carries.
func hintRegistrationID(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 422 {
		if _, ok := apiErr.FieldErrors["registration_id"]; ok {
			return withHint(err, "--registration-id takes an iOS registration's id from `p202 app list --platform ios`, or 0 for the account-wide encodings.")
		}
		if _, ok := apiErr.FieldErrors["goal_id"]; ok {
			return withHint(err, "--goal-id takes a live goal of that app (`p202 goal list --registration-id <id>`) or of the account (`p202 goal list --account`) that a device can reach: the iOS SDK evaluates it on the device, where there is no click, so neither the goal nor any goal it waits for may count `within` \"from\": \"click\". Create one with `p202 goal create --registration-id <id> --name <event> --event <event>`.")
		}
	}
	return err
}

var appEncodingCmd = &cobra.Command{
	Use:     "encoding",
	Aliases: []string{"encodings", "skan-encoding", "skan-encodings"},
	Short:   "SKAN encodings: which conversion value means which goal was reached (mirror of the in-app schema)",
}

var appEncodingListCmd = &cobra.Command{
	Use:   "list",
	Short: "List SKAN encodings",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("registration-id"); v != "" {
			params["filter[registration_id]"] = v
		}
		return runPagedList(cmd, "apps/skan-encodings", params)
	},
}

var appEncodingGetCmd = newAppGetCmd("apps/skan-encodings",
	"Get a SKAN encoding")

var appEncodingCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create an encoding (one fine value 0-63 OR one coarse value) for a registration or account-wide",
	RunE: func(cmd *cobra.Command, args []string) error {
		body := collectAppBody(cmd, appEncodingBodyFields, false)
		if body["goal_id"] == "" {
			return validationError("required flag --goal-id is missing").
				WithHint("An encoding names the goal a value means: `p202 goal list --registration-id <id>` (or --account) shows them, `p202 goal create` makes one.")
		}
		_, hasFine := body["fine_value"]
		_, hasCoarse := body["coarse_value"]
		if hasFine == hasCoarse {
			return validationError("an encoding maps exactly one conversion value").
				WithHint("Pass --fine-value 0..63 (the 6-bit value) or --coarse-value low|medium|high — one of them, not both.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("apps/skan-encodings", body, idemKey)
		if err != nil {
			return hintRegistrationID(err)
		}
		render(data)
		return nil
	},
}

var appEncodingUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update an encoding (switch kinds with --clear-fine-value/--clear-coarse-value)",
	Long: "Updates fields of an encoding. An encoding always maps exactly ONE conversion\n" +
		"value, so switching one between kinds takes the clear and the replacement in one\n" +
		"command: `update 5 --clear-fine-value --coarse-value high` turns a fine encoding\n" +
		"into a coarse one. The clear flags send an explicit JSON null.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		clearFine, _ := cmd.Flags().GetBool("clear-fine-value")
		clearCoarse, _ := cmd.Flags().GetBool("clear-coarse-value")
		clearOverride, _ := cmd.Flags().GetBool("clear-revenue-override")
		if clearOverride && cmd.Flags().Changed("revenue-override") {
			return validationError("--clear-revenue-override and --revenue-override are mutually exclusive")
		}
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
		for field, v := range collectAppBody(cmd, appEncodingBodyFields, true) {
			body[field] = v
		}
		if clearFine {
			body["fine_value"] = nil
		}
		if clearCoarse {
			body["coarse_value"] = nil
		}
		if clearOverride {
			body["revenue_override"] = nil
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("Pass at least one of --registration-id, --fine-value, --coarse-value, --goal-id, --revenue-override, or a --clear-* flag (with its replacement value, for the kinds).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("apps/skan-encodings/"+args[0], body)
		if err != nil {
			return hintRegistrationID(err)
		}
		render(data)
		return nil
	},
}

var appEncodingDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Delete an encoding",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "apps/skan-encodings", "SKAN encoding")
	},
}

func init() {
	registerPagedListFlags(appListCmd)
	appListCmd.Flags().String("platform", "", "Only this platform: ios or android")
	// Which app a registration is can only be said at create: the server
	// refuses a different app on update, so update does not offer the flags.
	appCreateCmd.Flags().String("store-link", "", "App Store or Google Play link, market:// link, App Store id or package name")
	appCreateCmd.Flags().String("platform", "", "ios or android (default: read from --app-key or --store-link)")
	appCreateCmd.Flags().String("app-key", "", "The App Store id (iOS) or the package name (Android)")
	for _, cmd := range []*cobra.Command{appCreateCmd, appUpdateCmd} {
		cmd.Flags().String("app-name", "", "Display name for reports")
		cmd.Flags().String("notes", "", "Free-form notes")
		cmd.Flags().String("accept-test-signals", "", "1 = trust test signals for this app (AdAttributionKit development-signed postbacks; integration testing), 0 = store them flagged (default)")
	}
	registerDeleteFlags(appDeleteCmd, "app registration")

	registerPagedListFlags(appPostbacksListCmd)
	registerAppFilterFlags(appPostbacksListCmd)
	appPostbacksCmd.AddCommand(appPostbacksListCmd, appPostbacksGetCmd)

	appReportCmd.Flags().String("group-by", "day", "Group results by: day, registration, ad-network, source, country, version, protocol, conversion-type")
	appReportCmd.Flags().StringP("limit", "l", "", "Max groups to return (default 100)")
	registerAppFilterFlags(appReportCmd)

	appVerifyCmd.Flags().StringP("file", "f", "", "Path to the postback JSON (default: read piped stdin)")

	registerPagedListFlags(appEncodingListCmd)
	appEncodingListCmd.Flags().String("registration-id", "", "Only this registration's encodings (0 = the account-wide ones)")
	for _, cmd := range []*cobra.Command{appEncodingCreateCmd, appEncodingUpdateCmd} {
		cmd.Flags().String("registration-id", "", "iOS registration the encoding applies to (from `p202 app list`; 0 = account-wide)")
		cmd.Flags().String("fine-value", "", "Fine conversion value 0-63")
		cmd.Flags().String("coarse-value", "", "Coarse conversion value: low, medium, high")
		cmd.Flags().String("goal-id", "", "The goal the value means (`p202 goal list --registration-id <id>`; evaluated on the device, so no click window)")
		cmd.Flags().String("revenue-override", "", "Revenue per decoded postback, instead of the goal's own value (tiered decoding)")
	}
	appEncodingUpdateCmd.Flags().Bool("clear-revenue-override", false, "Go back to the goal's own value")
	appEncodingUpdateCmd.Flags().Bool("clear-fine-value", false, "Set fine_value to null (pair with --coarse-value to switch kinds)")
	appEncodingUpdateCmd.Flags().Bool("clear-coarse-value", false, "Set coarse_value to null (pair with --fine-value to switch kinds)")
	registerIdempotencyKeyFlag(appEncodingCreateCmd)
	registerDeleteFlags(appEncodingDeleteCmd, "SKAN encoding")
	appEncodingCmd.AddCommand(appEncodingListCmd, appEncodingGetCmd, appEncodingCreateCmd, appEncodingUpdateCmd, appEncodingDeleteCmd)

	appCmd.AddCommand(appListCmd, appGetCmd, appCreateCmd, appUpdateCmd, appDeleteCmd, appRotateTokenCmd, appSchemaCmd,
		appPostbacksCmd, appReportCmd, appVerifyCmd, appEncodingCmd)
	rootCmd.AddCommand(appCmd)
}
