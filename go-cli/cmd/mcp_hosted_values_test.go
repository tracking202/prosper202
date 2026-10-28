package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"runtime"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"
)

// tinyRows is a list answer of n rows of {"a":0}: two values a row, the
// shape that costs a decoder the most memory per byte.
func tinyRows(n int) []byte {
	return []byte(`{"data":[` + strings.TrimSuffix(strings.Repeat(`{"a":0},`, n), ",") +
		fmt.Sprintf(`],"pagination":{"total":%d,"limit":%d,"offset":0}}`, n, n))
}

// valuesInstall answers the version and capability reads as an install does
// and every other GET with answer(path), recording each data request.
type valuesInstall struct {
	mu     sync.Mutex
	data   []string
	answer func(path string) []byte
	// capabilities, when set, is the capabilities answer.
	capabilities []byte
}

func (f *valuesInstall) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
			return
		case "/api/v3/capabilities":
			if f.capabilities != nil {
				_, _ = w.Write(f.capabilities)
				return
			}
			_, _ = w.Write([]byte(`{"data":{"features":{"staged_writes":true,"delete_dry_run":true},"cli":true,"principal":{"user_id":1,"roles":["Super user"],"scopes":["*"]}}}`))
			return
		}
		f.mu.Lock()
		f.data = append(f.data, r.Method+" "+r.URL.RequestURI())
		f.mu.Unlock()
		_, _ = w.Write(f.answer(r.URL.Path))
	}))
	t.Cleanup(srv.Close)
	// A call leaves the flags it parsed set until the next one resets them;
	// a test that reads a command directly (its Args) must not see them.
	t.Cleanup(func() { resetAllFlags(rootCmd) })
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	return srv
}

func (f *valuesInstall) requests() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.data...)
}

// allocated is what fn allocates, in bytes: the decode of an answer is most
// of what a call allocates, so it shows whether one happened.
func allocated(fn func()) uint64 {
	var before, after runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&before)
	fn()
	runtime.ReadMemStats(&after)
	return after.TotalAlloc - before.TotalAlloc
}

func wantValuesRefusal(t *testing.T, tool string, err error, stdout string) {
	t.Helper()
	if err == nil {
		t.Fatalf("%s: an answer past the value bound was taken; printed %d bytes", tool, len(stdout))
	}
	if !api.AnswerTooManyValues(err) || exitCodeForError(err) != ExitValidation || api.ErrorCategory(err) != "validation" {
		t.Fatalf("%s: %v (exit %d, category %q), want the value refusal", tool, err, exitCodeForError(err), api.ErrorCategory(err))
	}
	if want := fmt.Sprintf("more than %d JSON values", hostedAnswerValues); !strings.Contains(err.Error(), want) {
		t.Fatalf("%s: %q does not say %q", tool, err, want)
	}
	hint := hintFor(err)
	if !strings.Contains(hint, "smaller limit (with offset for the next page)") || !strings.Contains(hint, tool+" takes: ") {
		t.Fatalf("%s: hint %q does not say how to ask for less and what the tool takes", tool, hint)
	}
	if stdout != "" {
		t.Fatalf("%s: printed %d bytes of a refused answer", tool, len(stdout))
	}
}

