package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// ltvRequest is one request a command under test sent, body kept raw so a
// test can compare it byte for byte after normalizing.
type ltvRequest struct {
	Method   string
	Path     string
	RawQuery string
	Body     string
}

type ltvServer struct {
	*httptest.Server
	mu   sync.Mutex
	reqs []ltvRequest
}

// newLtvServer answers the client's capability probe (delete_dry_run and
// staged_writes on), records every other request, and answers each with
// respond(request) — or {"data":{}} when respond is nil.
func newLtvServer(t *testing.T, respond func(r ltvRequest) (int, string)) *ltvServer {
	t.Helper()
	s := &ltvServer{}
	s.Server = httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		req := ltvRequest{Method: r.Method, Path: strings.TrimPrefix(r.URL.Path, "/api/v3"), RawQuery: r.URL.RawQuery, Body: string(data)}
		s.mu.Lock()
		s.reqs = append(s.reqs, req)
		s.mu.Unlock()
		status, body := 200, `{"data":{}}`
		if respond != nil {
			status, body = respond(req)
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(s.Close)
	home := t.TempDir()
	setTestHome(t, home)
	writeTestConfig(t, home, s.URL, "test-api-key-1234")
	t.Cleanup(func() { api.SetStagedMode(false) })
	return s
}

func (s *ltvServer) seen() []ltvRequest {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]ltvRequest(nil), s.reqs...)
}

// only returns the single request the command sent, failing otherwise.
func (s *ltvServer) only(t *testing.T) ltvRequest {
	t.Helper()
	reqs := s.seen()
	if len(reqs) != 1 {
		t.Fatalf("want exactly one request, got %d: %+v", len(reqs), reqs)
	}
	return reqs[0]
}

// assertJSON compares a request body with the JSON it should be, as values
// (key order and spacing aside), numbers compared as written.
func assertJSON(t *testing.T, got, want string) {
	t.Helper()
	decode := func(s string) interface{} {
		var v interface{}
		if err := decodeOneJSON([]byte(s), &v); err != nil {
			t.Fatalf("not JSON: %s (%v)", s, err)
		}
		return v
	}
	if !reflect.DeepEqual(decode(got), decode(want)) {
		t.Errorf("body =\n  %s\nwant\n  %s", got, want)
	}
}

// queryOf parses a raw query, failing on a malformed one.
func queryOf(t *testing.T, raw string) url.Values {
	t.Helper()
	q, err := url.ParseQuery(raw)
	if err != nil {
		t.Fatalf("query %q: %v", raw, err)
	}
	return q
}

// ── Reads ───────────────────────────────────────────────────────────

func TestLtvCustomersSendsSearchSegmentAndDottedFieldFilters(t *testing.T) {
	srv := newLtvServer(t, nil)
	_, _, err := executeCommand("ltv", "customers", "--search", "acme", "--segment", "repeat",
		"--cf", "plan=pro", "--cf", "score.min=50", "--cf", "renewal.max=2026-12-31", "--limit", "20", "--offset", "40")
	if err != nil {
		t.Fatalf("ltv customers: %v", err)
	}
	req := srv.only(t)
	if req.Method != "GET" || req.Path != "/ltv/customers" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	// The server reads the cf.* keys from the raw query string with their
	// dots (PHP would rewrite cf.plan to cf_plan in $_GET), so the literal
	// dotted key must be on the wire.
	for _, want := range []string{"cf.plan=pro", "cf.score.min=50", "cf.renewal.max=2026-12-31", "q=acme", "segment=repeat", "limit=20", "offset=40"} {
		if !strings.Contains("&"+req.RawQuery+"&", "&"+want+"&") {
			t.Errorf("query %q lacks %q", req.RawQuery, want)
		}
	}
	if strings.Contains(req.RawQuery, "cf_") {
		t.Errorf("query %q carries an underscored cf key the server never reads", req.RawQuery)
	}
}

func TestLtvFieldFiltersReachEveryReadTheServerFiltersByThem(t *testing.T) {
	for _, tc := range []struct{ cmd, path string }{
		{"summary", "/ltv/summary"},
		{"breakdown", "/ltv/breakdown"},
		{"predict", "/ltv/predict"},
	} {
		t.Run(tc.cmd, func(t *testing.T) {
			srv := newLtvServer(t, nil)
			if _, _, err := executeCommand("ltv", tc.cmd, "--cf", "vip=true"); err != nil {
				t.Fatalf("ltv %s: %v", tc.cmd, err)
			}
			req := srv.only(t)
			if req.Path != tc.path || queryOf(t, req.RawQuery).Get("cf.vip") != "true" {
				t.Errorf("request = %s?%s", req.Path, req.RawQuery)
			}
		})
	}
}

