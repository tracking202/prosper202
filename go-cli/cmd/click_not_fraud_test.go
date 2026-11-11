package cmd

import (
	"net/http"
	"strings"
	"testing"

	"p202/internal/api"
)

const notFraudAnswer = `{"data":{"click_id":940001,"ip_address":"203.0.113.50","clicks_counted":2,"more_clicks_filtered":false,
"address_always_counted":true,"note":"The address is on the click filter list as always count, so it is not scored again."}}`

func TestClickNotFraudPostsTheClickAndSaysWhatChanged(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, notFraudAnswer })

	stdout, _, err := executeCommand("click", "not-fraud", "940001")
	if err != nil {
		t.Fatalf("click not-fraud: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || (*seen)[0].Path != "/api/v3/clicks/940001/not-fraud" || len((*seen)[0].Query) != 0 {
		t.Fatalf("requests = %+v, want one POST /clicks/940001/not-fraud", *seen)
	}
	for _, want := range []string{"2 click(s) from 203.0.113.50 count again.", "always count"} {
		if !strings.Contains(stdout, want) {
			t.Errorf("output lacks %q:\n%s", want, stdout)
		}
	}
}

func TestClickNotFraudOnAClickWithNoVerdictSaysWhereToFindOne(t *testing.T) {
	newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		return 404, `{"error":true,"status":404,"message":"No click 5 of yours is filtered for its fraud score (filter_reason fraud_score)"}`
	})

	_, _, err := executeCommand("click", "not-fraud", "5", "--json")
	if err == nil {
		t.Fatal("a click with no verdict was reported as counted again")
	}
	if code := exitCodeForError(err); code == 0 {
		t.Errorf("exit code %d, want a failure", code)
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 click list --filter-reason fraud_score") {
		t.Errorf("hint %q does not name the command that lists such clicks", hint)
	}
}

func TestClickNotFraudRefusesAnIdThatIsNotOne(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, notFraudAnswer })

	_, _, err := executeCommand("click", "not-fraud", "12abc")
	if err == nil {
		t.Fatal("12abc was taken as a click id")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code %d, want %d (validation)", code, ExitValidation)
	}
	if len(*seen) != 0 {
		t.Errorf("a request was sent for a bad id: %+v", *seen)
	}
}

// A read-only connection sends nothing: the command changes data.
func TestClickNotFraudIsRefusedOnAReadOnlyConnection(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, notFraudAnswer })
	api.SetReadOnlyMode(true)
	t.Cleanup(func() { api.SetReadOnlyMode(false) })

	_, _, err := executeCommand("click", "not-fraud", "940001")
	if err == nil || !strings.Contains(err.Error(), "read-only") {
		t.Fatalf("err = %v, want the read-only refusal", err)
	}
	if len(*seen) != 0 {
		t.Errorf("a request was sent on a read-only connection: %+v", *seen)
	}
}
