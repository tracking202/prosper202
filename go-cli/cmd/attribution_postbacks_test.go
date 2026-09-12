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

	"p202/internal/api"
)

func TestAttributionPostbacksListMapsFlagsToApiParams(t *testing.T) {
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

	_, _, err := executeCommand("attribution", "postbacks", "list",
		"--app-id", "525463029", "--signature", "valid",
		"--ad-network-id", "example123.skadnetwork", "--did-win", "1",
		"--protocol", "aak", "--conversion-type", "re-engagement", "--ad-interaction-type", "view")
	if err != nil {
		t.Fatalf("attribution postbacks list error: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/attribution/postbacks") {
		t.Errorf("path = %q, want .../attribution/postbacks", gotPath)
	}
	for param, want := range map[string]string{
		"app_id":        "525463029",
		"signature":     "valid",
		"ad_network_id": "example123.skadnetwork",
		"did_win":       "1",
		// The server resolves the shorthand; the CLI passes it through.
		"protocol":            "aak",
		"conversion_type":     "re-engagement",
		"ad_interaction_type": "view",
	} {
		if got := gotParams.Get(param); got != want {
			t.Errorf("param %s = %q, want %q", param, got, want)
		}
	}
}

func TestAttributionReportLiftsGroupsIntoTheListShape(t *testing.T) {
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

	stdout, _, err := executeCommand("attribution", "report", "--group-by", "ad-network", "--json")
	if err != nil {
		t.Fatalf("attribution report error: %v", err)
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

func TestAttributionVerifySendsThePayloadWithIntegersIntact(t *testing.T) {
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

	stdout, _, err := executeCommand("attribution", "verify", "--file", file, "--json")
	if err != nil {
		t.Fatalf("attribution verify error: %v", err)
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

func TestAttributionVerifyRejectsNonJsonInputWithAHint(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	// No server: the input is rejected before any request could be built.
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	file := filepath.Join(tmp, "not-json.txt")
	if err := os.WriteFile(file, []byte("plainly not json"), 0o600); err != nil {
		t.Fatal(err)
	}
	_, _, err := executeCommand("attribution", "verify", "--file", file)
	if err == nil {
		t.Fatal("expected a validation error")
	}
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "postback") {
		t.Errorf("hint should tell the agent what to provide, got %q", hint)
	}
}

func TestAttributionCvCreateDemandsExactlyOneValueKind(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	// Neither kind.
	_, _, err := executeCommand("attribution", "cv", "create", "--event-name", "purchase")
	if err == nil {
		t.Fatal("expected a validation error for a rule with no value")
	}
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "--fine-value") {
		t.Errorf("hint should name the flags, got %q", hint)
	}

	// No event name.
	_, _, err = executeCommand("attribution", "cv", "create", "--fine-value", "10")
	if err == nil || !strings.Contains(err.Error(), "--event-name") {
		t.Fatalf("expected the missing --event-name to be named, got %v", err)
	}
	assertValidationError(t, err)

	// Both kinds.
	_, _, err = executeCommand("attribution", "cv", "create",
		"--event-name", "purchase", "--fine-value", "10", "--coarse-value", "high")
	if err == nil {
		t.Fatal("expected a validation error for a rule with both values")
	}
	assertValidationError(t, err)
}

func TestAttributionAppCreateRequiresAppIdAndName(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	_, _, err := executeCommand("attribution", "app", "create", "--app-name", "My App")
	if err == nil || !strings.Contains(err.Error(), "--app-id") {
		t.Fatalf("expected the missing --app-id to be named, got %v", err)
	}
	// The category is the contract, not the prose: a bare fmt.Errorf here
	// would still name the flag, but the --json envelope would report no
	// category of its own and the hint would be gone.
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "App Store") {
		t.Errorf("hint should say where the id comes from, got %q", hint)
	}
	_, _, err = executeCommand("attribution", "app", "create", "--app-id", "42")
	if err == nil || !strings.Contains(err.Error(), "--app-name") {
		t.Fatalf("expected the missing --app-name to be named, got %v", err)
	}
	assertValidationError(t, err)
}

func TestAttributionAppCreateSendsTheDevelopmentOptIn(t *testing.T) {
	var gotBody map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/attribution/apps") {
			_ = json.NewDecoder(r.Body).Decode(&gotBody)
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"attribution_app_id":7,"app_id":42,"app_name":"My App","accept_development_postbacks":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "app", "create", "--app-id", "42", "--app-name", "My App", "--accept-development-postbacks", "1")
	if err != nil {
		t.Fatalf("attribution app create error: %v", err)
	}
	if got := gotBody["accept_development_postbacks"]; got != "1" {
		t.Errorf("accept_development_postbacks = %v, want \"1\"", got)
	}
}

func TestAttributionAppCreateRejectsANonBooleanOptIn(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	_, _, err := executeCommand("attribution", "app", "create", "--app-id", "42", "--app-name", "My App", "--accept-development-postbacks", "yes")
	if err == nil || !strings.Contains(err.Error(), "--accept-development-postbacks") {
		t.Fatalf("expected the flag to be named, got %v", err)
	}
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "development") {
		t.Errorf("hint = %q, want it to explain the flag", hint)
	}
}

