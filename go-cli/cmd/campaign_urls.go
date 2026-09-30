package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strings"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/metrics"
	"p202/internal/output"
)

// campaignURLFields are a campaign's five offer URL slots, in rotation order.
var campaignURLFields = []string{
	"aff_campaign_url",
	"aff_campaign_url_2",
	"aff_campaign_url_3",
	"aff_campaign_url_4",
	"aff_campaign_url_5",
}

// urlChange is one offer URL slot that replace-url rewrites.
type urlChange struct {
	CampaignID   string `json:"aff_campaign_id"`
	CampaignName string `json:"aff_campaign_name"`
	Field        string `json:"field"`
	OldURL       string `json:"old_url"`
	NewURL       string `json:"new_url"`
	Status       string `json:"status,omitempty"`
	ChangeID     string `json:"change_id,omitempty"`
	Error        string `json:"error,omitempty"`
}

// replaceURLColumns keeps old before new in tables; --fields still overrides.
var replaceURLColumns = []string{"aff_campaign_id", "aff_campaign_name", "field", "old_url", "new_url", "status", "change_id", "error"}

func containsFold(s, sub string) bool {
	return strings.Contains(strings.ToLower(s), strings.ToLower(sub))
}

// rowMatchesURL reports whether any of fields holds a URL containing sub.
func rowMatchesURL(row map[string]interface{}, fields []string, sub string) bool {
	for _, f := range fields {
		if v, ok := row[f].(string); ok && v != "" && containsFold(v, sub) {
			return true
		}
	}
	return false
}

var slugInvalid = regexp.MustCompile(`[^a-z0-9]+`)

// slugify turns a campaign name into a URL-safe token ("Star Trek Online" -> "star-trek-online").
func slugify(name string) string {
	return strings.Trim(slugInvalid.ReplaceAllString(strings.ToLower(name), "-"), "-")
}

// expandURLTemplate fills {id} and {slug} in a --set template for one campaign.
func expandURLTemplate(tmpl, id, name string) string {
	slug := slugify(name)
	if slug == "" {
		slug = "campaign-" + id
	}
	return strings.NewReplacer("{id}", id, "{slug}", slug).Replace(tmpl)
}

// parseURLSlots maps --slot values (1-5, comma-separated) to field names.
func parseURLSlots(raw string) ([]string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" || strings.EqualFold(raw, "all") {
		return campaignURLFields, nil
	}
	seen := map[string]bool{}
	var fields []string
	for _, part := range strings.Split(raw, ",") {
		part = strings.TrimSpace(part)
		idx := -1
		for i := range campaignURLFields {
			if part == fmt.Sprint(i+1) {
				idx = i
			}
		}
		if idx < 0 {
			return nil, validationError("invalid --slot %q: use 1, 2, 3, 4, 5, a comma-separated list of them, or all", part).
				WithHint("Slot 1 is the primary offer URL (aff_campaign_url); 2-5 are the rotation URLs.")
		}
		if f := campaignURLFields[idx]; !seen[f] {
			seen[f] = true
			fields = append(fields, f)
		}
	}
	return fields, nil
}

// planURLReplacements lists every slot whose URL contains match, with its
// rewritten value: match replaced by with, or the whole URL set from setTmpl.
// Slots whose value would not change are left out.
func planURLReplacements(rows []map[string]interface{}, fields []string, match, with, setTmpl string, useSet bool, onlyIDs map[string]bool) []urlChange {
	pattern := regexp.MustCompile("(?i)" + regexp.QuoteMeta(match))
	var changes []urlChange
	for _, row := range rows {
		rawID, ok := extractIntField(row, "aff_campaign_id", "id")
		if !ok {
			continue
		}
		id := fmt.Sprint(rawID)
		if len(onlyIDs) > 0 && !onlyIDs[id] {
			continue
		}
		name, _ := row["aff_campaign_name"].(string)
		for _, f := range fields {
			old, _ := row[f].(string)
			if old == "" || !pattern.MatchString(old) {
				continue
			}
			var next string
			if useSet {
				next = expandURLTemplate(setTmpl, id, name)
			} else {
				next = pattern.ReplaceAllLiteralString(old, with)
			}
			if next == old {
				continue
			}
			changes = append(changes, urlChange{CampaignID: id, CampaignName: name, Field: f, OldURL: old, NewURL: next})
		}
	}
	return changes
}

