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

func TestInboundLogSearchesAndShowsWhatToDo(t *testing.T) {
	body := `{"data":[{"inbound_id":43,"received_at":1793000000,"endpoint":"gpb","http_status":403,"accepted":false,
 "outcome":"unauthenticated","reason":"This account accepts only authenticated postbacks.","click_id":null,"conversion_id":null,
 "fix":{"text":"Give the sender the URL that carries your token.","page":"tracking202/setup/get_postback.php","label":"Copy"},
 "can_record":true}],"next_before":null}`
	_, seen := goalServer(t, 200, body)
	out, _, err := executeCommand("--json", "conversion", "inbound-log", "--search", "ORD-20391")
	if err != nil {
		t.Fatalf("conversion inbound-log --search: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains((*seen)[0].Query, "search=ORD-20391") {
		t.Fatalf("requests = %+v, want one carrying search=ORD-20391", *seen)
	}
	for _, want := range []string{"Give the sender the URL that carries your token.", "can_record"} {
		if !strings.Contains(out, want) {
			t.Errorf("output should show %q:\n%s", want, out)
		}
	}
}

func TestInboundLogRecordPostsTheRowAndPrintsTheConversion(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{"status":"recorded","message":"Recorded as conversion 77 on click 9001.","conversion_id":77,"click_id":9001,"inbound_id":43}}`)
	out, _, err := executeCommand("--json", "conversion", "inbound-log", "record", "43")
	if err != nil {
		t.Fatalf("conversion inbound-log record: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/conversions/inbound-log/43/record") {
		t.Fatalf("requests = %+v, want one POST /conversions/inbound-log/43/record", *seen)
	}
	if !strings.Contains(out, "Recorded as conversion 77") {
		t.Errorf("output should carry the answer:\n%s", out)
	}
}

func TestInboundLogRecordRefusalsSayWhatToDoNext(t *testing.T) {
	cases := []struct {
		status int
		want   string
	}{
		{422, "p202 conversion create"},
		{404, "p202 conversion inbound-log --outcome refused"},
		{409, "--search"},
	}
	for _, tc := range cases {
		goalServer(t, tc.status, `{"error":true,"message":"refused"}`)
		_, _, err := executeCommand("conversion", "inbound-log", "record", "43")
		if err == nil {
			t.Fatalf("HTTP %d should be an error", tc.status)
		}
		if !strings.Contains(hintFor(err), tc.want) {
			t.Errorf("HTTP %d hint = %q, want one naming %s", tc.status, hintFor(err), tc.want)
		}
	}
	_, seen := goalServer(t, 200, `{}`)
	_, _, err := executeCommand("conversion", "inbound-log", "record", "abc")
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("a non-numeric id should be a validation error, got %v", err)
	}
	if len(*seen) != 0 {
		t.Errorf("a refused id should send nothing, sent %+v", *seen)
	}
}

func TestPostbackAuthReadinessReadsTheReport(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"mode":"off","requests":12,"without_credentials":3,"safe_to_require_token":false,"senders":[]}}`)
	out, _, err := executeCommand("--json", "conversion", "postback-auth", "readiness")
	if err != nil {
		t.Fatalf("conversion postback-auth readiness: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/conversions/postback-auth/readiness") {
		t.Fatalf("requests = %+v, want one GET /conversions/postback-auth/readiness", *seen)
	}
	if !strings.Contains(out, "safe_to_require_token") {
		t.Errorf("output should carry the answer:\n%s", out)
	}
}

func TestInboundLogFiltersByClickAndRefusesAMalformedOne(t *testing.T) {
	_, seen := goalServer(t, 200, inboundLogJSON)
	if _, _, err := executeCommand("--json", "conversion", "inbound-log", "--click-id", "9001"); err != nil {
		t.Fatalf("conversion inbound-log --click-id: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains((*seen)[0].Query, "click_id=9001") {
		t.Fatalf("requests = %+v, want one carrying click_id=9001", *seen)
	}
	_, _, err := executeCommand("conversion", "inbound-log", "--click-id", "12abc")
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("--click-id 12abc should be a validation error, got %v", err)
	}
	if !strings.Contains(hintFor(err), "p202 click list") {
		t.Errorf("hint = %q, want one naming p202 click list", hintFor(err))
	}
	if len(*seen) != 1 {
		t.Errorf("a refused flag should send nothing more, sent %+v", *seen)
	}
}
