package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Destinations: the ad platforms an account's conversions are sent to from
// its own server (/api/v3/destinations). Secrets (the GA4 API secret, the
// Google Ads credentials) are read from a file or piped stdin, never a flag
// value, and never printed: reads say only whether credentials are set.

var (
	destinationIDPattern    = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)
	destinationParamFields  = []string{"utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "keyword", "c1", "c2", "c3", "c4", "campaign_id", "click_id"}
	destinationConsent      = newEnum([]string{"granted", "denied", "unset"}, enumHint("What you state for Google's consent signal; unset stops sending it."))
	destinationTypes        = newEnum([]string{"ga4", "google_ads"})
	googleAdsCredentialKeys = []string{"developer_token", "oauth_client_id", "oauth_client_secret"}
)

const destinationListHint = "`p202 destination list` shows your destinations and their ids."

var destinationCmd = &cobra.Command{
	Use:   "destination",
	Short: "Send conversions to ad platforms (GA4, Google Ads) from your server: add, test, delivery log",
	Long: "Each new conversion is queued for every enabled destination with the conversion itself and\n" +
		"sent by the minutely cron job from this server, retried with backoff. Raw personal data never\n" +
		"leaves it: with --send-user-data, only SHA-256 hashes of the customer's normalised email and phone.\n\n" +
		"  p202 destination create --name \"Store GA4\" --measurement-id G-ABC123 --api-secret-file secret.txt\n" +
		"  p202 destination create --type google_ads --name Search --credentials-file google-ads.json\n" +
		"  p202 destination connect 2   # open the link it prints, signed in to Prosper202\n" +
		"  p202 destination pick 2 --account \"Online Store\" --action Purchase   # from your Google Ads account\n" +
		"  p202 destination test 1\n" +
		"  p202 destination deliveries 1 --status failed\n" +
		"  p202 destination resend 1 --status failed",
}

func destinationArg(raw string) error {
	if !destinationIDPattern.MatchString(raw) {
		return validationError("destination id must be a whole number greater than 0: %s", raw).WithHint("%s", destinationListHint)
	}
	return nil
}

func destinationNoStaged(name string) error {
	if api.StagedMode() {
		return validationError("--staged cannot apply to `destination %s`: the route is not stageable, and its body can carry a secret", name).
			WithHint("Run it without --staged.")
	}
	return nil
}

// readAPISecret reads the secret from --api-secret-file ("-" is piped stdin).
func readAPISecret(file string) (string, error) {
	var raw []byte
	var err error
	if file == "-" {
		if isTerminal(os.Stdin) {
			return "", validationError("--api-secret-file - reads piped stdin, and nothing is piped").
				WithHint("Pipe it in (`printf %%s \"$SECRET\" | p202 destination create … --api-secret-file -`) or pass a file path.")
		}
		raw, err = io.ReadAll(io.LimitReader(os.Stdin, 4096))
	} else {
		raw, err = os.ReadFile(file)
	}
	if err != nil {
		return "", validationError("reading the API secret: %v", err).WithHint("Pass the path of a file holding only the secret, or - for piped stdin.")
	}
	secret := strings.TrimSpace(string(raw))
	if secret == "" {
		return "", validationError("the API secret file is empty").
			WithHint("Create a secret in GA4: Admin › Data streams › your web stream › Measurement Protocol API secrets.")
	}
	return secret, nil
}

// readGoogleAdsCredentials reads the Google Ads credentials from a JSON file
// ("-" is piped stdin): developer_token, oauth_client_id, oauth_client_secret.
func readGoogleAdsCredentials(file string) (map[string]interface{}, error) {
	var raw []byte
	var err error
	if file == "-" {
		if isTerminal(os.Stdin) {
			return nil, validationError("--credentials-file - reads piped stdin, and nothing is piped").
				WithHint("Pipe the JSON in or pass a file path.")
		}
		raw, err = io.ReadAll(io.LimitReader(os.Stdin, 16384))
	} else {
		raw, err = os.ReadFile(file)
	}
	if err != nil {
		return nil, validationError("reading the credentials file: %v", err).WithHint("Pass the path of a JSON file, or - for piped stdin.")
	}
	creds := map[string]interface{}{}
	if err := json.Unmarshal(raw, &creds); err != nil {
		return nil, validationError("the credentials file is not a JSON object: %v", err).
			WithHint(`It holds {"developer_token": "…", "oauth_client_id": "….apps.googleusercontent.com", "oauth_client_secret": "…"}.`)
	}
	for _, k := range googleAdsCredentialKeys {
		if v, ok := creds[k].(string); !ok || strings.TrimSpace(v) == "" {
			return nil, validationError("the credentials file has no %s", k).
				WithHint("It needs %s; the refresh token is stored by `p202 destination connect`, never typed.", strings.Join(googleAdsCredentialKeys, ", "))
		}
	}
	for k := range creds {
		if !containsString(googleAdsCredentialKeys, k) {
			return nil, validationError("the credentials file holds %s, which is not a Google Ads credential", k).
				WithHint("It takes only %s.", strings.Join(googleAdsCredentialKeys, ", "))
		}
	}
	return creds, nil
}

