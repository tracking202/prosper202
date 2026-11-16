package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"p202/internal/shell"
)

// newShellCapableServer creates a mock server that supports the capabilities
// endpoint with shell access enabled. It serves health and campaign endpoints.
func newShellCapableServer(t *testing.T) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"shell":true}}`))
		case r.URL.Path == "/api/v3/system/health":
			_, _ = w.Write([]byte(`{"data":{"status":"ok","version":"4.0"}}`))
		case r.URL.Path == "/api/v3/campaigns" && r.Method == http.MethodGet:
			_, _ = w.Write([]byte(`{"data":[{"id":1,"aff_campaign_name":"Test Campaign"}],"pagination":{"total":1,"limit":50,"offset":0}}`))
		case r.URL.Path == "/api/v3/system/version":
			_, _ = w.Write([]byte(`{"data":{"version":"4.0","php":"8.3"}}`))
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
}

// newShellDeniedServer creates a mock server whose capabilities say shell and
// cli false. It still serves health: the shell does not refuse on those flags
// itself; the install's own 402 on each command does that.
func newShellDeniedServer(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"shell":false,"cli":false}}`))
		case r.URL.Path == "/api/v3/system/health":
			_, _ = w.Write([]byte(`{"data":{"status":"ok","version":"4.0"}}`))
		default:
			w.WriteHeader(404)
		}
	}))
	return srv
}

// The shell does not refuse on capabilities that say shell or cli false; the
// commands it runs meet the install's licence gate like any other.
func TestShellRunsWhateverTheCapabilitiesSay(t *testing.T) {
	srv := newShellDeniedServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "system health")
	if err != nil {
		t.Fatalf("the shell refused: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output, got: %s", stdout)
	}
}

func TestShellBatchSingleCommand(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "system health")
	if err != nil {
		t.Fatalf("shell batch failed: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected 'ok' in output, got: %s", stdout)
	}
}

func TestShellBatchMultipleCommands(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "system health; system version")
	if err != nil {
		t.Fatalf("shell batch failed: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output, got: %s", stdout)
	}
	if !strings.Contains(stdout, "4.0") {
		t.Errorf("expected version output, got: %s", stdout)
	}
}

func TestShellBatchJSONLOutput(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "--json", "-c", "system health; system version")
	if err != nil {
		t.Fatalf("shell batch JSON failed: %v", err)
	}

	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) < 2 {
		t.Fatalf("expected at least 2 JSONL lines, got %d: %s", len(lines), stdout)
	}

	for i, line := range lines {
		var result map[string]interface{}
		if err := json.Unmarshal([]byte(line), &result); err != nil {
			t.Errorf("line %d is not valid JSON: %s", i, line)
			continue
		}
		if _, ok := result["command"]; !ok {
			t.Errorf("line %d missing 'command' field: %s", i, line)
		}
		if _, ok := result["success"]; !ok {
			t.Errorf("line %d missing 'success' field: %s", i, line)
		}
	}
}

func TestShellScriptFile(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	scriptPath := filepath.Join(tmp, "commands.txt")
	content := "# This is a comment\nsystem health\nsystem version\n"
	if err := os.WriteFile(scriptPath, []byte(content), 0600); err != nil {
		t.Fatalf("writing script file: %v", err)
	}

	stdout, _, err := executeCommand("shell", "--script", scriptPath)
	if err != nil {
		t.Fatalf("shell script failed: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output, got: %s", stdout)
	}
}

