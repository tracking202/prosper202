package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// rotator check gates deploys, so a rotator whose detail fetch fails must be
// reported and counted as a failure — not silently skipped with exit code 0.
func TestRotatorCheckCountsUnfetchableRotatorsAsFailures(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasSuffix(r.URL.Path, "/rotators/1"):
			_, _ = w.Write([]byte(`{"data":{"id":1,"name":"healthy","default_url":"https://example.com/lp","rules":[]}}`))
		case strings.HasSuffix(r.URL.Path, "/rotators/2"):
			w.WriteHeader(500)
			_, _ = w.Write([]byte(`{"message":"boom"}`))
		case strings.HasSuffix(r.URL.Path, "/rotators"):
			_, _ = w.Write([]byte(`{"data":[{"id":1,"name":"healthy"},{"id":2,"name":"broken"}]}`))
		default:
			_, _ = w.Write([]byte(`{"data":{}}`))
		}
	}))
	defer srv.Close()
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	stdout, _, err := executeCommand("rotator", "check", "--json")
	if err == nil {
		t.Fatal("expected a failure exit when a rotator could not be fetched")
	}
	if !strings.Contains(err.Error(), "1 rotator(s) have configuration issues") {
		t.Fatalf("unexpected error: %v", err)
	}
	if !strings.Contains(stdout, "could not fetch rotator") {
		t.Fatalf("the unfetchable rotator must appear in the report, got %q", stdout)
	}
	if !strings.Contains(stdout, "broken") {
		t.Fatalf("the failing rotator should be named, got %q", stdout)
	}
}