func TestLtvBadFieldFiltersAreRefusedBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args []string
		want string
	}{
		{[]string{"--cf", "plan"}, "not key=value"},
		{[]string{"--cf", "=pro"}, "not key=value"},
		{[]string{"--cf", "Plan=pro"}, "not a custom field key"},
		{[]string{"--cf", "score.avg=5"}, "only .min or .max"},
		{[]string{"--cf", "score.min= "}, "no value"},
		{[]string{"--cf", "a=1", "--cf", "b=2", "--cf", "c=3", "--cf", "d=4"}, "at most 3"},
		{[]string{"--cf", "plan=pro", "--cf", "plan=free"}, "given twice"},
		{[]string{"--limit", "900"}, "--limit must be a whole number from 1 to 500"},
		{[]string{"--limit", "abc"}, "--limit must be"},
		{[]string{"--offset", "-3"}, "--offset must be"},
		{[]string{"--time_from", "2026-01-01"}, "unix timestamp"},
		{[]string{"--all", "--limit", "5"}, "does not combine"},
		{[]string{"7", "--search", "acme"}, "filters the customer list"},
		{[]string{"seven"}, "numeric"},
	}
	for _, tc := range cases {
		srv := newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "customers"}, tc.args...)...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want it to mention %q", tc.args, err, tc.want)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d", tc.args, code, ExitValidation)
		}
		if hintFor(err) == "" {
			t.Errorf("%v: no hint", tc.args)
		}
		if reqs := srv.seen(); len(reqs) != 0 {
			t.Errorf("%v: sent %+v", tc.args, reqs)
		}
	}
}

func TestLtvCustomersAllReadsEveryPageAt500(t *testing.T) {
	const total = 3
	srv := newLtvServer(t, func(r ltvRequest) (int, string) {
		q, _ := url.ParseQuery(r.RawQuery)
		offset := q.Get("offset")
		var rows []string
		if offset == "0" {
			rows = []string{`{"customer_id":1,"total_revenue":"10"}`, `{"customer_id":2,"total_revenue":"9"}`}
		} else {
			// The page shifted under the read: customer 2 shows again with a
			// new total. --all must keep one row for it, not two.
			rows = []string{`{"customer_id":2,"total_revenue":"11"}`, `{"customer_id":3,"total_revenue":"1"}`}
		}
		return 200, fmt.Sprintf(`{"data":[%s],"pagination":{"total":%d,"limit":500,"offset":%s}}`, strings.Join(rows, ","), total, offset)
	})
	stdout, _, err := executeCommand("ltv", "customers", "--all", "--cf", "plan=pro", "--json")
	if err != nil {
		t.Fatalf("ltv customers --all: %v", err)
	}
	reqs := srv.seen()
	if len(reqs) != 2 {
		t.Fatalf("want 2 page requests, got %+v", reqs)
	}
	for _, r := range reqs {
		q := queryOf(t, r.RawQuery)
		if q.Get("limit") != "500" || q.Get("cf.plan") != "pro" {
			t.Errorf("page request %q: want limit=500 and the filter on every page", r.RawQuery)
		}
	}
	var out struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("output is not JSON: %v\n%s", err, stdout)
	}
	if len(out.Data) != 3 {
		t.Errorf("rows = %d, want 3 (customer 2 once): %s", len(out.Data), stdout)
	}
}

func TestLtvCohortsAndProducts(t *testing.T) {
	srv := newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "cohorts", "--months", "12"); err != nil {
		t.Fatalf("ltv cohorts: %v", err)
	}
	if req := srv.only(t); req.Path != "/ltv/cohorts" || req.RawQuery != "months=12" {
		t.Errorf("cohorts request = %s?%s", req.Path, req.RawQuery)
	}

	srv = newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "products", "--limit", "10", "--offset", "5"); err != nil {
		t.Fatalf("ltv products: %v", err)
	}
	if req := srv.only(t); req.Path != "/ltv/products" || queryOf(t, req.RawQuery).Get("limit") != "10" || queryOf(t, req.RawQuery).Get("offset") != "5" {
		t.Errorf("products request = %s?%s", req.Path, req.RawQuery)
	}

	for _, months := range []string{"0", "25", "six"} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand("ltv", "cohorts", "--months", months)
		if err == nil || !strings.Contains(err.Error(), "1 to 24") || len(srv.seen()) != 0 {
			t.Errorf("--months %s: err = %v, requests %d; the server would clamp it silently", months, err, len(srv.seen()))
		}
	}
}

// A repeatable flag must start every command empty. The shell and the tests
// reset the flag tree between commands, and pflag's Set appends to a slice
// flag that was parsed before — the second command used to send the first
// one's filters too.
func TestRepeatableFlagsDoNotCarryIntoTheNextCommand(t *testing.T) {
	srv := newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "customers", "--cf", "plan=pro"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("ltv", "customers", "--cf", "tier=gold"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("ltv", "customers"); err != nil {
		t.Fatal(err)
	}
	reqs := srv.seen()
	if len(reqs) != 3 {
		t.Fatalf("requests: %+v", reqs)
	}
	if q := reqs[1].RawQuery; q != "cf.tier=gold" {
		t.Errorf("second command sent %q, want only its own filter", q)
	}
	if q := reqs[2].RawQuery; q != "" {
		t.Errorf("third command sent %q, want no filter at all", q)
	}
}

// ── Customer writes ─────────────────────────────────────────────────

func TestLtvCustomerUpsertSendsTheRecord(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"customer_id":42}}` })
	_, _, err := executeCommand("ltv", "customer", "upsert", "--customer-ref", "9f1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e",
		"--customer-ref-type", "email_md5", "--email", "ada@example.com", "--first-name", "Ada", "--company", "Acme",
		"--field", "plan=pro", "--alias", "esp_id=48213")
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/customers" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"customer_ref":"9f1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e","customer_ref_type":"email_md5",
		"email":"ada@example.com","first_name":"Ada","company":"Acme",
		"custom_fields":{"plan":"pro"},"aliases":[{"type":"esp_id","value":"48213"}]}`)
}

