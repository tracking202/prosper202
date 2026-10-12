package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"p202/internal/api"
)

// An install with the Pro-only CLI gate answers 402 to p202-cli when its
// ClickServer key has no active Pro subscription; the CLI must say why and
// point at the trial, not blame the API key.
func TestCliExplainsProLicenceRefusal(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.Header.Get("User-Agent"), "p202-cli/") {
			t.Errorf("request without the p202-cli User-Agent the install gates on: %q", r.Header.Get("User-Agent"))
		}
		w.WriteHeader(402)
		w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence. Start a 7-day free trial at https://my.tracking202.com/api/customers/subscriptions/support"}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("user", "list")
	if err == nil || !strings.Contains(err.Error(), "Pro licence") {
		t.Fatalf("want the Pro licence refusal, got %v", err)
	}
	if h := hintFor(err); !strings.Contains(h, "7-day free trial") || strings.Contains(h, "Verify your API key") {
		t.Errorf("hint %q should point at the trial, not the key", h)
	}
	// A stable contract for agents: its own category and exit code, not
	// "validation"/1 (bad input) and not "auth"/2 (bad key).
	if got := api.ErrorCategory(err); got != "licence" {
		t.Errorf("category = %q, want licence", got)
	}
	if got := exitCodeForError(err); got != ExitLicence {
		t.Errorf("exit code = %d, want %d (ExitLicence)", got, ExitLicence)
	}
}

// licenceServer answers the handshake and refuses everything else with the
// install's 402 envelope; hits counts the refused requests.
func licenceServer(t *testing.T, hits *int) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/versions"):
			w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			w.Write([]byte(`{"data":{"shell":true,"cli":false,"features":{"delete_dry_run":true}}}`))
		default:
			*hits++
			w.WriteHeader(402)
			w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence on this install's ClickServer API key. Start a 7-day free trial at https://my.tracking202.com/api/customers/subscriptions/support"}`))
		}
	}))
}

func assertLicenceRefusal(t *testing.T, err error, what string) {
	t.Helper()
	if err == nil {
		t.Fatalf("%s: want the licence refusal, got nil", what)
	}
	if got := api.ErrorCategory(err); got != "licence" {
		t.Errorf("%s: category = %q, want licence (%v)", what, got, err)
	}
	if got := exitCodeForError(err); got != ExitLicence {
		t.Errorf("%s: exit code = %d, want %d", what, got, ExitLicence)
	}
	if h := hintFor(err); !strings.Contains(h, "7-day free trial") {
		t.Errorf("%s: hint %q should point at the trial", what, h)
	}
}

// The shell starts (the key is valid) and the first command meets the 402:
// the batch reports the refusal, not "one or more commands failed".
func TestShellBatchPreservesTheLicenceRefusal(t *testing.T) {
	hits := 0
	srv := licenceServer(t, &hits)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
	_, _, err := executeCommand("shell", "-c", "user list; campaign list")
	assertLicenceRefusal(t, err, "shell -c")
}

// Every profile refused: the report says so with the licence contract.
func TestMultiProfileReportPreservesTheLicenceRefusal(t *testing.T) {
	hits := 0
	a := licenceServer(t, &hits)
	defer a.Close()
	b := licenceServer(t, &hits)
	defer b.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": a.URL, "api_key": "prod-key-123456"},
		"staging": {"url": b.URL, "api_key": "staging-key-123456"},
	})
	_, _, err := executeCommand("report", "summary", "--all-profiles", "--json")
	assertLicenceRefusal(t, err, "report summary --all-profiles")
}

// A bulk delete stops at the first refusal instead of trying every id and
// calling the result a partial failure.
func TestBulkDeleteStopsOnTheLicenceRefusal(t *testing.T) {
	hits := 0
	srv := licenceServer(t, &hits)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
	stdout, _, err := executeCommand("campaign", "delete", "--ids", "1,2,3", "--force")
	assertLicenceRefusal(t, err, "campaign delete --ids")
	if hits != 1 {
		t.Errorf("want one refused request, then a stop; got %d", hits)
	}
	if strings.Contains(stdout, "Deleted") {
		t.Errorf("stdout must stay empty on a refusal, got %q", stdout)
	}
}

// exec runs children in their own processes; the parent reads their exit
// codes, so the contract has to be rebuilt from those.
func TestExecFailureIsTheLicenceRefusalWhenEveryChildWasRefused(t *testing.T) {
	err := execFailure(map[string]execResult{
		"prod":    {Profile: "prod", ExitCode: ExitLicence},
		"staging": {Profile: "staging", ExitCode: ExitLicence},
		"dev":     {Profile: "dev", ExitCode: 0},
	})
	assertLicenceRefusal(t, err, "exec, every failed child refused")
	mixed := execFailure(map[string]execResult{
		"prod":    {Profile: "prod", ExitCode: ExitLicence},
		"staging": {Profile: "staging", ExitCode: 1},
	})
	if api.ErrorCategory(mixed) == "licence" {
		t.Error("a child that failed for another reason must not be reported as a licence refusal")
	}
}

// A 402 is the licence refusal only with the install's own envelope: a bare
// 402 from a proxy is a server error (exit 4, retry), not a trip to the trial.
func TestABare402IsNotALicenceRefusal(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(402)
		w.Write([]byte(`<html><body>Payment Required</body></html>`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("user", "list")
	if err == nil {
		t.Fatal("want an error from the 402")
	}
	if got := api.ErrorCategory(err); got != "server" {
		t.Errorf("category = %q, want server", got)
	}
	if got := exitCodeForError(err); got != ExitServer {
		t.Errorf("exit code = %d, want %d (ExitServer)", got, ExitServer)
	}
	if h := hintFor(err); strings.Contains(h, "7-day free trial") {
		t.Errorf("hint %q must not send the user to the trial", h)
	}
	if abortsImport(err) {
		t.Error("a bare 402 is a server error and must not abort an import")
	}
}

// Every row of an import would meet the same 402, so the import stops at the
// first one (exit 6) instead of reporting each row as a partial failure.
func TestALicenceRefusalAbortsAnImport(t *testing.T) {
	refused := &api.APIError{Status: 402, Category: "licence", Message: "The Prosper202 CLI requires a Prosper202 ClickServer Pro licence."}
	if !abortsImport(refused) {
		t.Fatal("a licence refusal should abort the import like a refused key does")
	}
	if abortsImport(&api.APIError{Status: 422, Category: "validation", Message: "bad row"}) {
		t.Fatal("a validation error is per row and must not abort the import")
	}
}
