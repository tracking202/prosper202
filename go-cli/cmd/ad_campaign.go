package cmd

import (
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// ChatGPT Ads campaigns, ad groups and ads through an ad connection
// (/api/v3/ad-connections/{id}/campaigns, /ad-groups, /ads, /spend): read
// from OpenAI, paused and activated, the tracking template set, event
// settings attached. Every write takes --dry-run.

var (
	adObjectIDPattern = regexp.MustCompile(`^[a-z]+_[A-Za-z0-9-]{1,64}$`)
	adCampaignLevels  = newEnum([]string{"campaigns", "ad-groups", "ads"}, enumHint("Which kind of object: campaigns, their ad groups, or ads."))
	adSpendDate       = regexp.MustCompile(`^[0-9]{4}-[0-9]{2}-[0-9]{2}$`)
)

const adCampaignListHint = "`p202 ad-campaign list <connection-id>` lists the campaigns (--level ad-groups or ads for the rest); `p202 ad-connection list` the connections."

var adCampaignCmd = &cobra.Command{
	Use:   "ad-campaign",
	Short: "ChatGPT Ads campaigns, ad groups and ads: list with status and serving issues, pause, activate, set the tracking template, spend",
	Long: "Works through an ad connection (`p202 ad-connection`), live at OpenAI. Every write takes --dry-run, which\n" +
		"reads the object and says what would change without changing it.\n\n" +
		"  p202 ad-campaign list 1\n" +
		"  p202 ad-campaign list 1 --level ads --ad-group-id adgrp_12\n" +
		"  p202 ad-campaign pause 1 cmpn_101 --dry-run\n" +
		"  p202 ad-campaign set-template 1 cmpn_101          (the template Get Links gives)\n" +
		"  p202 ad-campaign attach-events 1 cmpn_101         (the ones made when it was connected)\n" +
		"  p202 ad-campaign spend 1 --since 2026-11-01 --until 2026-11-10",
}

func adCampaignArgs(args []string) error {
	if err := adConnectionArg(args[0]); err != nil {
		return err
	}
	if len(args) > 1 && !adObjectIDPattern.MatchString(args[1]) {
		return validationError("%q is not a ChatGPT Ads id (cmpn_…, adgrp_…, ad_…)", args[1]).WithHint("%s", adCampaignListHint)
	}
	return nil
}

func adCampaignLevel(cmd *cobra.Command) string {
	if v := enumValue(cmd, "level"); v != "" {
		return v
	}
	return "campaigns"
}

// adCampaignWrite posts one write, with ?dry_run=1 when asked.
func adCampaignWrite(cmd *cobra.Command, name, path string, body map[string]interface{}) error {
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	var params map[string]string
	if dry, _ := cmd.Flags().GetBool("dry-run"); dry {
		params = map[string]string{"dry_run": "1"}
	}
	data, err := c.PostUpdate(path, params, body)
	if err != nil {
		return withHint(err, "%s", adCampaignListHint)
	}
	render(data)
	return nil
}

var adCampaignListCmd = &cobra.Command{
	Use:   "list <connection-id>",
	Short: "List ChatGPT Ads campaigns, ad groups or ads, with serving issues",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adCampaignArgs(args); err != nil {
			return err
		}
		level := adCampaignLevel(cmd)
		params := map[string]string{}
		for flag, key := range map[string]string{"campaign-id": "campaign_id", "ad-group-id": "ad_group_id", "after": "after"} {
			if !cmd.Flags().Changed(flag) {
				continue
			}
			v, _ := cmd.Flags().GetString(flag)
			if (flag == "campaign-id" && level != "ad-groups") || (flag == "ad-group-id" && level != "ads") {
				return validationError("--%s narrows %s, not %s", flag, map[string]string{"campaign-id": "ad groups (--level ad-groups)", "ad-group-id": "ads (--level ads)"}[flag], level).
					WithHint("Give --level ad-groups with --campaign-id, or --level ads with --ad-group-id.")
			}
			if flag != "after" && !adObjectIDPattern.MatchString(v) {
				return validationError("--%s %q is not a ChatGPT Ads id", flag, v).WithHint("%s", adCampaignListHint)
			}
			params[key] = v
		}
		if cmd.Flags().Changed("limit") {
			n, _ := cmd.Flags().GetInt("limit")
			if n < 1 || n > 500 {
				return validationError("--limit must be 1 to 500").WithHint("For more rows, pass --after with the meta.after the answer gives.")
			}
			params["limit"] = strconv.Itoa(n)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ad-connections/"+args[0]+"/"+level, params)
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

var adCampaignGetCmd = &cobra.Command{
	Use:   "get <connection-id> <id>",
	Short: "Show one ChatGPT Ads campaign, ad group or ad as OpenAI has it",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adCampaignArgs(args); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ad-connections/"+args[0]+"/"+adCampaignLevel(cmd)+"/"+args[1], nil)
		if err != nil {
			return withHint(err, "%s", adCampaignListHint)
		}
		render(data)
		return nil
	},
}

func adCampaignStatusCmd(action, short string) *cobra.Command {
	return &cobra.Command{
		Use:   action + " <connection-id> <id>",
		Short: short,
		Args:  cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := adCampaignArgs(args); err != nil {
				return err
			}
			return adCampaignWrite(cmd, action, "ad-connections/"+args[0]+"/"+adCampaignLevel(cmd)+"/"+args[1]+"/"+action, map[string]interface{}{})
		},
	}
}

var (
	adCampaignPauseCmd    = adCampaignStatusCmd("pause", "Pause a ChatGPT Ads campaign, ad group or ad")
	adCampaignActivateCmd = adCampaignStatusCmd("activate", "Activate (resume) a ChatGPT Ads campaign, ad group or ad")
)

var adCampaignSetTemplateCmd = &cobra.Command{
	Use:   "set-template <connection-id> <id>",
	Short: "Set the tracking template OpenAI appends to ad URLs",
	Long: "Sets the query string OpenAI appends to the ad's URL on a campaign (or --level ad-groups, ads; the\n" +
		"lowest level set wins). Without --template it is the template Get Links shows for the ChatGPT Ads source;\n" +
		"for a tracker that sends visitors straight to your site, pass the one `p202 tracker get-url <id>` gives (with t202id).",
	Args: cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adCampaignArgs(args); err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("template") {
			t, _ := cmd.Flags().GetString("template")
			t = strings.TrimPrefix(strings.TrimSpace(t), "?")
			if t == "" || len(t) > 2048 || strings.ContainsAny(t, " \t\r\n#") {
				return validationError("--template must be a query string of at most 2048 characters with no spaces or #").
					WithHint("For example utm_source=chatgpt&campaign_id={campaign_id}&ad_id={ad_id}&oppref={oppref}; leave --template out for the Get Links one.")
			}
			body["template"] = t
		}
		return adCampaignWrite(cmd, "set-template", "ad-connections/"+args[0]+"/"+adCampaignLevel(cmd)+"/"+args[1]+"/template", body)
	},
}