// A record flag given empty clears that field on the server; a flag not
// given is not sent at all, so the field keeps its value.
func TestLtvCustomerUpdateClearsWhatIsGivenEmptyAndSendsNothingElse(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 200, `{"data":{"customer_id":42}}` })
	_, _, err := executeCommand("ltv", "customer", "update", "42", "--phone", "", "--company", "",
		"--email", "ada@example.com", "--field", "plan=", "--field", "tier=gold")
	if err != nil {
		t.Fatalf("update: %v", err)
	}
	req := srv.only(t)
	if req.Method != "PATCH" || req.Path != "/ltv/customers/42" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"phone":"","company":"","email":"ada@example.com","custom_fields":{"plan":"","tier":"gold"}}`)
}

func TestLtvCustomerWritesRefuseBadInputBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args       []string
		want, hint string
	}{
		{[]string{"upsert", "--email", "a@b.co"}, "--customer-id or --customer-ref", "p202 ltv customers"},
		{[]string{"upsert", "--customer-id", "4", "--customer-ref", "x"}, "give one", "p202 ltv customers"},
		{[]string{"upsert", "--customer-id", "4"}, "nothing to change", "--email"},
		{[]string{"upsert", "--customer-id", "4", "--customer-ref-type", "esp_id", "--email", "a@b.co"}, "describes --customer-ref", ""},
		{[]string{"upsert", "--customer-id", "zero", "--email", "a@b.co"}, "positive whole number", "p202 ltv customers"},
		{[]string{"upsert", "--customer-ref", "x", "--field", "Plan=pro"}, "custom field key", "p202 ltv fields list"},
		{[]string{"upsert", "--customer-ref", "x", "--field", "plan=a", "--field", "plan=b"}, "given twice", ""},
		{[]string{"upsert", "--customer-ref", "x", "--alias", "fax=123"}, "not type=value", "esp_id"},
		{[]string{"update", "42"}, "no fields to update", "--phone"},
		{[]string{"update", "abc", "--email", "a@b.co"}, "numeric", "p202 ltv customers"},
		{[]string{"merge", "42"}, "--from is missing", "p202 ltv customers"},
		{[]string{"merge", "42", "--from", "42", "--force"}, "into itself", ""},
		{[]string{"alias", "add", "42"}, "--value is missing", "--type"},
	}
	for _, tc := range cases {
		srv := newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "customer"}, tc.args...)...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.args, err, tc.want)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d", tc.args, code)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q, want it to mention %q", tc.args, hintFor(err), tc.hint)
		}
		if reqs := srv.seen(); len(reqs) != 0 {
			t.Errorf("%v: sent %+v", tc.args, reqs)
		}
	}
}

func TestLtvCustomerMergeAsksFirstAndPostsTheSource(t *testing.T) {
	srv := newLtvServer(t, nil)
	answerPrompts(t, "")
	_, _, err := executeCommand("ltv", "customer", "merge", "42", "--from", "57")
	if err == nil || !strings.Contains(err.Error(), "nothing was done") || !strings.Contains(hintFor(err), "--force") {
		t.Fatalf("an unanswered merge must fail naming --force: %v / %q", err, hintFor(err))
	}
	if len(srv.seen()) != 0 {
		t.Fatalf("an unanswered merge sent %+v", srv.seen())
	}

	srv = newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "customer", "merge", "42", "--from", "57", "--force"); err != nil {
		t.Fatalf("merge --force: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/customers/42/merge" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"source_customer_id":57}`)
}

func TestLtvCustomerEraseIsPreviewedAndConfirmedAsAnErasure(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) {
		return 200, `{"data":{"dry_run":true,"action":"erase","mode":"anonymize","record":{"customer_id":42},"cascade":[]}}`
	})
	if _, _, err := executeCommand("ltv", "customer", "erase", "42", "--dry-run"); err != nil {
		t.Fatalf("erase --dry-run: %v", err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/customers/42" || req.RawQuery != "dry_run=1" {
		t.Errorf("preview = %s %s?%s", req.Method, req.Path, req.RawQuery)
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 204, `` })
	answerPrompts(t, "n\n")
	_, stderr, err := executeCommand("ltv", "customer", "erase", "42")
	if err != nil || !strings.Contains(stderr, "Erase customer 42") || !strings.Contains(stderr, "revenue history is kept") {
		t.Fatalf("the question must say erase and what stays: err %v, stderr %q", err, stderr)
	}
	if len(srv.seen()) != 0 {
		t.Fatalf("a declined erase sent %+v", srv.seen())
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 204, `` })
	stdout, stderr, err := executeCommand("ltv", "customer", "erase", "42", "--force")
	if err != nil {
		t.Fatalf("erase --force: %v", err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/customers/42" || req.RawQuery != "" {
		t.Errorf("erase = %s %s?%s", req.Method, req.Path, req.RawQuery)
	}
	// A 204 has no body: the success line is the only output there is.
	if !strings.Contains(stderr, "Customer 42 erased.") {
		t.Errorf("no success line for the 204: stdout %q stderr %q", stdout, stderr)
	}
}