// destinationTypeFlags are the flags only one destination type reads (its
// settingKeys and its credentials). A flag of the other type is refused,
// never dropped: an agent would read a dropped flag as applied.
var destinationTypeFlags = map[string][]string{
	"ga4":        {"measurement-id", "api-secret-file", "event-name", "client-id-source", "user-id-source", "param"},
	"google_ads": {"credentials-file", "customer-id", "login-customer-id", "conversion-action", "campaign-conversion-action"},
}

// destinationFlagsFor refuses a flag the destination's type does not read.
func destinationFlagsFor(cmd *cobra.Command, typ, hint string) error {
	for _, other := range destinationTypes.values {
		if other == typ {
			continue
		}
		for _, f := range destinationTypeFlags[other] {
			if cmd.Flags().Changed(f) {
				return validationError("--%s is for %s destinations; this one is %s", f, other, typ).WithHint(hint, f)
			}
		}
	}
	return nil
}

// destinationTypeFlagChanged reports whether any type-specific flag was given.
func destinationTypeFlagChanged(cmd *cobra.Command) bool {
	for _, flags := range destinationTypeFlags {
		for _, f := range flags {
			if cmd.Flags().Changed(f) {
				return true
			}
		}
	}
	return false
}

// destinationSettings applies the flags the caller set over $base (the
// stored settings on an update, empty on a create).
func destinationSettings(cmd *cobra.Command, base map[string]interface{}) (map[string]interface{}, error) {
	s := map[string]interface{}{}
	for k, v := range base {
		s[k] = v
	}
	for flag, key := range map[string]string{"measurement-id": "measurement_id", "event-name": "event_name",
		"customer-id": "customer_id", "login-customer-id": "login_customer_id", "conversion-action": "conversion_action"} {
		if cmd.Flags().Changed(flag) {
			v, _ := cmd.Flags().GetString(flag)
			s[key] = v
		}
	}
	for flag, key := range map[string]string{"client-id-source": "client_id_source", "user-id-source": "user_id_source"} {
		if v := enumValue(cmd, flag); v != "" {
			s[key] = v
		}
	}
	if cmd.Flags().Changed("param") {
		params := map[string]interface{}{}
		values, _ := cmd.Flags().GetStringArray("param")
		for _, p := range values {
			name, field, ok := strings.Cut(p, "=")
			if !ok || name == "" || !containsString(destinationParamFields, field) {
				return nil, validationError("--param %q must be name=field, the field one of: %s", p, strings.Join(destinationParamFields, ", ")).
					WithHint("For example --param campaign=utm_campaign --param keyword=keyword.")
			}
			params[name] = field
		}
		s["params"] = params
	}
	if cmd.Flags().Changed("campaign-conversion-action") {
		// Merged into the stored map: each flag sets one campaign's action, and N= (on an update) removes it.
		actions := map[string]interface{}{}
		if stored, ok := s["campaign_conversion_actions"].(map[string]interface{}); ok {
			for k, v := range stored {
				actions[k] = v
			}
		}
		values, _ := cmd.Flags().GetStringArray("campaign-conversion-action")
		for _, p := range values {
			id, action, ok := strings.Cut(p, "=")
			if n, err := strconv.Atoi(id); !ok || err != nil || n <= 0 || (action == "" && base == nil) {
				return nil, validationError("--campaign-conversion-action %q must be campaign_id=conversion_action", p).
					WithHint("For example --campaign-conversion-action 12=987654; on an update, 12= removes campaign 12's. `p202 campaign list` shows the campaign ids.")
			}
			if action == "" {
				delete(actions, id)
				continue
			}
			actions[id] = action
		}
		s["campaign_conversion_actions"] = actions
	}
	if all, _ := cmd.Flags().GetBool("all-campaigns"); all {
		if cmd.Flags().Changed("campaign-id") {
			return nil, validationError("--all-campaigns and --campaign-id are exclusive").WithHint("Give one of them.")
		}
		s["campaign_ids"] = []int{}
	}
	if cmd.Flags().Changed("campaign-id") {
		raw, _ := cmd.Flags().GetStringArray("campaign-id")
		ids := []int{}
		for _, r := range raw {
			n, err := strconv.Atoi(r)
			if err != nil || n <= 0 {
				return nil, validationError("--campaign-id must be a campaign id: %s", r).WithHint("`p202 campaign list` shows the ids.")
			}
			ids = append(ids, n)
		}
		s["campaign_ids"] = ids
	}
	if cmd.Flags().Changed("send-user-data") {
		v, _ := cmd.Flags().GetBool("send-user-data")
		s["send_user_data"] = v
	}
	consent, _ := s["consent"].(map[string]interface{})
	if consent == nil {
		consent = map[string]interface{}{}
	}
	for flag, key := range map[string]string{"ad-user-data": "ad_user_data", "ad-personalization": "ad_personalization"} {
		switch v := enumValue(cmd, flag); v {
		case "":
		case "unset":
			delete(consent, key)
		default:
			consent[key] = v
		}
	}
	s["consent"] = consent
	return s, nil
}

