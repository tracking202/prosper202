package cmd

import (
	"net/http"
	"strconv"
	"strings"
	"testing"

	"p202/internal/api"
)

// Each answer of POST /spend-ledger/sync/{id}/run reaches an agent as its
// class: OpenAI's limit a rate limit (exit 7) with the wait, its outage or an
// unusable answer a server failure (exit 4) with the class's hint, and only
// the refusals that are the caller's to fix as validation (exit 1), each
// with its own next step. The "enable it first" hint is the off sync's only.
func TestSpendSyncRunAnswersEachFailureAsItsClass(t *testing.T) {
	upstream := func(status int, reason string, retry string) string {
		return `{"error":true,"status":` + strconv.Itoa(status) + `,"message":"Spend sync run for connection 3 failed: OpenAI said so",` +
			retry + `"details":{"upstream":"openai_ads","connection_id":3,"reason":"` + reason + `"}}`
	}
	refusal := func(field, message string) string {
		return `{"error":true,"status":422,"message":"` + message + `","field_errors":{"` + field + `":"` + message + `"}}`
	}
	for _, c := range []struct {
		what   string
		status int
		body   string
		exit   int
		hint   string // what the hint must say
	}{
		// OpenAI's own wait, not the hourly run's back-off in details.retry_after (a unix time).
		{"OpenAI's rate limit", http.StatusTooManyRequests, `{"error":true,"status":429,"message":"Spend sync run for connection 3 failed: OpenAI answered 429",` +
			`"retry_after_seconds":60,"details":{"upstream":"openai_ads","connection_id":3,"reason":"rate_limited","retry_after":1794303600}}`, ExitRateLimited, "Wait 60 seconds"},
		{"an outage", http.StatusServiceUnavailable, upstream(503, "unreachable", `"retry_after_seconds":120,`), ExitServer, "could not get a usable answer from OpenAI's Advertiser API"},
		{"an unusable answer", http.StatusBadGateway, upstream(502, "unreadable_answer", ""), ExitServer, "could not get a usable answer from OpenAI's Advertiser API"},
		{"a refused key", http.StatusUnprocessableEntity, refusal("api_key", "OpenAI refused connection 3's Advertiser API key"), ExitValidation, "p202 ad-connection update 3 --api-key-file"},
		{"a sync that is off", http.StatusUnprocessableEntity, refusal("enabled", "Spend sync is off"), ExitValidation, "p202 spend sync enable 3"},
		{"another currency", http.StatusUnprocessableEntity, refusal("connection", "billed in EUR"), ExitValidation, "not converted between currencies"},
		{"a page the ledger refused", http.StatusUnprocessableEntity, refusal("spend", "spend must be an amount"), ExitValidation, "The spend ledger refused a page"},
		{"a request OpenAI refused", http.StatusUnprocessableEntity, refusal("request", "OpenAI answered 400: bad time range"), ExitValidation, "running it again sends the same request"},
		{"an unknown id", http.StatusNotFound, `{"error":true,"status":404,"message":"No ChatGPT Ads connection 3"}`, ExitValidation, adSpendSyncListHint},
	} {
		t.Run(c.what, func(t *testing.T) {
			spendServer(t, c.status, c.body)
			out, _, err := executeCommand("--json", "spend", "sync", "run", "3")
			if err == nil {
				t.Fatalf("no error; stdout %q", out)
			}
			if out != "" {
				t.Errorf("stdout = %q, want nothing on a failure", out)
			}
			if got := exitCodeForError(err); got != c.exit {
				t.Errorf("exit %d, want %d (%v)", got, c.exit, err)
			}
			hint := hintFor(err)
			if !strings.Contains(hint, c.hint) {
				t.Errorf("hint = %q, want it to say %q", hint, c.hint)
			}
			if c.status != http.StatusUnprocessableEntity && c.status != http.StatusNotFound && hint != api.HintFor(err) {
				t.Errorf("hint = %q, want the class's (nothing in the request is wrong)", hint)
			}
			if strings.Contains(hint, "1794303600") {
				t.Errorf("hint = %q: the hourly run's back-off is not this request's wait", hint)
			}
			if c.what != "a sync that is off" && strings.Contains(hint, "spend sync enable") {
				t.Errorf("hint = %q: the sync is on", hint)
			}
		})
	}
}