// A hosted call refuses an answer with more values than it decodes, before
// decoding it: nothing is printed and the call allocates a fraction of what
// the decode would. A native call decodes and prints the same answer.
func TestHostedCallRefusesAnAnswerPastTheValueBound(t *testing.T) {
	rows := 130000 // 1 MiB of {"a":0}, the relay's whole read budget
	f := &valuesInstall{answer: func(string) []byte { return tinyRows(rows) }}
	f.server(t)

	withHostedRun(t, true)
	var stdout string
	var err error
	hosted := allocated(func() { stdout, _, err = mcpExecute(t, "mcp", "call", "campaign_list", "--input", "{}", "--read-only") })
	wantValuesRefusal(t, "campaign_list", err, stdout)
	if hint := hintFor(err); !strings.Contains(hint, "limit") || !strings.Contains(hint, "offset") {
		t.Fatalf("hint %q does not list limit and offset among what campaign_list takes", hint)
	}
	if n := len(f.requests()); n != 1 {
		t.Fatalf("the hosted call made %d data requests, want 1", n)
	}

	mcpHostedRun = false
	native := allocated(func() { stdout, _, err = mcpExecute(t, "mcp", "call", "campaign_list", "--input", "{}", "--read-only") })
	if err != nil {
		t.Fatalf("native: %v", err)
	}
	var printed struct {
		Data []map[string]interface{} `json:"data"`
	}
	if jerr := json.Unmarshal([]byte(stdout), &printed); jerr != nil || len(printed.Data) != rows {
		t.Fatalf("native printed %d rows (%v), want %d", len(printed.Data), jerr, rows)
	}
	t.Logf("allocated: hosted %d KB, native %d KB", hosted>>10, native>>10)
	if hosted > 16<<20 || hosted*4 > native {
		t.Fatalf("the hosted call allocated %d KB against the native call's %d KB: the refused answer was decoded", hosted>>10, native>>10)
	}
}

// The bound covers all of a call's answers together: a command that reads
// several keeps each one's rows, so two answers each under it but over it
// together are refused.
func TestHostedCallBoundCoversEveryAnswerOfTheCall(t *testing.T) {
	half := hostedAnswerValues * 3 / 5
	campaigns := make([]string, 0, half/2)
	for i := 1; len(campaigns) < half/2; i++ {
		campaigns = append(campaigns, fmt.Sprintf(`{"aff_campaign_id":%d}`, i))
	}
	stats := make([]string, 0, half/7)
	for i := 1; len(stats) < half/7; i++ {
		stats = append(stats, fmt.Sprintf(`{"id":%d,"total_clicks":1,"total_leads":0,"total_income":0,"total_cost":0,"total_net":0}`, i))
	}
	page := func(rows []string) []byte {
		return []byte(fmt.Sprintf(`{"data":[%s],"pagination":{"total":%d,"limit":%d,"offset":0}}`, strings.Join(rows, ","), len(rows), len(rows)))
	}
	f := &valuesInstall{answer: func(path string) []byte {
		if strings.HasSuffix(path, "/reports/breakdown") {
			return page(stats)
		}
		return page(campaigns)
	}}
	f.server(t)
	input := `{"with_stats":true}`

	withHostedRun(t, true)
	stdout, _, err := mcpExecute(t, "mcp", "call", "campaign_list", "--input", input, "--read-only")
	wantValuesRefusal(t, "campaign_list", err, stdout)
	if hits := f.requests(); len(hits) != 2 || !strings.Contains(hits[1], "/reports/breakdown") {
		t.Fatalf("the hosted call asked %q, want the campaigns then their stats", hits)
	}

	mcpHostedRun = false
	stdout, _, err = mcpExecute(t, "mcp", "call", "campaign_list", "--input", input, "--read-only")
	if err != nil || !strings.Contains(stdout, `"total_clicks":1`) {
		t.Fatalf("native with_stats: %v (printed %d bytes)", err, len(stdout))
	}
}

