package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The Android fraud limits (plan §7.1): refused by flag before any request,
// with a hint that says what the setting does, and sent as typed when valid.
func TestAppLimitFlagsAreRefusedByNameBeforeAnyRequest(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // no server configured: a request would fail differently
	for _, tc := range []struct {
		args []string
		hint string
	}{
		{[]string{"app", "update", "3", "--install-cap-per-minute", "0"}, "429"},
		{[]string{"app", "update", "3", "--install-cap-per-minute", "1e3"}, "429"},
		{[]string{"app", "update", "3", "--event-cap-per-minute", "99"}, "full batch"},
		{[]string{"app", "update", "3", "--ctit-min-seconds", "07"}, "click injection"},
		{[]string{"app", "update", "3", "--ctit-max-seconds", "59"}, "click spamming"},
		{[]string{"app", "update", "3", "--fast-goal-seconds", "3601"}, "0 flags none"},
		{[]string{"app", "update", "3", "--fast-goal-policy", "Hold"}, "never pays"},
		{[]string{"app", "create", "--app-key", "com.example.app", "--app-name", "x", "--install-cap-per-minute", "-1"}, "429"},
		{[]string{"app", "report", "--platform", "android", "--ctit-flag", "fast"}, "ctit-min-seconds"},
		{[]string{"app", "report", "--platform", "android", "--fast-goals", "yes"}, ""},
		{[]string{"app", "report", "--ctit-flag", "short"}, "--platform android"},
		{[]string{"app", "report", "--platform", "ios", "--group-by", "ctit-flag"}, "--platform android"},
		{[]string{"app", "install", "list", "3", "--ctit-flag", "Short"}, ""},
	} {
		_, _, err := executeCommand(tc.args...)
		assertValidationError(t, err)
		if exitCodeForError(err) != 1 {
			t.Errorf("%v: exit %d, want 1", tc.args, exitCodeForError(err))
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q does not mention %q", tc.args, hintFor(err), tc.hint)
		}
	}
}

func TestAppLimitFlagsAndReportFiltersReachTheServerAsTyped(t *testing.T) {
	var body map[string]interface{}
	var query string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			query = r.URL.RawQuery
			w.Write([]byte(`{"data":{"platform":"android","groups":[],"totals":{}},"meta":{}}`))
			return
		}
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &body)
		w.Write([]byte(`{"data":{"registration_id":3}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	if _, _, err := executeCommand("app", "update", "3", "--install-cap-per-minute", "1000", "--event-cap-per-minute", "100",
		"--ctit-min-seconds", "0", "--ctit-max-seconds", "3600", "--fast-goal-seconds", "30", "--fast-goal-policy", "hold"); err != nil {
		t.Fatalf("update: %v", err)
	}
	want := map[string]string{
		"install_cap_per_minute": "1000", "event_cap_per_minute": "100", "ctit_min_seconds": "0",
		"ctit_max_seconds": "3600", "fast_goal_seconds": "30", "fast_goal_policy": "hold",
	}
	for field, v := range want {
		if body[field] != v {
			t.Errorf("%s = %v, want %q (body %v)", field, body[field], v, body)
		}
	}
	if _, _, err := executeCommand("app", "report", "--platform", "android", "--group-by", "ctit-flag", "--ctit-flag", "short", "--fast-goals", "1"); err != nil {
		t.Fatalf("report: %v", err)
	}
	for _, part := range []string{"group_by=ctit-flag", "ctit_flag=short", "fast_goals=1", "platform=android"} {
		if !strings.Contains(query, part) {
			t.Errorf("query %q lacks %s", query, part)
		}
	}
}
