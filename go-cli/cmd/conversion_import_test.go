package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"p202/internal/api"
)

// convImportFake serves GET /clicks/{id}/conversions and POST /conversions the way
// ConversionsController and ClickBreakdown answer them, and records every request.
type convImportFake struct {
	mu            sync.Mutex
	clicks        map[int64]*fakeImportClick
	nextConv      int64
	keys          map[string]map[string]interface{} // Idempotency-Key -> recorded response
	total         int                               // every request, the client's handshake included
	gets          []int64
	posts         []convImportPost
	failPost      map[int64]bool  // POST answers 500
	postNotFound  map[int64]bool  // POST answers 404 Click not found
	dropPost      map[int64]bool  // POST closes the connection unanswered
	postReturns   map[int64]int64 // POST answers with this existing conversion
	ledgerHidden  map[int64]bool  // GET shows no conversions for the click
	getStatus     map[int64]int   // GET answers this status
	noLedgerRoute bool            // GET /clicks/{id}/conversions is not a route (older server)
	stringAmounts bool            // amounts are numeric strings, as servers before 1.9.76's numbers sent them
	// unflagged answers a duplicate as though it were new, and a repeat of a deleted
	// conversion with the 500 that hid it (servers before the duplicate flag).
	unflagged bool
}

type fakeImportClick struct {
	lead  bool
	convs []fakeImportConv
}

type fakeImportConv struct {
	id       int64
	txid     string
	reverses int64
	deleted  bool
	amount   string
	convTime int64
}

type convImportPost struct {
	Key    string
	Staged bool
	Body   map[string]interface{}
}

// sameFakeAmount compares a posted payout with a stored amount as Amount::toUnits does.
func sameFakeAmount(posted interface{}, stored string) bool {
	a, _, okA := parseImportAmount(fmt.Sprint(posted))
	b, _, okB := parseImportAmount(stored)
	return okA && okB && a == b
}

func newConvImportFake(clicks ...int64) *convImportFake {
	f := &convImportFake{clicks: map[int64]*fakeImportClick{}, nextConv: 1000, keys: map[string]map[string]interface{}{},
		failPost: map[int64]bool{}, postNotFound: map[int64]bool{}, dropPost: map[int64]bool{}, postReturns: map[int64]int64{},
		ledgerHidden: map[int64]bool{}, getStatus: map[int64]int{}}
	for _, c := range clicks {
		f.clicks[c] = &fakeImportClick{}
	}
	return f
}

// money is an amount as the server sends it: a JSON number (ConversionsController::present(),
// ClicksController::conversions()), or with stringAmounts the numeric string older servers sent.
func (f *convImportFake) money(amount string) interface{} {
	if _, err := strconv.ParseFloat(amount, 64); err != nil || f.stringAmounts {
		return amount
	}
	return json.Number(amount)
}

func (f *convImportFake) conversions() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, c := range f.clicks {
		n += len(c.convs)
	}
	return n
}

