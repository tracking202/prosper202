package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
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

// newShellDeniedServer creates a mock server where shell capability is not enabled.
func newShellDeniedServer(t *testing.T) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3"}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{}}`))
		default:
			w.WriteHeader(404)
		}
	}))
}

func TestShellDeniedWithoutCapability(t *testing.T) {
	srv := newShellDeniedServer(t)
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-12345678")

	_, _, err := executeCommand("shell", "-c", "health")
	if err == nil {
		t.Fatal("expected error when shell capability is not enabled")
	}
	if !strings.Contains(err.Error(), "shell access is not enabled") {
		t.Errorf("unexpected error: %v", err)
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
