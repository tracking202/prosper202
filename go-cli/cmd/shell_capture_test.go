package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"p202/internal/shell"
)

func emptyListServer(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			// `p202 shell` is gated on this capability.
			_, _ = w.Write([]byte(`{"data":{"shell":true}}`))
			return
		}
		_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

// A list that legitimately matches nothing is a successful command with an
// empty result, not a failure. In the shell's default table mode it writes
// nothing to stdout ("No results." goes to stderr), which made it
// indistinguishable from a void operation and failed the assignment — under
// --stop-on-error that aborted the whole batch.
func TestAssignmentCapturesAnEmptyResultSet(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := emptyListServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	state := shell.NewState()
	handled, _, _, err := handleBuiltin("$rows = campaign list", state, "default")
	if !handled {
		t.Fatal("assignment should be handled as a builtin")
	}
	if err != nil {
		t.Fatalf("an empty result set is not an error: %v", err)
	}

	raw, ok := state.Get("rows")
	if !ok {
		t.Fatal("$rows was not set")
	}
	var parsed struct {
		Data []interface{} `json:"data"`
	}
	if err := json.Unmarshal(raw, &parsed); err != nil {
		t.Fatalf("stored value is not the JSON envelope: %v (%q)", err, raw)
	}
	if len(parsed.Data) != 0 {
		t.Fatalf("expected an empty data array, got %v", parsed.Data)
	}
}

// The batch must not abort on it either — that was the reported failure.
func TestBatchWithStopOnErrorSurvivesAnEmptyResultSet(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := emptyListServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	if _, _, err := executeCommand("shell", "--stop-on-error", "-c", "$x = campaign list; campaign list"); err != nil {
		t.Fatalf("batch aborted on a legitimately empty result: %v", err)
	}
}

// The original defect stays fixed: a void operation writes nothing to stdout
// even as JSON, so there is genuinely nothing to capture and the user must be
// told rather than left believing $name holds the result.
func TestAssignmentFromAVoidOperationStillReports(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := newRecordingServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	state := shell.NewState()
	_, _, _, err := handleBuiltin("$gone = campaign delete 7 --force", state, "default")
	if err == nil {
		t.Fatal("expected an error explaining that $gone was not set")
	}
	if !strings.Contains(err.Error(), "$gone") {
		t.Fatalf("error should name the variable, got %q", err)
	}
	if _, ok := state.Get("gone"); ok {
		t.Fatal("$gone must not be set when there was nothing to capture")
	}
}

// Forcing JSON is scoped to the capture: it must not leak into the session's
// display mode for subsequent commands.
func TestAssignmentDoesNotChangeTheSessionOutputMode(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := emptyListServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	before := jsonOutput
	state := shell.NewState()
	if _, _, _, err := handleBuiltin("$rows = campaign list", state, "default"); err != nil {
		t.Fatalf("assignment: %v", err)
	}
	if jsonOutput != before {
		t.Fatalf("session jsonOutput changed from %v to %v", before, jsonOutput)
	}
}

// A capture stores JSON whatever format the session is in: one asked for by
// flag is restored before each command, one from P202_OUTPUT, config or an
// agent is derived again, and the capture must win over both -- forced on
// top of a --csv session it would otherwise be a "--json and --csv" conflict,
// and left to the derivation it would store CSV.
func TestAssignmentCapturesJSONWhateverTheSessionFormat(t *testing.T) {
	for _, tc := range []struct {
		name  string
		setup func(t *testing.T)
	}{
		{"session --csv", func(t *testing.T) {
			saved := csvOutput
			csvOutput = true
			t.Cleanup(func() { csvOutput = saved })
		}},
		{"P202_OUTPUT=csv", func(t *testing.T) {
			t.Setenv(outputEnvVar, "csv")
			saved := outputImplicit
			outputImplicit = true
			t.Cleanup(func() { outputImplicit = saved })
		}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := emptyListServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")
			tc.setup(t)

			state := shell.NewState()
			if _, _, _, err := handleBuiltin("$rows = campaign list", state, "default"); err != nil {
				t.Fatalf("assignment: %v", err)
			}
			raw, ok := state.Get("rows")
			if !ok {
				t.Fatal("$rows was not set")
			}
			var parsed struct {
				Data []interface{} `json:"data"`
			}
			if err := json.Unmarshal(raw, &parsed); err != nil || parsed.Data == nil {
				t.Fatalf("stored value is not the JSON envelope: %v (%q)", err, raw)
			}
		})
	}
}

// activeCommandPath is a package global that PersistentPreRunE re-stamps on
// every in-process execution. Without restoring it across the shell's
// re-entry, a failing `p202 shell` reports the last command the batch ran, and
// the hint sends an agent to that command's --help instead of its own.
func TestShellErrorEnvelopeNamesTheShellNotTheLastInnerCommand(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := emptyListServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	stdout, _, err := executeCommand("shell", "--json", "-c", "campaign list; campaign delete abc --force")
	if err == nil {
		t.Fatal("expected the batch to fail on the invalid id")
	}

	// The envelope is printed by Execute(), which is not reached from a test;
	// assert on the state Execute() would read.
	if activeCommandPath != "" && !strings.Contains(activeCommandPath, "shell") {
		t.Fatalf("activeCommandPath = %q, want it to name the shell (stdout: %q)", activeCommandPath, stdout)
	}
	if hint := hintFor(err); strings.Contains(hint, "campaign delete") {
		t.Fatalf("hint points at the inner command: %q", hint)
	}
}
