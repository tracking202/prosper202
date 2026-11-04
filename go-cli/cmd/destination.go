package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Destinations: the ad platforms an account's conversions are sent to from
// its own server (/api/v3/destinations). The API secret is read from a file
// or piped stdin, never a flag value, and never printed: reads say only
// whether credentials are set.

var (
	destinationIDPattern   = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)
	destinationParamFields = []string{"utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "keyword", "c1", "c2", "c3", "c4", "campaign_id", "click_id"}
	destinationConsent     = newEnum([]string{"granted", "denied"}, enumHint("What you state for Google's consent signal; leave it out to send none."))
)

const destinationListHint = "`p202 destination list` shows your destinations and their ids."

var destinationCmd = &cobra.Command{
	Use:   "destination",
	Short: "Send conversions to ad platforms (GA4) from your server: add, test, delivery log",
	Long: "Each new conversion is queued for every enabled destination with the conversion itself and\n" +
		"sent by the minutely cron job from this server, retried with backoff. Raw personal data never\n" +
		"leaves it: with --send-user-data, only SHA-256 hashes of the customer's normalised email and phone.\n\n" +
		"  p202 destination create --name \"Store GA4\" --measurement-id G-ABC123 --api-secret-file secret.txt\n" +
		"  p202 destination test 1\n" +
		"  p202 destination deliveries 1 --status failed",
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

// destinationSettings applies the flags the caller set over $base (the
// stored settings on an update, empty on a create).
func destinationSettings(cmd *cobra.Command, base map[string]interface{}) (map[string]interface{}, error) {
	s := map[string]interface{}{}
	for k, v := range base {
		s[k] = v
	}
	for flag, key := range map[string]string{"measurement-id": "measurement_id", "event-name": "event_name"} {
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
		if v := enumValue(cmd, flag); v != "" {
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
	Short: "Add a GA4 destination (Measurement Protocol)",
	Long: "Sends each new conversion to a GA4 web stream as an event (purchase by default) with its value,\n" +
		"currency and transaction id. Run `p202 destination test <id>` after: GA4's validation endpoint\n" +
		"says what is wrong without recording anything.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
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

var destinationUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a destination: name, on/off, settings, or its API secret",
	Long: "Only the flags you give are applied; the rest of the settings stay as they are.\n\n" +
		"  p202 destination update 1 --disable\n" +
		"  p202 destination update 1 --campaign-id 12 --campaign-id 14 --send-user-data",
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
		settingsFlags := []string{"measurement-id", "event-name", "client-id-source", "user-id-source", "param", "campaign-id", "send-user-data", "ad-user-data", "ad-personalization"}
		changed := false
		for _, f := range settingsFlags {
			changed = changed || cmd.Flags().Changed(f)
		}
		if changed {
			raw, err := c.Get("destinations/"+args[0], nil)
			if err != nil {
				return withHint(err, "%s", destinationListHint)
			}
			var current struct {
				Data struct {
					Settings map[string]interface{} `json:"settings"`
				} `json:"data"`
			}
			if err := json.Unmarshal(raw, &current); err != nil {
				return fmt.Errorf("reading destination %s: %w", args[0], err)
			}
			if body["settings"], err = destinationSettings(cmd, current.Data.Settings); err != nil {
				return err
			}
		}
		if file, _ := cmd.Flags().GetString("api-secret-file"); file != "" {
			secret, err := readAPISecret(file)
			if err != nil {
				return err
			}
			body["credentials"] = map[string]interface{}{"api_secret": secret}
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

func destinationSettingFlags(cmd *cobra.Command) {
	cmd.Flags().String("name", "", "A name for it")
	cmd.Flags().String("measurement-id", "", "GA4 web stream Measurement ID (G-…)")
	cmd.Flags().String("api-secret-file", "", "File holding the GA4 Measurement Protocol API secret; - reads piped stdin")
	cmd.Flags().String("event-name", "", "GA4 event name (default purchase)")
	cmd.Flags().String("client-id-source", "", "Where the GA client id is, one of {values}: click (p202.<click id>) or the c variable your landing page puts the _ga id in")
	cmd.Flags().String("user-id-source", "", "Send a user_id, one of {values}: customer sends the LTV customer's ref")
	cmd.Flags().StringArray("param", nil, "An event parameter as name=field (repeatable), field one of: "+strings.Join(destinationParamFields, ", "))
	cmd.Flags().StringArray("campaign-id", nil, "Only this campaign's conversions (repeatable); none for every campaign")
	cmd.Flags().Bool("send-user-data", false, "Send SHA-256 hashes of the customer's email and phone (never the raw values)")
	cmd.Flags().String("ad-user-data", "", "Google consent signal you state, one of {values}")
	cmd.Flags().String("ad-personalization", "", "Google consent signal you state, one of {values}")
	enumFlag(cmd, "client-id-source", newEnum([]string{"click", "c1", "c2", "c3", "c4"}))
	enumFlag(cmd, "user-id-source", newEnum([]string{"none", "customer"}))
	enumFlag(cmd, "ad-user-data", destinationConsent)
	enumFlag(cmd, "ad-personalization", destinationConsent)
}

func init() {
	destinationSettingFlags(destinationCreateCmd)
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
	destinationCmd.AddCommand(destinationListCmd, destinationGetCmd, destinationCreateCmd, destinationUpdateCmd,
		destinationDeleteCmd, destinationTestCmd, destinationDeliveriesCmd)
	rootCmd.AddCommand(destinationCmd)
}