func destinationClient(name string) (*api.Client, error) {
	if err := destinationNoStaged(name); err != nil {
		return nil, err
	}
	return api.NewFromConfig()
}

var destinationListCmd = &cobra.Command{
	Use:   "list",
	Short: "List destinations (credentials are never shown, only whether they are stored)",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("destinations", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var destinationGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Show one destination and its settings",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("destinations/"+args[0], nil)
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

var destinationCreateCmd = &cobra.Command{
	Use:   "create --name <name> --measurement-id <G-…> --api-secret-file <file>",
	Short: "Add a GA4 or Google Ads destination",
	Long: "GA4 (the default): each new conversion goes to a GA4 web stream as an event (purchase by default)\n" +
		"with its value, currency and transaction id. Run `p202 destination test <id>` after: GA4's\n" +
		"validation endpoint says what is wrong without recording anything.\n\n" +
		"--type google_ads uploads each new conversion as a click conversion with your own developer\n" +
		"token and OAuth client (--credentials-file, a JSON file). Then run\n" +
		"`p202 destination connect <id>` and open the address it prints; until then conversions wait.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		if enumValue(cmd, "type") == "google_ads" {
			return createGoogleAdsDestination(cmd)
		}
		if err := destinationFlagsFor(cmd, "ga4", "Add --type google_ads to make a Google Ads destination, or leave --%s out."); err != nil {
			return err
		}
		name, _ := cmd.Flags().GetString("name")
		file, _ := cmd.Flags().GetString("api-secret-file")
		if name == "" || file == "" || !cmd.Flags().Changed("measurement-id") {
			return validationError("--name, --measurement-id and --api-secret-file are required").
				WithHint("The Measurement ID and the API secret are on GA4's Admin › Data streams › your web stream.")
		}
		settings, err := destinationSettings(cmd, nil)
		if err != nil {
			return err
		}
		secret, err := readAPISecret(file)
		if err != nil {
			return err
		}
		c, err := destinationClient("create")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations", map[string]interface{}{
			"type": "ga4", "name": name, "settings": settings, "credentials": map[string]interface{}{"api_secret": secret},
		})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func createGoogleAdsDestination(cmd *cobra.Command) error {
	if err := destinationFlagsFor(cmd, "google_ads", "Leave --%s out: it is a GA4 setting."); err != nil {
		return err
	}
	name, _ := cmd.Flags().GetString("name")
	file, _ := cmd.Flags().GetString("credentials-file")
	if name == "" || file == "" {
		return validationError("--name and --credentials-file are required for google_ads").
			WithHint("The credentials file is JSON with developer_token, oauth_client_id and oauth_client_secret. Leave --customer-id and --conversion-action out to choose them after `p202 destination connect`, with `p202 destination pick`.")
	}
	if cmd.Flags().Changed("conversion-action") && !cmd.Flags().Changed("customer-id") {
		return validationError("--conversion-action needs --customer-id: a conversion action belongs to an account").
			WithHint("Give both, or neither: after `p202 destination connect <id>`, `p202 destination pick <id>` chooses them from your Google Ads account by name.")
	}
	settings, err := destinationSettings(cmd, nil)
	if err != nil {
		return err
	}
	creds, err := readGoogleAdsCredentials(file)
	if err != nil {
		return err
	}
	c, err := destinationClient("create")
	if err != nil {
		return err
	}
	data, err := c.Post("destinations", map[string]interface{}{
		"type": "google_ads", "name": name, "settings": settings, "credentials": creds,
	})
	if err != nil {
		return err
	}
	render(data)
	return nil
}

