package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strings"
	"time"

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

// pendingURLChanges returns the rows not yet given a status (to be written).
func pendingURLChanges(rows []urlChange) []urlChange {
	var out []urlChange
	for _, r := range rows {
		if r.Status == "" {
			out = append(out, r)
		}
	}
	return out
}

// renderURLChanges prints the rows; undoPath, when set, goes in meta.undo_manifest.
func renderURLChanges(changes []urlChange, undoPath string) error {
	if changes == nil {
		changes = []urlChange{}
	}
	payload := map[string]interface{}{"data": changes}
	if undoPath != "" {
		payload["meta"] = map[string]interface{}{"undo_manifest": undoPath}
	}
	data, err := json.Marshal(payload)
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

// applyURLChanges confirms and sends the rows without a status (one PUT per
// campaign), saves the applied ones in an undo manifest, and renders every row.
func applyURLChanges(c *api.Client, rows []urlChange, force bool, record undoManifest, retryHint string) error {
	todo := pendingURLChanges(rows)
	order, bodies := groupChangesByCampaign(todo)
	skipped := countStatus(rows, "skipped")
	if !force && !api.StagedMode() {
		fmt.Fprintf(os.Stderr, "%d URL(s) on %d campaign(s) will change:\n", len(todo), len(order))
		for _, ch := range todo {
			fmt.Fprintf(os.Stderr, "  #%s %s [%s]\n    - %s\n    + %s\n", ch.CampaignID, ch.CampaignName, ch.Field, ch.OldURL, ch.NewURL)
		}
		if skipped > 0 {
			fmt.Fprintf(os.Stderr, "%d slot(s) are skipped and left as they are.\n", skipped)
		}
		if !confirmPrompt("Apply these %d change(s)?", len(todo)) {
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
	var applied []undoEntry
	for i := range rows {
		if rows[i].Status != "" {
			continue
		}
		o := outcome[rows[i].CampaignID]
		rows[i].Status, rows[i].ChangeID, rows[i].Error = o.Status, o.ChangeID, o.Error
		if r := rows[i]; r.Status == "applied" {
			applied = append(applied, undoEntry{CampaignID: r.CampaignID, CampaignName: r.CampaignName, Field: r.Field, OldURL: r.OldURL, NewURL: r.NewURL})
		}
	}

	// Staged and failed slots changed nothing on the server, so only applied ones are recorded.
	var undoPath string
	var undoErr error
	if len(applied) > 0 {
		now := time.Now()
		record.Format, record.Version, record.CreatedAt, record.Changes = undoManifestFormat, undoManifestVersion, now.UTC().Format(time.RFC3339), applied
		undoPath, undoErr = writeUndoManifest(record, now)
	}
	if err := renderURLChanges(rows, undoPath); err != nil {
		return err
	}
	if staged > 0 {
		output.Success("Staged %d of %d campaign update(s); apply them with `p202 change apply <change_id>`.", staged, len(order))
	} else {
		output.Success("Updated %d of %d campaign(s) (%d URL(s)).", updated, len(order), len(todo))
	}
	if skipped > 0 {
		output.Success("Skipped %d slot(s); their rows say why.", skipped)
	}
	if undoPath != "" {
		output.Success("Undo with: p202 campaign replace-url --undo %s --profile %s", shellArg(undoPath), shellArg(record.Profile))
	}

	var manifestHint string
	if undoErr != nil {
		// The writes happened: print the manifest so the undo is not lost with the file.
		if line, err := encodeUndoManifest(record, ""); err == nil {
			output.Success("Undo manifest (save it to a file, then pass it to --undo): %s", bytes.TrimSpace(line))
		}
		manifestHint = fmt.Sprintf("The rows with status applied were written. Save the JSON after `Undo manifest:` on stderr to a file to undo them with --undo <file>, and fix %s (permissions, free space) so later runs can save theirs.", undoDir())
	}
	switch {
	case failed > 0 && undoErr != nil:
		return partialFailureError("failed to update %d of %d campaigns, and the undo manifest for the %d applied slot(s) could not be saved: %v", failed, len(order), len(applied), undoErr).
			WithHint("%s %s", retryHint, manifestHint)
	case failed > 0:
		return partialFailureError("failed to update %d of %d campaigns", failed, len(order)).WithHint("%s", retryHint)
	case undoErr != nil:
		return partialFailureError("updated %d of %d campaign(s), but the undo manifest could not be saved: %v", updated, len(order), undoErr).
			WithHint("%s", manifestHint)
	}
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
			"but with --with it rewrites again if the replacement still contains --match.\n\n" +
			"Undo: every run that applies a change saves the applied slots (old and new\n" +
			"URL, profile, base URL) to ~/.p202/undo/replace-url-<UTC time>.json and\n" +
			"prints `Undo with: p202 campaign replace-url --undo <file>` (under --json\n" +
			"also meta.undo_manifest). --undo puts old_url back only where the slot\n" +
			"still holds that run's new_url; slots changed since, and deleted campaigns,\n" +
			"are left alone and listed with status skipped. It refuses a manifest\n" +
			"written against another base URL, cannot be combined with --match, --with,\n" +
			"--set, --slot, --ids or --aff-network-id, and otherwise behaves like a\n" +
			"normal run (--dry-run, the prompt, --force, --staged, exit 5 on a failed\n" +
			"PUT). An undo that applies changes saves its own manifest, so it can be\n" +
			"undone too. If a manifest cannot be saved after the writes, the command\n" +
			"prints it on stderr and exits 5.",
		Example: "  p202 campaign replace-url --match g2afse.com --set 'https://example.com/?utm_source={slug}' --dry-run\n" +
			"  p202 campaign replace-url --match http://promo.example.com --with https://promo.example.com --force\n" +
			"  p202 campaign replace-url --undo ~/.p202/undo/replace-url-20260930T101500Z.json --dry-run",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("replace-url", "campaigns")
			defer func() { done(retErr == nil, errString(retErr)) }()
			if cmd.Flags().Changed("undo") {
				return runReplaceURLUndo(cmd)
			}

			// Validate every flag before building a client.
			match, _ := cmd.Flags().GetString("match")
			if strings.TrimSpace(match) == "" {
				return validationError("--match is required").
					WithHint("Pass the text the old URLs share, e.g. --match old-network.com (preview with `p202 campaign list --url-contains <text>`), or --undo <file> to revert an earlier run.")
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
			target, err := activeReplaceURLTarget()
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

			if len(changes) == 0 {
				if err := renderURLChanges(changes, ""); err != nil {
					return err
				}
				output.Success("No campaign offer URL contains %q (or every match already has the new value); nothing to change.", match)
				return nil
			}
			if dryRun {
				if err := renderURLChanges(changes, ""); err != nil {
					return err
				}
				order, _ := groupChangesByCampaign(changes)
				output.Success("Dry run: %d URL(s) on %d campaign(s) would change. Nothing was written; drop --dry-run to apply.", len(changes), len(order))
				return nil
			}
			record := undoManifest{Profile: target.Profile, BaseURL: target.BaseURL, Match: match, With: with, Set: setTmpl}
			return applyURLChanges(c, changes, force, record,
				"Rows with status failed carry the server error. Fix it and re-run the same command: updated campaigns no longer match --match (unless the --with text still contains it), so only the failures are retried.")
		},
	}
	cmd.Flags().String("match", "", "Text the offer URLs to change contain (case-insensitive; required unless --undo)")
	cmd.Flags().String("with", "", "Replace the matched text with this")
	cmd.Flags().String("set", "", "Replace the whole URL with this; {id} and {slug} are filled per campaign")
	cmd.Flags().String("slot", "all", "URL slots to consider: 1-5, comma-separated, or all")
	cmd.Flags().String("ids", "", "Only these campaign IDs (comma-separated)")
	cmd.Flags().String("aff-network-id", "", "Only campaigns in this affiliate network (`p202 aff-network list`)")
	cmd.Flags().Bool("dry-run", false, "List the changes without writing anything")
	cmd.Flags().BoolP("force", "f", false, "Skip the confirmation prompt")
	cmd.Flags().String("undo", "", "Revert a run from its undo manifest `file` (printed after \"Undo with:\"): restores old URLs where the slot is unchanged since")
	return cmd
}
