package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	configpkg "p202/internal/config"
	"p202/internal/output"
)

const (
	undoManifestFormat  = "p202.campaign.replace-url.undo"
	undoManifestVersion = 1
)

// undoEntry is one offer URL slot a replace-url run changed on the server.
type undoEntry struct {
	CampaignID   string `json:"aff_campaign_id"`
	CampaignName string `json:"aff_campaign_name"`
	Field        string `json:"field"`
	OldURL       string `json:"old_url"`
	NewURL       string `json:"new_url"`
}

// undoManifest records the applied slots of one replace-url run so --undo can put them back.
type undoManifest struct {
	Format    string      `json:"format"`
	Version   int         `json:"version"`
	CreatedAt string      `json:"created_at"`
	Profile   string      `json:"profile"`
	BaseURL   string      `json:"base_url"`
	Match     string      `json:"match,omitempty"`
	With      string      `json:"with,omitempty"`
	Set       string      `json:"set,omitempty"`
	UndoOf    string      `json:"undo_of,omitempty"`
	Changes   []undoEntry `json:"changes"`
}

// replaceURLTarget is the profile, and its base URL, that replace-url writes to.
type replaceURLTarget struct {
	Profile string
	BaseURL string
}

func trimBaseURL(u string) string {
	return strings.TrimRight(strings.TrimSpace(u), "/")
}

func activeReplaceURLTarget() (replaceURLTarget, error) {
	p, name, err := configpkg.LoadProfileWithName("")
	if err != nil {
		return replaceURLTarget{}, err
	}
	return replaceURLTarget{Profile: name, BaseURL: trimBaseURL(p.URL)}, nil
}

func undoDir() string {
	return filepath.Join(configpkg.Dir(), "undo")
}

// encodeUndoManifest keeps & and < literal: offer URLs are full of them.
func encodeUndoManifest(m undoManifest, indent string) ([]byte, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", indent)
	if err := enc.Encode(m); err != nil {
		return nil, fmt.Errorf("encoding the undo manifest: %w", err)
	}
	return buf.Bytes(), nil
}

// writeUndoManifest saves m as undo/replace-url-<UTC time>.json and returns its path.
func writeUndoManifest(m undoManifest, now time.Time) (string, error) {
	dir := undoDir()
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", fmt.Errorf("creating %s: %w", dir, err)
	}
	data, err := encodeUndoManifest(m, "  ")
	if err != nil {
		return "", err
	}
	base := "replace-url-" + now.UTC().Format("20060102T150405Z")
	// O_EXCL never overwrites an earlier manifest; runs in the same second get -2, -3, ...
	for n := 1; n <= 100; n++ {
		name := base + ".json"
		if n > 1 {
			name = fmt.Sprintf("%s-%d.json", base, n)
		}
		path := filepath.Join(dir, name)
		f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if errors.Is(err, fs.ErrExist) {
			continue
		}
		if err != nil {
			return "", fmt.Errorf("creating %s: %w", path, err)
		}
		if _, err := f.Write(data); err != nil {
			_ = f.Close()
			_ = os.Remove(path)
			return "", fmt.Errorf("writing %s: %w", path, err)
		}
		if err := f.Close(); err != nil {
			_ = os.Remove(path)
			return "", fmt.Errorf("writing %s: %w", path, err)
		}
		return path, nil
	}
	return "", fmt.Errorf("no free file name for %s in %s", base, dir)
}

// loadUndoManifest reads and checks a manifest written by writeUndoManifest.
func loadUndoManifest(path string) (*undoManifest, error) {
	where := fmt.Sprintf("replace-url prints the path after `Undo with:` and keeps every manifest in %s.", undoDir())
	data, err := os.ReadFile(path)
	if err != nil {
		var pe *fs.PathError
		if errors.As(err, &pe) {
			err = pe.Err // the message already names the path
		}
		return nil, validationError("cannot read undo manifest %s: %v", path, err).WithHint("Check the path; %s", where)
	}
	var m undoManifest
	if err := json.Unmarshal(data, &m); err != nil {
		return nil, validationError("%s is not a replace-url undo manifest: %v", path, err).WithHint("Pass a file replace-url wrote; %s", where)
	}
	if m.Format != undoManifestFormat {
		return nil, validationError("%s is not a replace-url undo manifest (format %q, want %q)", path, m.Format, undoManifestFormat).
			WithHint("Pass a file replace-url wrote; %s", where)
	}
	if m.Version != undoManifestVersion {
		return nil, validationError("undo manifest %s has format version %d; this p202 reads version %d", path, m.Version, undoManifestVersion).
			WithHint("Run the undo with the p202 build that wrote the manifest, or upgrade p202 (`p202 --version`).")
	}
	if trimBaseURL(m.BaseURL) == "" || len(m.Changes) == 0 {
		return nil, validationError("undo manifest %s has no base_url or no changes", path).WithHint("Pass a file replace-url wrote; %s", where)
	}
	seen := map[string]bool{}
	for i, e := range m.Changes {
		known := false
		for _, f := range campaignURLFields {
			known = known || e.Field == f
		}
		if _, err := strconv.Atoi(e.CampaignID); err != nil || !known || seen[e.CampaignID+"/"+e.Field] {
			return nil, validationError("undo manifest %s: change %d (campaign %q, field %q) is invalid or repeated", path, i+1, e.CampaignID, e.Field).
				WithHint("Only numeric campaign ids and the offer URL fields %s can be restored; pass the file unedited.", strings.Join(campaignURLFields, ", "))
		}
		seen[e.CampaignID+"/"+e.Field] = true
	}
	return &m, nil
}