func TestAttributionAppRotateTokenPostsToTheRotateRoute(t *testing.T) {
	var gotMethod, gotPath string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"attribution_app_id":7,"app_id":525463029,"schema_token":"` + strings.Repeat("ab", 32) + `"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("attribution", "app", "rotate-token", "7", "--json")
	if err != nil {
		t.Fatalf("attribution app rotate-token error: %v", err)
	}
	if gotMethod != "POST" || !strings.HasSuffix(gotPath, "/attribution/apps/7/schema-token/rotate") {
		t.Errorf("request = %s %s, want POST .../attribution/apps/7/schema-token/rotate", gotMethod, gotPath)
	}
	if !strings.Contains(stdout, "schema_token") {
		t.Errorf("stdout should carry the new token, got:\n%s", stdout)
	}
}

func TestAttributionSchemaFetchesTheDeviceFacingDocumentByHeaderToken(t *testing.T) {
	token := strings.Repeat("cd", 32)
	var schemaHeaderToken, schemaRawQuery string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/attribution/apps/7"):
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"attribution_app_id":7,"app_id":525463029,"schema_token":"` + token + `"}}`))
		case strings.HasSuffix(r.URL.Path, "/attribution/schema"):
			schemaHeaderToken = r.Header.Get("X-P202-Schema-Token")
			schemaRawQuery = r.URL.RawQuery
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

	stdout, _, err := executeCommand("attribution", "schema", "7", "--json")
	if err != nil {
		t.Fatalf("attribution schema error: %v", err)
	}
	if schemaHeaderToken != token {
		t.Errorf("schema fetched with X-P202-Schema-Token %q, want the app's %q", schemaHeaderToken, token)
	}
	// Header-only transport: a token in the query string would be captured
	// by ordinary access logging, and the server rejects it there.
	if strings.Contains(schemaRawQuery, "token") {
		t.Errorf("the token must not appear in the query string, got %q", schemaRawQuery)
	}
	if !strings.Contains(stdout, `"schema_version"`) || !strings.Contains(stdout, "purchase") {
		t.Errorf("stdout should render the device-facing document, got:\n%s", stdout)
	}
}

func TestAttributionSchemaWithoutATokenNamesTheServerRequirement(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"attribution_app_id":7,"app_id":525463029}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "schema", "7")
	if err == nil {
		t.Fatal("expected a validation error when the registration has no token")
	}
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "features.attribution_postbacks") {
		t.Errorf("hint should name the server requirement, got %q", hint)
	}
}

func TestAttributionAppDeleteDryRunPreviewsWithoutConfirmation(t *testing.T) {
	var gotMethod, gotPath string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"delete","resource":"202_attribution_apps","mode":"hard","record":{"attribution_app_id":7},"cascade":[]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("attribution", "app", "delete", "7", "--dry-run")
	if err != nil {
		t.Fatalf("attribution app delete --dry-run error: %v", err)
	}
	if gotMethod != "DELETE" || !strings.HasSuffix(gotPath, "/attribution/apps/7") {
		t.Errorf("request = %s %s, want DELETE .../attribution/apps/7", gotMethod, gotPath)
	}
	if gotParams.Get("dry_run") != "1" {
		t.Errorf("dry_run param = %q, want 1", gotParams.Get("dry_run"))
	}
	if !strings.Contains(stdout, "dry_run") {
		t.Errorf("stdout should render the preview, got:\n%s", stdout)
	}
}

func TestAttributionVerifyUnderStagedModeStaysAnImmediateRead(t *testing.T) {
	// verify computes and stores nothing; there is no proposal to record.
	// Under global --staged (e.g. a staged shell profile) it must run
	// directly rather than being stamped staged=1 and bounced by the
	// server's "staged is not supported" rejection.
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"signature":"invalid","signed_message_base64":"eA=="}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	postback := `{"version":"4.0","ad-network-id":"n","app-id":1,"transaction-id":"tx","redownload":false,"attribution-signature":"AA=="}`
	file := filepath.Join(tmp, "postback.json")
	if err := os.WriteFile(file, []byte(postback), 0o600); err != nil {
		t.Fatal(err)
	}

	stdout, _, err := executeCommand("attribution", "verify", "--file", file, "--staged", "--json")
	if err != nil {
		t.Fatalf("attribution verify --staged error: %v", err)
	}
	if gotParams.Get("staged") != "" {
		t.Errorf("verify must not be stamped staged=1, params: %v", gotParams)
	}
	if !strings.Contains(stdout, `"signature"`) {
		t.Errorf("stdout should carry the verdict, got:\n%s", stdout)
	}
}

func TestAttributionCvUpdateClearFlagsSendExplicitNulls(t *testing.T) {
	// Switching a rule between kinds takes the clear and the replacement in
	// one request; the clear must arrive as a JSON null (an absent field
	// means "keep"), so the body cannot be map[string]string.
	var gotMethod string
	var gotBody []byte
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod = r.Method
		gotBody, _ = io.ReadAll(r.Body)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"rule_id":5,"fine_value":null,"coarse_value":"high","event_name":"purchase"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "cv", "update", "5", "--clear-fine-value", "--coarse-value", "high", "--json")
	if err != nil {
		t.Fatalf("attribution cv update error: %v", err)
	}
	if gotMethod != "PUT" {
		t.Errorf("method = %q, want PUT", gotMethod)
	}
	if !strings.Contains(string(gotBody), `"fine_value":null`) {
		t.Errorf("body must carry an explicit null for fine_value, got: %s", gotBody)
	}
	if !strings.Contains(string(gotBody), `"coarse_value":"high"`) {
		t.Errorf("body must carry the replacement coarse value, got: %s", gotBody)
	}
}

func TestAttributionCvUpdateClearAndSetOfTheSameKindConflict(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	// No server: the conflict is rejected before any request is built.
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	_, _, err := executeCommand("attribution", "cv", "update", "5", "--clear-fine-value", "--fine-value", "10")
	if err == nil {
		t.Fatal("expected a validation error")
	}
	assertValidationError(t, err)
	// The coarse pair is the analogous site; both must answer alike.
	_, _, err = executeCommand("attribution", "cv", "update", "5", "--clear-coarse-value", "--coarse-value", "high")
	if err == nil {
		t.Fatal("expected a validation error for the coarse pair too")
	}
	assertValidationError(t, err)
}

func TestAttributionAppListAllPaginatesThroughEveryPage(t *testing.T) {
	// The server caps pages at 2 rows (pagination.limit=2), so all three
	// rows take two requests — the traversal must follow the server's page
	// size, not assume its own.
	var offsets []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		offset := r.URL.Query().Get("offset")
		offsets = append(offsets, offset)
		w.WriteHeader(200)
		if offset == "0" {
			w.Write([]byte(`{"data":[{"attribution_app_id":1,"app_id":100},{"attribution_app_id":2,"app_id":200}],"pagination":{"total":3,"limit":2,"offset":0}}`))
			return
		}
		w.Write([]byte(`{"data":[{"attribution_app_id":3,"app_id":300}],"pagination":{"total":3,"limit":2,"offset":2}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("attribution", "app", "list", "--all", "--json")
	if err != nil {
		t.Fatalf("attribution app list --all error: %v", err)
	}
	if len(offsets) != 2 || offsets[0] != "0" || offsets[1] != "2" {
		t.Errorf("expected two pages at offsets 0 and 2, got: %v", offsets)
	}
	for _, id := range []string{`"app_id": 100`, `"app_id": 200`, `"app_id": 300`} {
		if !strings.Contains(stdout, id) && !strings.Contains(stdout, strings.ReplaceAll(id, ": ", ":")) {
			t.Errorf("stdout should carry every row (%s missing), got:\n%s", id, stdout)
		}
	}
}

