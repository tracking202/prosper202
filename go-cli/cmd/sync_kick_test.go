package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// kickRecorder is an install that queues job j1, answers every request to run
// its sync worker with workerStatus, and reports the job as jobStatus.
func kickRecorder(t *testing.T, workerStatus int, jobStatus string) (*httptest.Server, func() []string) {
	t.Helper()
	var mu sync.Mutex
	var hits []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		hits = append(hits, r.Method+" "+r.URL.Path)
		mu.Unlock()
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"sync_features":{"async_jobs":true}}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/jobs":
			w.WriteHeader(http.StatusAccepted)
			_, _ = w.Write([]byte(`{"data":{"job_id":"j1","status":"queued"}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/worker/run":
			w.WriteHeader(workerStatus)
			if workerStatus >= 400 {
				_, _ = w.Write([]byte(`{"error":true,"message":"Internal server error","status":500}`))
				return
			}
			_, _ = w.Write([]byte(`{"data":{"processed":0}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/sync/jobs/j1":
			_, _ = w.Write([]byte(`{"data":{"job_id":"j1","status":"` + jobStatus + `"}}`))
		default:
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	t.Cleanup(server.Close)
	return server, func() []string {
		mu.Lock()
		defer mu.Unlock()
		return append([]string(nil), hits...)
	}
}

func fastSyncPolls(t *testing.T) {
	t.Helper()
	old := syncPollInterval
	syncPollInterval = time.Millisecond
	t.Cleanup(func() { syncPollInterval = old })
}

func countHits(hits []string, want string) int {
	n := 0
	for _, h := range hits {
		if h == want {
			n++
		}
	}
	return n
}

// Every request to run the worker failed and the job is still queued: nothing
// in this run started it, so the command fails naming the job and what would
// run it, instead of printing the queued job as though the sync had run.
func TestSyncFailsWhenEveryWorkerKickFailsAndTheJobStaysQueued(t *testing.T) {
	fastSyncPolls(t)
	orchestrator, hits := kickRecorder(t, http.StatusInternalServerError, "queued")
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("the target was called (%s %s): a queued server-side job must not fall back to a client-side sync", r.Method, r.URL.Path)
	}))
	defer target.Close()
	syncProfiles(t, orchestrator.URL, target.URL)

	stdout, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err == nil {
		t.Fatalf("expected an error when every worker kick failed; stdout:\n%s", stdout)
	}
	if got := countHits(hits(), "POST /api/v3/sync/worker/run"); got == 0 {
		t.Fatalf("the worker was never kicked: %v", hits())
	}
	if got := countHits(hits(), "POST /api/v3/sync/jobs"); got != 1 {
		t.Fatalf("expected exactly one job created, got %d: %v", got, hits())
	}
	if strings.TrimSpace(stdout) != "" {
		t.Fatalf("stdout must stay empty on failure, got:\n%s", stdout)
	}
	msg := err.Error()
	for _, want := range []string{"sync job j1", "still queued", "500"} {
		if !strings.Contains(msg, want) {
			t.Errorf("error %q does not contain %q", msg, want)
		}
	}
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("exit code = %d, want %d (the kick's 500)", code, ExitServer)
	}
	hint := hintFor(err)
	for _, want := range []string{"202-cronjobs/sync-worker.php", "p202 sync status", "p202 sync"} {
		if !strings.Contains(hint, want) {
			t.Errorf("hint %q does not name %q", hint, want)
		}
	}
}

// A kick that failed while the server runs the job (a kick that timed out
// while its worker carried on) is a warning naming the job; the command still
// succeeds, since the job is running and a fallback would run the sync twice.
func TestSyncWarnsWhenAWorkerKickFailsButTheJobIsRunning(t *testing.T) {
	fastSyncPolls(t)
	orchestrator, _ := kickRecorder(t, http.StatusInternalServerError, "running")
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("the target was called (%s %s)", r.Method, r.URL.Path)
	}))
	defer target.Close()
	syncProfiles(t, orchestrator.URL, target.URL)

	stdout, stderr, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("a running job is not a failed sync: %v", err)
	}
	if !strings.Contains(stderr, "Warning") || !strings.Contains(stderr, "sync job j1") || !strings.Contains(stderr, "500") {
		t.Fatalf("expected a warning naming job j1 and the kick's error, stderr:\n%s", stderr)
	}
	if !strings.Contains(stdout, `"status": "running"`) {
		t.Fatalf("expected the job as it stands (running), got:\n%s", stdout)
	}
}

// A kick that succeeded leaves the old answer alone.
func TestSyncKickThatSucceedsAddsNoWarning(t *testing.T) {
	fastSyncPolls(t)
	orchestrator, _ := kickRecorder(t, http.StatusOK, "succeeded")
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	defer target.Close()
	syncProfiles(t, orchestrator.URL, target.URL)

	stdout, stderr, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync failed: %v", err)
	}
	if strings.Contains(stderr, "Warning") {
		t.Fatalf("unexpected warning:\n%s", stderr)
	}
	if !strings.Contains(stdout, `"status": "succeeded"`) {
		t.Fatalf("expected the finished job, got:\n%s", stdout)
	}
}
