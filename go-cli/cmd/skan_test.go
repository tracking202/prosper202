package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSkanPostbacksListMapsFlagsToApiParams(t *testing.T) {
	var gotPath string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("skan", "postbacks", "list",
		"--app-id", "525463029", "--signature", "valid",
		"--ad-network-id", "example123.skadnetwork", "--did-win", "1")
	if err != nil {
		t.Fatalf("skan postbacks list error: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/skan/postbacks") {
		t.Errorf("path = %q, want .../skan/postbacks", gotPath)
	}
	for param, want := range map[string]string{
		"app_id":        "525463029",
		"signature":     "valid",
		"ad_network_id": "example123.skadnetwork",
		"did_win":       "1",
	} {
		if got := gotParams.Get(param); got != want {
			t.Errorf("param %s = %q, want %q", param, got, want)
		}
	}
}

func TestSkanReportLiftsGroupsIntoTheListShape(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if got := r.URL.Query().Get("group_by"); got != "ad-network" {
			t.Errorf("group_by param = %q, want ad-network", got)
		}
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"group_by":"ad-network","groups":[{"ad_network_id":"n1","postbacks":3,"installs":2}]},"meta":{"timezone":"UTC"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("skan", "report", "--group-by", "ad-network", "--json")
	if err != nil {
		t.Fatalf("skan report error: %v", err)
	}
	var parsed struct {
		Data []map[string]interface{} `json:"data"`
		Meta map[string]interface{}   `json:"meta"`
	}
	if err := json.Unmarshal([]byte(stdout), &parsed); err != nil {
		t.Fatalf("output is not the reshaped envelope: %v\n%s", err, stdout)
	}
	if len(parsed.Data) != 1 || parsed.Data[0]["ad_network_id"] != "n1" {
		t.Errorf("data should be the groups array, got %v", parsed.Data)
	}
	if parsed.Meta["group_by"] != "ad-network" || parsed.Meta["timezone"] != "UTC" {
		t.Errorf("meta should keep timezone and gain group_by, got %v", parsed.Meta)
	}
}

func TestSkanVerifySendsThePayloadWithIntegersIntact(t *testing.T) {
	var gotBody []byte
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotBody, _ = io.ReadAll(r.Body)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"signature":"invalid","signed_message_base64":"eA==","verifiable_versions":["2.1","2.2","3.0","4.0"]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	postback := `{"version":"4.0","ad-network-id":"n","app-id":525463029,"transaction-id":"tx","redownload":false,"attribution-signature":"AA=="}`
	file := filepath.Join(tmp, "postback.json")
	if err := os.WriteFile(file, []byte(postback), 0o600); err != nil {
		t.Fatal(err)
	}

	stdout, _, err := executeCommand("skan", "verify", "--file", file, "--json")
	if err != nil {
		t.Fatalf("skan verify error: %v", err)
	}
	// The server-side verifier refuses coerced types, so the app-id must
	// arrive as the integer it was — not 5.25463029e+08.
	if !strings.Contains(string(gotBody), `"app-id":525463029`) {
		t.Errorf("app-id must survive as an integer, body: %s", gotBody)
	}
	if !strings.Contains(stdout, `"signature"`) {
		t.Errorf("stdout should carry the verdict, got:\n%s", stdout)
	}
}

func TestSkanVerifyRejectsNonJsonInputWithAHint(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	// No server: the input is rejected before any request could be built.
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	file := filepath.Join(tmp, "not-json.txt")
	if err := os.WriteFile(file, []byte("plainly not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	_, _, err := executeCommand("skan", "verify", "--file", file)
	if err == nil {
		t.Fatal("expected a validation error")
	}
	if got := exitCodeForError(err); got != 1 {
		t.Errorf("exit code = %d, want 1 (validation)", got)
	}
	if hint := hintFor(err); !strings.Contains(hint, "postback") {
		t.Errorf("hint should tell the agent what to provide, got %q", hint)
	}
}

func TestSkanCvCreateDemandsExactlyOneValueKind(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	// Neither kind.
	_, _, err := executeCommand("skan", "cv", "create", "--event-name", "purchase")
	if err == nil {
		t.Fatal("expected a validation error for a rule with no value")
	}
	if hint := hintFor(err); !strings.Contains(hint, "--fine-value") {
		t.Errorf("hint should name the flags, got %q", hint)
	}

	// Both kinds.
	_, _, err = executeCommand("skan", "cv", "create",
		"--event-name", "purchase", "--fine-value", "10", "--coarse-value", "high")
	if err == nil {
		t.Fatal("expected a validation error for a rule with both values")
	}
	if got := exitCodeForError(err); got != 1 {
		t.Errorf("exit code = %d, want 1 (validation)", got)
	}
}

func TestSkanAppCreateRequiresAppIdAndName(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	_, _, err := executeCommand("skan", "app", "create", "--app-name", "My App")
	if err == nil || !strings.Contains(err.Error(), "--app-id") {
		t.Fatalf("expected the missing --app-id to be named, got %v", err)
	}
	_, _, err = executeCommand("skan", "app", "create", "--app-id", "42")
	if err == nil || !strings.Contains(err.Error(), "--app-name") {
		t.Fatalf("expected the missing --app-name to be named, got %v", err)
	}
}

func TestSkanAppRotateTokenPostsToTheRotateRoute(t *testing.T) {
	var gotMethod, gotPath string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"skan_app_id":7,"app_id":525463029,"schema_token":"` + strings.Repeat("ab", 32) + `"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("skan", "app", "rotate-token", "7", "--json")
	if err != nil {
		t.Fatalf("skan app rotate-token error: %v", err)
	}
	if gotMethod != "POST" || !strings.HasSuffix(gotPath, "/skan/apps/7/schema-token/rotate") {
		t.Errorf("request = %s %s, want POST .../skan/apps/7/schema-token/rotate", gotMethod, gotPath)
	}
	if !strings.Contains(stdout, "schema_token") {
		t.Errorf("stdout should carry the new token, got:\n%s", stdout)
	}
}

func TestSkanSchemaFetchesTheDeviceFacingDocumentByToken(t *testing.T) {
	token := strings.Repeat("cd", 32)
	var schemaQueryToken string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/skan/apps/7"):
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"skan_app_id":7,"app_id":525463029,"schema_token":"` + token + `"}}`))
		case strings.HasSuffix(r.URL.Path, "/skan/schema"):
			schemaQueryToken = r.URL.Query().Get("token")
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"app_id":525463029,"schema_version":"v1","events":{"purchase":{"fine_value":63,"coarse_value":"high"}}}}`))
		default:
			t.Errorf("unexpected request %s", r.URL.Path)
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("skan", "schema", "7", "--json")
	if err != nil {
		t.Fatalf("skan schema error: %v", err)
	}
	if schemaQueryToken != token {
		t.Errorf("schema fetched with token %q, want the app's %q", schemaQueryToken, token)
	}
	if !strings.Contains(stdout, `"schema_version"`) || !strings.Contains(stdout, "purchase") {
		t.Errorf("stdout should render the device-facing document, got:\n%s", stdout)
	}
}

