package cmd

import (
	"crypto/rand"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Android installs: what the SDK reported to the public intake
// (POST /apps/installs), read by registration, and the install token a
// Google Play store link carries for one click. `simulate` posts an
// SDK-shaped install for a real click, exactly as a device would.

var appInstallCmd = &cobra.Command{
	Use:     "install",
	Aliases: []string{"installs"},
	Short:   "Android installs: list, inspect, mint a click's install token, simulate the SDK",
	Long: "Android installs reach the server from the SDK (POST /apps/installs); each is\n" +
		"classified into a match state (attributed, organic, bad_token, …) with its reason.\n\n" +
		"  p202 app install list 3 --match-state attributed\n" +
		"  p202 app install token 3 --click 1042\n" +
		"  p202 app install simulate 3 --click 1042",
}

// appInstallFilterDefs is the one table of list filter flags: flag, API
// query parameter, help.
var appInstallFilterDefs = []struct {
	flag  string
	param string
	help  string
}{
	{"match-state", "match_state", "Only this state: attributed, organic, third_party, unavailable, pending_click, bad_token, foreign_click, implausible, outside_window, duplicate_click, pending_integrity, integrity_failed, integrity_unverified"},
	{"integrity-state", "integrity_state", "Only this Play Integrity state: not_requested, received, missing, pending, valid, invalid, error, skipped"},
	{"trusted", "trusted", "Only this trust class: trusted, refuted or unvouched"},
	{"test", "test", "1 = only test installs, 0 = only real ones"},
	{"click-id", "click_id", "Only installs attributed or matched to this click"},
	{"time-from", "time_from", "Received-at range start (unix timestamp)"},
	{"time-to", "time_to", "Received-at range end (unix timestamp)"},
}

var matchStates = map[string]bool{
	"attributed": true, "organic": true, "third_party": true, "unavailable": true, "pending_click": true,
	"bad_token": true, "foreign_click": true, "implausible": true, "outside_window": true, "duplicate_click": true,
	"pending_integrity": true, "integrity_failed": true, "integrity_unverified": true,
}

var integrityStates = map[string]bool{
	"not_requested": true, "received": true, "missing": true, "pending": true,
	"valid": true, "invalid": true, "error": true, "skipped": true,
}

var positiveID = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)
var installUUID = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// registrationArg validates the registration id argument before any client
// is built, so a typo is a validation error even with no server configured.
func registrationArg(v string) error {
	if !positiveID.MatchString(v) {
		return validationError("the registration id must be a positive whole number, got %q", v).
			WithHint("`p202 app list --platform android` lists your Android registrations and their ids.")
	}
	return nil
}

func collectInstallFilters(cmd *cobra.Command) (map[string]string, error) {
	params := map[string]string{}
	for _, def := range appInstallFilterDefs {
		v, _ := cmd.Flags().GetString(def.flag)
		if v == "" {
			continue
		}
		params[def.param] = v
	}
	if v, ok := params["match_state"]; ok && !matchStates[v] {
		return nil, validationError("--match-state must be one of: attributed, organic, third_party, unavailable, pending_click, bad_token, foreign_click, implausible, outside_window, duplicate_click, pending_integrity, integrity_failed, integrity_unverified; got %q", v)
	}
	if v, ok := params["integrity_state"]; ok && !integrityStates[v] {
		return nil, validationError("--integrity-state must be one of: not_requested, received, missing, pending, valid, invalid, error, skipped; got %q", v)
	}
	if v, ok := params["trusted"]; ok && v != "trusted" && v != "refuted" && v != "unvouched" {
		return nil, validationError("--trusted must be one of: trusted, refuted, unvouched; got %q", v)
	}
	if v, ok := params["test"]; ok && v != "0" && v != "1" {
		return nil, validationError("--test must be 0 or 1, got %q", v)
	}
	if v, ok := params["click_id"]; ok && !positiveID.MatchString(v) {
		return nil, validationError("--click-id must be a positive whole number, got %q", v).
			WithHint("`p202 click list` lists click ids.")
	}
	for _, f := range []string{"time_from", "time_to"} {
		if v, ok := params[f]; ok {
			if _, err := strconv.ParseUint(v, 10, 32); err != nil {
				return nil, validationError("--%s must be a unix timestamp in seconds, got %q", map[string]string{"time_from": "time-from", "time_to": "time-to"}[f], v)
			}
		}
	}
	return params, nil
}

