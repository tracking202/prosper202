package cmd

import (
	"errors"
	"io"
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
		w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence. Start a Pro trial or subscription at https://my.tracking202.com/api/customers/subscriptions/support"}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("user", "list")
	if err == nil || !strings.Contains(err.Error(), "Pro licence") {
		t.Fatalf("want the Pro licence refusal, got %v", err)
	}
	if h := hintFor(err); !strings.Contains(h, "Pro trial or subscription") || strings.Contains(h, "Verify your API key") {
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

// A refusal because the install could not confirm its licence points at
// reaching Tracking202, not at buying a trial the user may already have.
func TestCliExplainsAnUnconfirmedLicence(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(402)
		w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"This install's licence could not be checked with Tracking202. Make sure this server can reach my.tracking202.com over HTTPS; Pro features come back once it can. For help, contact support@tracking202.com or the support chat."}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("user", "list")
	if err == nil || !strings.Contains(err.Error(), "could not be checked") {
		t.Fatalf("want the unconfirmed-licence refusal, got %v", err)
	}
	if h := hintFor(err); !strings.Contains(h, "my.tracking202.com over HTTPS") || !strings.Contains(h, "support@tracking202.com") || strings.Contains(h, "Pro trial") {
		t.Errorf("hint %q should point at reaching Tracking202, not at a trial", h)
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
			w.Write([]byte(`{"data":{"shell":true,"cli":false,"features":{"delete_dry_run":true,"staged_writes":true}}}`))
		default:
			*hits++
			w.WriteHeader(402)
			w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence on this install's ClickServer API key. Start a Pro trial or subscription at https://my.tracking202.com/api/customers/subscriptions/support"}`))
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
	if h := hintFor(err); !strings.Contains(h, "Pro trial or subscription") {
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
	if !strings.Contains(err.Error(), "2 of 3 selected profiles failed") {
		t.Errorf("message %q should count the refused profiles, not call all three refused", err)
	}
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
	h := hintFor(err)
	if strings.Contains(h, "Pro trial or subscription") {
		t.Errorf("hint %q must not send the user to the trial", h)
	}
	// It is still a failure with a next step: retry it as the server error it
	// is, and look at what answers in front of the install.
	if !strings.Contains(h, "Retry") || !strings.Contains(h, "proxy") {
		t.Errorf("hint %q should say to retry and name what sits in front of the install", h)
	}
	if env, _ := errorEnvelope(err)["error"].(map[string]interface{}); env["hint"] == nil {
		t.Errorf("the --json envelope carries no hint: %v", env)
	}
	if abortsImport(err) {
		t.Error("a bare 402 is a server error and must not abort an import")
	}
}

// A JSON 402 is a licence refusal only when it names the licence category
// exactly; a WAF's or a suspended host's JSON 402 stays a server error.
func TestAJSON402WithoutTheLicenceCategoryIsNotARefusal(t *testing.T) {
	for _, body := range []string{
		`{"error":true,"status":402,"message":"Payment Required"}`,
		`{"error":true,"status":402,"category":"server","message":"Payment Required"}`,
		`{"error":true,"status":402,"category":"Licence","message":"wrong case"}`,
		`{"message":"account suspended"}`,
	} {
		t.Run(body, func(t *testing.T) {
			srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				w.WriteHeader(402)
				w.Write([]byte(body))
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
			if abortsImport(err) {
				t.Error("a 402 that is not the licence refusal must not abort an import")
			}
		})
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

// A run that aggregates several failures is the licence refusal only when a
// licence is what every one of them needs: one profile refused and another
// unreachable is not fixed by a trial.
func TestLicenceRefusalRequiresEveryFailureToBeOne(t *testing.T) {
	refused := &api.APIError{Status: 402, Category: "licence", Message: "requires a Pro licence"}
	other := &api.APIError{Status: 402, Category: "licence", Message: "the second profile's refusal"}
	unreachable := &api.RequestError{Kind: "network", Op: "request", Err: errors.New("connection refused")}
	if got := licenceRefusal(refused, nil, other); !errors.Is(got, refused) {
		t.Errorf("every failure refused: got %v, want the first refusal", got)
	}
	if got := licenceRefusal(refused, unreachable); got != nil {
		t.Errorf("a refusal beside a network failure: got %v, want nil", got)
	}
	if got := licenceRefusal(unreachable, refused); got != nil {
		t.Errorf("a network failure beside a refusal: got %v, want nil", got)
	}
	if got := licenceRefusal(); got != nil {
		t.Errorf("no failures: got %v, want nil", got)
	}
	if got := licenceRefusal(nil, nil); got != nil {
		t.Errorf("only nils: got %v, want nil", got)
	}
}

// Every profile failed, but only one for the licence: the run is not the
// licence refusal (no exit 6, no trial hint), whichever profile answers first.
func TestMultiProfileReportIsNotALicenceRefusalWhenAnotherProfileFailedOtherwise(t *testing.T) {
	hits := 0
	refusing := licenceServer(t, &hits)
	defer refusing.Close()
	dead := httptest.NewServer(http.NotFoundHandler())
	deadURL := dead.URL
	dead.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": refusing.URL, "api_key": "prod-key-123456"},
		"staging": {"url": deadURL, "api_key": "staging-key-123456"},
	})
	_, _, err := executeCommand("report", "summary", "--all-profiles", "--json")
	if err == nil {
		t.Fatal("want an error when every profile failed")
	}
	if hits == 0 {
		t.Fatal("the refusing profile was never asked; the test would prove nothing")
	}
	if got := api.ErrorCategory(err); got == "licence" {
		t.Errorf("category = licence for a run one licence cannot fix (%v)", err)
	}
	if got := exitCodeForError(err); got == ExitLicence {
		t.Errorf("exit code = %d (ExitLicence) for a run one licence cannot fix", got)
	}
	if h := hintFor(err); strings.Contains(h, "Pro trial or subscription") {
		t.Errorf("hint %q sends the user to the trial for a failure it cannot fix", h)
	}
}

// The same rule for a shell batch: a refused command beside a command or a
// built-in that failed for its own reason is not reported as the licence
// refusal.
func TestShellBatchIsNotALicenceRefusalWhenAnotherCommandFailedOtherwise(t *testing.T) {
	for _, batch := range []string{"user list; shell", "user list; unset nosuch"} {
		t.Run(batch, func(t *testing.T) {
			hits := 0
			srv := licenceServer(t, &hits)
			defer srv.Close()
			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
			_, _, err := executeCommand("shell", "-c", batch)
			if err == nil {
				t.Fatal("want an error from the batch")
			}
			if hits == 0 {
				t.Fatal("no command met the refusal; the test would prove nothing")
			}
			if got := api.ErrorCategory(err); got == "licence" || exitCodeForError(err) == ExitLicence {
				t.Errorf("category %q / exit %d for a batch one licence cannot fix (%v)", got, exitCodeForError(err), err)
			}
		})
	}
}

// p202 import <entity> sends one POST per record. Once the install refuses
// the CLI every record meets the same 402, so the import stops at the first
// one and reports the refusal (exit 6, the trial hint), --skip-errors or not.
func TestEntityImportStopsOnTheLicenceRefusal(t *testing.T) {
	file := writeImportFile(t, "campaigns.json", `[{"aff_campaign_name":"a"},{"aff_campaign_name":"b"},{"aff_campaign_name":"c"}]`)
	for _, extra := range [][]string{nil, {"--skip-errors"}} {
		t.Run(strings.Join(append([]string{"import"}, extra...), " "), func(t *testing.T) {
			hits := 0
			srv := licenceServer(t, &hits)
			defer srv.Close()
			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
			stdout, _, err := executeCommand(append([]string{"import", "campaigns", file}, extra...)...)
			assertLicenceRefusal(t, err, "import campaigns")
			if hits != 1 {
				t.Errorf("want one refused request, then a stop; got %d", hits)
			}
			if strings.TrimSpace(stdout) != "" {
				t.Errorf("stdout must stay empty on a refusal, got %q", stdout)
			}
		})
	}
}

// The entity import keeps each record's own error: a refused key stops it
// with the auth contract (exit 2, the key hint), and a record the server
// rejects on its own is skipped under --skip-errors while the rest go on.
func TestEntityImportKeepsTheRecordsErrorContract(t *testing.T) {
	file := writeImportFile(t, "campaigns.json", `[{"aff_campaign_name":"a"},{"aff_campaign_name":"b"},{"aff_campaign_name":"c"}]`)
	t.Run("a refused key", func(t *testing.T) {
		posts := 0
		srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
			posts++
			w.WriteHeader(401)
			w.Write([]byte(`{"error":true,"status":401,"message":"Invalid API key"}`))
		}))
		defer srv.Close()
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
		for _, extra := range [][]string{nil, {"--skip-errors"}} {
			posts = 0
			_, _, err := executeCommand(append([]string{"import", "campaigns", file}, extra...)...)
			if err == nil {
				t.Errorf("%v: every record refused and the import succeeded (%d posts)", extra, posts)
				continue
			}
			if got := exitCodeForError(err); got != ExitAuth || !strings.Contains(hintFor(err), "config set-key") {
				t.Errorf("%v: exit %d, hint %q (%v); want %d and the key hint", extra, got, hintFor(err), err, ExitAuth)
			}
			if posts != 1 {
				t.Errorf("%v: want a stop at the first refused record, got %d posts", extra, posts)
			}
		}
	})
	t.Run("a record's own rejection under --skip-errors", func(t *testing.T) {
		posts := 0
		srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
			posts++
			raw, _ := io.ReadAll(r.Body)
			if strings.Contains(string(raw), `"b"`) {
				w.WriteHeader(422)
				w.Write([]byte(`{"error":true,"status":422,"message":"Validation failed","field_errors":{"aff_campaign_name":"taken"}}`))
				return
			}
			w.WriteHeader(201)
			w.Write([]byte(`{"data":{"aff_campaign_id":1}}`))
		}))
		defer srv.Close()
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
		if _, _, err := executeCommand("import", "campaigns", file, "--skip-errors"); exitCodeForError(err) == ExitLicence || exitCodeForError(err) == ExitAuth {
			t.Errorf("a record's own rejection reported as %v (exit %d)", err, exitCodeForError(err))
		}
		if posts != 3 {
			t.Errorf("want every record sent past the rejected one, got %d posts", posts)
		}
	})
}