// assertValidationError checks what the --json envelope actually carries for
// a rejected input: the explicit "validation" category and exit code 1.
// The category is the load-bearing half — exit 1 is also the fallback for an
// unrecognized error, so a path rewritten as a bare fmt.Errorf would keep the
// exit code while reporting no category at all.
func assertValidationError(t *testing.T, err error) {
	t.Helper()
	if err == nil {
		t.Fatal("expected an error")
	}
	if got := exitCodeForError(err); got != ExitValidation {
		t.Errorf("exit code = %d, want %d (validation)", got, ExitValidation)
	}
	if got := api.ErrorCategory(err); got != "validation" {
		t.Errorf("category = %q, want \"validation\" (a bare fmt.Errorf carries none)", got)
	}
}

// ── Error contract: exit code and hint, not just the wording ─────────
//
// Every error path below is asserted through exitCodeForError/hintFor
// rather than on prose, per the Go CLI error contract: a path rewritten as
// a bare fmt.Errorf (or wrapped with %v) keeps its sentence but silently
// changes the exit code and the --json envelope's category.

func TestAttributionPagingFlagsAreValidatedOnEveryCommandThatTakesThem(t *testing.T) {
	// The three list commands used to pass --limit straight through as a
	// query parameter while `report` validated it, so one typo was a CLI
	// validation error on one command and a server 422 on the next.
	var serverSaw []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		serverSaw = append(serverSaw, r.URL.Path+"?"+r.URL.RawQuery)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	cases := []struct {
		name    string
		args    []string
		message string
		hint    string
	}{
		{"postbacks list, non-numeric limit", []string{"attribution", "postbacks", "list", "--limit", "abc"}, "--limit must be a positive integer", "--limit 50"},
		{"app list, non-numeric limit", []string{"attribution", "app", "list", "--limit", "abc"}, "--limit must be a positive integer", "--limit 50"},
		{"cv list, non-numeric limit", []string{"attribution", "cv", "list", "--limit", "abc"}, "--limit must be a positive integer", "--limit 50"},
		{"report, non-numeric limit", []string{"attribution", "report", "--limit", "abc"}, "--limit must be a positive integer", "--limit 50"},
		{"postbacks list, zero limit", []string{"attribution", "postbacks", "list", "--limit", "0"}, "--limit must be a positive integer", "--limit 50"},
		{"postbacks list, negative offset", []string{"attribution", "postbacks", "list", "--offset", "-5"}, "--offset must be a non-negative integer", "--all"},
		{"app list, non-numeric offset", []string{"attribution", "app", "list", "--offset", "x"}, "--offset must be a non-negative integer", "--all"},
		{"cv list, negative offset", []string{"attribution", "cv", "list", "--offset", "-1"}, "--offset must be a non-negative integer", "--all"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			serverSaw = nil
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error for %v", tc.args)
			}
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), tc.message) {
				t.Errorf("message = %q, want it to contain %q", err.Error(), tc.message)
			}
			if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", hint, tc.hint)
			}
			if len(serverSaw) != 0 {
				t.Errorf("a mistyped paging flag must be caught before the request, server saw %v", serverSaw)
			}
		})
	}
}