var destinationConnectCmd = &cobra.Command{
	Use:   "connect <id>",
	Short: "Connect a Google Ads destination to your Google account (prints the consent link to open)",
	Long: "Prints the link to Google's consent screen for the destination's OAuth client. Open it within 10\n" +
		"minutes in a browser signed in to Prosper202 as the same user; Google sends it back to this install,\n" +
		"which stores the refresh token. Register the redirect_uri it prints on the OAuth client first.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		c, err := destinationClient("connect")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations/"+args[0]+"/oauth/start", map[string]interface{}{})
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

const destinationPickHint = "`p202 destination accounts <id>` lists the accounts, `p202 destination conversion-actions <id>` the actions; a destination must be connected first (`p202 destination connect <id>`)."

var destinationAccountsCmd = &cobra.Command{
	Use:   "accounts <id>",
	Short: "List the Google Ads accounts a connected destination can send conversions to",
	Long: "Reads, from Google, the accounts the Google user you connected can open: each with its customer ID,\n" +
		"its name and, when it is reached through a manager account, that manager (its login customer ID).\n" +
		"`p202 destination pick <id> --account <name or ID>` sets one.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("destinations/"+args[0]+"/google-ads/accounts", nil)
		if err != nil {
			return googleAdsReadError(err, fmt.Sprintf("The destination must be a connected Google Ads one: `p202 destination get %s` shows its status, `p202 destination connect %s` connects it.", args[0], args[0]))
		}
		render(data)
		return nil
	},
}

// googleAdsReadError attaches hint to a failed Google Ads read, except to a
// 429: the server's per-key limit on these reads, whose only next step is the
// class-wide one, to wait the seconds it names (api.HintFor).
func googleAdsReadError(err error, hint string) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == http.StatusTooManyRequests {
		return err
	}
	return withHint(err, "%s", hint)
}

var destinationConversionActionsCmd = &cobra.Command{
	Use:   "conversion-actions <id>",
	Short: "List the conversion actions of a Google Ads account that take uploaded clicks",
	Long: "Reads, from Google, the enabled conversion actions that take uploaded click conversions (source Import):\n" +
		"of the destination's own account, or of --customer-id (with --login-customer-id when it is reached\n" +
		"through a manager). `p202 destination pick <id> --action <name or ID>` sets one.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		params := map[string]string{}
		for flag, key := range map[string]string{"customer-id": "customer_id", "login-customer-id": "login_customer_id"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				if v == "" {
					return validationError("--%s cannot be empty", flag).WithHint("Give the 10-digit customer ID (123-456-7890), or leave the flag out for the destination's own account.")
				}
				params[key] = v
			}
		}
		if _, login := params["login_customer_id"]; login && params["customer_id"] == "" {
			return validationError("--login-customer-id needs --customer-id: it is the manager that account is reached through").
				WithHint("`p202 destination accounts %s` shows each account with its manager.", args[0])
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("destinations/"+args[0]+"/google-ads/conversion-actions", params)
		if err != nil {
			return googleAdsReadError(err, destinationPickHint)
		}
		render(data)
		return nil
	},
}

