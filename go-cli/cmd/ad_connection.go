package cmd

import (
	"io"
	"os"
	"regexp"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Ad connections: ad platform accounts reached through their own API with the
// operator's key (/api/v3/ad-connections), ChatGPT Ads today. Keys are read
// from a file or piped stdin, never a flag value, and never printed: reads say
// only whether each is stored.

var (
	adConnectionIDPattern = regexp.MustCompile(`^[1-9][0-9]{0,9}$`)
	adConnectionSteps     = []string{"pixel", "conversions_key", "destination", "event_settings"}
	adConnectionEvents    = []string{"order_created", "lead_created", "registration_completed", "subscription_created", "trial_started",
		"appointment_scheduled", "checkout_started", "items_added", "contents_viewed", "page_viewed"}
)

const adConnectionListHint = "`p202 ad-connection list` shows your connections and their ids."

var adConnectionCmd = &cobra.Command{
	Use:   "ad-connection",
	Short: "Connect a ChatGPT Ads account with its Advertiser API key and set up its pixel, key and events",
	Long: "Paste one Advertiser API key (Ads Manager › Settings): Prosper202 checks it with OpenAI and fills the ad\n" +
		"account's name, currency and time zone, then `setup` finds or makes the web pixel, a Conversions API key,\n" +
		"the ChatGPT Ads destination and one event setting per event type. Keys are stored encrypted, never shown.\n\n" +
		"  p202 ad-connection create --api-key-file key.txt --ppc-account-id 4\n" +
		"  p202 ad-connection setup 1\n" +
		"  p202 ad-connection setup 1 --pixel-id 134534901 --source-id clidsrc_123   # pixels off for the account\n" +
		"  p202 ad-connection check 1",
}

func adConnectionArg(raw string) error {
	if !adConnectionIDPattern.MatchString(raw) {
		return validationError("ad connection id must be a whole number greater than 0: %s", raw).WithHint("%s", adConnectionListHint)
	}
	return nil
}

func adConnectionClient(name string) (*api.Client, error) {
	if api.StagedMode() {
		return nil, validationError("--staged cannot apply to `ad-connection %s`: the route is not stageable, and its body can carry a key", name).
			WithHint("Run it without --staged.")
	}
	return api.NewFromConfig()
}

// readKeyFile reads one key from a file ("-" is piped stdin), trimmed.
func readKeyFile(flag, file, where string) (string, error) {
	var raw []byte
	var err error
	if file == "-" {
		if isTerminal(os.Stdin) {
			return "", validationError("--%s - reads piped stdin, and nothing is piped", flag).
				WithHint("Pipe it in (`printf %%s \"$KEY\" | p202 ad-connection … --%s -`) or pass a file path.", flag)
		}
		raw, err = io.ReadAll(io.LimitReader(os.Stdin, 4096))
	} else {
		raw, err = os.ReadFile(file)
	}
	if err != nil {
		return "", validationError("reading --%s: %v", flag, err).WithHint("Pass the path of a file holding only the key, or - for piped stdin.")
	}
	key := strings.TrimSpace(string(raw))
	if key == "" || strings.ContainsAny(key, " \t\r\n") {
		return "", validationError("the --%s file must hold one key and nothing else", flag).WithHint("%s", where)
	}
	return key, nil
}

const advertiserKeyWhere = "The Advertiser API key is in ChatGPT Ads Manager › Settings, one per ad account."

// adConnectionPpcAccount reads --ppc-account-id / --no-ppc-account into body.
func adConnectionPpcAccount(cmd *cobra.Command, body map[string]interface{}) error {
	none, _ := cmd.Flags().GetBool("no-ppc-account")
	if cmd.Flags().Changed("ppc-account-id") {
		if none {
			return validationError("--ppc-account-id and --no-ppc-account are exclusive").WithHint("Give one of them.")
		}
		id, _ := cmd.Flags().GetInt("ppc-account-id")
		if id <= 0 {
			return validationError("--ppc-account-id must be a traffic source account id: %d", id).WithHint("`p202 ppc-account list` shows the ids.")
		}
		body["ppc_account_id"] = id
	} else if none {
		body["ppc_account_id"] = nil
	}
	return nil
}

var adConnectionListCmd = &cobra.Command{
	Use:   "list",
	Short: "List ad connections (keys are never shown, only whether they are stored)",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ad-connections", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var adConnectionGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Show one ad connection: its account, status and what setup made",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adConnectionArg(args[0]); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ad-connections/"+args[0], nil)
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

var adConnectionCreateCmd = &cobra.Command{
	Use:   "create --api-key-file <file>",
	Short: "Connect a ChatGPT Ads account with its Advertiser API key (checked with OpenAI first)",
	Long: "Reads the Advertiser API key from --api-key-file (- reads piped stdin) and checks it with OpenAI: a key\n" +
		"OpenAI refuses, or one that could not be checked, is not stored. Then run `p202 ad-connection setup <id>`.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		file, _ := cmd.Flags().GetString("api-key-file")
		if file == "" {
			return validationError("--api-key-file is required").WithHint("%s Save it to a file, or pipe it with --api-key-file -.", advertiserKeyWhere)
		}
		body := map[string]interface{}{"platform": "openai_ads"}
		if err := adConnectionPpcAccount(cmd, body); err != nil {
			return err
		}
		key, err := readKeyFile("api-key-file", file, advertiserKeyWhere)
		if err != nil {
			return err
		}
		body["api_key"] = key
		c, err := adConnectionClient("create")
		if err != nil {
			return err
		}
		data, err := c.Post("ad-connections", body)
		if err != nil {
			return withHint(err, "%s", advertiserKeyWhere)
		}
		render(data)
		return nil
	},
}

var adConnectionUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Replace an ad connection's Advertiser API key, or link it to a traffic source account",
	Long: "  p202 ad-connection update 1 --api-key-file new-key.txt   (must reach the same ad account)\n" +
		"  p202 ad-connection update 1 --ppc-account-id 4\n" +
		"  p202 ad-connection update 1 --no-ppc-account",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adConnectionArg(args[0]); err != nil {
			return err
		}
		body := map[string]interface{}{}
		if err := adConnectionPpcAccount(cmd, body); err != nil {
			return err
		}
		if file, _ := cmd.Flags().GetString("api-key-file"); file != "" {
			key, err := readKeyFile("api-key-file", file, advertiserKeyWhere)
			if err != nil {
				return err
			}
			body["api_key"] = key
		}
		if len(body) == 0 {
			return validationError("nothing to update").WithHint("Give --api-key-file, --ppc-account-id or --no-ppc-account.")
		}
		c, err := adConnectionClient("update")
		if err != nil {
			return err
		}
		data, err := c.Patch("ad-connections/"+args[0], body)
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

var adConnectionDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete an ad connection (its keys are erased here; nothing is removed at OpenAI)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		for _, a := range args {
			if err := adConnectionArg(a); err != nil {
				return err
			}
		}
		if api.StagedMode() {
			return validationError("--staged cannot apply to `ad-connection delete`: the route is not stageable").WithHint("Run it without --staged.")
		}
		return bulkOrSingleDelete(cmd, "ad-connections", "ad connection")
	},
}