func (f *convImportFake) server(t *testing.T) *httptest.Server {
	t.Helper()
	inner := withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		switch {
		case r.Method == http.MethodGet && strings.HasPrefix(r.URL.Path, "/api/v3/clicks/") && strings.HasSuffix(r.URL.Path, "/conversions"):
			id, _ := strconv.ParseInt(strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/api/v3/clicks/"), "/conversions"), 10, 64)
			f.gets = append(f.gets, id)
			if f.noLedgerRoute {
				w.WriteHeader(404)
				w.Write([]byte(`{"error":true,"message":"Not found","status":404}`))
				return
			}
			if st := f.getStatus[id]; st != 0 {
				w.WriteHeader(st)
				fmt.Fprintf(w, `{"error":true,"message":"status %d","status":%d}`, st, st)
				return
			}
			c, ok := f.clicks[id]
			if !ok {
				w.WriteHeader(404)
				w.Write([]byte(`{"error":true,"message":"Click not found","status":404}`))
				return
			}
			rows := []map[string]interface{}{}
			lead := c.lead
			if f.ledgerHidden[id] {
				lead = false
			} else {
				for _, cv := range c.convs {
					amount := cv.amount
					if amount == "" {
						amount = "5.00000"
					}
					row := map[string]interface{}{"conv_id": cv.id, "click_id": id, "amount": f.money(amount), "deleted": cv.deleted, "transaction_id": nil, "reverses_conv_id": nil}
					if cv.txid != "" {
						row["transaction_id"] = cv.txid
					}
					if cv.reverses != 0 {
						row["reverses_conv_id"] = cv.reverses
					}
					if cv.convTime != 0 {
						row["conv_time"] = cv.convTime
					}
					rows = append(rows, row)
				}
			}
			json.NewEncoder(w).Encode(map[string]interface{}{"data": rows, "click": map[string]interface{}{"click_id": id, "lead": lead}})
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/conversions":
			raw, _ := io.ReadAll(r.Body)
			var body map[string]interface{}
			_ = json.Unmarshal(raw, &body)
			key := r.Header.Get("Idempotency-Key")
			staged := r.URL.Query().Get("staged") == "1"
			f.posts = append(f.posts, convImportPost{Key: key, Staged: staged, Body: body})
			click := int64(body["click_id"].(float64))
			if staged {
				w.WriteHeader(202)
				fmt.Fprintf(w, `{"data":{"change_id":"chg_%024d","status":"staged","method":"POST","path":"/conversions"}}`, len(f.posts))
				return
			}
			if prev, ok := f.keys[key]; ok && key != "" {
				// IdempotentCreate: the recorded response, as it was, plus the replay flag.
				replay := map[string]interface{}{"idempotent_replay": true}
				for k, v := range prev {
					replay[k] = v
				}
				w.WriteHeader(201)
				json.NewEncoder(w).Encode(replay)
				return
			}
			if f.dropPost[click] {
				conn, _, err := w.(http.Hijacker).Hijack()
				if err == nil {
					conn.Close()
				}
				return
			}
			if f.failPost[click] {
				w.WriteHeader(500)
				w.Write([]byte(`{"error":true,"message":"Failed to create conversion: Deadlock found when trying to get lock","status":500}`))
				return
			}
			c, ok := f.clicks[click]
			if !ok || f.postNotFound[click] {
				w.WriteHeader(404)
				w.Write([]byte(`{"error":true,"message":"Click not found or not owned by user","status":404}`))
				return
			}
			txid, _ := body["transaction_id"].(string)
			payout := "5.00000" // the campaign's default payout
			if p, ok := body["payout"].(string); ok {
				payout = p
			}
			answer := func(id int64, duplicate bool) {
				resp := map[string]interface{}{"data": map[string]interface{}{"conv_id": id, "click_id": click, "click_payout": f.money(payout), "transaction_id": txid, "source": "api"}}
				if duplicate && !f.unflagged {
					resp["duplicate"] = true
				}
				if key != "" {
					f.keys[key] = resp
				}
				w.WriteHeader(201)
				json.NewEncoder(w).Encode(resp)
			}
			if id, ok := f.postReturns[click]; ok {
				answer(id, true)
				return
			}
			// MysqlConversionRepository::recordLocked: a negative payout with a transaction id
			// reverses the sale with that id when one is on file, keyed apart from it.
			var reverses int64
			if strings.HasPrefix(payout, "-") && txid != "" {
				for _, cv := range c.convs {
					if cv.txid == txid && cv.reverses == 0 && !cv.deleted {
						reverses = cv.id
						break
					}
				}
			}
			for _, cv := range c.convs {
				if txid != "" && cv.txid == txid && cv.reverses == reverses {
					switch {
					case cv.deleted && f.unflagged:
						w.WriteHeader(500)
						w.Write([]byte(`{"error":true,"message":"The write to conversion completed, but the request could not be finished afterwards.","status":500}`))
					case cv.deleted:
						// ConversionsController::create(): a deleted row keeps its ledger key.
						w.WriteHeader(409)
						fmt.Fprintf(w, `{"error":true,"message":"Conversion %d on click %d had transaction id \"%s\" and was deleted.","status":409,"details":{"conv_id":%d,"click_id":%d,"deleted":true}}`,
							cv.id, click, txid, cv.id, click)
					case cv.amount != "" && body["payout"] != nil && !sameFakeAmount(body["payout"], cv.amount):
						// ConversionsController::refuseADifferentSale(): the transaction id located
						// the conversion, and the request states another payout.
						w.WriteHeader(422)
						fmt.Fprintf(w, `{"error":true,"message":"The sale with transaction_id \"%s\" on click %d is already recorded as conversion %d, and this request states a different sale (payout recorded %s, sent %v). Nothing was recorded.","status":422,"field_errors":{"transaction_id":"Already recorded as conversion %d with a different payout; a different sale needs its own transaction_id"}}`,
							txid, click, cv.id, cv.amount, body["payout"], cv.id)
					default:
						answer(cv.id, true)
					}
					return
				}
			}
			f.nextConv++
			c.convs = append(c.convs, fakeImportConv{id: f.nextConv, txid: txid, reverses: reverses, amount: payout})
			c.lead = true
			answer(f.nextConv, false)
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"error":true,"message":"Not found","status":404}`))
		}
	})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.total++
		f.mu.Unlock()
		inner(w, r)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func setupConvImportFake(t *testing.T, f *convImportFake) {
	t.Helper()
	srv := f.server(t)
	home := t.TempDir()
	setTestHome(t, home)
	writeTestConfig(t, home, srv.URL, "test-key")
}

func writeImportFile(t *testing.T, name, content string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), name)
	if err := os.WriteFile(p, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

type importResult struct {
	Data []conversionImportRow `json:"data"`
	Meta struct {
		Format  string                 `json:"format"`
		Columns map[string]interface{} `json:"columns"`
		DryRun  bool                   `json:"dry_run"`
		Summary map[string]interface{} `json:"summary"`
	} `json:"meta"`
}

func decodeImport(t *testing.T, stdout string) importResult {
	t.Helper()
	var res importResult
	if err := json.Unmarshal([]byte(stdout), &res); err != nil {
		t.Fatalf("stdout is not the import JSON: %v\n%s", err, stdout)
	}
	return res
}

func importByRow(res importResult) map[int]conversionImportRow {
	out := map[int]conversionImportRow{}
	for _, r := range res.Data {
		out[r.Row] = r
	}
	return out
}

// --- Columns and input ---

func TestConversionImportDetectsCommonHeadersAndReportsThem(t *testing.T) {
	setTestHome(t, t.TempDir()) // no configuration: an offline dry run needs none
	file := writeImportFile(t, "export.csv", "Conversion Date,Sub-ID,Commission ($),Transaction ID,Status\n"+
		"2026-02-03 14:05:00,12345,\"$1,200.50\",A-1001,approved\n")
	stdout, stderr, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v\n%s", err, stderr)
	}
	res := decodeImport(t, stdout)
	want := map[string]interface{}{"subid": "Sub-ID", "payout": "Commission ($)", "transaction_id": "Transaction ID", "conv_time": "Conversion Date"}
	for k, v := range want {
		if res.Meta.Columns[k] != v {
			t.Errorf("meta.columns[%s] = %v, want %q", k, res.Meta.Columns[k], v)
		}
	}
	if !strings.Contains(stderr, `subid="Sub-ID" (auto)`) {
		t.Errorf("stderr does not report the detected subid column:\n%s", stderr)
	}
	r := res.Data[0]
	if r.Status != importReady || r.ClickID != 12345 || r.Payout != "1200.50" || r.TransactionID != "A-1001" || r.Row != 2 {
		t.Errorf("row = %+v", r)
	}
	if want := time.Date(2026, 2, 3, 14, 5, 0, 0, time.UTC).Unix(); r.ConvTime != want {
		t.Errorf("conv_time = %d, want %d (UTC by default)", r.ConvTime, want)
	}
}

func TestConversionImportColumnFlagsOverrideDetection(t *testing.T) {
	setTestHome(t, t.TempDir())
	file := writeImportFile(t, "export.csv", "click_id,aff_sub,Net Sale,amount,when\n"+
		"999,12345,7.25,100,1770127500\n")

	// Two subid candidates: refused, naming the flag that picks one.
	_, _, err := executeCommand("conversion", "import", file, "--dry-run")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(err.Error(), `"click_id", "aff_sub"`) ||
		!strings.Contains(hintFor(err), "--subid-column") {
		t.Fatalf("ambiguous subid: err = %v, hint = %q", err, hintFor(err))
	}

	stdout, stderr, err := executeCommand("conversion", "import", file, "--dry-run", "--json",
		"--subid-column", "AFF_SUB", "--payout-column", "net sale", "--time-column", "when")
	if err != nil {
		t.Fatalf("with overrides: %v\n%s", err, stderr)
	}
	res := decodeImport(t, stdout)
	r := res.Data[0]
	if r.ClickID != 12345 || r.Payout != "7.25" || r.ConvTime != 1770127500 {
		t.Errorf("row = %+v, want click 12345, payout 7.25 from Net Sale, time from when", r)
	}
	if res.Meta.Columns["subid"] != "aff_sub" || res.Meta.Columns["payout"] != "Net Sale" || res.Meta.Columns["transaction_id"] != nil {
		t.Errorf("meta.columns = %v", res.Meta.Columns)
	}
	if !strings.Contains(stderr, `subid="aff_sub" (--subid-column)`) || !strings.Contains(stderr, "transaction id=(none)") {
		t.Errorf("stderr does not say how each column was chosen:\n%s", stderr)
	}
}

func TestConversionImportReadsJSONArrays(t *testing.T) {
	setTestHome(t, t.TempDir())
	file := writeImportFile(t, "conv.json", `[
		{"sub1": 12345, "payout": 12.5, "order_id": "O-1", "created_at": "2026-02-03T10:00:00-05:00"},
		{"sub1": "12346", "payout": null, "order_id": 77},
		{"sub1": 12347, "payout": "3", "order_id": {"id": 1}}
	]`)
	stdout, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	res := decodeImport(t, stdout)
	if res.Meta.Format != "json" {
		t.Errorf("meta.format = %q", res.Meta.Format)
	}
	rows := importByRow(res)
	if r := rows[1]; r.Status != importReady || r.ClickID != 12345 || r.Payout != "12.50" || r.TransactionID != "O-1" ||
		r.ConvTime != time.Date(2026, 2, 3, 15, 0, 0, 0, time.UTC).Unix() {
		t.Errorf("row 1 = %+v", r)
	}
	if r := rows[2]; r.Status != importReady || r.ClickID != 12346 || r.Payout != "" || r.TransactionID != "77" || r.ConvTime != 0 {
		t.Errorf("row 2 = %+v (null payout and absent time are left to the server)", r)
	}
	if r := rows[3]; r.Status != importInvalid || !strings.Contains(r.Reason, "transaction id is an object") {
		t.Errorf("row 3 = %+v, want invalid for an object transaction id", r)
	}
}

func TestConversionImportSniffsSemicolonDelimiter(t *testing.T) {
	setTestHome(t, t.TempDir())
	file := writeImportFile(t, "export.csv", "subid;payout\n12345;4.50\n12346;4,50\n")
	stdout, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	rows := decodeImport(t, stdout).Data
	if len(rows) != 2 {
		t.Fatalf("rows = %+v", rows)
	}
	if r := rows[0]; r.ClickID != 12345 || r.Payout != "4.50" {
		t.Errorf("row = %+v", r)
	}
	// A semicolon file is often a decimal-comma one. This test read "4,50" as 450, as the server's
	// parser then did; a comma is a thousands separator only between groups of three digits, so
	// the row is refused rather than sent at a hundred times its payout.
	if r := rows[1]; r.ClickID != 12346 || r.Status != importInvalid || r.Reason != `payout "4,50" is not a number` {
		t.Errorf("row = %+v", r)
	}
}

func TestConversionImportMarksInvalidRowsWithReasons(t *testing.T) {
	setTestHome(t, t.TempDir())
	long := strings.Repeat("x", 256)
	file := writeImportFile(t, "export.csv", "subid,payout,txid,date\n"+
		",1,a,\n"+ // 2 empty subid
		"abc,1,b,\n"+ // 3
		"0123,1,c,\n"+ // 4 leading zero
		"12.5,1,d,\n"+ // 5
		"99999999999999999999,1,e,\n"+ // 6 past bigint
		"12345,ten,f,\n"+ // 7
		"12345,1,g,yesterday\n"+ // 8
		"12345,-5,,\n"+ // 9 negative without transaction id
		"12345,1,"+long+",\n"+ // 10
		"12345,1e3,h,\n"+ // 11 exponent
		"12345,1,i,02/03/2026\n"+ // 12 ambiguous date
		"12345,$1.000005,j,2026-02-03\n") // 13 valid, rounded to 5 places like the server
	stdout, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	rows := importByRow(decodeImport(t, stdout))
	want := map[int]string{
		2:  "subid is empty",
		3:  `subid "abc" is not a Prosper202 click id`,
		4:  `subid "0123" is not a Prosper202 click id`,
		5:  `subid "12.5" is not`,
		6:  `subid "99999999999999999999" is not`,
		7:  `payout "ten" is not a number`,
		8:  `time "yesterday" is not a unix timestamp`,
		9:  "a negative payout needs the transaction id",
		10: "transaction id is 256 bytes",
		11: `payout "1e3" is not a number`,
		12: "pass --time-format",
	}
	for row, reason := range want {
		if r := rows[row]; r.Status != importInvalid || !strings.Contains(r.Reason, reason) || r.IdempotencyKey != "" {
			t.Errorf("row %d = %+v, want invalid with %q and no key", row, r, reason)
		}
	}
	if r := rows[13]; r.Status != importReady || r.Payout != "1.00001" {
		t.Errorf("row 13 = %+v, want ready with payout 1.00001", r)
	}
}

func TestConversionImportMarksDuplicatesWithinTheFile(t *testing.T) {
	setTestHome(t, t.TempDir())
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n"+
		"100,10,T1\n"+ // 2 ready
		"100,12,T1\n"+ // 3 same click + transaction id
		"100,10,T2\n"+ // 4 another sale on the click: ready
		"100,-10,T1\n"+ // 5 the reversal of T1: ready
		"101,10,T1\n"+ // 6 same id on another click: ready
		"200,5,\n"+ // 7 id-less: ready
		"200,5,\n"+ // 8 id-less again
		"100,5,\n") // 9 id-less on a click an earlier row converts
	stdout, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	rows := importByRow(decodeImport(t, stdout))
	for _, n := range []int{2, 4, 5, 6, 7} {
		if rows[n].Status != importReady {
			t.Errorf("row %d = %+v, want ready", n, rows[n])
		}
	}
	for n, reason := range map[int]string{3: "as row 2", 8: "row 7 already converts click 200", 9: "row 2 already converts click 100"} {
		if r := rows[n]; r.Status != importDuplicateInFile || !strings.Contains(r.Reason, reason) {
			t.Errorf("row %d = %+v, want duplicate_in_file naming %q", n, r, reason)
		}
	}
}

// --- Dry run ---

func TestConversionImportDryRunSendsNothing(t *testing.T) {
	f := newConvImportFake(100)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n404,10,T2\n")

	stdout, stderr, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	if f.total != 0 {
		t.Errorf("an offline dry run made %d request(s)", f.total)
	}
	res := decodeImport(t, stdout)
	if !res.Meta.DryRun || res.Meta.Summary["ready"] != float64(2) || res.Meta.Summary["ready_payout"] != "20.00" {
		t.Errorf("meta = %+v", res.Meta)
	}
	if !strings.Contains(stderr, "nothing was written") {
		t.Errorf("stderr = %s", stderr)
	}
}

func TestConversionImportDryRunCheckClicksReadsOnly(t *testing.T) {
	f := newConvImportFake(100, 101)
	f.clicks[101].convs = []fakeImportConv{{id: 55, txid: "T9", amount: "10.00000"}}
	f.clicks[101].lead = true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,10,T9\n404,10,T2\n100,1,T3\n")

	stdout, _, err := executeCommand("conversion", "import", file, "--dry-run", "--check-clicks", "--json")
	if err != nil {
		t.Fatalf("dry run: %v", err)
	}
	if len(f.posts) != 0 || f.conversions() != 1 {
		t.Fatalf("a dry run wrote: posts %+v", f.posts)
	}
	if len(f.gets) != 3 {
		t.Errorf("GETs = %v, want one per distinct click (100, 101, 404)", f.gets)
	}
	rows := importByRow(decodeImport(t, stdout))
	if rows[2].Status != importReady || rows[5].Status != importReady {
		t.Errorf("rows 2/5 = %+v / %+v, want ready", rows[2], rows[5])
	}
	if r := rows[3]; r.Status != importDuplicate || r.ConvID != 55 {
		t.Errorf("row 3 = %+v, want duplicate of conversion 55", r)
	}
	if r := rows[4]; r.Status != importClickNotFound {
		t.Errorf("row 4 = %+v, want click_not_found", r)
	}
}

// --- Idempotency keys ---

func TestConversionImportKeysAreStableAndPerRow(t *testing.T) {
	base := conversionImportRow{ClickID: 100, TransactionID: "T1", Payout: "10.00", ConvTime: 1770127500}
	same := base
	if conversionImportKey(base) != conversionImportKey(same) {
		t.Fatal("the same row got two keys")
	}
	seen := map[string]string{conversionImportKey(base): "base"}
	for name, r := range map[string]conversionImportRow{
		"click":  {ClickID: 101, TransactionID: "T1", Payout: "10.00", ConvTime: 1770127500},
		"txid":   {ClickID: 100, TransactionID: "T2", Payout: "10.00", ConvTime: 1770127500},
		"payout": {ClickID: 100, TransactionID: "T1", Payout: "10.01", ConvTime: 1770127500},
		"time":   {ClickID: 100, TransactionID: "T1", Payout: "10.00", ConvTime: 1770127501},
		"notime": {ClickID: 100, TransactionID: "T1", Payout: "10.00"},
		"shift":  {ClickID: 100, TransactionID: "T1\n\"10.00\"", Payout: ""},
	} {
		k := conversionImportKey(r)
		if other, dup := seen[k]; dup {
			t.Errorf("%s and %s share key %s", name, other, k)
		}
		seen[k] = name
	}

	// Across runs of the same file, and as sent.
	f := newConvImportFake(100, 101)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id,date\n100,10,T1,1770127500\n101,,,\n")
	first, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatal(err)
	}
	second, _, err := executeCommand("conversion", "import", file, "--dry-run", "--json")
	if err != nil {
		t.Fatal(err)
	}
	a, b := decodeImport(t, first).Data, decodeImport(t, second).Data
	if a[0].IdempotencyKey == "" || a[0].IdempotencyKey != b[0].IdempotencyKey || a[1].IdempotencyKey != b[1].IdempotencyKey {
		t.Errorf("keys changed between runs: %q/%q, %q/%q", a[0].IdempotencyKey, b[0].IdempotencyKey, a[1].IdempotencyKey, b[1].IdempotencyKey)
	}
	if a[0].IdempotencyKey != conversionImportKey(conversionImportRow{ClickID: 100, TransactionID: "T1", Payout: "10.00", ConvTime: 1770127500}) {
		t.Errorf("key %q is not the formula's", a[0].IdempotencyKey)
	}
	if _, _, err := executeCommand("conversion", "import", file, "--force", "--json"); err != nil {
		t.Fatal(err)
	}
	if len(f.posts) != 2 || f.posts[0].Key != a[0].IdempotencyKey || f.posts[1].Key != a[1].IdempotencyKey {
		t.Errorf("posts = %+v, want the planned keys", f.posts)
	}
	if body := f.posts[0].Body; body["click_id"] != float64(100) || body["transaction_id"] != "T1" || body["payout"] != "10.00" || body["conv_time"] != float64(1770127500) {
		t.Errorf("body = %v", body)
	}
	if body := f.posts[1].Body; len(body) != 1 || body["click_id"] != float64(101) {
		t.Errorf("an id-less row with no payout or time sends only the click: %v", body)
	}
}

// --- Apply ---

func TestConversionImportMapsEveryOutcome(t *testing.T) {
	f := newConvImportFake(100, 101, 102, 103, 104, 105, 106, 107)
	f.clicks[101].convs = []fakeImportConv{{id: 55, txid: "T2", amount: "10.00000"}}
	f.clicks[102].lead = true
	f.postNotFound[103] = true
	f.failPost[104] = true
	f.clicks[105].convs = []fakeImportConv{{id: 77, txid: "OTHER"}}
	f.postReturns[105] = 77
	f.clicks[106].convs = []fakeImportConv{{id: 60, txid: "T6", amount: "10.00000"}, {id: 61, txid: "T6", reverses: 60, amount: "-10.00000"}}
	f.clicks[107].convs = []fakeImportConv{{id: 62, txid: "T7", deleted: true}}
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n"+
		"100,10,T1\n"+ // 2 created
		"101,10,T2\n"+ // 3 duplicate: the click has T2
		"102,10,\n"+ // 4 duplicate: id-less on a converted click
		"999,10,T9\n"+ // 5 click_not_found (read)
		"103,10,T3\n"+ // 6 click_not_found (write)
		"104,10,T4\n"+ // 7 failed
		"105,,\n"+ // 8 duplicate: the server matched an existing conversion
		"106,-10,T6\n"+ // 9 duplicate: already reversed
		"107,10,T7\n"+ // 10 duplicate: recorded and deleted
		"abc,1,X\n") // 11 invalid

	stdout, stderr, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err == nil {
		t.Fatal("a failed row must fail the command")
	}
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", code, ExitPartialFailure)
	}
	if h := hintFor(err); !strings.Contains(h, "re-run the same command") || !strings.Contains(h, "only the failures") {
		t.Errorf("hint = %q", h)
	}
	res := decodeImport(t, stdout)
	if len(res.Data) != 10 {
		t.Fatalf("rendered %d rows, want all 10", len(res.Data))
	}
	rows := importByRow(res)
	want := map[int]struct {
		status, reason string
		conv           int64
	}{
		2:  {importCreated, "", 1001},
		3:  {importDuplicate, "same transaction id", 55},
		4:  {importDuplicate, "converts its click only once", 0},
		5:  {importClickNotFound, "no click 999", 0},
		6:  {importClickNotFound, "no click 103", 0},
		7:  {importFailed, "Deadlock", 0},
		8:  {importDuplicate, "answered with conversion 77", 77},
		9:  {importDuplicate, "already reverses", 61},
		10: {importDuplicate, "was deleted", 62},
		11: {importInvalid, "not a Prosper202 click id", 0},
	}
	for n, w := range want {
		r := rows[n]
		if r.Status != w.status || !strings.Contains(r.Reason, w.reason) || r.ConvID != w.conv {
			t.Errorf("row %d = %+v, want %s (%q, conv %d)", n, r, w.status, w.reason, w.conv)
		}
	}
	if rows[2].RecordedPayout != "10.00" {
		t.Errorf("row 2 recorded_payout = %q", rows[2].RecordedPayout)
	}
	// Rows the read already answered are never sent.
	var sent []int64
	for _, p := range f.posts {
		sent = append(sent, int64(p.Body["click_id"].(float64)))
	}
	if fmt.Sprint(sent) != "[100 103 104 105]" {
		t.Errorf("POSTed clicks = %v, want [100 103 104 105]", sent)
	}
	s := res.Meta.Summary
	if s["created"] != float64(1) || s["duplicate"] != float64(5) || s["click_not_found"] != float64(2) || s["failed"] != float64(1) ||
		s["invalid"] != float64(1) || s["payout_imported"] != "10.00" {
		t.Errorf("summary = %v", s)
	}
	if !strings.Contains(stderr, "10.00 recorded in payouts") {
		t.Errorf("stderr = %s", stderr)
	}
}

// The server's duplicate flag decides a row the read before the writes could not see: a
// conversion recorded between the read and the write, or one the read missed.
func TestConversionImportTrustsTheServersDuplicateFlag(t *testing.T) {
	f := newConvImportFake(100, 101, 102)
	f.clicks[100].convs = []fakeImportConv{{id: 55, txid: "T1", amount: "10.00000"}}
	f.clicks[101].convs = []fakeImportConv{{id: 56, txid: "T2", deleted: true}}
	f.ledgerHidden[100], f.ledgerHidden[101] = true, true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,10,T2\n102,3,T3\n")

	stdout, stderr, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("import: %v (a deleted conversion is not a failure)\n%s", err, stderr)
	}
	if len(f.posts) != 3 {
		t.Fatalf("POSTed %d rows, want all 3 (the read saw none of them)", len(f.posts))
	}
	res := decodeImport(t, stdout)
	rows := importByRow(res)
	if r := rows[2]; r.Status != importDuplicate || r.ConvID != 55 || !strings.Contains(r.Reason, "answered with conversion 55") {
		t.Errorf("row 2 = %+v, want duplicate of 55 by the server's flag", r)
	}
	if r := rows[3]; r.Status != importDuplicate || r.ConvID != 56 || !strings.Contains(r.Reason, "conversion 56 had this transaction id and was deleted") {
		t.Errorf("row 3 = %+v, want duplicate of the deleted 56", r)
	}
	if r := rows[4]; r.Status != importCreated {
		t.Errorf("row 4 = %+v, want created", r)
	}
	if s := res.Meta.Summary; s["created"] != float64(1) || s["duplicate"] != float64(2) || s["payout_imported"] != "3.00" {
		t.Errorf("summary = %v, want only T3's 3.00 imported", s)
	}
}

// A transaction id the click already has is that sale again only when the row states what was
// recorded. A corrected payout or time under it was reported duplicate and dropped; it is a
// conflict, never sent, and the import exits 5 naming what to do. A row the read could not see
// is refused by the server and lands as a conflict too.
func TestConversionImportReportsADifferentSaleAsAConflict(t *testing.T) {
	f := newConvImportFake(100, 101, 102, 103)
	f.clicks[100].convs = []fakeImportConv{{id: 55, txid: "T1", amount: "10.00000"}}
	f.clicks[101].convs = []fakeImportConv{{id: 56, txid: "T2", amount: "10.00000", convTime: 1770127500}}
	f.clicks[102].convs = []fakeImportConv{{id: 57, txid: "T3", amount: "10.00000"}}
	f.clicks[103].convs = []fakeImportConv{{id: 58, txid: "T4", amount: "10.00000"}}
	f.ledgerHidden[103] = true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id,date\n"+
		"100,12.50,T1,\n"+ // 2 conflict: another payout
		"101,10,T2,1770131100\n"+ // 3 conflict: another time
		"102,10.00,T3,\n"+ // 4 duplicate: the payout recorded, written another way
		"103,11,T4,\n") // 5 conflict: the server's refusal

	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err == nil {
		t.Fatal("a conflict must fail the command: the file states money that was not recorded")
	}
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", code, ExitPartialFailure)
	}
	if h := hintFor(err); !strings.Contains(h, "--status reversed") || !strings.Contains(h, "p202 click conversions") {
		t.Errorf("hint = %q", h)
	}
	rows := importByRow(decodeImport(t, stdout))
	want := map[int]struct {
		status, reason string
		conv           int64
	}{
		2: {importConflict, "payout recorded 10.00, this row 12.50", 55},
		3: {importConflict, "conv_time recorded 1770127500, this row 1770131100", 56},
		4: {importDuplicate, "same transaction id", 57},
		5: {importConflict, "Already recorded as conversion 58", 0},
	}
	for n, w := range want {
		r := rows[n]
		if r.Status != w.status || !strings.Contains(r.Reason, w.reason) || r.ConvID != w.conv {
			t.Errorf("row %d = %+v, want %s (%q, conv %d)", n, r, w.status, w.reason, w.conv)
		}
	}
	if len(f.posts) != 1 || f.posts[0].Body["click_id"] != float64(103) {
		t.Errorf("POSTs = %+v, want only the row the read could not see", f.posts)
	}
	if f.conversions() != 4 {
		t.Errorf("conversions = %d, want the 4 seeded and nothing recorded", f.conversions())
	}
}

// Servers before the duplicate flag answer a duplicate as though it were new; the conversion
// ids read before the writes still recognise it.
func TestConversionImportFallsBackToTheReadOnUnflaggedServers(t *testing.T) {
	f := newConvImportFake(105)
	f.unflagged = true
	f.clicks[105].convs = []fakeImportConv{{id: 77, txid: "OTHER"}}
	f.postReturns[105] = 77
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout\n105,\n")

	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("import: %v", err)
	}
	if len(f.posts) != 1 {
		t.Fatalf("POSTed %d rows, want 1", len(f.posts))
	}
	if r := importByRow(decodeImport(t, stdout))[2]; r.Status != importDuplicate || r.ConvID != 77 || !strings.Contains(r.Reason, "matched conversion 77, already recorded") {
		t.Errorf("row 2 = %+v, want duplicate of 77 from the read", r)
	}
}

// `conversion create` of a transaction id whose conversion was deleted is refused with a 409
// whose hint says why, not "update it instead of creating" (there is no conversion update).
func TestConversionCreateOfADeletedTransactionIDIsExplained(t *testing.T) {
	f := newConvImportFake(107)
	f.clicks[107].convs = []fakeImportConv{{id: 62, txid: "T7", deleted: true}}
	setupConvImportFake(t, f)

	_, _, err := executeCommand("conversion", "create", "--click_id", "107", "--transaction_id", "T7")
	if err == nil {
		t.Fatal("a deleted conversion's transaction id must be refused")
	}
	if id := api.DeletedConversionID(err); id != 62 {
		t.Errorf("DeletedConversionID = %d, want 62 (err %v)", id, err)
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	if h := hintFor(err); !strings.Contains(h, "never recorded again") || !strings.Contains(h, "p202 click conversions") || strings.Contains(h, "instead of creating") {
		t.Errorf("hint = %q", h)
	}
}

// `conversion create` of a transaction id the click has with another payout is refused (422
// naming transaction_id), and the hint says what a re-send and a different sale each need.
func TestConversionCreateOfADifferentSaleIsExplained(t *testing.T) {
	f := newConvImportFake(107)
	f.clicks[107].convs = []fakeImportConv{{id: 62, txid: "T7", amount: "10.00000"}}
	setupConvImportFake(t, f)

	_, _, err := executeCommand("conversion", "create", "--click_id", "107", "--transaction_id", "T7", "--payout", "20")
	if err == nil {
		t.Fatal("a different sale under a recorded transaction id must be refused")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	if h := hintFor(err); !strings.Contains(h, "p202 click conversions") || !strings.Contains(h, "--status reversed") {
		t.Errorf("hint = %q", h)
	}
	if f.conversions() != 1 {
		t.Errorf("conversions = %d, want 1", f.conversions())
	}
}

func TestConversionImportRerunRecordsNothingTwice(t *testing.T) {
	f := newConvImportFake(100, 101, 102)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,,\n102,3,T3\n")
	if _, _, err := executeCommand("conversion", "import", file, "--force"); err != nil {
		t.Fatalf("first run: %v", err)
	}
	if f.conversions() != 3 {
		t.Fatalf("first run recorded %d conversions, want 3", f.conversions())
	}

	// The ledger answers the transaction ids and the converted click: nothing is sent again.
	posts := len(f.posts)
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("second run: %v", err)
	}
	if len(f.posts) != posts || f.conversions() != 3 {
		t.Errorf("second run sent %d request(s) and left %d conversions", len(f.posts)-posts, f.conversions())
	}
	for _, r := range decodeImport(t, stdout).Data {
		if r.Status != importDuplicate {
			t.Errorf("row %d = %+v, want duplicate", r.Row, r)
		}
	}

	// Where the read cannot see them, the same Idempotency-Key is replayed instead.
	for id := range f.clicks {
		f.ledgerHidden[id] = true
	}
	stdout, _, err = executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("third run: %v", err)
	}
	if f.conversions() != 3 {
		t.Errorf("third run left %d conversions, want 3", f.conversions())
	}
	for _, r := range decodeImport(t, stdout).Data {
		if r.Status != importDuplicate || !strings.Contains(r.Reason, "Idempotency-Key was replayed") {
			t.Errorf("row %d = %+v, want duplicate by replay", r.Row, r)
		}
	}
}

func TestConversionImportSendsSalesBeforeTheirReversals(t *testing.T) {
	f := newConvImportFake(100, 200)
	setupConvImportFake(t, f)
	// Newest first, as network reports often are: the reversal precedes its sale.
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,-10,T1\n100,10,T1\n200,-4,T2\n")
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("import: %v", err)
	}
	var sent []string
	for _, p := range f.posts {
		sent = append(sent, p.Body["payout"].(string))
	}
	if fmt.Sprint(sent) != "[10.00 -10.00 -4.00]" {
		t.Errorf("payouts sent in order %v, want the sale before the reversals", sent)
	}
	c := f.clicks[100].convs
	if len(c) != 2 || c[1].reverses != c[0].id {
		t.Fatalf("click 100 = %+v, want the sale and a reversal of it", c)
	}
	rows := importByRow(decodeImport(t, stdout))
	if rows[2].Status != importCreated || rows[3].Status != importCreated || rows[4].Status != importCreated {
		t.Errorf("rows = %+v", rows)
	}

	// A re-run finds the sale, its reversal, and the negative row with no sale (which the server
	// would otherwise reverse): nothing is sent.
	posts := len(f.posts)
	stdout, _, err = executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("re-run: %v", err)
	}
	if len(f.posts) != posts {
		t.Errorf("the re-run sent %d request(s)", len(f.posts)-posts)
	}
	for _, r := range decodeImport(t, stdout).Data {
		if r.Status != importDuplicate {
			t.Errorf("re-run row %+v, want duplicate", r)
		}
	}
}

// Servers before amounts were numbers sent them as numeric strings; the ledger read takes
// both, so a re-run against one still finds the negative row it would otherwise send again.
func TestConversionImportReadsAnOlderServersStringAmounts(t *testing.T) {
	f := newConvImportFake(100, 200)
	f.stringAmounts = true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n100,-10,T1\n200,-4,T2\n")
	if _, _, err := executeCommand("conversion", "import", file, "--force", "--json"); err != nil {
		t.Fatalf("import: %v", err)
	}
	posts := len(f.posts)
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("re-run: %v", err)
	}
	if len(f.posts) != posts {
		t.Errorf("the re-run sent %d request(s)", len(f.posts)-posts)
	}
	for _, r := range decodeImport(t, stdout).Data {
		if r.Status != importDuplicate {
			t.Errorf("re-run row %+v, want duplicate", r)
		}
	}
}

// The server's click_payout is a JSON number; read as a float64 a $1,000,000 payout prints as
// 1e+06, which the amount parser refuses, and the row lost its recorded_payout.
func TestConversionImportReportsALargePayoutAsRecorded(t *testing.T) {
	f := newConvImportFake(100, 200)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout\n100,1000000\n200,0.00001\n")
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if err != nil {
		t.Fatalf("import: %v", err)
	}
	rows := importByRow(decodeImport(t, stdout))
	if rows[2].RecordedPayout != "1000000.00" || rows[3].RecordedPayout != "0.00001" {
		t.Errorf("recorded_payout = %q and %q, want 1000000.00 and 0.00001", rows[2].RecordedPayout, rows[3].RecordedPayout)
	}
}

func TestConversionImportStagedRecordsProposalsWithoutPrompting(t *testing.T) {
	f := newConvImportFake(100, 101)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,5,T2\n")
	// No --force and nothing on stdin: a proposal writes nothing, so there is no prompt.
	stdout, stderr, err := executeCommand("--staged", "conversion", "import", file, "--json")
	if err != nil {
		t.Fatalf("staged import: %v\n%s", err, stderr)
	}
	if len(f.posts) != 2 || !f.posts[0].Staged || !f.posts[1].Staged {
		t.Fatalf("posts = %+v, want two staged POSTs", f.posts)
	}
	if f.conversions() != 0 {
		t.Errorf("staging recorded %d conversions", f.conversions())
	}
	for _, r := range decodeImport(t, stdout).Data {
		if r.Status != importStaged || !strings.HasPrefix(r.ChangeID, "chg_") {
			t.Errorf("row %+v, want staged with a change id", r)
		}
	}
	if !strings.Contains(stderr, "p202 change apply") {
		t.Errorf("stderr = %s", stderr)
	}
}

func TestConversionImportAsksBeforeWriting(t *testing.T) {
	f := newConvImportFake(100)
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n")

	feedStdin(t, "n")
	stdout, stderr, err := executeCommand("conversion", "import", file)
	if err != nil {
		t.Fatalf("declined import: %v", err)
	}
	if len(f.posts) != 0 || strings.TrimSpace(stdout) != "" || !strings.Contains(stderr, "Cancelled.") ||
		!strings.Contains(stderr, "1 conversion(s) will be recorded: 10.00") {
		t.Fatalf("declined: posts %d, stdout %q, stderr %q", len(f.posts), stdout, stderr)
	}

	feedStdin(t, "y")
	if _, _, err := executeCommand("conversion", "import", file); err != nil {
		t.Fatalf("confirmed import: %v", err)
	}
	if len(f.posts) != 1 || f.conversions() != 1 {
		t.Errorf("confirmed: posts %d, conversions %d", len(f.posts), f.conversions())
	}
}

func TestConversionImportStopsAtAConnectionFailure(t *testing.T) {
	f := newConvImportFake(100, 101, 102)
	f.dropPost[101] = true
	setupConvImportFake(t, f)
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,10,T2\n102,10,T3\n")
	stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
	if exitCodeForError(err) != ExitPartialFailure || !strings.Contains(hintFor(err), "p202 config test") {
		t.Fatalf("err = %v (exit %d), hint = %q", err, exitCodeForError(err), hintFor(err))
	}
	rows := importByRow(decodeImport(t, stdout))
	if rows[2].Status != importCreated || rows[3].Status != importFailed || rows[4].Status != importFailed ||
		!strings.Contains(rows[4].Reason, "not sent: the import stopped at row 3 (network error)") {
		t.Errorf("rows = %+v", rows)
	}
	for _, p := range f.posts {
		if p.Body["click_id"] == float64(102) {
			t.Error("a row after the connection failure was sent")
		}
	}
}

func TestConversionImportReadFailuresBeforeAnyWrite(t *testing.T) {
	file := writeImportFile(t, "export.csv", "subid,payout,transaction_id\n100,10,T1\n101,10,T2\n")

	t.Run("a refused key stops the import with its exit code", func(t *testing.T) {
		f := newConvImportFake(100, 101)
		f.getStatus[100] = 401
		setupConvImportFake(t, f)
		stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
		if exitCodeForError(err) != ExitAuth || !strings.Contains(hintFor(err), "config set-key") {
			t.Fatalf("err = %v (exit %d), hint %q", err, exitCodeForError(err), hintFor(err))
		}
		if len(f.posts) != 0 || strings.TrimSpace(stdout) != "" {
			t.Errorf("posts %d, stdout %q", len(f.posts), stdout)
		}
	})
	t.Run("a server without the click ledger route is refused", func(t *testing.T) {
		f := newConvImportFake(100, 101)
		f.noLedgerRoute = true
		setupConvImportFake(t, f)
		_, _, err := executeCommand("conversion", "import", file, "--force")
		if err == nil || !strings.Contains(hintFor(err), "1.9.76") || len(f.posts) != 0 {
			t.Fatalf("err = %v, hint %q, posts %d", err, hintFor(err), len(f.posts))
		}
	})
	t.Run("one unreadable click fails only its rows", func(t *testing.T) {
		f := newConvImportFake(100, 101)
		f.getStatus[100] = 500
		setupConvImportFake(t, f)
		stdout, _, err := executeCommand("conversion", "import", file, "--force", "--json")
		if exitCodeForError(err) != ExitPartialFailure {
			t.Fatalf("err = %v (exit %d)", err, exitCodeForError(err))
		}
		rows := importByRow(decodeImport(t, stdout))
		if rows[2].Status != importFailed || !strings.Contains(rows[2].Reason, "could not read the click's conversions") || rows[3].Status != importCreated {
			t.Errorf("rows = %+v", rows)
		}
		if len(f.posts) != 1 {
			t.Errorf("posts = %d, want only click 101's", len(f.posts))
		}
	})
}

// --- Validation before any request ---

func TestConversionImportRefusesBadInputBeforeAnyRequest(t *testing.T) {
	dir := t.TempDir()
	cases := []struct {
		name    string
		content string // file content; "" = no such file
		args    []string
		msg     string
		hint    string
	}{
		{"missing file", "", nil, "cannot read", "Check the path"},
		{"empty file", " \n", nil, "is empty", "CSV file with a header row"},
		{"JSON object", `{"subid": 1}`, nil, "holds a JSON object", "Wrap the conversions"},
		{"malformed JSON", `[{"subid": 1},`, nil, "not a JSON array of objects", "Each element"},
		{"empty JSON array", `[]`, nil, "empty JSON array", "Export"},
		{"header only", "subid,payout\n", nil, "no conversions", "Export"},
		{"broken quote", "subid,payout\n1,\"4\n2,\"5\"x\n", nil, "not a readable CSV", "unbalanced quote"},
		{"no subid column", "user,payout\n1,2\n", nil, "no subid column", "--subid-column"},
		{"override names nothing", "subid,payout\n1,2\n", []string{"--payout-column", "commission"}, `the file's headers are: "subid", "payout"`, "Pass one of those headers"},
		{"one column for two roles", "subid,payout\n1,2\n", []string{"--payout-column", "subid"}, "both the subid and the payout", "--payout-column"},
		{"bad timezone", "subid\n1\n", []string{"--timezone", "Mars/Olympus"}, "not a time zone", "IANA"},
		{"time format without a time column", "subid\n1\n", []string{"--time-format", "01/02/2006"}, "no time column", "--time-column"},
		{"empty column flag", "subid\n1\n", []string{"--subid-column", " "}, "--subid-column was given an empty value", "auto-detect"},
		{"every row invalid", "subid,payout\nabc,1\nxyz,2\n", nil, "none of the 2 row(s) can be imported (row 2: subid \"abc\"", "--dry-run"},
	}
	for i, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := newConvImportFake(1)
			setupConvImportFake(t, f)
			path := filepath.Join(dir, fmt.Sprintf("case%d.csv", i))
			if tc.content != "" {
				if err := os.WriteFile(path, []byte(tc.content), 0o600); err != nil {
					t.Fatal(err)
				}
			}
			stdout, _, err := executeCommand(append([]string{"conversion", "import", path, "--force"}, tc.args...)...)
			if err == nil {
				t.Fatal("accepted")
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d", code, ExitValidation)
			}
			if !strings.Contains(err.Error(), tc.msg) || !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("err = %q, hint = %q; want %q / %q", err.Error(), hintFor(err), tc.msg, tc.hint)
			}
			if f.total != 0 || strings.TrimSpace(stdout) != "" {
				t.Errorf("%d request(s) made, stdout %q", f.total, stdout)
			}
		})
	}
	t.Run("two files", func(t *testing.T) {
		_, _, err := executeCommand("conversion", "import", "a.csv", "b.csv")
		if exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "conversion import <file") {
			t.Errorf("err = %v, hint = %q", err, hintFor(err))
		}
	})
}

