package cmd

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"testing"
	"time"
)

var undoNamePattern = regexp.MustCompile(`^replace-url-\d{8}T\d{6}Z(-\d+)?\.json$`)

// undoFiles lists the undo manifests under home's config dir, oldest name first.
func undoFiles(t *testing.T, home string) []string {
	t.Helper()
	paths, err := filepath.Glob(filepath.Join(home, ".p202", "undo", "*.json"))
	if err != nil {
		t.Fatal(err)
	}
	sort.Strings(paths)
	return paths
}

func readManifest(t *testing.T, path string) (undoManifest, map[string]interface{}) {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading manifest: %v", err)
	}
	var m undoManifest
	var raw map[string]interface{}
	if err := json.Unmarshal(data, &m); err != nil {
		t.Fatalf("manifest is not JSON: %v\n%s", err, data)
	}
	if err := json.Unmarshal(data, &raw); err != nil {
		t.Fatal(err)
	}
	return m, raw
}

func rowByID(f *campaignFake, id int) map[string]interface{} {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, row := range f.rows {
		if row["aff_campaign_id"] == id {
			return row
		}
	}
	return nil
}

func TestReplaceURLUndoManifestRecordsOnlyAppliedSlots(t *testing.T) {
	f := newCampaignFake()
	f.failPut = map[string]bool{"281": true}
	home, baseURL := setupCampaignFake(t, f)

	stdout, stderr, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/?c={id}", "--force", "--json")
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Fatalf("exit code = %d (err %v), want %d for the failed PUT", got, err, ExitPartialFailure)
	}
	files := undoFiles(t, home)
	if len(files) != 1 {
		t.Fatalf("undo manifests = %v, want exactly one", files)
	}
	path := files[0]
	if !undoNamePattern.MatchString(filepath.Base(path)) {
		t.Errorf("manifest name %q, want replace-url-YYYYMMDDTHHMMSSZ.json", filepath.Base(path))
	}
	if runtime.GOOS != "windows" {
		for p, want := range map[string]os.FileMode{filepath.Dir(path): 0o700, path: 0o600} {
			info, serr := os.Stat(p)
			if serr != nil {
				t.Fatal(serr)
			}
			if got := info.Mode().Perm(); got != want {
				t.Errorf("%s permissions = %o, want %o", p, got, want)
			}
		}
	}

	if body, _ := os.ReadFile(path); !strings.Contains(string(body), "pid=2753&offer_id=1683") {
		t.Errorf("manifest should keep & literal for reading by eye:\n%s", body)
	}
	m, raw := readManifest(t, path)
	if m.Format != undoManifestFormat || m.Version != 1 || m.Profile != "default" || m.BaseURL != baseURL {
		t.Errorf("manifest header = %+v, want format %q, version 1, profile default, base_url %s", m, undoManifestFormat, baseURL)
	}
	if _, perr := time.Parse(time.RFC3339, m.CreatedAt); perr != nil {
		t.Errorf("created_at %q is not RFC 3339: %v", m.CreatedAt, perr)
	}
	if m.Match != "g2afse" || m.Set != "https://new.example/?c={id}" {
		t.Errorf("recorded flags match=%q set=%q", m.Match, m.Set)
	}
	if _, ok := raw["with"]; ok {
		t.Errorf("--with was not given, so the manifest should not carry it: %v", raw)
	}
	want := []undoEntry{{CampaignID: "279", CampaignName: "Darkmoon Realm", Field: "aff_campaign_url",
		OldURL: "https://aanicca.G2AFSE.com/click?pid=2753&offer_id=1683&sub1=[[subid]]", NewURL: "https://new.example/?c=279"}}
	if len(m.Changes) != 1 || m.Changes[0] != want[0] {
		t.Errorf("changes = %+v, want only the applied slot %+v (281 failed)", m.Changes, want)
	}

	var resp struct {
		Meta map[string]string `json:"meta"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v", err)
	}
	if resp.Meta["undo_manifest"] != path {
		t.Errorf("meta.undo_manifest = %q, want %q", resp.Meta["undo_manifest"], path)
	}
	if !strings.Contains(stderr, "Undo with: p202 campaign replace-url --undo "+path+" --profile default") {
		t.Errorf("stderr does not name the undo command:\n%s", stderr)
	}
}

func TestReplaceURLWritesNoUndoManifestWhenNothingWasApplied(t *testing.T) {
	cases := []struct {
		name    string
		args    []string
		stdin   string
		failAll bool
		stage   bool
	}{
		{name: "dry run", args: []string{"--dry-run"}},
		{name: "declined prompt", stdin: "n"},
		{name: "staged", args: []string{"--force"}, stage: true},
		{name: "every PUT failed", args: []string{"--force"}, failAll: true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := newCampaignFake()
			f.stageAll = tc.stage
			if tc.failAll {
				f.failPut = map[string]bool{"279": true, "281": true}
			}
			home, _ := setupCampaignFake(t, f)
			if tc.stdin != "" {
				feedStdin(t, tc.stdin)
			}
			args := []string{"campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--json"}
			if tc.stage {
				args = append([]string{"--staged"}, args...)
			}
			stdout, _, _ := executeCommand(append(args, tc.args...)...)
			if files := undoFiles(t, home); len(files) != 0 {
				t.Errorf("undo manifests = %v, want none", files)
			}
			if strings.Contains(stdout, "undo_manifest") {
				t.Errorf("stdout names a manifest that was not written:\n%s", stdout)
			}
		})
	}
}

func TestReplaceURLUndoRestoresOnlySlotsStillHoldingTheRunsValue(t *testing.T) {
	f := newCampaignFake()
	home, _ := setupCampaignFake(t, f)
	original := map[int]map[string]interface{}{}
	for _, row := range f.rows {
		cp := map[string]interface{}{}
		for k, v := range row {
			cp[k] = v
		}
		original[row["aff_campaign_id"].(int)] = cp
	}

	// Every URL of the fake holds ".com": 6 slots on 4 campaigns.
	if _, _, err := executeCommand("campaign", "replace-url", "--match", ".com", "--with", ".net", "--force"); err != nil {
		t.Fatalf("replace-url: %v", err)
	}
	files := undoFiles(t, home)
	if len(files) != 1 {
		t.Fatalf("undo manifests = %v, want one", files)
	}
	first := files[0]
	if m, _ := readManifest(t, first); len(m.Changes) != 6 {
		t.Fatalf("manifest changes = %+v, want 6", m.Changes)
	}

	// Since the run: someone edits 60's slot 2, and campaign 5 is deleted.
	rowByID(f, 60)["aff_campaign_url_2"] = "https://manual.example/alt"
	f.mu.Lock()
	f.rows = f.rows[:3]
	f.puts = nil
	f.mu.Unlock()

	stdout, stderr, err := executeCommand("campaign", "replace-url", "--undo", first, "--force", "--json")
	if err != nil {
		t.Fatalf("replace-url --undo: %v\n%s", err, stderr)
	}
	bodies := map[string]map[string]interface{}{}
	for _, p := range f.puts {
		bodies[p.ID] = p.Body
	}
	if len(f.puts) != 3 {
		t.Fatalf("puts = %+v, want one each for 279, 60 and 281", f.puts)
	}
	if len(bodies["60"]) != 1 || bodies["60"]["aff_campaign_url"] != original[60]["aff_campaign_url"] {
		t.Errorf("PUT 60 = %v, want only slot 1 restored (slot 2 changed since)", bodies["60"])
	}
	if bodies["281"]["aff_campaign_url"] != original[281]["aff_campaign_url"] ||
		bodies["281"]["aff_campaign_url_3"] != original[281]["aff_campaign_url_3"] {
		t.Errorf("PUT 281 = %v, want both slots restored", bodies["281"])
	}
	if rowByID(f, 279)["aff_campaign_url"] != original[279]["aff_campaign_url"] {
		t.Errorf("279 slot 1 = %v, want the original back", rowByID(f, 279)["aff_campaign_url"])
	}
	if rowByID(f, 60)["aff_campaign_url_2"] != "https://manual.example/alt" {
		t.Errorf("the edited slot was overwritten: %v", rowByID(f, 60)["aff_campaign_url_2"])
	}

	got := map[string]urlChange{}
	for _, ch := range decodeChanges(t, stdout) {
		got[ch.CampaignID+"/"+ch.Field] = ch
	}
	if ch := got["60/aff_campaign_url_2"]; ch.Status != "skipped" || !strings.Contains(ch.Error, "changed since") || ch.OldURL != "https://manual.example/alt" {
		t.Errorf("60 slot 2 row = %+v, want skipped as changed since, showing the current value", ch)
	}
	if ch := got["5/aff_campaign_url"]; ch.Status != "skipped" || !strings.Contains(ch.Error, "not found") {
		t.Errorf("deleted campaign row = %+v, want skipped as not found", ch)
	}
	if ch := got["279/aff_campaign_url"]; ch.Status != "applied" || ch.OldURL != "https://aanicca.G2AFSE.net/click?pid=2753&offer_id=1683&sub1=[[subid]]" || ch.NewURL != original[279]["aff_campaign_url"] {
		t.Errorf("279 row = %+v, want applied with old_url the current value and new_url the restored one", ch)
	}

	// The undo saved its own manifest, pointing back at the first.
	files = undoFiles(t, home)
	if len(files) != 2 {
		t.Fatalf("undo manifests = %v, want the run's and the undo's", files)
	}
	var undoOfUndo undoManifest
	for _, p := range files {
		if m, _ := readManifest(t, p); m.UndoOf != "" {
			undoOfUndo = m
		}
	}
	if undoOfUndo.UndoOf != first || len(undoOfUndo.Changes) != 4 || undoOfUndo.Match != "" {
		t.Errorf("undo's manifest = %+v, want undo_of the first manifest and the 4 restored slots", undoOfUndo)
	}
}

func TestReplaceURLUndoDryRunWritesNothing(t *testing.T) {
	f := newCampaignFake()
	home, _ := setupCampaignFake(t, f)
	if _, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--force"); err != nil {
		t.Fatalf("replace-url: %v", err)
	}
	manifest := undoFiles(t, home)[0]
	f.mu.Lock()
	f.puts = nil
	f.mu.Unlock()

	stdout, stderr, err := executeCommand("campaign", "replace-url", "--undo", manifest, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("replace-url --undo --dry-run: %v", err)
	}
	if len(f.puts) != 0 {
		t.Fatalf("dry run wrote: %+v", f.puts)
	}
	if files := undoFiles(t, home); len(files) != 1 {
		t.Errorf("undo manifests = %v, want only the original run's", files)
	}
	rows := decodeChanges(t, stdout)
	if len(rows) != 2 || rows[0].OldURL != "https://new.example/" || rows[0].NewURL != "https://aanicca.G2AFSE.com/click?pid=2753&offer_id=1683&sub1=[[subid]]" || rows[0].Status != "" {
		t.Errorf("dry-run rows = %+v, want the planned restores with no status", rows)
	}
	if !strings.Contains(stderr, "would be restored") {
		t.Errorf("stderr = %q", stderr)
	}
}

func TestReplaceURLUndoRefusesAnotherBaseURLBeforeAnyRequest(t *testing.T) {
	prod, stage := newCampaignFake(), newCampaignFake()
	prodSrv, stageSrv := prod.server(t), stage.server(t)
	home := t.TempDir()
	setTestHome(t, home)
	writeTestConfigWithProfiles(t, home, "prod", map[string]map[string]interface{}{
		"prod":  {"url": prodSrv.URL, "api_key": "test-key"},
		"stage": {"url": stageSrv.URL, "api_key": "test-key"},
	})
	if _, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--force"); err != nil {
		t.Fatalf("replace-url: %v", err)
	}
	manifest := undoFiles(t, home)[0]
	prod.mu.Lock()
	prod.puts, prod.gets = nil, nil
	prod.mu.Unlock()

	_, _, err := executeCommand("--profile", "stage", "campaign", "replace-url", "--undo", manifest, "--force")
	if got := exitCodeForError(err); got != ExitValidation {
		t.Fatalf("exit code = %d (err %v), want %d", got, err, ExitValidation)
	}
	if !strings.Contains(err.Error(), prodSrv.URL) || !strings.Contains(hintFor(err), "--profile prod") {
		t.Errorf("err = %v, hint = %q; want the manifest's URL and --profile prod", err, hintFor(err))
	}
	if n := len(stage.gets) + len(stage.puts) + len(prod.gets) + len(prod.puts); n != 0 {
		t.Errorf("refusal must precede any request; stage gets=%v puts=%v prod gets=%v puts=%v", stage.gets, stage.puts, prod.gets, prod.puts)
	}

	// Same profile name, moved to another URL: point at add-profile instead.
	writeTestConfigWithProfiles(t, home, "prod", map[string]map[string]interface{}{
		"prod": {"url": stageSrv.URL, "api_key": "test-key"},
	})
	_, _, err = executeCommand("campaign", "replace-url", "--undo", manifest, "--force")
	if got := exitCodeForError(err); got != ExitValidation || !strings.Contains(hintFor(err), "add-profile") {
		t.Errorf("exit %d, hint %q; want validation with an add-profile hint", got, hintFor(err))
	}
	if len(stage.gets)+len(stage.puts) != 0 {
		t.Errorf("stage got requests: gets=%v puts=%v", stage.gets, stage.puts)
	}
}

func TestReplaceURLUndoValidatesBeforeAnyRequest(t *testing.T) {
	dir := t.TempDir()
	write := func(name, body string) string {
		p := filepath.Join(dir, name)
		if err := os.WriteFile(p, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		return p
	}
	entry := `{"aff_campaign_id":"279","aff_campaign_name":"x","field":"aff_campaign_url","old_url":"https://a/","new_url":"https://b/"}`
	header := `"format":"p202.campaign.replace-url.undo","created_at":"2026-09-30T00:00:00Z","profile":"default","base_url":"http://127.0.0.1:9"`
	valid := write("valid.json", `{`+header+`,"version":1,"changes":[`+entry+`]}`)
	cases := []struct {
		name string
		args []string
		msg  string
		hint string
	}{
		{"with match", []string{"--undo", valid, "--match", "a"}, "cannot be combined with --match", "--undo <file> --dry-run"},
		{"with with", []string{"--undo", valid, "--with", "a"}, "cannot be combined with --with", "drop --match"},
		{"with set", []string{"--undo", valid, "--set", "https://x/"}, "cannot be combined with --set", "drop --match"},
		{"with slot", []string{"--undo", valid, "--slot", "all"}, "cannot be combined with --slot", "drop --match"},
		{"with ids", []string{"--undo", valid, "--ids", "279"}, "cannot be combined with --ids", "drop --match"},
		{"with network", []string{"--undo", valid, "--aff-network-id", "32"}, "cannot be combined with --aff-network-id", "drop --match"},
		{"missing file", []string{"--undo", filepath.Join(dir, "nope.json")}, "cannot read undo manifest", "Undo with:"},
		{"not JSON", []string{"--undo", write("bad.json", "{nope")}, "is not a replace-url undo manifest", "Undo with:"},
		{"other JSON", []string{"--undo", write("config.json", `{"url":"http://x","api_key":"k"}`)}, "is not a replace-url undo manifest", "Undo with:"},
		{"newer version", []string{"--undo", write("v2.json", `{`+header+`,"version":2,"changes":[`+entry+`]}`)}, "format version 2", "upgrade p202"},
		{"non-URL field", []string{"--undo", write("field.json", `{`+header+`,"version":1,"changes":[`+strings.Replace(entry, `"aff_campaign_url"`, `"aff_campaign_postback_url"`, 1)+`]}`)}, "is invalid or repeated", "offer URL fields"},
		{"no changes", []string{"--undo", write("empty.json", `{`+header+`,"version":1,"changes":[]}`)}, "no changes", "Undo with:"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := newCampaignFake()
			setupCampaignFake(t, f)
			_, _, err := executeCommand(append([]string{"campaign", "replace-url", "--force"}, tc.args...)...)
			if err == nil {
				t.Fatal("expected a validation error")
			}
			if got := exitCodeForError(err); got != ExitValidation {
				t.Errorf("exit code = %d, want %d", got, ExitValidation)
			}
			if !strings.Contains(err.Error(), tc.msg) {
				t.Errorf("message = %q, want it to contain %q", err.Error(), tc.msg)
			}
			if !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", hintFor(err), tc.hint)
			}
			if len(f.gets)+len(f.puts) != 0 {
				t.Errorf("validation must precede any request; gets=%v puts=%+v", f.gets, f.puts)
			}
		})
	}
}

func TestReplaceURLUndoManifestWriteFailureKeepsResultsAndExits5(t *testing.T) {
	f := newCampaignFake()
	home, _ := setupCampaignFake(t, f)
	// A file where the undo directory belongs makes the save fail after the PUTs.
	blocker := filepath.Join(home, ".p202", "undo")
	if err := os.WriteFile(blocker, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}

	stdout, stderr, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--force", "--json")
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Fatalf("exit code = %d (err %v), want %d", got, err, ExitPartialFailure)
	}
	if !strings.Contains(err.Error(), "undo manifest could not be saved") || !strings.Contains(hintFor(err), "Undo manifest:") {
		t.Errorf("err = %v, hint = %q", err, hintFor(err))
	}
	rows := decodeChanges(t, stdout)
	if len(rows) != 2 || rows[0].Status != "applied" || rows[1].Status != "applied" {
		t.Errorf("rows = %+v, want both applied rows rendered", rows)
	}

	// The manifest printed on stderr is a working --undo input.
	_, line, ok := strings.Cut(stderr, "Undo manifest (save it to a file, then pass it to --undo): ")
	if !ok {
		t.Fatalf("stderr has no manifest:\n%s", stderr)
	}
	line, _, _ = strings.Cut(line, "\n")
	saved := filepath.Join(t.TempDir(), "saved.json")
	if err := os.WriteFile(saved, []byte(line), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(blocker); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("campaign", "replace-url", "--undo", saved, "--force"); err != nil {
		t.Fatalf("--undo with the printed manifest: %v", err)
	}
	if got := rowByID(f, 281)["aff_campaign_url_3"]; got != "https://aanicca.g2afse.com/click?offer_id=1692" {
		t.Errorf("281 slot 3 = %v, want it restored", got)
	}
}
