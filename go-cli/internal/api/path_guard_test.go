package api

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestCheckAPIPathKeepsTheRequestWhereTheCommandPutIt(t *testing.T) {
	for _, ok := range []string{"campaigns", "campaigns/7", "/campaigns/7", "staged-changes/chg_1a2b/apply", "ltv/subscriptions/sub%2F1/events", "apps/12/installs/abc-def"} {
		if err := checkAPIPath(ok); err != nil {
			t.Errorf("%q refused: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "/", "staged-changes/chg_1/apply?/discard", "campaigns/7#x", "campaigns/../users", "campaigns/./7", "campaigns//7", "campaigns/7/", "campaigns/a b", "campaigns/7\\x", "campaigns/7\n", "campaigns/\x7f",
		"campaigns/%2e%2e/users", "campaigns/%2E%2E/users", "campaigns/.%2e/users", "campaigns/%2e./users",
		"campaigns/%2e/7", "campaigns/%2E", "campaigns/%zz", "campaigns/7%"} {
		err := checkAPIPath(bad)
		if err == nil {
			t.Errorf("%q was not refused", bad)
			continue
		}
		if ErrorCategory(err) != "validation" {
			t.Errorf("%q: category %q, want validation", bad, ErrorCategory(err))
		}
	}
}

func TestReadOnlyModeSendsNothingThatChangesData(t *testing.T) {
	var got []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = append(got, r.Method+" "+r.URL.Path)
		_, _ = w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()
	c := newTestClient(srv.URL)
	SetReadOnlyMode(true)
	defer SetReadOnlyMode(false)
	for _, method := range []string{"POST", "PUT", "PATCH", "DELETE"} {
		_, err := c.doLimited(method, "campaigns/7", nil, map[string]string{"a": "b"}, nil, maxResponseSize, false)
		if err == nil || ErrorCategory(err) != "validation" {
			t.Errorf("%s under read-only: err %v, want a validation refusal", method, err)
		}
	}
	if len(got) != 0 {
		t.Fatalf("read-only mode sent %q", got)
	}
	// Reads, and the reads that arrive as POST, still go.
	if _, err := c.doLimited("GET", "campaigns/7", nil, nil, nil, maxResponseSize, false); err != nil {
		t.Errorf("GET under read-only: %v", err)
	}
	if _, err := c.doLimited("POST", "goals/validate", nil, map[string]string{}, nil, maxResponseSize, false); err != nil {
		t.Errorf("POST goals/validate under read-only: %v", err)
	}
	if want := []string{"GET /campaigns/7", "POST /goals/validate"}; strings.Join(got, ",") != strings.Join(want, ",") {
		t.Errorf("sent %q, want %q", got, want)
	}
}