// The conversion import reads every click first, so an unlicensed install
// refuses it before any write. A refusal that starts between the read and the
// writes (the install's allow expired) stops the writes with the licence
// contract too, not the generic stop that points at `p202 config test`,
// which answers "Connection successful!" on such an install (/system is not
// gated).
func TestConversionImportStopsOnALicenceRefusalWhileWriting(t *testing.T) {
	f := newConvImportFake(100, 101, 102)
	f.licencePost[101] = true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,10,T2\n102,10,T3\n")
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	assertLicenceRefusal(t, err, "conversion import, refused while writing")
	if h := hintFor(err); strings.Contains(h, "config test") || !strings.Contains(h, "re-run") {
		t.Errorf("hint %q should keep the re-run advice and not point at `p202 config test`", h)
	}
	rows := importByRow(decodeImport(t, stdout))
	if rows[2].Status != importCreated || rows[3].Status != importFailed || rows[4].Status != importFailed ||
		!strings.Contains(rows[4].Reason, "not sent: the import stopped at row 3 (licence error)") {
		t.Errorf("rows = %+v", rows)
	}
	for _, p := range f.posts {
		if p.Body["click_id"] == float64(102) {
			t.Error("a row after the refusal was sent")
		}
	}
}

// The multi-id delete preview and staged delete are the bulk delete's
// siblings: an unlicensed install refuses the first id, and the rest would
// meet the same 402.
func TestBulkDeletePreviewAndStagingStopOnTheLicenceRefusal(t *testing.T) {
	for _, mode := range []string{"--dry-run", "--staged"} {
		t.Run(mode, func(t *testing.T) {
			hits := 0
			srv := licenceServer(t, &hits)
			defer srv.Close()
			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
			stdout, _, err := executeCommand("campaign", "delete", "--ids", "1,2,3", "--force", mode)
			assertLicenceRefusal(t, err, "campaign delete --ids "+mode)
			if hits != 1 {
				t.Errorf("want one refused request, then a stop; got %d", hits)
			}
			if strings.TrimSpace(stdout) != "" {
				t.Errorf("stdout must stay empty on a refusal, got %q", stdout)
			}
		})
	}
}

// tracker check <id> reads the tracker's link from the install first; a
// refusal there is the install's licence, not a broken tracker.
func TestTrackerCheckIsTheLicenceRefusal(t *testing.T) {
	hits := 0
	srv := licenceServer(t, &hits)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")
	stdout, _, err := executeCommand("tracker", "check", "5")
	assertLicenceRefusal(t, err, "tracker check 5")
	if hits != 1 {
		t.Errorf("want one refused request; got %d", hits)
	}
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("stdout must stay empty on a refusal, got %q", stdout)
	}
}