// Every read tool the hosted server offers reads the install through the
// bound: answered past it, each is refused before anything is printed. One
// that decoded an answer some other way would print it or fail otherwise.
func TestHostedReadToolsRefuseAnswersPastTheValueBound(t *testing.T) {
	withHostedRun(t, true)
	over := tinyRows(hostedAnswerValues/2 + 100)
	checked := 0
	for _, s := range mcpToolSpecs {
		if !s.readOnly() || s.NotHosted {
			continue
		}
		f := &valuesInstall{answer: func(string) []byte { return over }}
		f.server(t)
		input := map[string]string{}
		for _, a := range s.Args {
			input[a.Name] = "1"
			if a.Pattern == mcpChangeID {
				input[a.Name] = "chg_1"
			}
		}
		for name, value := range mcpReadToolRequired[s.Name] {
			input[name] = value
		}
		body, err := json.Marshal(input)
		if err != nil {
			t.Fatal(err)
		}
		stdout, stderr, err := mcpExecute(t, "mcp", "call", s.Name, "--input", string(body), "--read-only")
		if len(f.requests()) == 0 {
			t.Errorf("%s asked the install for nothing (err %v); give it the arguments it needs in mcpReadToolRequired", s.Name, err)
			continue
		}
		checked++
		if s.Name == "whoami" {
			// Its one data read, the user record, is optional: the answer
			// says it is unavailable, and why, and the call notes that part
			// of the answer is missing.
			if err != nil || !strings.Contains(stdout, `"user_name_unavailable"`) || !strings.Contains(stdout, "JSON values") {
				t.Errorf("whoami: %v, printed %s", err, stdout)
			}
			if !strings.Contains(stderr, "Note: part of this answer is missing") || !strings.Contains(stderr, "whoami takes: ") {
				t.Errorf("whoami: no note that part of the answer is missing: %q", stderr)
			}
			continue
		}
		if err == nil || !api.AnswerTooManyValues(err) || exitCodeForError(err) != ExitValidation || stdout != "" {
			t.Errorf("%s: %v (exit %d), printed %d bytes; want the value refusal and nothing printed", s.Name, err, exitCodeForError(err), len(stdout))
			continue
		}
		if !strings.Contains(hintFor(err), s.Name+" takes: ") {
			t.Errorf("%s: hint %q", s.Name, hintFor(err))
		}
	}
	if checked < 35 {
		t.Fatalf("checked %d read tools; the hosted server offers more", checked)
	}
}

// A hosted call's arguments are counted before they are decoded: a list
// argument becomes one flag an item, and 131,000 items grew an engine to
// 588 MiB. A native call leaves them to the command.
func TestHostedCallRefusesArgumentsPastTheValueBound(t *testing.T) {
	// 3 values for the object, id and the list; the rest are offers.
	offers := func(n int) string {
		return `{"id":"1","offer":[` + strings.TrimSuffix(strings.Repeat(`"campaign:1",`, n), ",") + `]}`
	}
	f := &valuesInstall{answer: func(string) []byte { return []byte(`{"data":{}}`) }}
	f.server(t)

	withHostedRun(t, true)
	_, _, err := mcpExecute(t, "mcp", "call", "landing_page_code", "--input", offers(hostedArgumentValues-2), "--read-only")
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("over the bound: %v", err)
	}
	if want := fmt.Sprintf("the arguments for landing_page_code hold more than %d JSON values", hostedArgumentValues); !strings.Contains(err.Error(), want) || !strings.Contains(hintFor(err), "Send less") {
		t.Fatalf("over the bound: %v (hint %q)", err, hintFor(err))
	}
	if hits := f.requests(); len(hits) != 0 {
		t.Fatalf("refused arguments reached the install: %q", hits)
	}
	// At the bound the command reads them, and refuses more than 100 offers itself.
	_, _, err = mcpExecute(t, "mcp", "call", "landing_page_code", "--input", offers(hostedArgumentValues-3), "--read-only")
	if err == nil || strings.Contains(err.Error(), "JSON values") || !strings.Contains(err.Error(), "at most 100 offers") {
		t.Fatalf("at the bound: %v", err)
	}

	mcpHostedRun = false
	_, _, err = mcpExecute(t, "mcp", "call", "landing_page_code", "--input", offers(hostedArgumentValues-2), "--read-only")
	if err == nil || strings.Contains(err.Error(), "JSON values") || !strings.Contains(err.Error(), "at most 100 offers") {
		t.Fatalf("native: %v", err)
	}
}

