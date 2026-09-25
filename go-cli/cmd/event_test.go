package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestEventSendBuildsOneEventWithTypedValues(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{"accepted":["ORD-1"]}}`)

	_, _, err := executeCommand("event", "send", "--click-id", "123", "--name", "purchase", "--id", "ORD-1",
		"--revenue", "49.90", "--transaction-id", "T 1", "--occurred-at", "1700000000", "--props", `{"plan":"pro","seats":3}`)
	if err != nil {
		t.Fatalf("event send: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/events") {
		t.Fatalf("requests = %+v, want one POST /events", *seen)
	}
	got, _ := json.Marshal((*seen)[0].Body)
	want := `{"click_id":123,"events":[{"event_id":"ORD-1","name":"purchase","occurred_at":1700000000,` +
		`"properties":{"plan":"pro","seats":3},"revenue":49.9,"transaction_id":"T 1"}]}`
	if string(got) != want {
		t.Errorf("body =\n %s\nwant\n %s", got, want)
	}
}

func TestEventSendReadsAFileOfEvents(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{}}`)
	file := filepath.Join(t.TempDir(), "events.json")
	if err := os.WriteFile(file, []byte(`{"events":[{"event_id":"a","name":"signup"},{"event_id":"b","name":"sale","revenue":20}]}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("event", "send", "--click-id", "9", "--file", file); err != nil {
		t.Fatalf("event send --file: %v", err)
	}
	events, _ := (*seen)[0].Body["events"].([]interface{})
	if len(events) != 2 || (*seen)[0].Body["click_id"] != float64(9) {
		t.Errorf("body = %+v, want click 9 with two events", (*seen)[0].Body)
	}
}

func TestEventSendSendsTheIdempotencyKey(t *testing.T) {
	var header string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		header = r.Header.Get("Idempotency-Key")
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{}}`))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	if _, _, err := executeCommand("event", "send", "--click-id", "9", "--name", "s", "--id", "e", "--idempotency-key", "k-1"); err != nil {
		t.Fatal(err)
	}
	if header != "k-1" {
		t.Errorf("Idempotency-Key = %q, want k-1", header)
	}
}

func TestEventSendRefusesBadInputBeforeAnyRequest(t *testing.T) {
	// No server configured: each must fail on its flags, as a validation
	// error with a next step, never on the missing config.
	setTestHome(t, t.TempDir())
	dir := t.TempDir()
	notList := filepath.Join(dir, "obj.json")
	os.WriteFile(notList, []byte(`{"click_id":1,"events":[]}`), 0o600)
	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"event", "send", "--name", "s", "--id", "e"}, "--click-id", "p202 click list"},
		{[]string{"event", "send", "--click-id", "1e3", "--name", "s", "--id", "e"}, "whole number", "p202 click list"},
		{[]string{"event", "send", "--click-id", "07", "--name", "s", "--id", "e"}, "whole number", ""},
		{[]string{"event", "send", "--click-id", "1", "--id", "e"}, "--name", "p202 goal list"},
		{[]string{"event", "send", "--click-id", "1", "--name", "Sale Complete", "--id", "e"}, "not an event name", ""},
		{[]string{"event", "send", "--click-id", "1", "--name", "s"}, "--id", "retry is safe"},
		{[]string{"event", "send", "--click-id", "1", "--name", "s", "--id", "@install"}, "--id", ""},
		{[]string{"event", "send", "--click-id", "1", "--name", "s", "--id", "e", "--revenue", "1e3"}, "--revenue", ""},
		{[]string{"event", "send", "--click-id", "1", "--name", "s", "--id", "e", "--occurred-at", "soon"}, "--occurred-at", "server's clock"},
		{[]string{"event", "send", "--click-id", "1", "--name", "s", "--id", "e", "--props", "[1]"}, "JSON object", "plan"},
		{[]string{"event", "send", "--click-id", "1", "--file", "x.json", "--name", "s"}, "exclusive", ""},
		{[]string{"event", "send", "--click-id", "1", "--file", notList}, "field \"click_id\"", "--click-id"},
	}
	for _, tc := range cases {
		_, _, err := executeCommand(tc.args...)
		if err == nil {
			t.Errorf("%v: expected an error", tc.args)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code %d, want %d (validation): %v", tc.args, code, ExitValidation, err)
		}
		if !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: error %q should mention %q", tc.args, err, tc.want)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q should mention %q", tc.args, hintFor(err), tc.hint)
		}
	}
}

func TestEventSendHintsForServerRefusals(t *testing.T) {
	cases := []struct {
		status int
		body   string
		hint   string
	}{
		{404, `{"error":true,"message":"Click 9 not found","status":404}`, "p202 click list"},
		{409, `{"error":true,"message":"Event id \"e\" was already recorded","status":409}`, "new --id"},
		{422, `{"error":true,"message":"The events are invalid","status":422,"field_errors":{"events[0].name":"bad"}}`, "23-events.md"},
	}
	for _, tc := range cases {
		goalServer(t, tc.status, tc.body)
		_, _, err := executeCommand("event", "send", "--click-id", "9", "--name", "s", "--id", "e")
		if err == nil {
			t.Fatalf("%d: expected an error", tc.status)
		}
		if !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%d: hint %q should mention %q", tc.status, hintFor(err), tc.hint)
		}
	}
}