// --- Parsers ---

func TestParseImportTime(t *testing.T) {
	ny, err := parseImportTimezone("America/New_York")
	if err != nil {
		t.Skipf("no zoneinfo here: %v", err)
	}
	minus5, err := parseImportTimezone("-05:00")
	if err != nil {
		t.Fatal(err)
	}
	feb3 := time.Date(2026, 2, 3, 14, 5, 0, 0, time.UTC).Unix()
	for _, tc := range []struct {
		raw, layout string
		loc         *time.Location
		want        int64
	}{
		{"1770127500", "", time.UTC, 1770127500},
		{"1770127500123", "", time.UTC, 1770127500},
		{"2026-02-03 14:05:00", "", time.UTC, feb3},
		{"2026-02-03T14:05:00", "", time.UTC, feb3},
		{"2026-02-03 14:05", "", time.UTC, feb3},
		{"2026-02-03 14:05:00.250", "", time.UTC, feb3},
		{"2026-02-03T14:05:00Z", "", ny, feb3},
		{"2026-02-03T09:05:00-05:00", "", time.UTC, feb3},
		{"2026-02-03 09:05:00", "", ny, feb3},
		{"2026-02-03 09:05:00", "", minus5, feb3},
		{"02/03/2026 9:05 AM", "01/02/2006 3:04 PM", ny, feb3},
		{"20260203", "", time.UTC, time.Date(2026, 2, 3, 0, 0, 0, 0, time.UTC).Unix()},
	} {
		got, why := parseImportTime(tc.raw, tc.layout, tc.loc)
		if why != "" || got != tc.want {
			t.Errorf("parseImportTime(%q, %q) = %d, %q; want %d", tc.raw, tc.layout, got, why, tc.want)
		}
	}
	for _, raw := range []string{"17701275", "177012750", "177012750012", "02/03/2026", "yesterday", "0", "0000000000"} {
		if _, why := parseImportTime(raw, "", time.UTC); why == "" {
			t.Errorf("parseImportTime(%q) was accepted", raw)
		}
	}
}