// groupChangesByCampaign builds one PUT body per campaign, in plan order.
func groupChangesByCampaign(changes []urlChange) ([]string, map[string]map[string]string) {
	var order []string
	bodies := map[string]map[string]string{}
	for _, ch := range changes {
		if _, ok := bodies[ch.CampaignID]; !ok {
			order = append(order, ch.CampaignID)
			bodies[ch.CampaignID] = map[string]string{}
		}
		bodies[ch.CampaignID][ch.Field] = ch.NewURL
	}
	return order, bodies
}

func renderURLChanges(changes []urlChange) error {
	if changes == nil {
		changes = []urlChange{}
	}
	data, err := json.Marshal(map[string]interface{}{"data": changes})
	if err != nil {
		return fmt.Errorf("encoding URL changes: %w", err)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = replaceURLColumns
	}
	output.RenderWith(data, opts)
	return nil
}

func newCampaignReplaceURLCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "replace-url",
		Short: "Find campaigns whose offer URLs contain a string and rewrite those URLs in bulk",
		Long: "Finds every campaign whose offer URL slots (aff_campaign_url, _2 to _5)\n" +
			"contain --match (case-insensitive), and rewrites the matching slots:\n\n" +
			"  --with <text>  replaces the matched text inside the URL\n" +
			"  --set <url>    replaces the whole URL; {id} and {slug} (the campaign\n" +
			"                 name, lowercased and hyphenated) are filled per campaign\n\n" +
			"Run with --dry-run first: it lists every campaign, slot, old and new URL\n" +
			"and writes nothing. Without --dry-run the same list is shown and you are\n" +
			"asked to confirm (--force skips the prompt; --staged records one proposal\n" +
			"per campaign instead of writing). Matching runs in the CLI over every page\n" +
			"of campaigns, so it works against any server version.\n\n" +
			"Re-running is safe with --set (updated URLs no longer contain --match),\n" +
			"but with --with it rewrites again if the replacement still contains --match.",
		Example: "  p202 campaign replace-url --match g2afse.com --set 'https://example.com/?utm_source={slug}' --dry-run\n" +
			"  p202 campaign replace-url --match http://promo.example.com --with https://promo.example.com --force",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("replace-url", "campaigns")
			defer func() { done(retErr == nil, errString(retErr)) }()

			// Validate every flag before building a client.
			match, _ := cmd.Flags().GetString("match")
			if strings.TrimSpace(match) == "" {
				return validationError("--match is required").
					WithHint("Pass the text the old URLs share, e.g. --match old-network.com; preview with `p202 campaign list --url-contains <text>`.")
			}
			withSet := cmd.Flags().Changed("with")
			setSet := cmd.Flags().Changed("set")
			if withSet == setSet {
				return validationError("pass exactly one of --with (replace the matched text) or --set (replace the whole URL)").
					WithHint("--with https://new.example.com swaps just the matched part; --set 'https://new.example.com/?utm_source={slug}' replaces the URL.")
			}
			with, _ := cmd.Flags().GetString("with")
			setTmpl, _ := cmd.Flags().GetString("set")
			if setSet {
				lower := strings.ToLower(strings.TrimSpace(setTmpl))
				if !strings.HasPrefix(lower, "http://") && !strings.HasPrefix(lower, "https://") {
					return validationError("--set must be an absolute http:// or https:// URL, got %q", setTmpl).
						WithHint("Offer URLs are where clicks are sent, so they need a scheme and host, e.g. https://example.com/?utm_source={slug}.")
				}
			}
			slotRaw, _ := cmd.Flags().GetString("slot")
			fields, err := parseURLSlots(slotRaw)
			if err != nil {
				return err
			}
			var onlyIDs map[string]bool
			if raw, _ := cmd.Flags().GetString("ids"); strings.TrimSpace(raw) != "" {
				ids, perr := parseIDList(raw)
				if perr != nil {
					return perr
				}
				onlyIDs = map[string]bool{}
				for _, id := range ids {
					onlyIDs[id] = true
				}
			}
			dryRun, _ := cmd.Flags().GetBool("dry-run")
			force, _ := cmd.Flags().GetBool("force")

			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			params := map[string]string{}
			if v, _ := cmd.Flags().GetString("aff-network-id"); v != "" {
				params["filter[aff_network_id]"] = v
			}
			rows, err := fetchAllRowsWithParams(c, "campaigns", params)
			if err != nil {
				return err
			}
			changes := planURLReplacements(rows, fields, match, with, setTmpl, setSet, onlyIDs)
			order, bodies := groupChangesByCampaign(changes)

			if len(changes) == 0 {
				if err := renderURLChanges(changes); err != nil {
					return err
				}
				output.Success("No campaign offer URL contains %q (or every match already has the new value); nothing to change.", match)
				return nil
			}
			if dryRun {
				if err := renderURLChanges(changes); err != nil {
					return err
				}
				output.Success("Dry run: %d URL(s) on %d campaign(s) would change. Nothing was written; drop --dry-run to apply.", len(changes), len(order))
				return nil
			}
			if !force && !api.StagedMode() {
				fmt.Fprintf(os.Stderr, "%d URL(s) on %d campaign(s) will change:\n", len(changes), len(order))
				for _, ch := range changes {
					fmt.Fprintf(os.Stderr, "  #%s %s [%s]\n    - %s\n    + %s\n", ch.CampaignID, ch.CampaignName, ch.Field, ch.OldURL, ch.NewURL)
				}
				if !confirmPrompt("Apply these %d change(s)?", len(changes)) {
					fmt.Fprintln(os.Stderr, "Cancelled.")
					return nil
				}
			}

			// One PUT per campaign, so every slot of a campaign shares its outcome.
			outcome := map[string]urlChange{}
			updated, staged, failed := 0, 0, 0
			for _, id := range order {
				data, perr := c.Put("campaigns/"+id, bodies[id])
				if perr != nil {
					outcome[id] = urlChange{Status: "failed", Error: perr.Error()}
					failed++
					fmt.Fprintf(os.Stderr, "Failed to update campaign %s: %v\n", id, perr)
					continue
				}
				if cid, ok := stagedChangeID(data); ok {
					outcome[id] = urlChange{Status: "staged", ChangeID: cid}
					staged++
					continue
				}
				outcome[id] = urlChange{Status: "applied"}
				updated++
			}
			for i := range changes {
				o := outcome[changes[i].CampaignID]
				changes[i].Status, changes[i].ChangeID, changes[i].Error = o.Status, o.ChangeID, o.Error
			}
			if err := renderURLChanges(changes); err != nil {
				return err
			}
			if staged > 0 {
				output.Success("Staged %d of %d campaign update(s); apply them with `p202 change apply <change_id>`.", staged, len(order))
			} else {
				output.Success("Updated %d of %d campaign(s) (%d URL(s)).", updated, len(order), len(changes))
			}
			if failed > 0 {
				return partialFailureError("failed to update %d of %d campaigns", failed, len(order)).
					WithHint("Rows with status failed carry the server error. Fix it and re-run the same command: updated campaigns no longer match --match (unless the --with text still contains it), so only the failures are retried.")
			}
			return nil
		},
	}
	cmd.Flags().String("match", "", "Text the offer URLs to change contain (case-insensitive, required)")
	cmd.Flags().String("with", "", "Replace the matched text with this")
	cmd.Flags().String("set", "", "Replace the whole URL with this; {id} and {slug} are filled per campaign")
	cmd.Flags().String("slot", "all", "URL slots to consider: 1-5, comma-separated, or all")
	cmd.Flags().String("ids", "", "Only these campaign IDs (comma-separated)")
	cmd.Flags().String("aff-network-id", "", "Only campaigns in this affiliate network (`p202 aff-network list`)")
	cmd.Flags().Bool("dry-run", false, "List the changes without writing anything")
	cmd.Flags().BoolP("force", "f", false, "Skip the confirmation prompt")
	return cmd
}
