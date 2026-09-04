package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/spf13/cobra"

	"p202/internal/api"
)

// withCapabilities answers the client's feature probe before delegating to
// the test's own handler. The client refuses to send --dry-run or --staged
// to a server that does not advertise support, because such a server would
// ignore the unknown query parameter and perform the real write; any mock
// exercising those flags therefore has to advertise them.
func withCapabilities(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		// The probe also negotiates the API version, so absorb both here:
		// tests that count requests are asserting on the command's own
		// traffic, not on the client's one-time handshake.
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"features":{"delete_dry_run":true,"staged_writes":true,"create_idempotency":true}}}`))
			return
		}
		if strings.HasSuffix(r.URL.Path, "/versions") {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"current":"v3","supported":["v3"]}}`))
			return
		}
		h(w, r)
	}
}

// --- Delete dry-run ---

func TestDeleteDryRunSendsDryRunParamWithoutConfirmation(t *testing.T) {
	var gotMethod string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"delete","resource":"campaigns","mode":"soft","record":{"aff_campaign_id":42},"cascade":[]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	// No --force: a preview is read-only and must not prompt (a prompt would
	// read empty stdin, cancel, and never hit the server).
	stdout, _, err := executeCommand("campaign", "delete", "42", "--dry-run")
	if err != nil {
		t.Fatalf("campaign delete --dry-run error: %v", err)
	}
	if gotMethod != "DELETE" {
		t.Errorf("method = %q, want DELETE", gotMethod)
	}
	if got := gotParams.Get("dry_run"); got != "1" {
		t.Errorf("dry_run param = %q, want %q", got, "1")
	}
	if !strings.Contains(stdout, "dry_run") {
		t.Errorf("stdout should render the preview, got:\n%s", stdout)
	}
}

func TestDeleteDryRunBulkIdsPreviewsEach(t *testing.T) {
	var paths []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.Method+" "+r.URL.Path+"?"+r.URL.RawQuery)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"delete","resource":"trackers","mode":"hard","record":{},"cascade":[]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("tracker", "delete", "--ids", "5,6", "--dry-run", "--json")
	if err != nil {
		t.Fatalf("tracker delete --ids --dry-run error: %v", err)
	}
	if len(paths) != 2 {
		t.Fatalf("expected 2 preview requests, got %d: %v", len(paths), paths)
	}
	for _, p := range paths {
		if !strings.HasPrefix(p, "DELETE /api/v3/trackers/") || !strings.Contains(p, "dry_run=1") {
			t.Errorf("unexpected preview request %q", p)
		}
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	if len(resp.Data) != 2 {
		t.Errorf("preview data length = %d, want 2", len(resp.Data))
	}
}

func TestRotatorRuleDeleteDryRunTargetsRuleEndpoint(t *testing.T) {
	var gotPath, gotQuery string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotQuery = r.URL.RawQuery
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"delete","resource":"rotator-rules","mode":"hard","record":{},"cascade":[]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("rotator", "rule-delete", "3", "9", "--dry-run"); err != nil {
		t.Fatalf("rotator rule-delete --dry-run error: %v", err)
	}
	if gotPath != "/api/v3/rotators/3/rules/9" {
		t.Errorf("path = %q, want /api/v3/rotators/3/rules/9", gotPath)
	}
	if !strings.Contains(gotQuery, "dry_run=1") {
		t.Errorf("query = %q, want dry_run=1", gotQuery)
	}
}

func TestEveryDeleteCommandHasDryRunFlag(t *testing.T) {
	// The delete contract is uniform: any command that deletes a remote
	// entity must offer --force and --dry-run so agents can preview before
	// destroying. `config remove-profile` is local-only and exempt.
	found := 0
	var visit func(c *cobra.Command)
	visit = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			visit(sub)
		}
		name := c.Name()
		isRemoteDelete := name == "delete" || name == "rule-delete" ||
			(name == "remove" && c.Parent() != nil && c.Parent().Name() == "role")
		if !isRemoteDelete {
			return
		}
		found++
		if c.Flags().Lookup("dry-run") == nil {
			t.Errorf("%s is missing --dry-run", c.CommandPath())
		}
		if c.Flags().Lookup("force") == nil {
			t.Errorf("%s is missing --force", c.CommandPath())
		}
	}
	visit(rootCmd)
	if found < 12 {
		t.Errorf("expected to find at least 12 delete commands, found %d — did command registration change?", found)
	}
}

