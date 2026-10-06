package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"p202/internal/api"
)

// A success status with a body that isn't a breakdown is the server's (or a proxy's) failure, not the flags': with a
// client-side filter set it is a server error (exit 4) whose hint points at the response, keeping the parse cause.
func TestBreakdownFiltersOnAMalformedPayloadAreAServerError(t *testing.T) {
	for _, body := range []string{`<html>502 Bad Gateway</html>`, `{"data":[{"total_clicks":`} {
		srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(200)
			w.Write([]byte(body))
		}))
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key")
		_, _, err := executeCommand("report", "breakdown", "--breakdown", "campaign", "--min-clicks", "5")
		srv.Close()
		if err == nil {
			t.Fatalf("%.20q: a malformed breakdown with a filter set should fail", body)
		}
		if code := exitCodeForError(err); code != ExitServer {
			t.Errorf("%.20q: exit code = %d, want %d (server)", body, code, ExitServer)
		}
		if !strings.Contains(err.Error(), "isn't valid JSON") {
			t.Errorf("%.20q: the message should keep the parse cause: %v", body, err)
		}
		if h := api.HintFor(err); !strings.Contains(h, "p202 system health") || !strings.Contains(h, "without the filter flags") {
			t.Errorf("%.20q: hint = %q, want the raw-response and system health steps", body, h)
		}
	}
}