func TestLtvCustomerAliasAddAndRemove(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"customer_id":42}}` })
	if _, _, err := executeCommand("ltv", "customer", "alias", "add", "42", "--type", "esp_id", "--value", "48213"); err != nil {
		t.Fatalf("alias add: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/customers/42/aliases" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"type":"esp_id","value":"48213"}`)

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 422, `{"message":"Alias already belongs to customer 57; use POST /ltv/customers/42/merge to combine records","field_errors":{"value":"Already mapped to another customer"}}`
	})
	_, _, err := executeCommand("ltv", "customer", "alias", "add", "42", "--value", "48213")
	if err == nil || !strings.Contains(hintFor(err), "p202 ltv customer merge 42 --from") || exitCodeForError(err) != ExitValidation {
		t.Errorf("a taken alias should point at merge: %v / %q", err, hintFor(err))
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 204, `` })
	_, stderr, err := executeCommand("ltv", "customer", "alias", "remove", "42", "7", "--force")
	if err != nil {
		t.Fatalf("alias remove: %v", err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/customers/42/aliases/7" {
		t.Errorf("request = %s %s", req.Method, req.Path)
	}
	if !strings.Contains(stderr, "Alias 7 deleted from customer 42.") {
		t.Errorf("stderr = %q", stderr)
	}

	newLtvServer(t, func(ltvRequest) (int, string) { return 404, `{"message":"Alias not found on this customer"}` })
	_, _, err = executeCommand("ltv", "customer", "alias", "remove", "42", "9", "--force")
	if err == nil || !strings.Contains(hintFor(err), "p202 ltv customers 42") {
		t.Errorf("a 404 should name where alias ids are: %v / %q", err, hintFor(err))
	}
}

// ── Companies ───────────────────────────────────────────────────────

func TestLtvCompanyWrites(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"company_id":3}}` })
	if _, _, err := executeCommand("ltv", "company", "create", "--name", "Acme Corp", "--domain", "acme.com"); err != nil {
		t.Fatalf("create: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/companies" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"name":"Acme Corp","domain":"acme.com"}`)

	// --domain "" clears it; --name not given is not sent.
	srv = newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "company", "update", "3", "--domain", ""); err != nil {
		t.Fatalf("update: %v", err)
	}
	req = srv.only(t)
	if req.Method != "PATCH" || req.Path != "/ltv/companies/3" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"domain":""}`)

	srv = newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "company", "merge", "3", "--from", "9", "--force"); err != nil {
		t.Fatalf("merge: %v", err)
	}
	req = srv.only(t)
	if req.Path != "/ltv/companies/3/merge" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"source_company_id":9}`)

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 409, `{"message":"Company \"Acme Corp\" already exists (#3); use PATCH /ltv/companies/3 to modify it"}`
	})
	_, _, err := executeCommand("ltv", "company", "create", "--name", "Acme Corp")
	if err == nil || !strings.Contains(hintFor(err), "p202 ltv company update") || exitCodeForError(err) != ExitValidation {
		t.Errorf("a duplicate company should point at update/merge: %v / %q", err, hintFor(err))
	}

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 422, `{"message":"Company still has 2 attached customer(s); merge it into another company instead"}`
	})
	_, _, err = executeCommand("ltv", "company", "delete", "3", "--force")
	if err == nil || !strings.Contains(hintFor(err), "p202 ltv company merge") {
		t.Errorf("a company with customers should point at merge: %v / %q", err, hintFor(err))
	}

	for _, args := range [][]string{
		{"create"},
		{"create", "--name", "Acme", "--domain", ""},
		{"update", "3"},
		{"update", "3", "--name", ""},
		{"merge", "3", "--from", "3", "--force"},
	} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "company"}, args...)...)
		if err == nil || exitCodeForError(err) != ExitValidation || hintFor(err) == "" || len(srv.seen()) != 0 {
			t.Errorf("%v: err %v, hint %q, sent %d", args, err, hintFor(err), len(srv.seen()))
		}
	}
}

// ── Ingest ──────────────────────────────────────────────────────────

func TestLtvRevenueRecordSendsExactNumbersAndItems(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) {
		return 201, `{"data":{"event_id":9,"customer_id":42,"duplicate":false}}`
	})
	_, stderr, err := executeCommand("ltv", "revenue", "record", "--customer-ref", "CUST-77", "--customer-crm", `{"email":"ada@example.com"}`,
		"--amount", "49.90", "--event-type", "one_time", "--currency", "usd", "--occurred-at", "1767225600",
		"--item", `{"sku":"PRO-1","name":"Pro","quantity":1,"unit_price":49.90}`,
		"--idempotency-key", "ORD-1001", "--external-ref", "ORD-1001", "--transaction-id", "ch_1")
	if err != nil {
		t.Fatalf("revenue record: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/revenue" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	// 49.90 travels as written, a JSON number, not a float re-rendered.
	if !strings.Contains(req.Body, `"amount":49.90`) {
		t.Errorf("amount not sent as written: %s", req.Body)
	}
	assertJSON(t, req.Body, `{"customer_ref":"CUST-77","customer_crm":{"email":"ada@example.com"},"amount":49.90,
		"event_type":"one_time","currency":"USD","occurred_at":1767225600,
		"items":[{"sku":"PRO-1","name":"Pro","quantity":1,"unit_price":49.90}],
		"idempotency_key":"ORD-1001","external_ref":"ORD-1001","transaction_id":"ch_1"}`)
	if strings.Contains(stderr, "already recorded") {
		t.Errorf("a new event was reported as a duplicate: %q", stderr)
	}

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 200, `{"data":{"event_id":9,"customer_id":42,"duplicate":true}}`
	})
	_, stderr, err = executeCommand("ltv", "revenue", "record", "--customer-id", "42", "--amount", "5", "--idempotency-key", "ORD-1001")
	if err != nil || !strings.Contains(stderr, "already recorded") || !strings.Contains(stderr, "event 9") {
		t.Errorf("a replayed key must say nothing new was recorded: %v / %q", err, stderr)
	}
}