// --- Create idempotency ---

func TestCreateSendsIdempotencyKeyHeader(t *testing.T) {
	var gotHeader string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotHeader = r.Header.Get("Idempotency-Key")
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"aff_campaign_id":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "create",
		"--aff_campaign_name", "A", "--aff_campaign_url", "https://x.example",
		"--idempotency-key", "create-A-1")
	if err != nil {
		t.Fatalf("campaign create --idempotency-key error: %v", err)
	}
	if gotHeader != "create-A-1" {
		t.Errorf("Idempotency-Key header = %q, want %q", gotHeader, "create-A-1")
	}
}

func TestCreateWithoutIdempotencyKeyOmitsHeader(t *testing.T) {
	sentinel := "unset"
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, ok := r.Header["Idempotency-Key"]; ok {
			sentinel = r.Header.Get("Idempotency-Key")
		} else {
			sentinel = ""
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"aff_campaign_id":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "create",
		"--aff_campaign_name", "A", "--aff_campaign_url", "https://x.example")
	if err != nil {
		t.Fatalf("campaign create error: %v", err)
	}
	if sentinel != "" {
		t.Errorf("Idempotency-Key header should be absent, got %q", sentinel)
	}
}

// --- API key scopes ---

func TestApikeyCreateSendsScope(t *testing.T) {
	var gotBody map[string]interface{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"user_id":7,"api_key":"k","scope":"read","created_at":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("user", "apikey", "create", "7", "--scope", "read"); err != nil {
		t.Fatalf("user apikey create --scope error: %v", err)
	}
	if gotBody["scope"] != "read" {
		t.Errorf("posted scope = %#v, want %q", gotBody["scope"], "read")
	}
}

func TestApikeyRotateCarriesOldKeyScope(t *testing.T) {
	var createBody map[string]interface{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "GET" && r.URL.Path == "/api/v3/users/7/api-keys":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"user_id":7,"api_key":"old-key-************************","scope":"reports:read","created_at":1}]}`))
		case r.Method == "POST" && r.URL.Path == "/api/v3/users/7/api-keys":
			_ = json.NewDecoder(r.Body).Decode(&createBody)
			w.WriteHeader(201)
			w.Write([]byte(`{"data":{"api_key":"new-key-abcdef1234","scope":"reports:read"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--keep-old")
	if err != nil {
		t.Fatalf("user apikey rotate error: %v", err)
	}
	if createBody["scope"] != "reports:read" {
		t.Errorf("rotation posted scope = %#v, want %q (carried from old key)", createBody["scope"], "reports:read")
	}
	if !strings.Contains(stdout, "reports:read") {
		t.Errorf("output should report the carried scope, got:\n%s", stdout)
	}
}

func TestApikeyRotateAmbiguousPrefixDemandsExplicitScope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" && r.URL.Path == "/api/v3/users/7/api-keys" {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[
				{"user_id":7,"api_key":"old-key-a***********************","scope":"read","created_at":1},
				{"user_id":7,"api_key":"old-key-b***********************","scope":"*","created_at":2}
			]}`))
			return
		}
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"rotation should not proceed"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--keep-old")
	if err == nil {
		t.Fatal("expected an error for ambiguous key prefix")
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "--scope") {
		t.Errorf("hint should name --scope, got %q", hint)
	}
}

func TestApikeyRotateUnknownPrefixRefusesRatherThanMintingFullAccess(t *testing.T) {
	// A lookup that matches nothing leaves the old key's scope unknown, and
	// unknown must never resolve to a full-access key.
	var posted bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" && r.URL.Path == "/api/v3/users/7/api-keys" {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"user_id":7,"api_key":"zzzzzzzz***********************","scope":"read","created_at":1}]}`))
			return
		}
		if r.Method == "POST" {
			posted = true
		}
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"rotation should not proceed"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--keep-old")
	if err == nil {
		t.Fatal("expected an error when no key matches the prefix")
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
	if posted {
		t.Error("no key may be minted when the old key's scope cannot be determined")
	}
}