func TestParseImportAmountMatchesTheServer(t *testing.T) {
	for raw, want := range map[string]string{
		"12":           "12.00",
		"12.5":         "12.50",
		" $1,234.50 ":  "1234.50",
		"-3":           "-3.00",
		"0.123456":     "0.12346",
		"-0.000005":    "-0.00001",
		"1\u00a0000.1": "1000.10",
		"007.25":       "7.25",
		"1,000,000":    "1000000.00",
		"-1,234.50":    "-1234.50",
	} {
		_, got, ok := parseImportAmount(raw)
		if !ok || got != want {
			t.Errorf("parseImportAmount(%q) = %q, %v; want %q", raw, got, ok, want)
		}
	}
	// A comma is a thousands separator only between groups of three digits: a decimal comma was
	// read as one, so "12,50" was sent as 1250.00 and "1.234,56" as 1.23456.
	for _, raw := range []string{"", "ten", "1e3", "1.", ".5", "--1", "12345678901234", "€5",
		"12,50", "12,5", "1.234,56", "1,23", "1,2345", "1234,567", ",123", "123,", "1,,234", "0,125", "01,234"} {
		if _, got, ok := parseImportAmount(raw); ok {
			t.Errorf("parseImportAmount(%q) = %q, want refused", raw, got)
		}
	}
}