func TestShellStopOnError(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	// The second command should fail (nonexistent endpoint), and --stop-on-error should stop execution
	_, _, err := executeCommand("shell", "--stop-on-error", "-c", "system health; campaign get 999; system version")
	if err == nil {
		t.Fatal("expected error with --stop-on-error and a failing command")
	}
	if !strings.Contains(err.Error(), "stopped on error") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestShellBatchContinuesOnError(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	// Without --stop-on-error, batch should continue past failures
	_, _, err := executeCommand("shell", "-c", "campaign get 999; system health")
	if err == nil {
		t.Fatal("expected overall error when some commands fail")
	}
	if !strings.Contains(err.Error(), "one or more commands failed") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestShellPreventsRecursion(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	_, _, err := executeCommand("shell", "-c", "shell")
	if err == nil {
		t.Fatal("expected error for recursive shell")
	}
	if !strings.Contains(err.Error(), "cannot run shell from within shell") && !strings.Contains(err.Error(), "one or more commands failed") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestShellMalformedAssignmentHandled(t *testing.T) {
	state := shell.NewState()

	for _, line := range []string{"$=", "$ = health", "$a b = health", "$x ="} {
		handled, _, _, err := handleBuiltin(line, state, "default")
		if !handled {
			t.Errorf("malformed assignment %q should be handled as a syntax error, not dispatched as a command", line)
		}
		if err == nil {
			t.Errorf("malformed assignment %q should return a syntax error", line)
		}
	}
	if state.Count() != 0 {
		t.Errorf("malformed assignments must not create variables, got %v", state.Names())
	}
}

func TestShellLargeOutputDoesNotDeadlock(t *testing.T) {
	// Output far beyond the kernel pipe buffer (~64KB) must not block the
	// stdout capture in executeShellCommand.
	big := `{"data":[{"id":1,"aff_campaign_name":"` + strings.Repeat("x", 256*1024) + `"}],"pagination":{"total":1,"limit":50,"offset":0}}`
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"shell":true}}`))
		case r.URL.Path == "/api/v3/campaigns":
			_, _ = w.Write([]byte(big))
		default:
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "campaign list --json")
	if err != nil {
		t.Fatalf("large-output batch failed: %v", err)
	}
	if len(stdout) < 256*1024 {
		t.Errorf("expected full large output, got %d bytes", len(stdout))
	}
}

func TestShellFlagsDoNotLeakBetweenCommands(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	// --json on the first command must not stick to the second.
	stdout, _, err := executeCommand("shell", "-c", "campaign list --json; campaign list")
	if err != nil {
		t.Fatalf("shell batch failed: %v", err)
	}
	// The quoted key appears only in JSON output; the table renders the bare
	// column name. Exactly one JSON rendering means no leak.
	switch n := strings.Count(stdout, `"aff_campaign_name"`); n {
	case 0:
		t.Fatalf("expected raw JSON from first command, got: %s", stdout)
	case 1: // expected
	default:
		t.Errorf("second command also produced raw JSON; --json leaked between shell commands: %s", stdout)
	}
	if !strings.Contains(stdout, "Test Campaign") {
		t.Errorf("expected table output from second command, got: %s", stdout)
	}
}

func TestShellBuiltinsWorkInBatchMode(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "$h = system health; vars")
	if err != nil {
		t.Fatalf("batch with builtins failed: %v", err)
	}
	if !strings.Contains(stdout, "$h") {
		t.Errorf("expected vars to list $h, got: %s", stdout)
	}
}

func TestShellExitStopsBatch(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "-c", "exit; system health")
	if err != nil {
		t.Fatalf("batch with exit failed: %v", err)
	}
	if strings.Contains(stdout, "ok") {
		t.Errorf("commands after exit should not run, got: %s", stdout)
	}
}

func TestShellLongCommandFlag(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	stdout, _, err := executeCommand("shell", "--command", "system health")
	if err != nil {
		t.Fatalf("--command flag failed: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output, got: %s", stdout)
	}
}

func TestShellAssignmentFailureStopsBatch(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	// A failing command inside $name = ... must fail the batch like a bare command.
	stdout, _, err := executeCommand("shell", "--stop-on-error", "-c", "$x = campaign get 999; system health")
	if err == nil {
		t.Fatal("expected error when assignment command fails with --stop-on-error")
	}
	if !strings.Contains(err.Error(), "stopped on error") {
		t.Errorf("unexpected error: %v", err)
	}
	if strings.Contains(stdout, "ok") {
		t.Errorf("commands after failed assignment should not run with --stop-on-error, got: %s", stdout)
	}

	// Without --stop-on-error the batch continues but still reports failure.
	_, _, err = executeCommand("shell", "-c", "$x = campaign get 999; system health")
	if err == nil || !strings.Contains(err.Error(), "one or more commands failed") {
		t.Errorf("expected batch failure from failed assignment, got: %v", err)
	}
}

// Switching to a profile on an install whose capabilities say shell false
// works; its commands meet that install's licence gate.
func TestShellUseSwitchesToAnyConfiguredProfile(t *testing.T) {
	first := newShellCapableServer(t)
	defer first.Close()
	second := newShellDeniedServer(t)
	defer second.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "default", map[string]map[string]interface{}{
		"default": {"url": first.URL, "api_key": "test-key-12345678"},
		"other":   {"url": second.URL, "api_key": "other-key-12345678"},
	})

	stdout, stderr, err := executeCommand("shell", "--stop-on-error", "-c", "use other; system health")
	if err != nil {
		t.Fatalf("use of a configured profile failed: %v (stdout: %s stderr: %s)", err, stdout, stderr)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output from the other profile, got: %s", stdout)
	}
}

// Switching to a profile that does not exist still fails.
func TestShellUseRefusesAnUnknownProfile(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	_, _, err := executeCommand("shell", "--stop-on-error", "-c", "use nosuch; system health")
	if err == nil || !strings.Contains(err.Error(), "stopped on error") {
		t.Errorf("expected use of an unknown profile to stop the batch, got: %v", err)
	}
}

func TestShellCommentsSkipped(t *testing.T) {
	srv := newShellCapableServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	// Comments should be ignored
	stdout, _, err := executeCommand("shell", "-c", "# this is a comment; system health")
	if err != nil {
		t.Fatalf("shell batch with comment failed: %v", err)
	}
	if !strings.Contains(stdout, "ok") {
		t.Errorf("expected health output after comment, got: %s", stdout)
	}
}

// A batch whose every failure is a 429 keeps the category and exit 7, and
// says to run only the commands that failed: the others already ran.
func TestShellBatchOfRateLimitsKeepsExit7(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"shell":true}}`))
		case r.URL.Path == "/api/v3/system/health":
			_, _ = w.Write([]byte(`{"data":{"status":"ok","version":"4.0"}}`))
		default:
			w.Header().Set("Retry-After", "20")
			w.WriteHeader(429)
			_, _ = w.Write([]byte(`{"error":true,"status":429,"message":"Rate limit exceeded","retry_after_seconds":20}`))
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	_, _, err := executeCommand("shell", "-c", "campaign get 9; system health; campaign get 10")
	if code := exitCodeForError(err); code != ExitRateLimited {
		t.Fatalf("exit %d (%v), want %d", code, err, ExitRateLimited)
	}
	if hint := hintFor(err); !strings.Contains(hint, "Wait 20 seconds") || !strings.Contains(hint, "only the commands that failed") || strings.Contains(hint, "run the same command again") {
		t.Errorf("hint = %q", hint)
	}
}