func TestApikeyRotateShortOldKeyRefusesRatherThanMintingFullAccess(t *testing.T) {
	var posted bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" {
			posted = true
		}
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"rotation should not proceed"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("user", "apikey", "rotate", "7", "short", "--keep-old")
	if err == nil {
		t.Fatal("expected an error for an old key too short to identify")
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
	if posted {
		t.Error("no key may be minted when the old key cannot be identified")
	}
}

func TestDryRunRefusesWhenTheServerDoesNotAdvertiseSupport(t *testing.T) {
	// A server predating dry-run ignores the unknown query parameter and
	// performs the delete, so the flag must never reach it.
	var sawDelete bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"features":{}}}`))
			return
		}
		if r.Method == "DELETE" {
			sawDelete = true
		}
		w.WriteHeader(204)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "delete", "42", "--dry-run")
	if err == nil {
		t.Fatal("expected --dry-run to be refused against a server without support")
	}
	if sawDelete {
		t.Fatal("no DELETE may be sent when the server cannot honor dry_run")
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
}

func TestStagedRefusesWhenTheServerDoesNotAdvertiseSupport(t *testing.T) {
	var sawWrite bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"features":{}}}`))
			return
		}
		if r.Method == "POST" {
			sawWrite = true
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"aff_campaign_id":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "create", "--aff-campaign-name", "X",
		"--aff-campaign-url", "https://example.com", "--aff-network-id", "1",
		"--aff-campaign-payout", "1", "--staged")
	if err == nil {
		t.Fatal("expected --staged to be refused against a server without support")
	}
	if sawWrite {
		t.Fatal("no write may be sent when the server would execute it instead of staging it")
	}
}

// --- Staged writes ---

