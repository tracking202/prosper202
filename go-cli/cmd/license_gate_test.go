package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"p202/internal/api"
)

// An install with the Pro-only CLI gate answers 402 to p202-cli when its
// ClickServer key has no active Pro subscription; the CLI must say why and
// point at the trial, not blame the API key.
func TestCliExplainsProLicenceRefusal(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.Header.Get("User-Agent"), "p202-cli/") {
			t.Errorf("request without the p202-cli User-Agent the install gates on: %q", r.Header.Get("User-Agent"))
		}
		w.WriteHeader(402)
		w.Write([]byte(`{"error":true,"status":402,"category":"licence","message":"The Prosper202 CLI requires a Prosper202 ClickServer Pro licence. Start a 7-day free trial at https://my.tracking202.com/api/customers/subscriptions/support"}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("user", "list")
	if err == nil || !strings.Contains(err.Error(), "Pro licence") {
		t.Fatalf("want the Pro licence refusal, got %v", err)
	}
	if h := hintFor(err); !strings.Contains(h, "7-day free trial") || strings.Contains(h, "Verify your API key") {
		t.Errorf("hint %q should point at the trial, not the key", h)
	}
	// A stable contract for agents: its own category and exit code, not
	// "validation"/1 (bad input) and not "auth"/2 (bad key).
	if got := api.ErrorCategory(err); got != "licence" {
		t.Errorf("category = %q, want licence", got)
	}
	if got := exitCodeForError(err); got != ExitLicence {
		t.Errorf("exit code = %d, want %d (ExitLicence)", got, ExitLicence)
	}
}