func TestAttributionListsStillSendValidatedPagingThrough(t *testing.T) {
	// The shared runner must keep passing the paging values it validates.
	for _, tc := range []struct {
		name string
		path string
		args []string
	}{
		{"postbacks", "/attribution/postbacks", []string{"attribution", "postbacks", "list", "--limit", "5", "--offset", "10"}},
		{"apps", "/attribution/apps", []string{"attribution", "app", "list", "--limit", "5", "--offset", "10"}},
		{"conversion values", "/attribution/conversion-values", []string{"attribution", "cv", "list", "--limit", "5", "--offset", "10"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var gotPath string
			var gotParams url.Values
			srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
				gotPath = r.URL.Path
				gotParams = r.URL.Query()
				w.WriteHeader(200)
				w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":5,"offset":10}}`))
			}))
			defer srv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key")

			if _, _, err := executeCommand(tc.args...); err != nil {
				t.Fatalf("%v: %v", tc.args, err)
			}
			if !strings.HasSuffix(gotPath, tc.path) {
				t.Errorf("path = %q, want ...%s", gotPath, tc.path)
			}
			if gotParams.Get("limit") != "5" || gotParams.Get("offset") != "10" {
				t.Errorf("paging params = %v, want limit=5 offset=10", gotParams)
			}
		})
	}
}