// A failed run that wrote rows first says so, with how to go on: the class
// hint's "nothing was done" would be false.
func TestSpendSyncRunThatWroteRowsBeforeFailingSaysSo(t *testing.T) {
	for _, c := range []struct {
		status int
		body   string
		exit   int
		wait   string
	}{
		{http.StatusServiceUnavailable, `{"error":true,"status":503,"message":"failed: OpenAI answered 503; 360 hour row(s) were written","retry_after_seconds":60,` +
			`"details":{"upstream":"openai_ads","connection_id":3,"reason":"unreachable","rows_written":360}}`, ExitServer, "Wait a minute"},
		{http.StatusTooManyRequests, `{"error":true,"status":429,"message":"failed: OpenAI answered 429","retry_after_seconds":60,` +
			`"details":{"upstream":"openai_ads","connection_id":3,"reason":"rate_limited","rows_written":12,"retry_after":1794303600}}`, ExitRateLimited, "Wait 60 seconds"},
	} {
		spendServer(t, c.status, c.body)
		_, _, err := executeCommand("--json", "spend", "sync", "run", "3")
		if got := exitCodeForError(err); got != c.exit {
			t.Errorf("%d: exit %d, want %d", c.status, got, c.exit)
		}
		hint := hintFor(err)
		for _, want := range []string{"wrote", "running it again adds nothing twice", c.wait, "p202 spend sync run 3"} {
			if !strings.Contains(hint, want) {
				t.Errorf("%d: hint = %q, want it to say %q", c.status, hint, want)
			}
		}
		if strings.Contains(hint, "nothing was done") {
			t.Errorf("%d: hint = %q says nothing was done", c.status, hint)
		}
	}
}

func TestSpendSyncRunSaysWhenTheRunIsNotComplete(t *testing.T) {
	spendServer(t, 200, `{"data":{"run":{"rows":3,"from":1,"to":2,"complete":false,"error":null,`+
		`"note":"1 row(s) OpenAI sent were left out: ad \"ad_9\""},"connection_id":3,"left_out":"1 row(s) ..."}}`)
	_, stderr, err := executeCommand("spend", "sync", "run", "3")
	if err != nil {
		t.Fatalf("spend sync run: %v", err)
	}
	if !strings.Contains(stderr, "Not complete: 1 row(s) OpenAI sent were left out") {
		t.Errorf("stderr = %q, want the run's note", stderr)
	}
}

// A run not complete with no note of its own (hours earlier runs left out are
// still read again) says why, never "Not complete: .".
func TestSpendSyncRunNotCompleteWithoutANoteSaysWhy(t *testing.T) {
	cases := []struct{ leftOut, want string }{
		{`"1 row(s) of hours from 2026-11-01 00:00 UTC were left out"`, "Not complete: left out: 1 row(s) of hours from 2026-11-01 00:00 UTC were left out."},
		{`null`, "Not complete: hours left out by earlier runs are still to be read again."},
	}
	for _, c := range cases {
		spendServer(t, 200, `{"data":{"run":{"rows":3,"from":1,"to":2,"complete":false,"error":null,"note":null},"connection_id":3,"left_out":`+c.leftOut+`}}`)
		_, stderr, err := executeCommand("spend", "sync", "run", "3")
		if err != nil {
			t.Fatalf("spend sync run: %v", err)
		}
		if !strings.Contains(stderr, c.want) || strings.Contains(stderr, "Not complete: .") {
			t.Errorf("stderr = %q, want %q", stderr, c.want)
		}
	}
}

func TestSpendConversionsLimitAndHints(t *testing.T) {
	seen := spendServer(t, 200, `{"data":[],"from_day":"2026-11-01","to_day":"2026-11-07","truncated":true,"limit":20}`)
	_, stderr, err := executeCommand("spend", "conversions", "--limit", "20")
	if err != nil {
		t.Fatalf("spend conversions --limit 20: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains((*seen)[0].Query, "limit=20") {
		t.Fatalf("requests = %+v, want limit=20 sent", *seen)
	}
	if !strings.Contains(stderr, "More rows than --limit 20") {
		t.Errorf("stderr = %q, want the truncated note", stderr)
	}

	for _, bad := range []string{"0", "5001", "12abc", "-1"} {
		seen := spendServer(t, 200, `{}`)
		_, _, err := executeCommand("--json", "spend", "conversions", "--limit", bad)
		if err == nil || exitCodeForError(err) != ExitValidation || len(*seen) != 0 {
			t.Errorf("--limit %s: err %v, exit %d, %d request(s); want refused before the server", bad, err, exitCodeForError(err), len(*seen))
		}
	}

	for field, want := range map[string]string{"time_from": "--time-from", "time_to": "--time-to", "platform": "--platform", "limit": "--limit", "period": "--period"} {
		spendServer(t, 422, `{"error":true,"status":422,"message":"Invalid `+field+`","field_errors":{"`+field+`":"no"}}`)
		_, _, err := executeCommand("--json", "spend", "conversions")
		hint := hintFor(err)
		if exitCodeForError(err) != ExitValidation || !strings.Contains(hint, want) || strings.Contains(hint, "spend sync") {
			t.Errorf("422 on %s: exit %d, hint %q; want one naming %s", field, exitCodeForError(err), hint, want)
		}
	}
}
