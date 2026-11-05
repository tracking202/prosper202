package cmd

import (
	"strings"
	"testing"
)

const inboundLogJSON = `{"data":[{"inbound_id":42,"received_at":1793000000,"endpoint":"pb","method":"GET","http_status":404,
 "accepted":false,"outcome":"unknown_click","reason":"No click has this id.","user_id":1,"click_id":9001,
 "conversion_id":null,"transaction_id":null,"fields":{"subid":"9001","amount":"49.00"}}],"next_before":42,"kept_per_account":5000}`

func TestInboundLogSendsTheFiltersAndShowsWhy(t *testing.T) {
	_, seen := goalServer(t, 200, inboundLogJSON)
	out, _, err := executeCommand("--table", "--wide", "conversion", "inbound-log", "--outcome", "refused", "--endpoint", "pb",
		"--limit", "1", "--before", "100")
	if err != nil {
		t.Fatalf("conversion inbound-log: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/conversions/inbound-log") {
		t.Fatalf("requests = %+v, want one GET /conversions/inbound-log", *seen)
	}
	for _, want := range []string{"outcome=refused", "endpoint=pb", "limit=1", "before=100"} {
		if !strings.Contains((*seen)[0].Query, want) {
			t.Errorf("query %q should carry %s", (*seen)[0].Query, want)
		}
	}
	for _, want := range []string{"unknown_click", "No click has this id.", "--before 42"} {
		if !strings.Contains(out, want) {
			t.Errorf("output should show %q:\n%s", want, out)
		}
	}
}

func TestInboundLogRefusesABadLimitWithAHint(t *testing.T) {
	_, seen := goalServer(t, 200, inboundLogJSON)
	_, _, err := executeCommand("conversion", "inbound-log", "--limit", "0")
	if err == nil {
		t.Fatal("--limit 0 should be refused")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want 1 (validation)", code)
	}
	if !strings.Contains(hintFor(err), "--before") {
		t.Errorf("hint = %q, want one naming --before", hintFor(err))
	}
	if len(*seen) != 0 {
		t.Errorf("a refused flag should send nothing, sent %+v", *seen)
	}
}

func TestInboundLogRefusesAnUnknownEndpoint(t *testing.T) {
	goalServer(t, 200, inboundLogJSON)
	_, _, err := executeCommand("conversion", "inbound-log", "--endpoint", "stripe")
	if err == nil || !strings.Contains(err.Error(), "jvzoo") {
		t.Fatalf("--endpoint stripe should be refused listing the endpoints, got %v", err)
	}
}