// The shared list runner returns the client's error as it stands, so an API
// failure keeps the category and exit code the client gave it. A wrap with
// %v instead of %w anywhere on that path would read the same to a human and
// turn a 401 (exit 2, category auth, the key-check hint) into a bare exit 1
// with no category at all.
func TestAttributionListsKeepTheApiErrorContract(t *testing.T) {
	for _, tc := range []struct {
		name string
		args []string
	}{
		{"postbacks", []string{"attribution", "postbacks", "list"}},
		{"apps", []string{"attribution", "app", "list"}},
		{"conversion values", []string{"attribution", "cv", "list"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(401)
				w.Write([]byte(`{"message":"invalid api key"}`))
			}))
			defer srv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "bad-key-123")

			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected an auth error for %v", tc.args)
			}
			if got := exitCodeForError(err); got != ExitAuth {
				t.Errorf("exit code = %d, want %d (auth)", got, ExitAuth)
			}
			if got := api.ErrorCategory(err); got != "auth" {
				t.Errorf("category = %q, want \"auth\"", got)
			}
			if hint := hintFor(err); !strings.Contains(hint, "config set-key") {
				t.Errorf("hint = %q, want it to name the key check", hint)
			}
		})
	}
}

func TestAttributionUpdatesUseTheSameEmptyBodyWordingAsTheGeneratedCommands(t *testing.T) {
	// An agent scripting against `p202 campaign update`'s wording must not
	// have to special-case these two commands.
	const want = "no fields specified; pass at least one flag to update"

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	for _, tc := range []struct {
		name string
		args []string
		hint string
	}{
		{"app update", []string{"attribution", "app", "update", "7"}, "--app-name"},
		{"cv update", []string{"attribution", "cv", "update", "5"}, "--fine-value"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error for %v", tc.args)
			}
			if err.Error() != want {
				t.Errorf("message = %q, want %q", err.Error(), want)
			}
			assertValidationError(t, err)
			if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
				t.Errorf("hint = %q, want it to name %s", hint, tc.hint)
			}
		})
	}
}

// withStdin points os.Stdin at f for one test and restores it afterwards.
func withStdin(t *testing.T, f *os.File) {
	t.Helper()
	old := os.Stdin
	os.Stdin = f
	t.Cleanup(func() { os.Stdin = old })
}

func TestAttributionVerifyWithoutPipedInputFailsInsteadOfBlocking(t *testing.T) {
	// io.ReadAll(os.Stdin) on a terminal never returns: the command printed
	// nothing, exited never, and an agent had nothing to read. /dev/null is
	// the character device a test can open; the guard is the same one.
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Skipf("cannot open %s to stand in for a terminal: %v", os.DevNull, err)
	}
	defer devNull.Close()
	if !isTerminal(devNull) {
		t.Fatalf("%s is not a character device here, so this test would exercise the piped path instead", os.DevNull)
	}
	withStdin(t, devNull)

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	for _, tc := range []struct {
		name string
		args []string
	}{
		{"no --file", []string{"attribution", "verify", "--json"}},
		{"--file -", []string{"attribution", "verify", "--file", "-", "--json"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatal("expected a validation error rather than a read that blocks")
			}
			assertValidationError(t, err)
			if hint := hintFor(err); !strings.Contains(hint, "--file") {
				t.Errorf("hint = %q, want it to name --file and the pipe form", hint)
			}
		})
	}
}