var adCampaignAttachEventsCmd = &cobra.Command{
	Use:   "attach-events <connection-id> <campaign-id>",
	Short: "Add conversion event settings to a ChatGPT Ads campaign",
	Long: "OpenAI replaces a campaign's list of event settings whole, so this reads the campaign, adds the ones\n" +
		"named (or, without --event-setting-id, the ones made when the connection was set up) and writes the list back.",
	Args: cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adCampaignArgs(args); err != nil {
			return err
		}
		ids, _ := cmd.Flags().GetStringArray("event-setting-id")
		for _, id := range ids {
			if !adObjectIDPattern.MatchString(id) {
				return validationError("--event-setting-id %q is not an event setting id (ces_…)", id).
					WithHint("`p202 ad-connection get %s` shows the ones made for it.", args[0])
			}
		}
		body := map[string]interface{}{}
		if len(ids) > 0 {
			body["event_setting_ids"] = ids
		}
		return adCampaignWrite(cmd, "attach-events", "ad-connections/"+args[0]+"/campaigns/"+args[1]+"/event-settings", body)
	},
}

var adCampaignSpendCmd = &cobra.Command{
	Use:   "spend <connection-id> [--since YYYY-MM-DD --until YYYY-MM-DD]",
	Short: "ChatGPT Ads spend by campaign (default yesterday)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := adCampaignArgs(args); err != nil {
			return err
		}
		params := map[string]string{}
		for _, flag := range []string{"since", "until"} {
			if cmd.Flags().Changed(flag) {
				v, _ := cmd.Flags().GetString(flag)
				if !adSpendDate.MatchString(v) {
					return validationError("--%s must be a date, YYYY-MM-DD: %s", flag, v).WithHint("Days are the ad account's own (`p202 ad-connection get` shows its time zone).")
				}
				params[flag] = v
			}
		}
		if len(params) == 1 {
			return validationError("--since and --until go together").WithHint("Give both, or neither for yesterday.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("ad-connections/"+args[0]+"/spend", params)
		if err != nil {
			return withHint(err, "%s", adConnectionListHint)
		}
		render(data)
		return nil
	},
}

func init() {
	for _, c := range []*cobra.Command{adCampaignListCmd, adCampaignGetCmd, adCampaignPauseCmd, adCampaignActivateCmd, adCampaignSetTemplateCmd} {
		c.Flags().String("level", "", "campaigns (default), ad-groups or ads: one of {values}")
		enumFlag(c, "level", adCampaignLevels)
	}
	for _, c := range []*cobra.Command{adCampaignPauseCmd, adCampaignActivateCmd, adCampaignSetTemplateCmd, adCampaignAttachEventsCmd} {
		c.Flags().Bool("dry-run", false, "Say what would change; change nothing")
	}
	adCampaignListCmd.Flags().String("campaign-id", "", "With --level ad-groups: only this campaign's")
	adCampaignListCmd.Flags().String("ad-group-id", "", "With --level ads: only this ad group's")
	adCampaignListCmd.Flags().Int("limit", 100, "Rows to return (1-500)")
	adCampaignListCmd.Flags().String("after", "", "Continue after this row: meta.after of the previous answer")
	adCampaignSetTemplateCmd.Flags().String("template", "", "The query string to append (at most 2048 characters); default the Get Links template")
	adCampaignAttachEventsCmd.Flags().StringArray("event-setting-id", nil, "An event setting to add (ces_…, repeatable); default the ones made for the connection")
	adCampaignSpendCmd.Flags().String("since", "", "First day, YYYY-MM-DD (the ad account's zone)")
	adCampaignSpendCmd.Flags().String("until", "", "Last day, included")
	adCampaignCmd.AddCommand(adCampaignListCmd, adCampaignGetCmd, adCampaignPauseCmd, adCampaignActivateCmd,
		adCampaignSetTemplateCmd, adCampaignAttachEventsCmd, adCampaignSpendCmd)
	rootCmd.AddCommand(adCampaignCmd)
}