// planURLRestore puts each entry's old_url back where the slot still holds the
// manifest's new_url; every other entry comes back with status skipped.
func planURLRestore(m *undoManifest, rows []map[string]interface{}) []urlChange {
	byID := map[string]map[string]interface{}{}
	for _, row := range rows {
		if rawID, ok := extractIntField(row, "aff_campaign_id", "id"); ok {
			byID[fmt.Sprint(rawID)] = row
		}
	}
	out := make([]urlChange, 0, len(m.Changes))
	for _, e := range m.Changes {
		ch := urlChange{CampaignID: e.CampaignID, CampaignName: e.CampaignName, Field: e.Field, NewURL: e.OldURL}
		row, ok := byID[e.CampaignID]
		if !ok {
			ch.Status, ch.Error = "skipped", "campaign not found; it was deleted since the manifest was written"
			out = append(out, ch)
			continue
		}
		if name, _ := row["aff_campaign_name"].(string); name != "" {
			ch.CampaignName = name
		}
		ch.OldURL, _ = row[e.Field].(string)
		switch ch.OldURL {
		case e.NewURL:
			// Still what the run wrote: restore it.
		case e.OldURL:
			ch.Status, ch.Error = "skipped", "already restored"
		default:
			ch.Status, ch.Error = "skipped", "changed since the manifest was written (expected "+e.NewURL+"); left as is"
		}
		out = append(out, ch)
	}
	return out
}

func countStatus(rows []urlChange, status string) int {
	n := 0
	for _, r := range rows {
		if r.Status == status {
			n++
		}
	}
	return n
}

// runReplaceURLUndo restores the slots an undo manifest lists.
func runReplaceURLUndo(cmd *cobra.Command) error {
	for _, name := range []string{"match", "with", "set", "slot", "ids", "aff-network-id"} {
		if cmd.Flags().Changed(name) {
			return validationError("--undo cannot be combined with --%s", name).
				WithHint("--undo restores exactly the slots its manifest lists: drop --match/--with/--set/--slot/--ids/--aff-network-id, and preview with --undo <file> --dry-run.")
		}
	}
	path, _ := cmd.Flags().GetString("undo")
	m, err := loadUndoManifest(path)
	if err != nil {
		return err
	}
	if abs, aerr := filepath.Abs(path); aerr == nil {
		path = abs
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
	if target.BaseURL != trimBaseURL(m.BaseURL) {
		e := validationError("undo manifest %s was written against %s (profile %q), but the active profile %q points at %s; nothing was changed",
			path, m.BaseURL, m.Profile, target.Profile, target.BaseURL)
		if m.Profile != target.Profile {
			return e.WithHint("Re-run with --profile %s (`p202 config list-profiles` shows each profile's URL).", m.Profile)
		}
		return e.WithHint("Profile %q now points elsewhere: add a profile for %s (`p202 config add-profile <name> --url %s --key <key>`) and re-run with --profile <name>; if the instance itself moved, set base_url in the manifest.", m.Profile, m.BaseURL, m.BaseURL)
	}

	rows, err := fetchAllRowsWithParams(c, "campaigns", nil)
	if err != nil {
		return err
	}
	plan := planURLRestore(m, rows)
	skipped := countStatus(plan, "skipped")
	pending := len(plan) - skipped
	if pending == 0 {
		if err := renderURLChanges(plan, ""); err != nil {
			return err
		}
		output.Success("Nothing to restore: all %d slot(s) in the manifest were skipped (see status and error).", skipped)
		return nil
	}
	if dryRun {
		if err := renderURLChanges(plan, ""); err != nil {
			return err
		}
		order, _ := groupChangesByCampaign(pendingURLChanges(plan))
		output.Success("Dry run: %d URL(s) on %d campaign(s) would be restored, %d slot(s) skipped. Nothing was written; drop --dry-run to apply.", pending, len(order), skipped)
		return nil
	}
	record := undoManifest{Profile: target.Profile, BaseURL: target.BaseURL, UndoOf: path}
	return applyURLChanges(c, plan, force, record,
		"Rows with status failed carry the server error. Fix it and re-run the same --undo: restored slots are skipped as already restored, so only the failures are retried.")
}

// shellArg single-quotes s when a POSIX shell would split or expand it.
func shellArg(s string) string {
	plain := func(r rune) bool {
		return r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || strings.ContainsRune("/._-:+=@,", r)
	}
	if s != "" && strings.IndexFunc(s, func(r rune) bool { return !plain(r) }) < 0 {
		return s
	}
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}