func TestGlobalStagedFlagStampsWrites(t *testing.T) {
	var gotQuery url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.Query()
		w.WriteHeader(202)
		w.Write([]byte(`{"data":{"change_id":"chg_aabbccddeeff001122334455","status":"staged","method":"POST","path":"/campaigns"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("campaign", "create",
		"--aff_campaign_name", "A", "--aff_campaign_url", "https://x.example",
		"--staged", "--json")
	if err != nil {
		t.Fatalf("campaign create --staged error: %v", err)
	}
	if got := gotQuery.Get("staged"); got != "1" {
		t.Errorf("staged param = %q, want %q", got, "1")
	}
	if !strings.Contains(stdout, "chg_aabbccddeeff001122334455") {
		t.Errorf("stdout should render the staged-change envelope, got:\n%s", stdout)
	}
}

func TestStagedDeleteRendersEnvelopeWithoutConfirmation(t *testing.T) {
	var gotMethod string
	var gotQuery url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotQuery = r.URL.Query()
		w.WriteHeader(202)
		w.Write([]byte(`{"data":{"change_id":"chg_aabbccddeeff001122334455","status":"staged","method":"DELETE","path":"/campaigns/42"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	// No --force: staging is a proposal, not a deletion, so it must not
	// prompt (a prompt would read empty stdin, cancel, and never call out).
	stdout, _, err := executeCommand("campaign", "delete", "42", "--staged")
	if err != nil {
		t.Fatalf("campaign delete --staged error: %v", err)
	}
	if gotMethod != "DELETE" || gotQuery.Get("staged") != "1" {
		t.Errorf("expected DELETE with staged=1, got %s %v", gotMethod, gotQuery)
	}
	if !strings.Contains(stdout, "chg_") {
		t.Errorf("stdout should render the staged-change envelope, got:\n%s", stdout)
	}
}

func TestStagedModeDoesNotStampChangeOrDryRunCalls(t *testing.T) {
	var paths []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.Method+" "+r.URL.RequestURI())
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"change_id":"chg_aabbccddeeff001122334455","status":"applied"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	// Applying under global --staged must not stage the apply itself.
	if _, _, err := executeCommand("change", "apply", "chg_aabbccddeeff001122334455", "--force", "--staged"); err != nil {
		t.Fatalf("change apply error: %v", err)
	}
	// An explicit --dry-run preview under global --staged stays a preview.
	if _, _, err := executeCommand("campaign", "delete", "42", "--dry-run", "--staged"); err != nil {
		t.Fatalf("campaign delete --dry-run --staged error: %v", err)
	}

	for _, p := range paths {
		if strings.Contains(p, "staged-changes") && strings.Contains(p, "staged=1") {
			t.Errorf("staged-changes call must not carry staged=1: %s", p)
		}
		if strings.Contains(p, "dry_run=1") && strings.Contains(p, "staged=1") {
			t.Errorf("dry-run preview must not also be staged: %s", p)
		}
	}
	if len(paths) != 2 {
		t.Fatalf("expected 2 requests, got %d: %v", len(paths), paths)
	}
}

func TestChangeListPassesFilters(t *testing.T) {
	var gotPath string
	var gotQuery url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotQuery = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("change", "list", "--status", "staged", "--all"); err != nil {
		t.Fatalf("change list error: %v", err)
	}
	if gotPath != "/api/v3/staged-changes" {
		t.Errorf("path = %q, want /api/v3/staged-changes", gotPath)
	}
	if gotQuery.Get("status") != "staged" || gotQuery.Get("all") != "1" {
		t.Errorf("query = %v, want status=staged and all=1", gotQuery)
	}
}

func TestChangeApplyForceSkipsTheFetchAndPosts(t *testing.T) {
	var paths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.Method+" "+r.URL.Path)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"change":{"change_id":"chg_aabbccddeeff001122334455","status":"applied"},"result":null}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("change", "apply", "chg_aabbccddeeff001122334455", "--force", "--json")
	if err != nil {
		t.Fatalf("change apply --force error: %v", err)
	}
	if len(paths) != 1 || paths[0] != "POST /api/v3/staged-changes/chg_aabbccddeeff001122334455/apply" {
		t.Errorf("requests = %v, want a single POST to .../apply", paths)
	}
	if !strings.Contains(stdout, "applied") {
		t.Errorf("stdout should show the applied change, got:\n%s", stdout)
	}
}

// --- Hints and exit codes for the new failure classes ---

func TestHintForStagedUnsupportedEndpoint(t *testing.T) {
	err := &api.APIError{Status: 422, Message: "staged is not supported for this endpoint"}
	if hint := hintFor(err); !strings.Contains(hint, "--staged") {
		t.Errorf("hint should tell the agent to drop --staged, got %q", hint)
	}
}

func TestHintForScopeDenied403(t *testing.T) {
	err := &api.APIError{Status: 403, Message: "Insufficient API key scope for this operation: requires 'campaigns:write' (key has: read)."}
	if exitCodeForError(err) != ExitAuth {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitAuth)
	}
	hint := hintFor(err)
	if !strings.Contains(hint, "--scope") || !strings.Contains(hint, "apikey create") {
		t.Errorf("scope-denied hint should point at minting a scoped key, got %q", hint)
	}
}

func TestHintForDryRunUnsupportedEndpoint(t *testing.T) {
	base := &api.APIError{Status: 422, Message: "dry_run is not supported for this endpoint"}
	err := withDryRunHint(base)
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "--dry-run") {
		t.Errorf("hint should tell the agent to drop --dry-run, got %q", hint)
	}
	// Unrelated 422s keep their normal hint.
	other := withDryRunHint(&api.APIError{Status: 422, Message: "Validation failed"})
	if hint := hintFor(other); strings.Contains(hint, "--dry-run") {
		t.Errorf("non-dry-run 422 must not get the dry-run hint, got %q", hint)
	}
}
