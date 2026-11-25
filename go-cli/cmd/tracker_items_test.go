package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// trackerItemsFake answers the tracker routes and records what reached it.
type trackerItemsFake struct {
	mu       sync.Mutex
	requests []string
	bodies   []map[string]interface{}
	keys     []string
	nextID   int
	urlFails bool
	// answer decides a write's reply from its body; nil is a success.
	answer func(method string, body map[string]interface{}) (int, string)
}

func newTrackerItemsFake(t *testing.T) *trackerItemsFake {
	t.Helper()
	fake := &trackerItemsFake{nextID: 100}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		fake.mu.Lock()
		defer fake.mu.Unlock()
		fake.requests = append(fake.requests, r.Method+" "+r.URL.Path)
		var body map[string]interface{}
		if r.Method != "GET" {
			raw, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(raw, &body)
			fake.bodies = append(fake.bodies, body)
			fake.keys = append(fake.keys, r.Header.Get("Idempotency-Key"))
			if fake.answer != nil {
				if status, reply := fake.answer(r.Method, body); status != 0 {
					w.WriteHeader(status)
					_, _ = w.Write([]byte(reply))
					return
				}
			}
		}
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/trackers":
			id := fake.nextID
			fake.nextID++
			w.WriteHeader(201)
			fmt.Fprintf(w, `{"data":{"tracker_id":%d,"aff_campaign_id":%q}}`, id, body["aff_campaign_id"])
		case r.Method == "GET" && strings.HasSuffix(r.URL.Path, "/url") && fake.urlFails:
			w.WriteHeader(500)
			_, _ = w.Write([]byte(`{"message":"link failed"}`))
		case r.Method == "GET" && strings.HasSuffix(r.URL.Path, "/url"):
			id := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/api/v3/trackers/"), "/url")
			fmt.Fprintf(w, `{"data":{"tracker_id":%s,"direct_url":"https://trk.example/%s"}}`, id, id)
		case r.Method == "PUT" && strings.HasPrefix(r.URL.Path, "/api/v3/trackers/"):
			id := strings.TrimPrefix(r.URL.Path, "/api/v3/trackers/")
			if id == "404" {
				w.WriteHeader(404)
				_, _ = w.Write([]byte(`{"message":"Tracker not found"}`))
				return
			}
			fmt.Fprintf(w, `{"data":{"tracker_id":%s,"text_ad_id":%q}}`, id, body["text_ad_id"])
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return fake
}

func (f *trackerItemsFake) writes() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []string
	for _, r := range f.requests {
		if !strings.HasPrefix(r, "GET ") {
			out = append(out, r)
		}
	}
	return out
}

type itemsAnswer struct {
	Data []struct {
		Index       int                    `json:"index"`
		Status      string                 `json:"status"`
		TrackerID   int                    `json:"tracker_id"`
		ChangeID    string                 `json:"change_id"`
		TrackingURL map[string]interface{} `json:"tracking_url"`
		Error       map[string]interface{} `json:"error"`
	} `json:"data"`
	Summary map[string]int `json:"summary"`
}

func decodeItemsAnswer(t *testing.T, stdout string) itemsAnswer {
	t.Helper()
	var got itemsAnswer
	if err := json.Unmarshal([]byte(stdout), &got); err != nil {
		t.Fatalf("the answer is not JSON: %v\n%s", err, stdout)
	}
	return got
}

func TestTrackerItemsCreateAnswersEachItem(t *testing.T) {
	fake := newTrackerItemsFake(t)
	fake.answer = func(method string, body map[string]interface{}) (int, string) {
		if body["aff_campaign_id"] == "99" {
			return 422, `{"message":"Campaign 99 not found","category":"validation","field_errors":{"aff_campaign_id":"not found"}}`
		}
		return 0, ""
	}
	stdout, _, err := executeCommand("tracker", "create-with-url", "--json", "--t202kw={keyword}", "--items",
		`[{"aff_campaign_id": "1", "ppc_account_id": 7}, {"aff_campaign_id": "99"}, {"aff_campaign_id": 2, "text_ad_id": "4"}]`)
	if err == nil || exitCodeForError(err) != ExitPartialFailure {
		t.Fatalf("one refused item should end as a partial failure, got %v (exit %d)", err, exitCodeForError(err))
	}
	for _, want := range []string{"1 of 3 items were not done", "item 1 error", "item 0 (tracker 100)", "item 2 (tracker 101)"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("the error does not say %q: %v", want, err)
		}
	}
	if !strings.Contains(hintFor(err), "Send again only the items that were not done") {
		t.Errorf("hint = %q", hintFor(err))
	}
	got := decodeItemsAnswer(t, stdout)
	if len(got.Data) != 3 || got.Data[0].Status != "created" || got.Data[1].Status != "error" || got.Data[2].Status != "created" {
		t.Fatalf("answers = %+v", got.Data)
	}
	if got.Data[0].TrackerID != 100 || got.Data[2].TrackerID != 101 || got.Data[2].TrackingURL["direct_url"] != "https://trk.example/101" {
		t.Errorf("the created items do not carry their trackers and links: %+v", got.Data)
	}
	if got.Data[1].Error["category"] != "validation" || !strings.Contains(fmt.Sprint(got.Data[1].Error["message"]), "Campaign 99") {
		t.Errorf("the refused item does not carry the install's refusal: %+v", got.Data[1].Error)
	}
	if got.Summary["created"] != 2 || got.Summary["error"] != 1 {
		t.Errorf("summary = %v", got.Summary)
	}
	if len(fake.bodies) != 3 || fake.bodies[0]["ppc_account_id"] != "7" || fake.bodies[2]["aff_campaign_id"] != "2" {
		t.Errorf("bodies = %v; each item goes as one create, its numbers as text", fake.bodies)
	}
	for _, b := range fake.bodies {
		if _, has := b["items"]; has {
			t.Errorf("a create carried the list: %v", b)
		}
	}
}

