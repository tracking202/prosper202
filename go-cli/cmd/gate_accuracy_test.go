package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// A forecast asked to apply seasonal weighting must not claim it did when the
// weekpart data was unavailable: the meta must report what actually happened
// and the fallback must be said out loud.
func TestForecastSeasonalReportsFallbackWhenWeekpartUnavailable(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasSuffix(r.URL.Path, "/reports/timeseries"):
			_, _ = w.Write([]byte(`{"data":[
				{"date":"2026-08-01","total_clicks":100},
				{"date":"2026-08-02","total_clicks":110},
				{"date":"2026-08-03","total_clicks":120},
				{"date":"2026-08-04","total_clicks":130},
				{"date":"2026-08-05","total_clicks":140}
			]}`))
		case strings.HasSuffix(r.URL.Path, "/reports/weekpart"):
			w.WriteHeader(500)
			_, _ = w.Write([]byte(`{"message":"weekpart unavailable"}`))
		default:
			_, _ = w.Write([]byte(`{"data":{}}`))
		}
	}))
	defer srv.Close()
	writeTestConfig(t, home, srv.URL, "test-api-key-1234")

	stdout, stderr, err := executeCommand("forecast", "--metric", "clicks", "--horizon", "3", "--seasonal", "--json")
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}
	if !strings.Contains(stderr, "unadjusted") {
		t.Fatalf("expected a fallback warning on stderr, got %q", stderr)
	}

	var out struct {
		Meta struct {
			Seasonal bool `json:"seasonal"`
		} `json:"meta"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("parsing forecast output: %v (stdout %q)", err, stdout)
	}
	if out.Meta.Seasonal {
		t.Fatal("meta.seasonal = true, but no seasonal weights were applied")
	}
}

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