var appInstallListCmd = &cobra.Command{
	Use:   "list <registration-id>",
	Short: "List an Android registration's installs, newest first, with their match state and reason",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		params, err := collectInstallFilters(cmd)
		if err != nil {
			return err
		}
		return runPagedList(cmd, "apps/"+args[0]+"/installs", params)
	},
}

var appInstallGetCmd = &cobra.Command{
	Use:   "get <registration-id> <install-uuid>",
	Short: "Get one install: its referrer, timestamps, match state, reason and trust",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		if !installUUID.MatchString(args[1]) {
			return validationError("the install id must be the install_uuid the SDK reported (a lower-case UUID), got %q", args[1]).
				WithHint("`p202 app install list " + args[0] + "` shows each install's install_uuid.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("apps/"+args[0]+"/installs/"+args[1], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func clickFlag(cmd *cobra.Command) (string, error) {
	click, _ := cmd.Flags().GetString("click")
	if click == "" {
		return "", validationError("required flag --click is missing").
			WithHint("Name one of your clicks, e.g. `--click 1042`; `p202 click list` lists them.")
	}
	if !positiveID.MatchString(click) {
		return "", validationError("--click must be a positive whole number, got %q", click).
			WithHint("`p202 click list` lists click ids.")
	}
	return click, nil
}

var appInstallTokenCmd = &cobra.Command{
	Use:   "token <registration-id> --click <click-id>",
	Short: "Show the install token and Google Play store link for one click",
	Long: "Mints [[p202_install_token]] for one of your clicks, exactly as the redirect does,\n" +
		"and the store link that carries it (…&referrer=p202%3D<token>). A read: nothing is stored.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		click, err := clickFlag(cmd)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("apps/"+args[0]+"/install-token", map[string]string{"click_id": click})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var appInstallSimulateCmd = &cobra.Command{
	Use:   "simulate <registration-id> --click <click-id>",
	Short: "Post an SDK-shaped install for a real click, as a device would",
	Long: "Reads the registration (its app token and package), mints the click's install token,\n" +
		"and posts the install the Android SDK would send to POST /apps/installs — with Google's\n" +
		"timestamps placed seconds after the click — then prints the intake's answer. The install\n" +
		"is real: an attributed one records the install conversion on the click.\n\n" +
		"Resend with the same --install-uuid to see the duplicate answer; --test marks it a test\n" +
		"install (it counts only when the registration accepts test signals).",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := registrationArg(args[0]); err != nil {
			return err
		}
		click, err := clickFlag(cmd)
		if err != nil {
			return err
		}
		uuid, _ := cmd.Flags().GetString("install-uuid")
		if uuid != "" && !installUUID.MatchString(uuid) {
			return validationError("--install-uuid must be a lower-case UUID (8-4-4-4-12 hexadecimal digits), got %q", uuid).
				WithHint("Leave it out to mint a new one, or reuse one from `p202 app install list " + args[0] + "`.")
		}
		delay, _ := cmd.Flags().GetInt("delay")
		if delay < 0 || delay > 3600 {
			return validationError("--delay must be 0-3600 seconds, got %d", delay).
				WithHint("It is how long after the click the store click happens; a real redirect reaches the store within seconds.")
		}
		test, _ := cmd.Flags().GetBool("test")
		// The intake is a public, pre-auth route: it cannot record a
		// proposal, so under --staged it would write for real. Refuse
		// rather than perform what --staged promises to withhold.
		if api.StagedMode() {
			return validationError("--staged cannot apply to `app install simulate`: it posts to the public intake, which records the install at once").
				WithHint("Run it without --staged against a test registration, or use `p202 app install token` to inspect the link without writing.")
		}
		if uuid == "" {
			uuid, err = newUUID()
			if err != nil {
				return err
			}
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		appData, err := c.Get("apps/"+args[0], nil)
		if err != nil {
			return hintRegistrationID(err)
		}
		var app struct {
			Data struct {
				AppToken string `json:"app_token"`
				AppKey   string `json:"app_key"`
				Platform string `json:"platform"`
			} `json:"data"`
		}
		if err := json.Unmarshal(appData, &app); err != nil {
			return withHint(fmt.Errorf("reading registration %s: could not decode the server's response: %w", args[0], err),
				"Check the configured URL with `p202 config show`: it should reach the API, not a proxy error page.")
		}
		if app.Data.Platform != "android" {
			return validationError("registration %s is a %q app; installs are Android's", args[0], app.Data.Platform).
				WithHint("`p202 app list --platform android` lists your Android registrations.")
		}
		tokenData, err := c.Get("apps/"+args[0]+"/install-token", map[string]string{"click_id": click})
		if err != nil {
			return err
		}
		var tok struct {
			Data struct {
				Referrer  string `json:"referrer"`
				ClickTime int64  `json:"click_time"`
			} `json:"data"`
		}
		if err := json.Unmarshal(tokenData, &tok); err != nil || tok.Data.Referrer == "" {
			return withHint(fmt.Errorf("reading the install token for click %s: unexpected response", click),
				"`p202 app install token "+args[0]+" --click "+click+"` shows what the server answered.")
		}
		t := tok.Data.ClickTime
		body := map[string]interface{}{
			"install_uuid": uuid,
			"app_key":      app.Data.AppKey,
			"store":        "google_play",
			"referrer": map[string]interface{}{
				"status":                                  "ok",
				"install_referrer":                        tok.Data.Referrer,
				"referrer_click_timestamp_seconds":        t + int64(delay),
				"install_begin_timestamp_seconds":         t + int64(delay) + 30,
				"referrer_click_timestamp_server_seconds": t + int64(delay),
				"install_begin_timestamp_server_seconds":  t + int64(delay) + 30,
				"install_version":                         "simulated",
				"google_play_instant":                     false,
			},
			"first_open_at":   t + int64(delay) + 60,
			"app_version":     "simulated",
			"sdk_version":     "p202-cli",
			"os_version":      "simulated",
			"test":            test,
			"integrity_token": nil,
		}
		data, err := c.PostWithHeaders("apps/installs", body, map[string]string{api.AppTokenHeader: app.Data.AppToken})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// newUUID mints a random (version 4) UUID in the lower-case form the intake
// accepts.
func newUUID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", fmt.Errorf("minting an install id: %w", err)
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16]), nil
}

func init() {
	registerPagedListFlags(appInstallListCmd)
	for _, def := range appInstallFilterDefs {
		appInstallListCmd.Flags().String(def.flag, "", def.help)
	}
	appInstallTokenCmd.Flags().String("click", "", "The click to sign (from `p202 click list`)")
	appInstallSimulateCmd.Flags().String("click", "", "The click the install comes from (from `p202 click list`)")
	appInstallSimulateCmd.Flags().String("install-uuid", "", "The install's id (default: a new one); reuse one to see the duplicate answer")
	appInstallSimulateCmd.Flags().Int("delay", 5, "Seconds from the click to the store click")
	appInstallSimulateCmd.Flags().Bool("test", false, "Send it as a test install (counts only under accept_test_signals)")
	appInstallCmd.AddCommand(appInstallListCmd, appInstallGetCmd, appInstallTokenCmd, appInstallSimulateCmd)
	appCmd.AddCommand(appInstallCmd)
}