func TestLtvRevenueRecordRefusesBadInputBeforeAnyRequest(t *testing.T) {
	dir := t.TempDir()
	itemsFile := filepath.Join(dir, "items.json")
	if err := os.WriteFile(itemsFile, []byte(`[{"sku":"A","quantity":1}] [{"sku":"B"}]`), 0o600); err != nil {
		t.Fatal(err)
	}
	cases := []struct {
		args []string
		want string
	}{
		{[]string{"--customer-ref", "x"}, "--amount is missing"},
		{[]string{"--customer-ref", "x", "--amount", "1e3"}, "plain decimal"},
		{[]string{"--customer-ref", "x", "--amount", "abc"}, "plain decimal"},
		{[]string{"--customer-ref", "x", "--amount", "-5"}, "negative"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--event-type", "gift"}, "--event-type must be one of"},
		{[]string{"--amount", "5"}, "--customer-id or --customer-ref"},
		{[]string{"--customer-id", "4", "--customer-crm", `{"email":"a@b.co"}`, "--amount", "5"}, "would be ignored"},
		{[]string{"--customer-ref", "x", "--customer-crm", `{"fax":"1"}`, "--amount", "5"}, `field "fax"`},
		{[]string{"--customer-ref", "x", "--customer-crm", `[1]`, "--amount", "5"}, "one JSON object"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--currency", "dollars"}, "3-letter"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--occurred-at", "2026-01-01"}, "unix time"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--idempotency-key", "void:conv:12"}, "reserves"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--item", `{"name":"no product"}`}, "names no product"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--item", `{"sku":"A","qty":2}`}, `field "qty"`},
		{[]string{"--customer-ref", "x", "--amount", "5", "--item", `{"sku":"A","quantity":"two"}`}, "JSON number"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--item", `{"sku":"A","quantity":0}`}, "greater than 0"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--item", `{"sku":"A"}`, "--items-file", itemsFile}, "exclusive"},
		{[]string{"--customer-ref", "x", "--amount", "5", "--items-file", itemsFile}, "not one JSON array"},
	}
	for _, tc := range cases {
		srv := newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "revenue", "record"}, tc.args...)...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.args, err, tc.want)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d", tc.args, code)
		}
		if reqs := srv.seen(); len(reqs) != 0 {
			t.Errorf("%v: sent %+v", tc.args, reqs)
		}
	}
}

func TestLtvEngagementEventSubscriptionProductAndNextOffer(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"engagement_id":1,"customer_id":42}}` })
	if _, _, err := executeCommand("ltv", "engagement-event", "record", "--customer-id", "42", "--event", "demo_requested", "--value", "12.5"); err != nil {
		t.Fatalf("engagement-event record: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/events" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"customer_id":42,"event":"demo_requested","value":12.5}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"subscriptionId":5,"customerId":42}}` })
	if _, _, err := executeCommand("ltv", "subscription", "upsert", "--external-sub-id", "sub_123", "--customer-ref", "CUST-77",
		"--amount", "29", "--interval", "month", "--interval-count", "3", "--status", "trialing", "--plan-name", "Pro",
		"--started-at", "1767225600", "--grace-days", "0"); err != nil {
		t.Fatalf("subscription upsert: %v", err)
	}
	req = srv.only(t)
	if req.Path != "/ltv/subscriptions" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"external_sub_id":"sub_123","customer_ref":"CUST-77","amount":29,"billing_interval":"month",
		"billing_interval_count":3,"status":"trialing","plan_name":"Pro","started_at":1767225600,"grace_days":0}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 200, `{"data":{"changed":false}}` })
	_, stderr, err := executeCommand("ltv", "subscription", "event", "sub_123", "--type", "renewal", "--amount", "29",
		"--transaction-id", "ch_889", "--period-end", "1769904000")
	if err != nil {
		t.Fatalf("subscription event: %v", err)
	}
	req = srv.only(t)
	if req.Path != "/ltv/subscriptions/sub_123/events" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"event_type":"renewal","amount":29,"transaction_id":"ch_889","current_period_end":1769904000}`)
	if !strings.Contains(stderr, "nothing changed") {
		t.Errorf("an idempotent replay must say so: %q", stderr)
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"product_id":8}}` })
	if _, _, err := executeCommand("ltv", "product", "upsert", "--sku", "PRO-1", "--name", "Pro plan", "--price", "49"); err != nil {
		t.Fatalf("product upsert: %v", err)
	}
	req = srv.only(t)
	if req.Path != "/ltv/products" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"sku":"PRO-1","name":"Pro plan","price":49}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"customer_id":42,"campaign_id":7}}` })
	if _, _, err := executeCommand("ltv", "next-offer", "impression", "42", "--campaign-id", "7"); err != nil {
		t.Fatalf("next-offer impression: %v", err)
	}
	req = srv.only(t)
	if req.Path != "/ltv/customers/42/next-offer/impression" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"campaign_id":7}`)

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 422, `{"message":"No current recommendation to record; pass campaign_id for the offer you delivered","field_errors":{"campaign_id":"Required when no recommendation is available"}}`
	})
	_, _, err = executeCommand("ltv", "next-offer", "impression", "42")
	if err == nil || !strings.Contains(hintFor(err), "--campaign-id") {
		t.Errorf("no recommendation should point at --campaign-id: %v / %q", err, hintFor(err))
	}

	for _, args := range [][]string{
		{"engagement-event", "record", "--customer-id", "42"},
		{"subscription", "upsert", "--external-sub-id", "s", "--customer-id", "4"},
		{"subscription", "upsert", "--external-sub-id", "s", "--customer-id", "4", "--amount", "-1"},
		{"subscription", "upsert", "--external-sub-id", "s", "--customer-id", "4", "--amount", "9", "--interval-count", "0"},
		{"subscription", "event", "sub_123"},
		{"subscription", "event", "sub_123", "--type", "cancel", "--amount", "5"},
		{"subscription", "event", "sub_123", "--type", "refund", "--period-end", "1769904000"},
		{"subscription", "event", "sub 123", "--type", "cancel"},
		{"subscription", "event", "sub_123", "--type", "renewal", "--idempotency-key", "sub:1"},
		{"product", "upsert", "--name", "Pro"},
		{"product", "upsert", "--sku", "A", "--price", "free"},
		{"next-offer", "impression", "42", "--campaign-id", "x"},
	} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv"}, args...)...)
		if err == nil || exitCodeForError(err) != ExitValidation || hintFor(err) == "" || len(srv.seen()) != 0 {
			t.Errorf("%v: err %v, hint %q, sent %d", args, err, hintFor(err), len(srv.seen()))
		}
	}
}