// A create whose answer is not a refusal may have been stored: the items
// after it are not sent (CLAUDE.md #13), and it says how to look first.
func TestTrackerItemsCreateStopsWhenAWriteIsUnknown(t *testing.T) {
	fake := newTrackerItemsFake(t)
	posts := 0
	fake.answer = func(method string, body map[string]interface{}) (int, string) {
		posts++
		if posts == 2 {
			return 500, `{"message":"Internal error"}`
		}
		return 0, ""
	}
	stdout, _, err := executeCommand("tracker", "create-with-url", "--json", "--items",
		`[{"aff_campaign_id": "1"}, {"aff_campaign_id": "2"}, {"aff_campaign_id": "3"}]`)
	if err == nil || exitCodeForError(err) != ExitPartialFailure {
		t.Fatalf("got %v", err)
	}
	got := decodeItemsAnswer(t, stdout)
	if got.Data[1].Status != "unconfirmed" || got.Data[2].Status != "not_attempted" {
		t.Fatalf("answers = %+v", got.Data)
	}
	if !strings.Contains(fmt.Sprint(got.Data[1].Error["hint"]), "tracker list --aff-campaign-id 2") {
		t.Errorf("the unknown item does not say how to look: %v", got.Data[1].Error)
	}
	if w := fake.writes(); len(w) != 2 {
		t.Errorf("writes = %v; nothing is sent after a write whose outcome is unknown", w)
	}
}

// An item whose link read failed was created: it is never answered as a
// failure to send again.
func TestTrackerItemsCreatedWithoutALinkIsStillCreated(t *testing.T) {
	fake := newTrackerItemsFake(t)
	fake.urlFails = true
	stdout, _, err := executeCommand("tracker", "create-with-url", "--json", "--items",
		`[{"aff_campaign_id": "1"}, {"aff_campaign_id": "2"}]`)
	if err != nil {
		t.Fatalf("every item was created: %v", err)
	}
	got := decodeItemsAnswer(t, stdout)
	if got.Data[0].Status != "created" || got.Data[0].TrackerID != 100 || got.Data[1].TrackerID != 101 || got.Summary["created"] != 2 {
		t.Fatalf("answers = %+v", got)
	}
	if !strings.Contains(fmt.Sprint(got.Data[0].Error["hint"]), "do not send this item again") {
		t.Errorf("hint = %v", got.Data[0].Error)
	}
}

func TestTrackerItemsCreateKeysEachItem(t *testing.T) {
	fake := newTrackerItemsFake(t)
	if _, _, err := executeCommand("tracker", "create-with-url", "--json", "--idempotency-key=batch7", "--items",
		`[{"aff_campaign_id": "1"}, {"aff_campaign_id": "2"}]`); err != nil {
		t.Fatal(err)
	}
	if strings.Join(fake.keys, ",") != "batch7-0,batch7-1" {
		t.Errorf("Idempotency-Key headers = %v, want one per item", fake.keys)
	}
}

func TestTrackerItemsStagedAreProposals(t *testing.T) {
	fake := newTrackerItemsFake(t)
	fake.answer = func(method string, body map[string]interface{}) (int, string) {
		return 202, `{"data":{"change_id":"chg_abc","status":"pending"}}`
	}
	stdout, _, err := executeCommand("--staged", "tracker", "create-with-url", "--json", "--items", `[{"aff_campaign_id": "1"}]`)
	if err != nil {
		t.Fatal(err)
	}
	got := decodeItemsAnswer(t, stdout)
	if got.Data[0].Status != "staged" || got.Data[0].ChangeID != "chg_abc" || got.Summary["staged"] != 1 {
		t.Fatalf("answers = %+v", got)
	}
	if strings.Contains(strings.Join(fake.requests, " "), "/url") {
		t.Errorf("a proposal has no link to read: %v", fake.requests)
	}
}