// rotator_set_rules sends back the set rotator_get answers, whole: a hosted
// call takes it up to hostedDocumentArgumentValues, a set past that is
// refused before anything is sent, and the document at its bound costs less
// than the list arguments at theirs.
func TestHostedCallTakesARuleSetAsLargeAsRotatorGetAnswers(t *testing.T) {
	// 4 values for the object, id, rules and dry_run; 17 a rule.
	set := func(n int) string {
		rule := `{"rule_name":"r","splittest":1,"criteria":[{"type":"country","statement":"is","value":"United States(US)"}],` +
			`"redirects":[{"redirect_url":"https://a.example/","weight":50,"name":"A"},{"redirect_url":"https://b.example/","weight":50,"name":"B"}]}`
		return `{"id":"7","dry_run":true,"rules":[` + strings.TrimSuffix(strings.Repeat(rule+",", n), ",") + `]}`
	}
	fits := (hostedDocumentArgumentValues - 4) / 17
	if n, over := api.JSONValuesOver([]byte(set(fits)), hostedDocumentArgumentValues); over || n != 4+17*fits {
		t.Fatalf("the set of %d rules holds %d values", fits, n)
	}
	if fits*17+4 <= hostedArgumentValues {
		t.Fatalf("a set of %d rules fits the bound of other arguments; the test shows nothing", fits)
	}
	f := &valuesInstall{answer: func(string) []byte { return []byte(`{"data":{"dry_run":true,"rotator_id":7,"rules":[],"changes":{}}}`) }}
	f.server(t)
	withHostedRun(t, true)

	var err error
	document := allocated(func() {
		_, _, err = mcpExecute(t, "mcp", "call", "rotator_set_rules", "--input", set(fits))
	})
	if err != nil {
		t.Fatalf("a set of %d rules: %v", fits, err)
	}
	if hits := f.requests(); len(hits) != 1 || hits[0] != "PUT /api/v3/rotators/7/rules?dry_run=1" {
		t.Fatalf("a set of %d rules asked %q", fits, hits)
	}

	_, _, err = mcpExecute(t, "mcp", "call", "rotator_set_rules", "--input", set(fits+1))
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("over the bound: %v", err)
	}
	if want := fmt.Sprintf("the arguments for rotator_set_rules hold more than %d JSON values", hostedDocumentArgumentValues); !strings.Contains(err.Error(), want) ||
		!strings.Contains(hintFor(err), "`p202 rotator set-rules`") {
		t.Fatalf("over the bound: %v (hint %q)", err, hintFor(err))
	}
	if hits := f.requests(); len(hits) != 1 {
		t.Fatalf("a refused set reached the install: %q", hits)
	}

	offers := `{"id":"1","offer":[` + strings.TrimSuffix(strings.Repeat(`"campaign:1",`, hostedArgumentValues-3), ",") + `]}`
	list := allocated(func() {
		_, _, _ = mcpExecute(t, "mcp", "call", "landing_page_code", "--input", offers, "--read-only")
	})
	// About the same (5.5 and 5.1 MB measured); a document read item by item,
	// as a list is, would cost several times what the list does.
	if document > list*3/2 {
		t.Fatalf("a set at its bound allocated %d bytes, the list arguments at theirs %d", document, list)
	}
}

// A write tool whose answer passes the bound says the install may have made
// the change, not how to ask for less (CLAUDE.md #13).
func TestHostedWriteToolRefusedAfterTheWriteSaysToLookFirst(t *testing.T) {
	f := &valuesInstall{answer: func(string) []byte { return tinyRows(hostedAnswerValues/2 + 100) }}
	f.server(t)
	withHostedRun(t, true)
	body, err := json.Marshal(mcpWriteToolInput["campaign_create"])
	if err != nil {
		t.Fatal(err)
	}
	stdout, _, err := mcpExecute(t, "mcp", "call", "campaign_create", "--input", string(body))
	if !api.AnswerTooManyValues(err) || exitCodeForError(err) != ExitValidation || stdout != "" {
		t.Fatalf("campaign_create: %v, printed %d bytes", err, len(stdout))
	}
	if !strings.Contains(err.Error(), "accepted the POST") || !strings.Contains(hintFor(err), "Look with the matching get or list tool before calling campaign_create again") {
		t.Fatalf("campaign_create: %v (hint %q)", err, hintFor(err))
	}
	if hits := f.requests(); len(hits) != 1 || !strings.HasPrefix(hits[0], "POST ") {
		t.Fatalf("asked %q", hits)
	}
}