var adConnectionCheckCmd = &cobra.Command{
	Use:   "check <id>",
	Short: "Read the ad account from OpenAI again: name, currency, time zone, and whether the key still works",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adConnectionArg(args[0]); err != nil {
			return err
		}
		c, err := adConnectionClient("check")
		if err != nil {
			return err
		}
		data, err := c.Post("ad-connections/"+args[0]+"/check", map[string]interface{}{})
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

var adConnectionSetupCmd = &cobra.Command{
	Use:   "setup <id>",
	Short: "Find or make the pixel, a Conversions API key, the destination and event settings (each step shown)",
	Long: "Runs the steps pixel, conversions_key, destination and event_settings, and prints what each did. A step\n" +
		"that ran before is kept, so it can be run again. Skip one with --skip. When OpenAI has pixel or key\n" +
		"creation switched off for the account, the step says so: give them from Ads Manager.\n\n" +
		"  p202 ad-connection setup 1 --event-type order_created --event-type lead_created\n" +
		"  p202 ad-connection setup 1 --pixel clidsrc_123          (one of several pixels the account has)\n" +
		"  p202 ad-connection setup 1 --pixel-id 134534901 --source-id clidsrc_123 --conversions-key-file capi.txt",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adConnectionArg(args[0]); err != nil {
			return err
		}
		body := map[string]interface{}{}
		skips, _ := cmd.Flags().GetStringArray("skip")
		for _, s := range skips {
			if !containsString(adConnectionSteps, s) {
				return validationError("--skip %q is not a step; the steps are %s", s, strings.Join(adConnectionSteps, ", ")).WithHint("Repeat --skip for each step to leave out.")
			}
		}
		if len(skips) > 0 {
			body["skip"] = skips
		}
		events, _ := cmd.Flags().GetStringArray("event-type")
		for _, e := range events {
			if !containsString(adConnectionEvents, e) {
				return validationError("--event-type %q is not one of %s", e, strings.Join(adConnectionEvents, ", ")).
					WithHint("A custom event setting is made in Ads Manager.")
			}
		}
		if len(events) > 0 {
			body["event_types"] = events
		}
		for flag, key := range map[string]string{"pixel": "pixel_source_id", "pixel-id": "pixel_id", "source-id": "source_id"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				if strings.TrimSpace(v) == "" {
					return validationError("--%s cannot be empty", flag).WithHint("Give the id, or leave the flag out.")
				}
				body[key] = v
			}
		}
		if _, ok := body["source_id"]; ok {
			if _, has := body["pixel_id"]; !has {
				return validationError("--source-id goes with --pixel-id").WithHint("Give both from Ads Manager, or neither to find or make the pixel here.")
			}
		}
		if _, ok := body["pixel_id"]; ok {
			if _, has := body["pixel_source_id"]; has {
				return validationError("--pixel and --pixel-id are exclusive").
					WithHint("--pixel picks one of the pixels the account lists; --pixel-id gives one from Ads Manager.")
			}
		}
		if file, _ := cmd.Flags().GetString("conversions-key-file"); file != "" {
			key, err := readKeyFile("conversions-key-file", file, "A Conversions API key is made in ChatGPT Ads Manager under the pixel's settings.")
			if err != nil {
				return err
			}
			body["conversions_api_key"] = key
		}
		c, err := adConnectionClient("setup")
		if err != nil {
			return err
		}
		data, err := c.Post("ad-connections/"+args[0]+"/setup", body)
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

func adConnectionPpcFlags(cmd *cobra.Command) {
	cmd.Flags().Int("ppc-account-id", 0, "The traffic source account it is for (`p202 ppc-account list`)")
	cmd.Flags().Bool("no-ppc-account", false, "Link it to no traffic source account")
}

func init() {
	adConnectionCreateCmd.Flags().String("api-key-file", "", "File holding only the Advertiser API key; - reads piped stdin")
	adConnectionPpcFlags(adConnectionCreateCmd)
	adConnectionUpdateCmd.Flags().String("api-key-file", "", "File holding the new Advertiser API key (same ad account); - reads piped stdin")
	adConnectionPpcFlags(adConnectionUpdateCmd)
	registerDeleteFlags(adConnectionDeleteCmd, "ad connection")
	adConnectionSetupCmd.Flags().StringArray("skip", nil, "Leave out a step (repeatable): "+strings.Join(adConnectionSteps, ", "))
	adConnectionSetupCmd.Flags().StringArray("event-type", nil, "Make an event setting for this event type (repeatable); default those the destination sends, else order_created")
	adConnectionSetupCmd.Flags().String("pixel", "", "Which of the account's web pixels, by its source id (clidsrc_…), when it has several")
	adConnectionSetupCmd.Flags().String("pixel-id", "", "A pixel id from Ads Manager, when the account cannot list or make pixels here")
	adConnectionSetupCmd.Flags().String("source-id", "", "That pixel's source id (clidsrc_…), needed for event settings")
	adConnectionSetupCmd.Flags().String("conversions-key-file", "", "File holding a Conversions API key from Ads Manager; - reads piped stdin")
	adConnectionCmd.AddCommand(adConnectionListCmd, adConnectionGetCmd, adConnectionCreateCmd, adConnectionUpdateCmd,
		adConnectionDeleteCmd, adConnectionCheckCmd, adConnectionSetupCmd)
	rootCmd.AddCommand(adConnectionCmd)
}