func TestTrackerItemsUpdate(t *testing.T) {
	fake := newTrackerItemsFake(t)
	stdout, _, err := executeCommand("tracker", "update", "--json", "--items",
		`[{"id": "12", "text_ad_id": "4"}, {"id": 404, "text_ad_id": "5"}, {"id": "13", "text_ad_id": 6}]`)
	if err == nil || exitCodeForError(err) != ExitPartialFailure {
		t.Fatalf("got %v", err)
	}
	if w := strings.Join(fake.writes(), ","); w != "PUT /api/v3/trackers/12,PUT /api/v3/trackers/404,PUT /api/v3/trackers/13" {
		t.Errorf("writes = %s; a not-found tracker does not stop the others", w)
	}
	for _, b := range fake.bodies {
		if _, has := b["id"]; has || len(b) != 1 {
			t.Errorf("body = %v; the id names the tracker and is not written", b)
		}
	}
	got := decodeItemsAnswer(t, stdout)
	if got.Data[0].Status != "updated" || got.Data[1].Status != "error" || got.Data[2].Status != "updated" || got.Data[2].TrackerID != 13 {
		t.Fatalf("answers = %+v", got.Data)
	}
	if fake.bodies[2]["text_ad_id"] != "6" {
		t.Errorf("text_ad_id = %v", fake.bodies[2]["text_ad_id"])
	}
}

// An update whose outcome is unknown may be sent again as it is; the items
// after it are not sent, as the same failure would meet them.
func TestTrackerItemsUpdateStopsWhenAWriteIsUnknown(t *testing.T) {
	fake := newTrackerItemsFake(t)
	fake.answer = func(method string, body map[string]interface{}) (int, string) {
		return 503, `{"message":"down"}`
	}
	stdout, _, err := executeCommand("tracker", "update", "--json", "--items", `[{"id": "12", "text_ad_id": "4"}, {"id": "13", "text_ad_id": "5"}]`)
	if err == nil {
		t.Fatal("no error")
	}
	got := decodeItemsAnswer(t, stdout)
	if got.Data[0].Status != "unconfirmed" || got.Data[1].Status != "not_attempted" || len(fake.writes()) != 1 {
		t.Fatalf("answers = %+v, writes %v", got.Data, fake.writes())
	}
	if !strings.Contains(fmt.Sprint(got.Data[0].Error["hint"]), "can be sent again as it is") {
		t.Errorf("hint = %v", got.Data[0].Error["hint"])
	}
}