// ── Settings: fields, webhooks, integrations ────────────────────────

func TestLtvFieldsWebhooksIntegrations(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"field_id":3}}` })
	if _, _, err := executeCommand("ltv", "fields", "create", "--key", "plan", "--type", "select", "--option", "free", "--option", "pro, annual",
		"--label", "Plan", "--required", "--sort-order", "2"); err != nil {
		t.Fatalf("fields create: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/fields" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"field_key":"plan","field_type":"select","options":["free","pro, annual"],"label":"Plan","is_required":true,"sort_order":2}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 200, `{"data":[]}` })
	if _, _, err := executeCommand("ltv", "fields", "update", "3", "--required=false", "--label", "Tier"); err != nil {
		t.Fatalf("fields update: %v", err)
	}
	req = srv.only(t)
	if req.Method != "PATCH" || req.Path != "/ltv/fields/3" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"is_required":false,"label":"Tier"}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) {
		return 200, `{"data":{"dry_run":true,"action":"delete","record":{"field_id":3},"cascade":[{"resource":"customer-field-values","count":12}]}}`
	})
	if _, _, err := executeCommand("ltv", "fields", "delete", "3", "--dry-run"); err != nil {
		t.Fatalf("fields delete --dry-run: %v", err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/fields/3" || req.RawQuery != "dry_run=1" {
		t.Errorf("preview = %s %s?%s", req.Method, req.Path, req.RawQuery)
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"webhook_id":4,"secret":"s3cr3t"}}` })
	stdout, stderr, err := executeCommand("ltv", "webhooks", "create", "--url", "https://hooks.example.com/p202", "--events", "revenue.recorded,subscription.changed")
	if err != nil {
		t.Fatalf("webhooks create: %v", err)
	}
	req = srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/webhooks" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	// Always an array: the server reads anything else as "no events" and
	// subscribes the hook to every one.
	assertJSON(t, req.Body, `{"url":"https://hooks.example.com/p202","events":["revenue.recorded","subscription.changed"]}`)
	if !strings.Contains(stdout, "s3cr3t") || !strings.Contains(stderr, "store the secret now") {
		t.Errorf("the secret must be printed and called out once: stdout %q stderr %q", stdout, stderr)
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"integration_id":6}}` })
	if _, _, err := executeCommand("ltv", "integrations", "create", "--provider", "klaviyo", "--name", "Main", "--config", `{"list_id":"XyZ","sync":true}`); err != nil {
		t.Fatalf("integrations create: %v", err)
	}
	req = srv.only(t)
	if req.Method != "POST" || req.Path != "/ltv/integrations" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"provider":"klaviyo","name":"Main","config":{"list_id":"XyZ","sync":true}}`)

	configFile := filepath.Join(t.TempDir(), "c.json")
	if err := os.WriteFile(configFile, []byte(`{"api_key_ref":"vault:7"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"integration_id":7}}` })
	if _, _, err := executeCommand("ltv", "integrations", "create", "--provider", "stripe", "--config-file", configFile); err != nil {
		t.Fatalf("integrations create --config-file: %v", err)
	}
	assertJSON(t, srv.only(t).Body, `{"provider":"stripe","config":{"api_key_ref":"vault:7"}}`)

	for _, args := range [][]string{
		{"fields", "create"},
		{"fields", "create", "--key", "Plan"},
		{"fields", "create", "--key", "plan", "--type", "select"},
		{"fields", "create", "--key", "plan", "--option", "a"},
		{"fields", "create", "--key", "plan", "--type", "select", "--option", "a", "--option", "a"},
		{"fields", "create", "--key", "plan", "--sort-order", "-1"},
		{"fields", "update", "3"},
		{"webhooks", "create"},
		{"webhooks", "create", "--url", "hooks.example.com"},
		{"webhooks", "create", "--url", "https://h.example.com", "--events", "revenue.recored"},
		{"webhooks", "create", "--url", "https://h.example.com", "--events", "*,revenue.recorded"},
		{"webhooks", "create", "--url", "https://h.example.com", "--events", "revenue.recorded,,customer.updated"},
		{"integrations", "create"},
		{"integrations", "create", "--provider", "Klaviyo"},
		{"integrations", "create", "--provider", "k", "--config", `["a"]`},
		{"integrations", "create", "--provider", "k", "--config", `{"a":1}{"b":2}`},
		{"integrations", "create", "--provider", "k", "--config", `{}`, "--config-file", configFile},
	} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv"}, args...)...)
		if err == nil || exitCodeForError(err) != ExitValidation || hintFor(err) == "" || len(srv.seen()) != 0 {
			t.Errorf("%v: err %v, hint %q, sent %d", args, err, hintFor(err), len(srv.seen()))
		}
	}
}