func TestSkanSchemaWithoutATokenNamesTheServerRequirement(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"skan_app_id":7,"app_id":525463029}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("skan", "schema", "7")
	if err == nil {
		t.Fatal("expected a validation error when the registration has no token")
	}
	if hint := hintFor(err); !strings.Contains(hint, "skan_remote_schema") {
		t.Errorf("hint should name the server requirement, got %q", hint)
	}
}

func TestSkanAppDeleteDryRunPreviewsWithoutConfirmation(t *testing.T) {
	var gotMethod, gotPath string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"delete","resource":"202_skan_apps","mode":"hard","record":{"skan_app_id":7},"cascade":[]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("skan", "app", "delete", "7", "--dry-run")
	if err != nil {
		t.Fatalf("skan app delete --dry-run error: %v", err)
	}
	if gotMethod != "DELETE" || !strings.HasSuffix(gotPath, "/skan/apps/7") {
		t.Errorf("request = %s %s, want DELETE .../skan/apps/7", gotMethod, gotPath)
	}
	if gotParams.Get("dry_run") != "1" {
		t.Errorf("dry_run param = %q, want 1", gotParams.Get("dry_run"))
	}
	if !strings.Contains(stdout, "dry_run") {
		t.Errorf("stdout should render the preview, got:\n%s", stdout)
	}
}