// Once a call's answers pass the bound it sends nothing more: a delete of
// several ids that carries on past a failed one makes no further change.
func TestHostedCallSendsNothingAfterTheBound(t *testing.T) {
	f := &valuesInstall{answer: func(string) []byte { return tinyRows(hostedAnswerValues/2 + 100) }}
	f.server(t)
	withHostedRun(t, true)
	_, stderr, err := mcpExecute(t, "mcp", "call", "campaign_delete", "--input", `{"ids":"1,2,3"}`)
	if err == nil {
		t.Fatal("a delete whose answer passed the bound succeeded")
	}
	hits := f.requests()
	if len(hits) != 1 || !strings.HasPrefix(hits[0], "DELETE ") {
		t.Fatalf("sent %q after the first answer passed the bound, want the first delete only", hits)
	}
	for _, want := range []string{"accepted the DELETE", "DELETE campaigns/2 was not sent", "DELETE campaigns/3 was not sent"} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr does not say %q:\n%s", want, stderr)
		}
	}
	// The loop answers with its own partial failure; the next step is still
	// to look before deleting again.
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit %d, want %d (the delete loop's partial failure)", code, ExitPartialFailure)
	}
	if hint := hintFor(err); !strings.Contains(hint, "Look with the matching get or list tool before calling campaign_delete again") {
		t.Errorf("hint %q does not say to look before deleting again", hint)
	}
}

// A refusal the command wraps in an error of another category (a staged
// write's feature check reads capabilities, and calls a failure to read them
// a network error) is still the refusal: validation, with its hint, and the
// write is not sent.
func TestHostedRefusalWrappedByTheCommandIsStillTheRefusal(t *testing.T) {
	f := &valuesInstall{answer: func(string) []byte { return []byte(`{"data":{}}`) }, capabilities: tinyRows(hostedAnswerValues/2 + 100)}
	f.server(t)
	withHostedRun(t, true)
	t.Cleanup(func() { api.SetStagedMode(false) })
	body, err := json.Marshal(mcpWriteToolInput["campaign_create"])
	if err != nil {
		t.Fatal(err)
	}
	stdout, _, err := mcpExecute(t, "--staged", "mcp", "call", "campaign_create", "--input", string(body))
	if !api.AnswerTooManyValues(err) || stdout != "" {
		t.Fatalf("staged campaign_create: %v, printed %d bytes", err, len(stdout))
	}
	if code, category := exitCodeForError(err), api.ErrorCategory(err); code != ExitValidation || category != "validation" {
		t.Fatalf("staged campaign_create: exit %d, category %q; want the refusal's validation, not the wrapper's", code, category)
	}
	if !strings.Contains(hintFor(err), "Nothing that changes data was sent") {
		t.Fatalf("hint %q does not say the write was not sent", hintFor(err))
	}
	if hits := f.requests(); len(hits) != 0 {
		t.Fatalf("sent %q after the capabilities answer was refused", hits)
	}
}

// text_ad_update reads the stored ad before its write when the type or a
// link changes: an answer past the bound there stops the call with no PUT,
// and the refusal says nothing was changed.
func TestHostedTextAdUpdateRefusedAtItsReadSendsNoWrite(t *testing.T) {
	f := &valuesInstall{answer: func(string) []byte { return tinyRows(hostedAnswerValues/2 + 100) }}
	f.server(t)
	withHostedRun(t, true)
	stdout, _, err := mcpExecute(t, "mcp", "call", "text_ad_update", "--input", `{"id":"5","text_ad_type":"1"}`)
	if !api.AnswerTooManyValues(err) || exitCodeForError(err) != ExitValidation || stdout != "" {
		t.Fatalf("text_ad_update: %v, printed %d bytes", err, len(stdout))
	}
	if !strings.Contains(hintFor(err), "Nothing that changes data was sent") {
		t.Fatalf("hint %q does not say the write was not sent", hintFor(err))
	}
	if hits := f.requests(); len(hits) != 1 || hits[0] != "GET /api/v3/text-ads/5" {
		t.Fatalf("asked %q, want the one read of the ad", hits)
	}
}