// Every refusal comes before any request, naming the item and the field.
func TestTrackerItemsAreCheckedBeforeAnyRequest(t *testing.T) {
	many := make([]string, trackerItemsMax+1)
	for i := range many {
		many[i] = `{"aff_campaign_id": "1"}`
	}
	for _, tc := range []struct {
		name string
		args []string
		want string
	}{
		{"not JSON", []string{"tracker", "create-with-url", "--items", `[{`}, "--items is not JSON"},
		{"not a list", []string{"tracker", "create-with-url", "--items", `{"aff_campaign_id": "1"}`}, "must be a JSON list"},
		{"empty", []string{"tracker", "create-with-url", "--items", `[]`}, "empty list"},
		{"too many", []string{"tracker", "create-with-url", "--items", "[" + strings.Join(many, ",") + "]"}, "at most 50"},
		{"not an object", []string{"tracker", "create-with-url", "--items", `["1"]`}, "item 0 is not an object"},
		{"unknown key", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1"}, {"campaign": "2"}]`}, "item 1 has campaign"},
		{"id on a create", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1", "id": "3"}]`}, "item 0 has id"},
		{"a boolean", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": true}]`}, "text or a number, not true"},
		{"null", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": null}]`}, "text or a number, not null"},
		{"missing required", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1"}, {"ppc_account_id": "2"}]`}, "item 1 has no aff_campaign_id, which a tracker needs"},
		{"enum", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1", "click_cloaking": "2"}]`}, "click_cloaking must be one of -1, 0, 1"},
		{"id form", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "12abc"}]`}, "must be a numeric id"},
		{"a fraction id", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": 1.5}]`}, "must be a numeric id"},
		{"empty value", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1", "click_cpc": ""}]`}, "click_cpc is empty"},
		{"duplicate key", []string{"tracker", "create-with-url", "--items", `[{"aff_campaign_id": "1", "aff_campaign_id": "2"}]`}, "given twice"},
		{"a field flag beside it", []string{"tracker", "create-with-url", "--aff-campaign-id=1", "--items", `[{"aff_campaign_id": "1"}]`}, "cannot be given with --items"},
		{"update without id", []string{"tracker", "update", "--items", `[{"text_ad_id": "4"}]`}, "item 0 has no id"},
		{"update id form", []string{"tracker", "update", "--items", `[{"id": "x", "text_ad_id": "4"}]`}, "id must be a numeric id"},
		{"update changes nothing", []string{"tracker", "update", "--items", `[{"id": "12"}]`}, "item 0 changes nothing"},
		{"update names one tracker twice", []string{"tracker", "update", "--items", `[{"id": "12", "text_ad_id": "4"}, {"id": 12, "text_ad_id": "5"}]`}, "items 0 and 1 both name tracker 12"},
		{"update with a positional", []string{"tracker", "update", "12", "--items", `[{"id": "12", "text_ad_id": "4"}]`}, "an id cannot be given with --items"},
		{"update with neither", []string{"tracker", "update", "--text-ad-id=4"}, "needs the tracker's id, or --items"},
		{"update field flag beside it", []string{"tracker", "update", "--text-ad-id=4", "--items", `[{"id": "12", "text_ad_id": "4"}]`}, "cannot be given with --items"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			fake := newTrackerItemsFake(t)
			_, _, err := executeCommand(tc.args...)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("got %v, want it to say %q", err, tc.want)
			}
			if exitCodeForError(err) != ExitValidation {
				t.Errorf("exit %d, want %d", exitCodeForError(err), ExitValidation)
			}
			if len(fake.requests) != 0 {
				t.Errorf("requests = %v; a refused list sends nothing", fake.requests)
			}
		})
	}
}

// The single tracker's command is as it was.
func TestTrackerUpdateWithOneIDIsUnchanged(t *testing.T) {
	fake := newTrackerItemsFake(t)
	if _, _, err := executeCommand("tracker", "update", "12", "--text-ad-id=4", "--json"); err != nil {
		t.Fatal(err)
	}
	if w := strings.Join(fake.writes(), ","); w != "PUT /api/v3/trackers/12" {
		t.Errorf("writes = %s", w)
	}
}

// runTrackerItemsUpdate does not read the stored record, which an entity's
// update Check does; the tracker has none.
func TestTrackerItemsEntityHasNoCheck(t *testing.T) {
	for _, e := range crudEntities {
		if e.Name == "tracker" && e.Check != nil {
			t.Fatal("the tracker entity has a Check; --items on tracker update must run it as registerCRUD's update does")
		}
	}
}

func TestTrackerItemsThroughMCP(t *testing.T) {
	fake := newTrackerItemsFake(t)
	stdout, _, err := executeCommand("mcp", "call", "tracker_create_with_url", "--input",
		`{"items": [{"aff_campaign_id": "1"}, {"aff_campaign_id": "2"}], "t202kw": "{keyword}"}`)
	if err != nil {
		t.Fatalf("tracker_create_with_url with items: %v", err)
	}
	got := decodeItemsAnswer(t, stdout)
	if len(got.Data) != 2 || got.Summary["created"] != 2 {
		t.Fatalf("answers = %+v", got)
	}
	resetCommandState()
	fake.requests, fake.bodies = nil, nil
	stdout, _, err = executeCommand("mcp", "call", "tracker_update", "--input",
		`{"items": [{"id": "100", "text_ad_id": "4"}, {"id": "101", "text_ad_id": "5"}]}`)
	if err != nil {
		t.Fatalf("tracker_update with items: %v", err)
	}
	if w := strings.Join(fake.writes(), ","); w != "PUT /api/v3/trackers/100,PUT /api/v3/trackers/101" {
		t.Errorf("writes = %s", w)
	}
	if decodeItemsAnswer(t, stdout).Summary["updated"] != 2 {
		t.Errorf("answer = %s", stdout)
	}
	if _, _, err := executeCommand("mcp", "call", "tracker_update", "--input", `{"text_ad_id": "4"}`); err == nil ||
		!strings.Contains(err.Error(), "needs the tracker's id, or --items") {
		t.Errorf("tracker_update with neither id nor items: %v", err)
	}
}

// A partial failure's answer reaches the model beside the error: without it,
// the items that were done would be sent again.
func TestMCPPartialFailureKeepsItsAnswer(t *testing.T) {
	res := mcpShapeResult(mcpCallResult{ExitCode: ExitPartialFailure, Stdout: []byte(`{"data":[{"index":0,"status":"created","tracker_id":100}]}`),
		Stderr: []byte(`{"error":{"category":"partial_failure"}}`)}, false, false)
	text := mcpResultText(res)
	if !res.IsError || !strings.Contains(text, "partial_failure") || !strings.Contains(text, `"tracker_id":100`) {
		t.Errorf("result = %v %q", res.IsError, text)
	}
}