// ── --staged ────────────────────────────────────────────────────────

// ltvReads are the ltv commands that only read. Every other runnable ltv
// command writes, and must refuse --staged before it sends anything: the
// server stages no /ltv write, and --staged promises the write is withheld.
// A new ltv command lands in one list or fails the test below.
var ltvReads = map[string]bool{
	"p202 ltv summary": true, "p202 ltv customers": true, "p202 ltv breakdown": true, "p202 ltv cohorts": true,
	"p202 ltv mrr": true, "p202 ltv predict": true, "p202 ltv products": true, "p202 ltv abm": true,
	"p202 ltv engagement": true, "p202 ltv subscriptions": true, "p202 ltv companies": true,
	"p202 ltv fields list": true, "p202 ltv webhooks list": true, "p202 ltv integrations list": true,
}

func TestEveryLtvWriteRefusesStagedBeforeSendingAnything(t *testing.T) {
	var writes []*cobra.Command
	var walk func(c *cobra.Command)
	walk = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			walk(sub)
		}
		if c.Runnable() && !ltvReads[c.CommandPath()] {
			writes = append(writes, c)
		}
	}
	walk(ltvCmd)
	if len(writes) < 20 {
		t.Fatalf("found %d ltv writes; the walk is not reaching the tree", len(writes))
	}
	sort.Slice(writes, func(i, j int) bool { return writes[i].CommandPath() < writes[j].CommandPath() })
	for _, c := range writes {
		path := strings.Fields(c.CommandPath())[1:]
		var err error
		var srv *ltvServer
		// Cobra checks the argument count before RunE; grow numeric
		// arguments until the command takes them.
		for n := 0; n <= 3; n++ {
			srv = newLtvServer(t, nil)
			args := append([]string{}, path...)
			for i := 0; i < n; i++ {
				args = append(args, "1")
			}
			_, _, err = executeCommand(append(args, "--staged")...)
			if err != nil && strings.Contains(err.Error(), "cannot be staged") {
				break
			}
		}
		where := c.CommandPath()
		if err == nil || !strings.Contains(err.Error(), "cannot be staged") {
			t.Errorf("%s --staged: err = %v, want the staged refusal", where, err)
			continue
		}
		if exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "Drop --staged") {
			t.Errorf("%s: exit %d, hint %q", where, exitCodeForError(err), hintFor(err))
		}
		if reqs := srv.seen(); len(reqs) != 0 {
			t.Errorf("%s --staged sent %+v", where, reqs)
		}
	}
	t.Logf("%d ltv writes refuse --staged", len(writes))
}

// A preview is a read: under --staged it still runs, and is not stamped.
func TestLtvDeletePreviewRunsUnderStaged(t *testing.T) {
	srv := newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "company", "delete", "3", "--dry-run", "--staged"); err != nil {
		t.Fatalf("delete --dry-run --staged: %v", err)
	}
	if req := srv.only(t); req.RawQuery != "dry_run=1" {
		t.Errorf("preview query = %q, want dry_run=1 and no staged", req.RawQuery)
	}
}

// ── conversion create (the LTV fields the API takes) ────────────────