func TestParseImportClickIDMatchesClickIdParse(t *testing.T) {
	for raw, want := range map[string]int64{"1": 1, "12345": 12345, "9223372036854775807": 9223372036854775807} {
		if got, ok := parseImportClickID(raw); !ok || got != want {
			t.Errorf("parseImportClickID(%q) = %d, %v", raw, got, ok)
		}
	}
	for _, raw := range []string{"", "0", "-1", "+1", "0123", "12.0", "1e3", " 42", "9223372036854775808", "12a"} {
		if got, ok := parseImportClickID(raw); ok {
			t.Errorf("parseImportClickID(%q) = %d, want refused", raw, got)
		}
	}
}

func TestConversionCreateSaysWhenTheServerMatchedAnExistingConversion(t *testing.T) {
	f := newConvImportFake(107)
	setupConvImportFake(t, f)

	_, stderr, err := executeCommand("conversion", "create", "--click_id", "107", "--transaction_id", "T9")
	if err != nil {
		t.Fatalf("first create: %v", err)
	}
	if strings.Contains(stderr, "Note:") {
		t.Errorf("a new conversion carries no note, got %q", stderr)
	}

	stdout, stderr, err := executeCommand("conversion", "create", "--click_id", "107", "--transaction_id", "T9")
	if err != nil {
		t.Fatalf("second create: %v", err)
	}
	if !strings.Contains(stderr, "Note: the click already has this conversion") || !strings.Contains(stderr, "recorded nothing new") {
		t.Errorf("stderr = %q, want the duplicate note", stderr)
	}
	if strings.Contains(stdout, "Note:") {
		t.Errorf("the note belongs on stderr, stdout = %q", stdout)
	}
}
