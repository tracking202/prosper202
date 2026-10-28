package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

// syncOrchestrator is a server that offers server-side sync jobs and
// answers POST sync/jobs (and re-sync) with jobAnswer; any other request
// to it, like every request to the target, is a list read the client-side
// fallback would make.
func syncOrchestrator(t *testing.T, jobStatus int, jobAnswer, polled string, reads *int32) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"sync_features":{"async_jobs":true}}}`))
		case r.Method == http.MethodPost && (r.URL.Path == "/api/v3/sync/jobs" || r.URL.Path == "/api/v3/sync/re-sync"):
			w.WriteHeader(jobStatus)
			_, _ = w.Write([]byte(jobAnswer))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/worker/run":
			_, _ = w.Write([]byte(`{"data":{"processed":1}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/sync/jobs/j1":
			_, _ = w.Write([]byte(polled))
		default:
			atomic.AddInt32(reads, 1)
			_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
		}
	}))
	t.Cleanup(server.Close)
	return server
}

func syncProfiles(t *testing.T, source, target string) {
	t.Helper()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source, "api_key": "source-key-123456"},
		"target": {"url": target, "api_key": "target-key-123456"},
	})
}

// A profile url the server will not call is refused on its field. The CLI
// says which profile to point where and which operator setting admits an
// install on the server's own network, and does not run the sync from this
// machine instead, where the refusal would never be read.
func TestSyncARefusedProfileURLNamesTheProfileAndTheAllowlist(t *testing.T) {
	for _, tc := range []struct{ command, field, profile string }{
		{"sync", "target.url", "target"},
		{"sync", "source.url", "source"},
		{"re-sync", "target.url", "target"},
	} {
		t.Run(tc.command+" "+tc.field, func(t *testing.T) {
			var reads int32
			refusal := `{"error":true,"message":"This server does not call the sync url","status":422,"field_errors":{"` +
				tc.field + `":"127.0.0.1, which is a loopback address. Syncs are only sent to public addresses."}}`
			orchestrator := syncOrchestrator(t, 422, refusal, "", &reads)
			target := syncOrchestrator(t, 404, `{}`, "", &reads)
			syncProfiles(t, orchestrator.URL, target.URL)

			args := []string{tc.command, "--from", "source", "--to", "target", "--json"}
			if tc.command == "sync" {
				args = append([]string{"sync", "campaigns"}, args[1:]...)
			}
			_, _, err := executeCommand(args...)
			if err == nil {
				t.Fatal("the refusal was not an error")
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit %d, want %d", code, ExitValidation)
			}
			hint := hintFor(err)
			for _, want := range []string{"`p202 --profile " + tc.profile + " config set-url https://…`", "P202_WEBHOOK_ALLOW_NETWORKS", "never admitted"} {
				if !strings.Contains(hint, want) {
					t.Errorf("hint %q lacks %q", hint, want)
				}
			}
			if n := atomic.LoadInt32(&reads); n != 0 {
				t.Errorf("the sync fell back to this machine: %d list reads", n)
			}
		})
	}
}

// Any other refusal of the job request still falls back to the client-side
// sync, as before.
func TestSyncAnotherRefusalOfTheJobStillFallsBack(t *testing.T) {
	var reads int32
	orchestrator := syncOrchestrator(t, 422, `{"error":true,"message":"Validation failed","status":422,"field_errors":{"entity":"Unsupported"}}`, "", &reads)
	target := syncOrchestrator(t, 404, `{}`, "", &reads)
	syncProfiles(t, orchestrator.URL, target.URL)

	if _, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json"); err != nil {
		t.Fatalf("the client-side sync: %v", err)
	}
	if atomic.LoadInt32(&reads) == 0 {
		t.Error("the sync did not fall back")
	}
}

// A job that stopped for a reason it names by error_code gets that reason's
// next step; one without a code keeps the general one.
func TestSyncAJobStoppedByTheGuardOrACapSaysWhatToDo(t *testing.T) {
	for code, want := range map[string]string{
		"sync_url_refused":    "P202_WEBHOOK_ALLOW_NETWORKS",
		"sync_url_unresolved": "does not resolve",
		"sync_limit":          "system health",
		"sync_key_unreadable": "queues a new job",
		"":                    "found on the target by their natural key",
	} {
		t.Run(code, func(t *testing.T) {
			var reads int32
			polled := `{"data":{"job_id":"j1","status":"failed","error":"stopped","error_code":"` + code + `"}}`
			if code == "" {
				polled = `{"data":{"job_id":"j1","status":"failed","error":"stopped","error_code":null}}`
			}
			orchestrator := syncOrchestrator(t, 202, `{"data":{"job_id":"j1","status":"queued"}}`, polled, &reads)
			target := syncOrchestrator(t, 404, `{}`, "", &reads)
			syncProfiles(t, orchestrator.URL, target.URL)

			_, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
			if err == nil || exitCodeForError(err) != ExitPartialFailure {
				t.Fatalf("want a partial failure, got %v (exit %d)", err, exitCodeForError(err))
			}
			if hint := hintFor(err); !strings.Contains(hint, want) {
				t.Errorf("hint %q lacks %q", hint, want)
			}
		})
	}
}