func TestConversionCreateSendsTheLtvAndReversalFields(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"conv_id":5}}` })
	_, _, err := executeCommand("conversion", "create", "--click-id", "123", "--payout", "49", "--transaction-id", "ORD-1",
		"--conv-time", "1767225600", "--customer-ref", "CUST-77", "--customer-ref-type", "merchant_id",
		"--customer-crm", `{"first_name":"Ada"}`, "--item", `{"sku":"PRO-1","quantity":1,"unit_price":49}`)
	if err != nil {
		t.Fatalf("conversion create: %v", err)
	}
	req := srv.only(t)
	if req.Method != "POST" || req.Path != "/conversions" {
		t.Fatalf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"click_id":123,"payout":"49","transaction_id":"ORD-1","conv_time":1767225600,
		"customer_ref":"CUST-77","customer_ref_type":"merchant_id","customer_crm":{"first_name":"Ada"},
		"items":[{"sku":"PRO-1","quantity":1,"unit_price":49}]}`)

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"conv_id":6}}` })
	if _, _, err := executeCommand("conversion", "create", "--click-id", "123", "--transaction-id", "ORD-1",
		"--status", "reversed", "--reversal-id", "rev-9", "--customer-id", "42"); err != nil {
		t.Fatalf("reversal: %v", err)
	}
	assertJSON(t, srv.only(t).Body, `{"click_id":123,"transaction_id":"ORD-1","status":"reversed","reversal_id":"rev-9","customer_id":42}`)

	for _, tc := range []struct {
		args       []string
		want, hint string
	}{
		{[]string{"--reversal-id", "r"}, "add --status reversed", "--reversal-id"},
		{[]string{"--status", "reversed"}, "needs --transaction-id", "p202 click conversions 123"},
		{[]string{"--status", "approved", "--transaction-id", "t"}, "--status must be one of", ""},
		{[]string{"--item", `{"sku":"A"}`}, "line items need a customer", "revenue event"},
		{[]string{"--customer-id", "4", "--customer-ref", "x"}, "give one", ""},
		{[]string{"--customer-ref-type", "esp_id"}, "describes --customer-ref", ""},
		{[]string{"--customer-id", "4", "--customer-crm", `{"email":"a@b.co"}`}, "would be ignored", "p202 ltv customer update"},
		{[]string{"--conv-time", "yesterday"}, "unix time", ""},
		{[]string{"--click-id", "abc"}, "positive integer", "p202 click list"},
	} {
		srv := newLtvServer(t, nil)
		args := append([]string{"conversion", "create"}, tc.args...)
		if !containsString(tc.args, "--click-id") {
			args = append(args, "--click-id", "123")
		}
		_, _, err := executeCommand(args...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.args, err, tc.want)
			continue
		}
		if exitCodeForError(err) != ExitValidation {
			t.Errorf("%v: exit %d", tc.args, exitCodeForError(err))
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q, want %q", tc.args, hintFor(err), tc.hint)
		}
		if reqs := srv.seen(); len(reqs) != 0 {
			t.Errorf("%v: sent %+v", tc.args, reqs)
		}
	}
}

// The server is the one that knows a field's key and type; when it refuses
// a --cf filter, the hint names the command that lists them.
func TestLtvRefusedFieldFilterNamesTheFieldsCommand(t *testing.T) {
	newLtvServer(t, func(ltvRequest) (int, string) {
		return 422, `{"message":"Unknown custom field \"nosuch\" in filter","field_errors":{"cf.nosuch":"No such field"}}`
	})
	for _, args := range [][]string{
		{"ltv", "customers", "--cf", "nosuch=1"},
		{"ltv", "customers", "--cf", "nosuch=1", "--all"},
		{"ltv", "summary", "--cf", "nosuch=1"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 ltv fields list") {
			t.Errorf("%v: %v, exit %d, hint %q", args, err, exitCodeForError(err), hintFor(err))
		}
	}
}

// A subscription whose id needs URL escaping can be stored but never sent
// an event (the server matches the path segment as sent): the upsert says so.
func TestLtvSubscriptionUpsertWarnsWhenEventsCannotReachIt(t *testing.T) {
	newLtvServer(t, func(ltvRequest) (int, string) { return 201, `{"data":{"subscriptionId":2,"customerId":4}}` })
	_, stderr, err := executeCommand("ltv", "subscription", "upsert", "--external-sub-id", "sub 9", "--customer-id", "4", "--amount", "5")
	if err != nil || !strings.Contains(stderr, "cannot reach this subscription") {
		t.Errorf("err %v, stderr %q", err, stderr)
	}
	_, stderr, err = executeCommand("ltv", "subscription", "upsert", "--external-sub-id", "sub_9", "--customer-id", "4", "--amount", "5")
	if err != nil || strings.Contains(stderr, "cannot reach") {
		t.Errorf("a plain id warned: err %v, stderr %q", err, stderr)
	}
}

// API failures keep their category through the LTV hints: a 404 exits as
// the validation it is with the list command named, a 5xx as a server error.
func TestLtvApiErrorsKeepTheirCategoryAndNameTheListCommand(t *testing.T) {
	newLtvServer(t, func(ltvRequest) (int, string) { return 404, `{"message":"Customer not found"}` })
	_, _, err := executeCommand("ltv", "customer", "update", "999", "--email", "a@b.co")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 ltv customers") {
		t.Errorf("404: %v, exit %d, hint %q", err, exitCodeForError(err), hintFor(err))
	}

	newLtvServer(t, func(ltvRequest) (int, string) { return 500, `{"message":"Internal server error"}` })
	_, _, err = executeCommand("ltv", "revenue", "record", "--customer-id", "4", "--amount", "5")
	if err == nil || exitCodeForError(err) != ExitServer || !strings.Contains(hintFor(err), "p202 system health") {
		t.Errorf("500: %v, exit %d, hint %q", err, exitCodeForError(err), hintFor(err))
	}

	newLtvServer(t, func(ltvRequest) (int, string) { return 401, `{"message":"Invalid API key"}` })
	_, _, err = executeCommand("ltv", "fields", "delete", "3", "--force")
	if err == nil || exitCodeForError(err) != ExitAuth {
		t.Errorf("401: %v, exit %d", err, exitCodeForError(err))
	}
}