var destinationPickCmd = &cobra.Command{
	Use:   "pick <id> [--account <name or ID>] [--action <name or ID>]",
	Short: "Set a Google Ads destination's account and conversion action by name, from your Google Ads account",
	Long: "Chooses from what Google lists for the connected Google user, so no ID has to be looked up:\n\n" +
		"  p202 destination pick 2 --account \"Online Store\" --action \"Store purchase\"\n" +
		"  p202 destination pick 2                      (one account and one action: both are taken)\n" +
		"  p202 destination pick 2 --action \"Sign-up\"   (keeps the account, changes the action)\n\n" +
		"A name is matched whole, ignoring case; an ID works too. Without --account the destination keeps its\n" +
		"account, or takes the only one there is; without --action it keeps its action or takes the account's\n" +
		"only one. When there are several, the error lists them. The manager an account is reached through is\n" +
		"set as its login customer ID. Another account takes none of the old one's conversion actions with it.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		body := map[string]interface{}{}
		for flag, key := range map[string]string{"account": "account", "action": "conversion_action"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				if strings.TrimSpace(v) == "" {
					return validationError("--%s cannot be empty", flag).WithHint("Give a name or an ID, or leave the flag out. %s", destinationPickHint)
				}
				body[key] = v
			}
		}
		c, err := destinationClient("pick")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations/"+args[0]+"/google-ads/pick", body)
		if err != nil {
			return googleAdsReadError(err, destinationPickHint)
		}
		render(data)
		return nil
	},
}

var destinationUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a destination: name, on/off, settings, or its API secret",
	Long: "Only the flags you give are applied; the rest of the settings stay as they are.\n\n" +
		"  p202 destination update 1 --disable\n" +
		"  p202 destination update 1 --campaign-id 12 --campaign-id 14 --send-user-data\n" +
		"  p202 destination update 2 --campaign-conversion-action 12=987655   (sets campaign 12's; 12= removes it)",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		enable, _ := cmd.Flags().GetBool("enable")
		disable, _ := cmd.Flags().GetBool("disable")
		if enable && disable {
			return validationError("--enable and --disable are exclusive").WithHint("Give one of them.")
		}
		if cmd.Flags().Changed("api-secret-file") && cmd.Flags().Changed("credentials-file") {
			return validationError("--api-secret-file and --credentials-file are exclusive: a destination has one kind of credentials").
				WithHint("--api-secret-file is a GA4 destination's, --credentials-file a Google Ads one's; `p202 destination get %s` shows its type.", args[0])
		}
		c, err := destinationClient("update")
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("name") {
			v, _ := cmd.Flags().GetString("name")
			body["name"] = v
		}
		if enable || disable {
			body["enabled"] = enable
		}
		settingsFlags := []string{"measurement-id", "event-name", "client-id-source", "user-id-source", "param", "campaign-id", "all-campaigns", "send-user-data", "ad-user-data", "ad-personalization",
			"customer-id", "login-customer-id", "conversion-action", "campaign-conversion-action"}
		changed := false
		for _, f := range settingsFlags {
			changed = changed || cmd.Flags().Changed(f)
		}
		if changed || destinationTypeFlagChanged(cmd) {
			raw, err := c.Get("destinations/"+args[0], nil)
			if err != nil {
				return withHint(err, "%s", destinationListHint)
			}
			var current struct {
				Data struct {
					Type     string                 `json:"type"`
					Settings map[string]interface{} `json:"settings"`
				} `json:"data"`
			}
			if err := json.Unmarshal(raw, &current); err != nil {
				return fmt.Errorf("reading destination %s: %w", args[0], err)
			}
			if err := destinationFlagsFor(cmd, current.Data.Type, "Leave --%s out; `p202 destination get "+args[0]+"` shows the destination's type and settings."); err != nil {
				return err
			}
			if changed {
				if body["settings"], err = destinationSettings(cmd, current.Data.Settings); err != nil {
					return err
				}
			}
		}
		if file, _ := cmd.Flags().GetString("api-secret-file"); file != "" {
			secret, err := readAPISecret(file)
			if err != nil {
				return err
			}
			body["credentials"] = map[string]interface{}{"api_secret": secret}
		}
		if file, _ := cmd.Flags().GetString("credentials-file"); file != "" {
			creds, err := readGoogleAdsCredentials(file)
			if err != nil {
				return err
			}
			body["credentials"] = creds
		}
		if len(body) == 0 {
			return validationError("nothing to change").WithHint("Give at least one flag; `p202 destination update --help` lists them.")
		}
		data, err := c.Patch("destinations/"+args[0], body)
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

var destinationDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a destination (its unsent conversions are cancelled; the log stays)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		for _, a := range args {
			if err := destinationArg(a); err != nil {
				return err
			}
		}
		if err := destinationNoStaged("delete"); err != nil {
			return err
		}
		return bulkOrSingleDelete(cmd, "destinations", "destination")
	},
}

