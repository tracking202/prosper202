package cmd

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// ── Link builder ────────────────────────────────────────────────────

var appLinkCmd = &cobra.Command{
	Use:   "link <registration-id>",
	Short: "The store link a campaign should send this app's clicks to, and whether a campaign does",
	Long: "Shows the store link for the app: for Android the Play link with [[p202_install_token]] in\n" +
		"its referrer (the redirect fills it per click; the SDK reads it back), for iOS the App Store\n" +
		"link and the SKAdNetwork/AdAttributionKit Info.plist setup. With --campaign-id it says whether\n" +
		"that campaign is ready — its offer URL is the store link and, for Android, the campaign is\n" +
		"linked to the app — and with --apply it makes it so (PUT /campaigns/{id}; honours --staged).\n" +
		"Then `p202 tracker create --aff-campaign-id N` gives the tracking link.",
	Example: "  p202 app link 7\n  p202 app link 7 --campaign-id 12 --apply",
	Args:    cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveID.MatchString(args[0]) {
			return validationError("the registration id must be a positive whole number, got %q", args[0]).
				WithHint("`p202 app list` lists your apps and their registration ids.")
		}
		campaign, _ := cmd.Flags().GetString("campaign-id")
		apply, _ := cmd.Flags().GetBool("apply")
		if campaign != "" && !positiveID.MatchString(campaign) {
			return validationError("--campaign-id must be a positive whole number, got %q", campaign).
				WithHint("`p202 campaign list` lists campaign ids.")
		}
		if apply && campaign == "" {
			return validationError("--apply needs --campaign-id: it changes that campaign's offer URL (and, for Android, links it to the app)").
				WithHint("`p202 campaign list` lists campaign ids; run without --apply first to see what would change.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		if campaign != "" {
			params["campaign_id"] = campaign
		}
		data, err := c.Get("apps/"+args[0]+"/store-link", params)
		if err != nil {
			return withHint(err, "`p202 app list` lists your apps; `p202 campaign list` your campaigns.")
		}
		if !apply {
			render(data)
			return nil
		}
		var envelope struct {
			Data struct {
				Campaign struct {
					Ready bool                   `json:"ready"`
					Apply map[string]interface{} `json:"apply"`
				} `json:"campaign"`
			} `json:"data"`
		}
		if err := json.Unmarshal(data, &envelope); err != nil {
			return fmt.Errorf("reading the store-link answer: %w", err)
		}
		if envelope.Data.Campaign.Ready || len(envelope.Data.Campaign.Apply) == 0 {
			// Nothing to write: say so in the same shape as a read.
			render(data)
			return nil
		}
		body := map[string]interface{}{}
		for field, value := range envelope.Data.Campaign.Apply {
			// app_registration_id comes back as a JSON number; the
			// campaign endpoint reads it raw, and a float64's %v would be
			// "7" anyway, but send it as the integer it is.
			if n, ok := value.(float64); ok {
				body[field] = strconv.FormatInt(int64(n), 10)
				continue
			}
			body[field] = value
		}
		updated, err := c.Put("campaigns/"+campaign, body)
		if err != nil {
			return withHint(err, "The campaign was not changed. `p202 campaign get "+campaign+"` shows it as it is.")
		}
		render(updated)
		return nil
	},
}

// ── Notification outbox ─────────────────────────────────────────────

var appNotificationStatuses = map[string]bool{"pending": true, "sent": true, "failed": true, "cancelled": true, "suppressed": true}
var appNotificationKinds = map[string]bool{"reached": true, "correction": true, "retraction": true}

var appNotificationsCmd = &cobra.Command{
	Use:   "notifications",
	Short: "Traffic-source postbacks the app installs' goals queued, and where each stands",
	Long: "Lists the notification outbox rows for app installs, newest first: the postback URL, its\n" +
		"kind (reached, correction, retraction) and status — pending (waiting or backing off),\n" +
		"sent, failed (attempts ran out; last_error says why), cancelled (replaced before it went\n" +
		"out) or suppressed (a correction the traffic source has no correction URL for).\n" +
		"meta.summary counts every status under the same filters.",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		for _, flag := range []string{"registration-id", "status", "kind", "time-from", "time-to"} {
			if v, _ := cmd.Flags().GetString(flag); v != "" {
				params[strings.ReplaceAll(flag, "-", "_")] = v
			}
		}
		if v, ok := params["registration_id"]; ok && !positiveID.MatchString(v) {
			return validationError("--registration-id must be a positive whole number, got %q", v).
				WithHint("`p202 app list` lists your apps and their registration ids.")
		}
		if v, ok := params["status"]; ok && !appNotificationStatuses[v] {
			return validationError("--status must be one of: pending, sent, failed, cancelled, suppressed; got %q", v)
		}
		if v, ok := params["kind"]; ok && !appNotificationKinds[v] {
			return validationError("--kind must be one of: reached, correction, retraction; got %q", v)
		}
		for _, f := range []string{"time_from", "time_to"} {
			if v, ok := params[f]; ok {
				if _, err := strconv.ParseInt(v, 10, 64); err != nil {
					return validationError("--%s must be a unix timestamp in seconds, got %q", strings.ReplaceAll(f, "_", "-"), v)
				}
			}
		}
		return runPagedList(cmd, "apps/notifications", params)
	},
}

func init() {
	appLinkCmd.Flags().String("campaign-id", "", "Say whether this campaign sends its clicks to the store link (`p202 campaign list`)")
	appLinkCmd.Flags().Bool("apply", false, "Set the campaign's offer URL to the store link and, for Android, link it to the app")

	registerPagedListFlags(appNotificationsCmd)
	appNotificationsCmd.Flags().String("registration-id", "", "Only this app's postbacks (`p202 app list`)")
	appNotificationsCmd.Flags().String("status", "", "Only this status: pending, sent, failed, cancelled, suppressed")
	appNotificationsCmd.Flags().String("kind", "", "Only this kind: reached, correction, retraction")
	appNotificationsCmd.Flags().String("time-from", "", "Queued at or after (unix timestamp)")
	appNotificationsCmd.Flags().String("time-to", "", "Queued at or before (unix timestamp)")

	appCmd.AddCommand(appLinkCmd, appNotificationsCmd)
}
