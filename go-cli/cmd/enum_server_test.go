package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

// capabilitiesServer serves /capabilities with the given report_breakdowns
// (nil: none) and records every other request's query.
func capabilitiesServer(t *testing.T, breakdowns []string, probes *int32, queries *[]string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			atomic.AddInt32(probes, 1)
			features := map[string]interface{}{"staged_writes": true}
			if breakdowns != nil {
				features["report_breakdowns"] = breakdowns
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": map[string]interface{}{"features": features}})
		case strings.HasSuffix(r.URL.Path, "/versions"):
			_, _ = w.Write([]byte(`{"data":{"current":"v3","supported":["v3"]}}`))
		default:
			*queries = append(*queries, r.URL.Path+"?"+r.URL.RawQuery)
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func TestServerAdvertisedDimensionIsAcceptedWhenTheBuiltInListLacksIt(t *testing.T) {
	var probes int32
	var queries []string
	srv := capabilitiesServer(t, append(append([]string{}, breakdownDimensions...), "language"), &probes, &queries)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("analytics", "--group-by", "language"); err != nil {
		t.Fatalf("a dimension the server advertises was refused: %v", err)
	}
	if len(queries) != 1 || !strings.Contains(queries[0], "breakdown=language") {
		t.Errorf("requests = %v, want one breakdown=language", queries)
	}
}

func TestServerListIsWhatTheErrorNamesWhenItHasOne(t *testing.T) {
	var probes int32
	var queries []string
	srv := capabilitiesServer(t, []string{"campaign", "country", "referer"}, &probes, &queries)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("report", "breakdown", "--breakdown", "zz-nope")
	if err == nil {
		t.Fatal("an unknown dimension was accepted")
	}
	if !strings.Contains(err.Error(), "must be one of (this server's list): campaign, country, referer") {
		t.Errorf("message %q, want the server's list", err.Error())
	}
	if len(queries) != 0 {
		t.Errorf("the report was requested anyway: %v", queries)
	}
}

func TestBuiltInDimensionsNeedNoServerAndAnOfflineServerFallsBack(t *testing.T) {
	var probes int32
	var queries []string
	srv := capabilitiesServer(t, []string{"campaign"}, &probes, &queries)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	// A value on the built-in list is never checked against the server.
	if _, _, err := executeCommand("analytics", "--group-by", "browser"); err != nil {
		t.Fatalf("analytics --group-by browser: %v", err)
	}
	if n := atomic.LoadInt32(&probes); n != 0 {
		t.Errorf("a built-in dimension probed /capabilities %d times", n)
	}

	// With the server gone, an unknown value is refused with the built-in list.
	writeTestConfig(t, tmp, "http://127.0.0.1:9", "test-key")
	_, _, err := executeCommand("analytics", "--group-by", "language")
	if err == nil || !strings.Contains(err.Error(), strings.Join(breakdownDimensions, ", ")) || strings.Contains(err.Error(), "server's list") {
		t.Errorf("offline error = %v, want the built-in list", err)
	}
}
