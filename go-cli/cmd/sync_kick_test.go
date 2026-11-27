package cmd

import (
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"slices"
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
			_, _ = w.Write([]byte(`{"data":{"job_id":"j1","status":"` + jobStatus + `","attempts":1}}`))
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

// The checks ran out and the job is still queued (a try of it failed and it
// waits for its next, or the worker never reached it): the sync has not
// happened, so the command fails naming the job and where its end will show,
// with the job as it stands on stdout, not the creation reply and exit 0.
func TestSyncFailsWhenTheChecksRunOutOnAJobStillQueued(t *testing.T) {
	fastSyncPolls(t)
	orchestrator, hits := kickRecorder(t, http.StatusOK, "queued")
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("the target was called (%s %s): a queued server-side job must not fall back to a client-side sync", r.Method, r.URL.Path)
	}))
	defer target.Close()
	syncProfiles(t, orchestrator.URL, target.URL)

	stdout, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err == nil {
		t.Fatalf("a job that has not run to an end is not a sync that happened; stdout:\n%s", stdout)
	}
	if got := countHits(hits(), "POST /api/v3/sync/worker/run"); got != syncJobPolls {
		t.Errorf("the worker was kicked %d times, want %d", got, syncJobPolls)
	}
	if got := countHits(hits(), "POST /api/v3/sync/jobs"); got != 1 {
		t.Fatalf("expected exactly one job created, got %d: %v", got, hits())
	}
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", code, ExitPartialFailure)
	}
	for _, want := range []string{"sync job j1", "has not finished", "still queued"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q does not contain %q", err.Error(), want)
		}
	}
	hint := hintFor(err)
	for _, want := range []string{"p202 sync history --from source --to target", "next_run_at", "queues one more job"} {
		if !strings.Contains(hint, want) {
			t.Errorf("hint %q does not name %q", hint, want)
		}
	}
	if !strings.Contains(stdout, `"attempts": 1`) || !strings.Contains(stdout, `"status": "queued"`) {
		t.Errorf("expected the job as it stands (as read, with its attempts), got:\n%s", stdout)
	}
}

// The checks ran out while the server runs the job: it will end by itself,
// so the command succeeds as it does when a kick failed on a running job,
// with a warning naming the job and the job as it stood.
func TestSyncWarnsWhenTheChecksRunOutOnAJobStillRunning(t *testing.T) {
	fastSyncPolls(t)
	orchestrator, _ := kickRecorder(t, http.StatusOK, "running")
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("the target was called (%s %s)", r.Method, r.URL.Path)
	}))
	defer target.Close()
	syncProfiles(t, orchestrator.URL, target.URL)

	stdout, stderr, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("a running job is not a failed sync: %v", err)
	}
	for _, want := range []string{"Warning", "sync job j1", "still running", "p202 sync history --from source --to target"} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr does not contain %q:\n%s", want, stderr)
		}
	}
	if !strings.Contains(stdout, `"status": "running"`) || !strings.Contains(stdout, `"attempts": 1`) {
		t.Fatalf("expected the job as it stood (running, as read), got:\n%s", stdout)
	}
}

// The statuses the CLI takes as a job's end are the server's terminal states,
// and the two it waits on are the rest: a state added on the server that the
// CLI does not know would be waited on for ever, or read as an end.
func TestSyncJobEndsAreTheServersTerminalStates(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Support", "SyncJobState.php"))
	if err != nil {
		t.Fatal(err)
	}
	values := map[string]string{}
	for _, m := range regexp.MustCompile(`(?m)^\s*case (\w+) = '([a-z_]+)';`).FindAllStringSubmatch(string(src), -1) {
		values[m[1]] = m[2]
	}
	if len(values) < 3 {
		t.Fatalf("read %d states from SyncJobState.php: the enum's cases are no longer `case Name = 'value';`", len(values))
	}
	// The arm of next() whose states move nowhere.
	arm := regexp.MustCompile(`((?:self::\w+,\s*)*self::\w+)\s*=>\s*\[\],`).FindAllStringSubmatch(string(src), -1)
	if len(arm) != 1 {
		t.Fatalf("found %d arms of next() that move nowhere, want the one that lists the terminal states", len(arm))
	}
	var terminal, waiting []string
	ends := map[string]bool{}
	for _, m := range regexp.MustCompile(`self::(\w+)`).FindAllStringSubmatch(arm[0][1], -1) {
		value, ok := values[m[1]]
		if !ok {
			t.Fatalf("next() names %s, which is no case", m[1])
		}
		terminal = append(terminal, value)
		ends[m[1]] = true
	}
	for name, value := range values {
		if !ends[name] {
			waiting = append(waiting, value)
		}
	}
	slices.Sort(terminal)
	slices.Sort(waiting)
	got := slices.Clone(syncJobEnds)
	slices.Sort(got)
	if !slices.Equal(got, terminal) {
		t.Errorf("syncJobEnds = %v, the server's terminal states are %v", got, terminal)
	}
	if want := []string{"queued", "running"}; !slices.Equal(waiting, want) {
		t.Errorf("the server's unfinished states are %v; tryServerSideSync answers for %v", waiting, want)
	}
}