var destinationTestCmd = &cobra.Command{
	Use:   "test <id>",
	Short: "Send a made-up conversion to the platform's validation endpoint and show its verdict",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		c, err := destinationClient("test")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations/"+args[0]+"/test", map[string]interface{}{})
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

var destinationDeliveriesCmd = &cobra.Command{
	Use:   "deliveries <id>",
	Short: "A destination's delivery log: sent, pending (retrying), failed, withheld, cancelled",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		params := map[string]string{}
		for _, f := range []string{"status", "kind"} {
			if v := enumValue(cmd, f); v != "" {
				params[f] = v
			}
		}
		for flag, key := range map[string]string{"conv-id": "conv_id", "limit": "limit", "offset": "offset"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetInt(flag)
				params[key] = strconv.Itoa(v)
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("destinations/"+args[0]+"/deliveries", params)
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

var destinationResendStatuses = newEnum([]string{"failed", "withheld"})

var destinationResendCmd = &cobra.Command{
	Use:   "resend <id> (--delivery-id <n> | --status failed|withheld)",
	Short: "Send failed or withheld conversions again, with all their tries: one delivery, or every one of a status",
	Long: "After you fix what the platform refused (a token, an account, a conversion action), the conversions\n" +
		"that ran out of tries stay failed until they are sent again. --status takes the deliveries of the last\n" +
		"90 days, 5,000 a call (the answer's `more` says to run it again); a withheld one is checked again and\n" +
		"withheld again if it is still held back. A conversion already sent is never sent twice.\n\n" +
		"  p202 destination deliveries 1 --status failed\n" +
		"  p202 destination resend 1 --status failed\n" +
		"  p202 destination resend 1 --delivery-id 4812",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		status := enumValue(cmd, "status")
		byID := cmd.Flags().Changed("delivery-id")
		if byID == (status != "") {
			return validationError("pass --delivery-id for one delivery or --status for every delivery of it, not both").
				WithHint("`p202 destination deliveries %s --status failed` lists the failed ones with their delivery_id.", args[0])
		}
		body := map[string]interface{}{"status": status}
		if byID {
			id, _ := cmd.Flags().GetInt("delivery-id")
			if id <= 0 {
				return validationError("--delivery-id must be a whole number greater than 0").
					WithHint("`p202 destination deliveries %s` shows each row's delivery_id.", args[0])
			}
			body = map[string]interface{}{"delivery_id": id}
		}
		c, err := destinationClient("resend")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations/"+args[0]+"/deliveries/resend", body)
		if err != nil {
			return withHint(err, "`p202 destination deliveries %s` shows its deliveries; %s", args[0], destinationListHint)
		}
		render(data)
		return nil
	},
}

var destinationBackfillCmd = &cobra.Command{
	Use:   "backfill <id> --days <n>",
	Short: "Queue the conversions of the last days a destination has no delivery for (recorded before it existed)",
	Long: "Queues each paid conversion of the last --days that this destination's campaigns take and its log has no\n" +
		"row for, oldest first, 5,000 a call (the answer's `more` says to run it again). GA4 takes 1 to 3 days\n" +
		"(it refuses an event more than 72 hours old); Google Ads 1 to 90. They go out with the cron job's runs.\n\n" +
		"  p202 destination backfill 2 --days 30",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := destinationArg(args[0]); err != nil {
			return err
		}
		days, _ := cmd.Flags().GetInt("days")
		if days < 1 || days > 90 {
			return validationError("--days must be 1 to 90 (1 to 3 for a GA4 destination)").
				WithHint("Pass how many days back to send, for example --days 3.")
		}
		c, err := destinationClient("backfill")
		if err != nil {
			return err
		}
		data, err := c.Post("destinations/"+args[0]+"/backfill", map[string]interface{}{"days": days})
		if err != nil {
			return withHint(err, "%s", destinationListHint)
		}
		render(data)
		return nil
	},
}

func destinationSettingFlags(cmd *cobra.Command) {
	cmd.Flags().String("name", "", "A name for it")
	cmd.Flags().String("measurement-id", "", "GA4 web stream Measurement ID (G-…)")
	cmd.Flags().String("api-secret-file", "", "File holding the GA4 Measurement Protocol API secret; - reads piped stdin")
	cmd.Flags().String("event-name", "", "GA4 event name (default purchase)")
	cmd.Flags().String("client-id-source", "", "Where the GA client id is, one of {values}: click (p202.<click id>) or the c variable your landing page puts the _ga id in")
	cmd.Flags().String("user-id-source", "", "Send a user_id, one of {values}: customer sends p202c.<customer id>")
	cmd.Flags().StringArray("param", nil, "An event parameter as name=field (repeatable), field one of: "+strings.Join(destinationParamFields, ", "))
	cmd.Flags().StringArray("campaign-id", nil, "Only this campaign's conversions (repeatable); none for every campaign")
	cmd.Flags().Bool("all-campaigns", false, "Every campaign's conversions (clears --campaign-id)")
	cmd.Flags().Bool("send-user-data", false, "Send SHA-256 hashes of the customer's email and phone (never the raw values)")
	cmd.Flags().String("ad-user-data", "", "Google consent signal you state, one of {values}")
	cmd.Flags().String("ad-personalization", "", "Google consent signal you state, one of {values}")
	cmd.Flags().String("credentials-file", "", "For google_ads: JSON file with developer_token, oauth_client_id, oauth_client_secret; - reads piped stdin")
	cmd.Flags().String("customer-id", "", "For google_ads: the customer ID conversions go to (123-456-7890)")
	cmd.Flags().String("login-customer-id", "", "For google_ads: the manager account your access goes through, if any")
	cmd.Flags().String("conversion-action", "", "For google_ads: conversion action ID (ctId in its address), or customers/<id>/conversionActions/<id>")
	cmd.Flags().StringArray("campaign-conversion-action", nil, "For google_ads: campaign_id=conversion_action for one campaign (repeatable); on update it is merged with the stored ones, and campaign_id= removes one")
	enumFlag(cmd, "client-id-source", newEnum([]string{"click", "c1", "c2", "c3", "c4"}))
	enumFlag(cmd, "user-id-source", newEnum([]string{"none", "customer"}))
	enumFlag(cmd, "ad-user-data", destinationConsent)
	enumFlag(cmd, "ad-personalization", destinationConsent)
}

func init() {
	destinationSettingFlags(destinationCreateCmd)
	destinationCreateCmd.Flags().String("type", "", "Destination type, one of {values} (default ga4)")
	enumFlag(destinationCreateCmd, "type", destinationTypes)
	destinationSettingFlags(destinationUpdateCmd)
	destinationUpdateCmd.Flags().Bool("enable", false, "Turn it on")
	destinationUpdateCmd.Flags().Bool("disable", false, "Turn it off (it queues nothing; what it queued waits)")
	registerDeleteFlags(destinationDeleteCmd, "destination")
	destinationDeliveriesCmd.Flags().String("status", "", "Only this status, one of {values}")
	destinationDeliveriesCmd.Flags().String("kind", "", "Only this kind, one of {values}")
	destinationDeliveriesCmd.Flags().Int("conv-id", 0, "Only this conversion's delivery")
	destinationDeliveriesCmd.Flags().Int("limit", 50, "Rows to return (1-500)")
	destinationDeliveriesCmd.Flags().Int("offset", 0, "Rows to skip")
	enumFlag(destinationDeliveriesCmd, "status", newEnum([]string{"pending", "sent", "failed", "withheld", "cancelled"}))
	enumFlag(destinationDeliveriesCmd, "kind", newEnum([]string{"conversion", "test"}))
	destinationConversionActionsCmd.Flags().String("customer-id", "", "The account whose actions to list (123-456-7890); default the destination's own")
	destinationConversionActionsCmd.Flags().String("login-customer-id", "", "The manager account --customer-id is reached through, if any")
	destinationPickCmd.Flags().String("account", "", "The Google Ads account, by its name or customer ID")
	destinationPickCmd.Flags().String("action", "", "The conversion action, by its name or ID")
	destinationResendCmd.Flags().Int("delivery-id", 0, "The one delivery to send again (from `destination deliveries`)")
	destinationResendCmd.Flags().String("status", "", "Send every delivery of this status again, one of {values}")
	enumFlag(destinationResendCmd, "status", destinationResendStatuses)
	destinationBackfillCmd.Flags().Int("days", 0, "How many days back (1-3 for GA4, 1-90 for Google Ads)")
	destinationCmd.AddCommand(destinationListCmd, destinationGetCmd, destinationCreateCmd, destinationUpdateCmd,
		destinationDeleteCmd, destinationTestCmd, destinationDeliveriesCmd, destinationConnectCmd,
		destinationResendCmd, destinationBackfillCmd,
		destinationAccountsCmd, destinationConversionActionsCmd, destinationPickCmd)
	rootCmd.AddCommand(destinationCmd)
}
