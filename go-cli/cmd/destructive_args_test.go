package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// recordingServer captures every request the CLI actually sends, so a test can
// assert that a rejected command sent nothing at all.
type recordingServer struct {
	*httptest.Server
	mu       sync.Mutex
	requests []string
}

func newRecordingServer(t *testing.T) *recordingServer {
	t.Helper()
	rs := &recordingServer{}
	rs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		rs.mu.Lock()
		rs.requests = append(rs.requests, r.Method+" "+r.URL.Path)
		rs.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{}}`))
	}))
	t.Cleanup(rs.Close)
	return rs
}

func (rs *recordingServer) seen() []string {
	rs.mu.Lock()
	defer rs.mu.Unlock()
	return append([]string(nil), rs.requests...)
}

// A blank positional id used to be interpolated straight into the request path,
// producing a request against the collection endpoint (DELETE campaigns/)
// instead of against a record. Every id-taking mutation must reject it before
// any request leaves the process.
func TestBlankOrNonNumericIDsAreRejectedBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		name string
		args []string
	}{
		{"campaign delete blank", []string{"campaign", "delete", "", "--force"}},
		{"campaign delete whitespace", []string{"campaign", "delete", "   ", "--force"}},
		{"campaign delete non-numeric", []string{"campaign", "delete", "../users", "--force"}},
		{"campaign update blank", []string{"campaign", "update", "", "--aff_campaign_name", "x"}},
		{"rotator delete blank", []string{"rotator", "delete", "", "--force"}},
		{"conversion delete blank", []string{"conversion", "delete", "", "--force"}},
		{"user delete blank", []string{"user", "delete", "", "--force"}},
		{"rotator rule-delete blank rotator", []string{"rotator", "rule-delete", "", "5", "--force"}},
		{"rotator rule-delete blank rule", []string{"rotator", "rule-delete", "5", "", "--force"}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := newRecordingServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")

			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error, got nil (requests: %v)", srv.seen())
			}
			// Assert the rejection is specifically about the id. Without this a
			// mistyped command name would satisfy the test for the wrong reason.
			if msg := err.Error(); !strings.Contains(msg, "ID") {
				t.Fatalf("error should name the invalid ID, got %q", msg)
			}
			for _, req := range srv.seen() {
				if strings.HasPrefix(req, "DELETE") || strings.HasPrefix(req, "PUT") {
					t.Fatalf("a mutating request was sent despite the invalid id: %s", req)
				}
			}
		})
	}
}

// Cancelling a delete must not print to stdout: these commands are scripted, and
// "Cancelled." landing in a piped stdout corrupts the caller's data stream. With
// no terminal attached the confirmation read fails, which is the cancel path.
func TestCancelledDeletesKeepStdoutClean(t *testing.T) {
	cases := []struct {
		name string
		args []string
	}{
		{"campaign delete", []string{"campaign", "delete", "7"}},
		{"rotator delete", []string{"rotator", "delete", "7"}},
		{"conversion delete", []string{"conversion", "delete", "7"}},
		{"user delete", []string{"user", "delete", "7"}},
		{"rotator rule-delete", []string{"rotator", "rule-delete", "7", "9"}},
		{"campaign bulk delete", []string{"campaign", "delete", "--ids", "7,8"}},
		{"rotator bulk delete", []string{"rotator", "delete", "--ids", "7,8"}},
		{"conversion bulk delete", []string{"conversion", "delete", "--ids", "7,8"}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := newRecordingServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")

			stdout, stderr, err := executeCommand(tc.args...)
			if err != nil {
				t.Fatalf("cancelling should not be an error: %v", err)
			}
			if strings.Contains(stdout, "Cancelled") {
				t.Fatalf("cancellation notice went to stdout: %q", stdout)
			}
			if !strings.Contains(stderr, "Cancelled") {
				t.Fatalf("cancellation notice missing from stderr: %q", stderr)
			}
			for _, req := range srv.seen() {
				if strings.HasPrefix(req, "DELETE") {
					t.Fatalf("a cancelled delete still sent %s", req)
				}
			}
		})
	}
}

// The confirmation question itself must also stay off stdout.
func TestConfirmationPromptDoesNotWriteToStdout(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	srv := newRecordingServer(t)
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	stdout, stderr, err := executeCommand("rotator", "rule-delete", "7", "9")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if strings.Contains(stdout, "[y/N]") {
		t.Fatalf("prompt was written to stdout: %q", stdout)
	}
	if !strings.Contains(stderr, "[y/N]") {
		t.Fatalf("prompt missing from stderr: %q", stderr)
	}
}

// The delete commands now share one runner and differ only in a wording spec.
// Pin the user-visible strings so a spec edit can't silently change the UX the
// old hand-rolled copies had.
func TestDeleteWordingIsPreserved(t *testing.T) {
	cases := []struct {
		name       string
		args       []string
		wantStderr string
	}{
		{"rotator single confirm keeps cascade warning",
			[]string{"rotator", "delete", "7"},
			"Delete rotator 7 and all its rules?"},
		{"rotator bulk confirm keeps cascade warning",
			[]string{"rotator", "delete", "--ids", "7,8"},
			"Delete 2 rotators and all their rules?"},
		{"rule single confirm names the parent rotator",
			[]string{"rotator", "rule-delete", "7", "9"},
			"Delete rule 9 from rotator 7?"},
		{"rule bulk confirm names the parent rotator",
			[]string{"rotator", "rule-delete", "7", "--ids", "9,11"},
			"Delete 2 rules from rotator 7?"},
		{"conversion single success",
			[]string{"conversion", "delete", "7", "--force"},
			"Conversion 7 deleted."},
		{"rule single success names the parent rotator",
			[]string{"rotator", "rule-delete", "7", "9", "--force"},
			"Rule 9 deleted from rotator 7."},
		{"rule bulk summary names the parent rotator",
			[]string{"rotator", "rule-delete", "7", "--ids", "9,11", "--force"},
			"Deleted 2 of 2 rules from rotator 7."},
		{"campaign bulk summary uses the entity plural",
			[]string{"campaign", "delete", "--ids", "7,8", "--force"},
			"Deleted 2 of 2 campaigns"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			home := t.TempDir()
			setTestHome(t, home)
			srv := newRecordingServer(t)
			writeTestConfig(t, home, srv.URL, "test-api-key-1234")

			_, stderr, err := executeCommand(tc.args...)
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if !strings.Contains(stderr, tc.wantStderr) {
				t.Fatalf("stderr = %q, want it to contain %q", stderr, tc.wantStderr)
			}
		})
	}
}