func TestAttributionVerifyStillReadsAPipedPostback(t *testing.T) {
	var gotBody []byte
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotBody, _ = io.ReadAll(r.Body)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"signature":"valid","signed_message_base64":"eA=="}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	postback := `{"version":"4.0","ad-network-id":"n","app-id":525463029,"transaction-id":"tx","attribution-signature":"AA=="}`
	if _, err := w.WriteString(postback); err != nil {
		t.Fatal(err)
	}
	w.Close()
	if isTerminal(r) {
		t.Fatal("a pipe must not read as a terminal, or the guard would refuse real input")
	}
	withStdin(t, r)

	stdout, _, err := executeCommand("attribution", "verify", "--json")
	if err != nil {
		t.Fatalf("piped verify error: %v", err)
	}
	if !strings.Contains(string(gotBody), `"app-id":525463029`) {
		t.Errorf("the piped postback must reach the server intact, body: %s", gotBody)
	}
	if !strings.Contains(stdout, `"signature"`) {
		t.Errorf("stdout should carry the verdict, got:\n%s", stdout)
	}
}

func TestAttributionVerifyNamesAnUnreadableFile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	_, _, err := executeCommand("attribution", "verify", "--file", filepath.Join(tmp, "nope.json"))
	if err == nil {
		t.Fatal("expected a validation error for a missing file")
	}
	assertValidationError(t, err)
	if hint := hintFor(err); !strings.Contains(hint, "--file") {
		t.Errorf("hint = %q, want it to name --file", hint)
	}
}

func TestAttributionSchemaSeparatesAnUnreadableResponseFromAMissingToken(t *testing.T) {
	// A proxy error page is a different failure with a different remedy
	// than a registration that has no token; the two must not fold into
	// one message, and the wrap must keep the validation exit code.
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`<html>proxy says no</html>`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "schema", "7")
	if err == nil {
		t.Fatal("expected an error when the response is not the JSON envelope")
	}
	// This one wraps a plain error rather than a CLIError, so it carries no
	// category — the exit code and the hint are what an agent gets.
	if got := exitCodeForError(err); got != ExitValidation {
		t.Errorf("exit code = %d, want %d (validation)", got, ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "config show") {
		t.Errorf("hint = %q, want it to point at the configured URL", hint)
	}
}

// The attribution model/snapshot/export commands in attribution.go had no
// test of their own and no test file; their error paths are asserted here
// rather than in a new file. Each writes a config first, so what is being
// asserted is the flag check and not a missing-config error (the commands
// build their client before validating, so an empty HOME would answer with
// the config error instead).

func TestAttributionModelCommandsReportTheirErrorContract(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:0", "test-key")

	for _, tc := range []struct {
		name    string
		args    []string
		message string
		hint    string
	}{
		{
			"create without a name",
			[]string{"attribution", "model", "create"},
			"required flag --model_name is missing", "",
		},
		{
			"create without a type",
			[]string{"attribution", "model", "create", "--model_name", "Linear"},
			"required flag --model_type is missing", "",
		},
		{
			"create with unparseable weighting config",
			[]string{"attribution", "model", "create", "--model_name", "L", "--model_type", "linear", "--weighting_config", "{"},
			"invalid --weighting_config JSON", "--weighting_config",
		},
		{
			"update with no fields",
			[]string{"attribution", "model", "update", "1"},
			"no fields specified; pass at least one flag to update", "",
		},
		{
			"update with unparseable weighting config",
			[]string{"attribution", "model", "update", "1", "--weighting_config", "nope"},
			"invalid --weighting_config JSON", "--weighting_config",
		},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error for %v", tc.args)
			}
			if !strings.Contains(err.Error(), tc.message) {
				t.Errorf("message = %q, want it to contain %q", err.Error(), tc.message)
			}
			assertValidationError(t, err)
			if tc.hint != "" {
				if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
					t.Errorf("hint = %q, want it to contain %q", hint, tc.hint)
				}
			}
		})
	}
}
