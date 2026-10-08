package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// clickFeed is a GET /clicks that behaves as the API does: newest first,
// time_from inclusive, limit/offset paging. Clicks can be added while a
// follow is running.
type clickFeed struct {
	mu       sync.Mutex
	clicks   []map[string]interface{}
	requests int
	// onRequest runs before each answer, with the request's number (1-based).
	onRequest func(n int, f *clickFeed)
	queries   []map[string]string
	failAt    int
}

func (f *clickFeed) add(id, at int64) {
	f.clicks = append(f.clicks, map[string]interface{}{
		"click_id": id, "click_time": strconv.FormatInt(at, 10), "keyword": "kw" + strconv.FormatInt(id, 10),
	})
}

func (f *clickFeed) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.requests++
	if f.onRequest != nil {
		f.onRequest(f.requests, f)
	}
	q := map[string]string{}
	for k := range r.URL.Query() {
		q[k] = r.URL.Query().Get(k)
	}
	f.queries = append(f.queries, q)
	if f.failAt > 0 && f.requests >= f.failAt {
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte(`{"error":true,"message":"boom","status":500}`))
		return
	}
	from, _ := strconv.ParseInt(q["time_from"], 10, 64)
	limit, _ := strconv.Atoi(q["limit"])
	offset, _ := strconv.Atoi(q["offset"])
	var rows []map[string]interface{}
	for _, c := range f.clicks {
		t, _ := strconv.ParseInt(c["click_time"].(string), 10, 64)
		if q["time_from"] == "" || t >= from {
			rows = append(rows, c)
		}
	}
	sort.Slice(rows, func(i, j int) bool {
		ti, _ := strconv.ParseInt(rows[i]["click_time"].(string), 10, 64)
		tj, _ := strconv.ParseInt(rows[j]["click_time"].(string), 10, 64)
		if ti != tj {
			return ti > tj
		}
		return rows[i]["click_id"].(int64) > rows[j]["click_id"].(int64)
	})
	total := len(rows)
	if offset > len(rows) {
		offset = len(rows)
	}
	rows = rows[offset:]
	if limit > 0 && limit < len(rows) {
		rows = rows[:limit]
	}
	if rows == nil {
		rows = []map[string]interface{}{}
	}
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"data": rows, "pagination": map[string]int{"total": total, "limit": limit, "offset": offset},
	})
}

func followedIDs(t *testing.T, stdout string) []int64 {
	t.Helper()
	var ids []int64
	for _, line := range strings.Split(strings.TrimSpace(stdout), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		var row struct {
			ClickID int64 `json:"click_id"`
		}
		if err := json.Unmarshal([]byte(line), &row); err != nil {
			t.Fatalf("a --follow line is not one JSON object: %q (%v)", line, err)
		}
		ids = append(ids, row.ClickID)
	}
	return ids
}

func lowerFollowLimits(t *testing.T, pageSize int) {
	t.Helper()
	oldMin, oldPage := clickFollowMinInterval, clickFollowPageSize
	clickFollowMinInterval, clickFollowPageSize = time.Millisecond, pageSize
	t.Cleanup(func() { clickFollowMinInterval, clickFollowPageSize = oldMin, oldPage })
}

// The Spy page's contract: the newest clicks, then each new one exactly
// once and in time order -- including one whose row lands after a later
// click's (a request that was in flight longer), which a cursor that only
// moved forward would skip -- and never a click older than the backlog.
func TestClickFollowPrintsEachNewClickOnce(t *testing.T) {
	lowerFollowLimits(t, 4) // windows span several pages
	feed := &clickFeed{}
	for id := int64(1); id <= 12; id++ {
		feed.add(id, 1000+id)
	}
	feed.onRequest = func(n int, f *clickFeed) {
		switch n {
		case 6: // after the backlog and its window have been read
			f.add(13, 1013)
		case 9:
			f.add(14, 1005) // committed late: older time than 13
			f.add(15, 1014)
		}
	}
	srv := httptest.NewServer(feed)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("click", "list", "--follow", "--ndjson", "--interval", "5ms", "--stop-after", "300ms", "--aff_campaign_id", "7")
	if err != nil {
		t.Fatalf("follow: %v", err)
	}
	got := followedIDs(t, stdout)
	want := []int64{3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15}
	if len(got) != len(want) {
		t.Fatalf("printed clicks %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("printed clicks %v, want %v", got, want)
		}
	}
	feed.mu.Lock()
	defer feed.mu.Unlock()
	if feed.requests < 10 {
		t.Fatalf("only %d requests; the follow stopped polling early", feed.requests)
	}
	for _, q := range feed.queries {
		if q["aff_campaign_id"] != "7" {
			t.Fatalf("a poll dropped the filter: %v", q)
		}
		if from := q["time_from"]; from != "" && from != "997" && from != "998" && from != "999" {
			t.Fatalf("a poll read from %s, not the lookback behind the newest click: %v", from, q)
		}
	}
}

// A backlog shorter than the lookback window leaves older clicks in that
// window out; they must stay out, not turn up on the first poll as new.
func TestClickFollowBacklogLimitHoldsAcrossPolls(t *testing.T) {
	lowerFollowLimits(t, 500)
	feed := &clickFeed{}
	for id := int64(1); id <= 8; id++ {
		feed.add(id, 2000+id)
	}
	srv := httptest.NewServer(feed)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("click", "list", "--follow", "--json", "--limit", "2", "--interval", "5ms", "--stop-after", "100ms")
	if err != nil {
		t.Fatalf("follow: %v", err)
	}
	got := followedIDs(t, stdout)
	if len(got) != 2 || got[0] != 7 || got[1] != 8 {
		t.Fatalf("printed %v, want the newest two [7 8] and nothing after", got)
	}
}

func TestClickFollowRefusesWhatItCannotHonour(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // nothing configured: these fail before any client
	for _, args := range [][]string{
		{"click", "list", "--follow", "--all"},
		{"click", "list", "--follow", "--time_from", "2026-10-01"},
		{"click", "list", "--follow", "--period", "last7"},
		{"click", "list", "--follow", "--page", "2"},
		{"click", "list", "--interval", "10s"},
		{"click", "list", "--stop-after", "1m"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil {
			t.Fatalf("%v: expected a refusal", args)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d (%v)", args, code, ExitValidation, err)
		}
		if strings.Contains(err.Error(), "no URL configured") {
			t.Errorf("%v: the flags must be refused before the configuration is read, got %v", args, err)
		}
	}

	writeTestConfig(t, tmp, "http://127.0.0.1:1", "test-key")
	for _, args := range [][]string{
		{"click", "list", "--follow", "--csv"},
		{"click", "list", "--follow", "--interval", "10ms"},
		{"click", "list", "--follow", "--limit", "0"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != ExitValidation {
			t.Errorf("%v: want a validation refusal, got %v", args, err)
		}
	}
}

// A poll that fails ends the follow with the failure's own exit code: an
// agent reading the stream must not take a dead server for a quiet one.
func TestClickFollowStopsOnAFailedPoll(t *testing.T) {
	lowerFollowLimits(t, 500)
	feed := &clickFeed{failAt: 4}
	feed.add(1, 3000)
	srv := httptest.NewServer(feed)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("click", "list", "--follow", "--ndjson", "--interval", "5ms", "--stop-after", "2s")
	if err == nil {
		t.Fatal("a failed poll must end the follow with an error")
	}
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("exit %d, want %d (%v)", code, ExitServer, err)
	}
	if got := followedIDs(t, stdout); len(got) != 1 || got[0] != 1 {
		t.Errorf("printed %v before failing, want [1]", got)
	}
}

// click list takes the Visitors page's filters under the names the reports
// use, and sends each as the server's parameter.
func TestClickListSendsTheVisitorsFilters(t *testing.T) {
	var got map[string]string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = map[string]string{}
		for k := range r.URL.Query() {
			got[k] = r.URL.Query().Get(k)
		}
		_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("click", "list", "--keyword", "shoes", "--show", "real", "--period", "last7",
		"--device_type", "2", "--ip", "203.0.113.9", "--referer", "news", "--ppc_network_id", "4",
		"--method_of_promotion", "directlink", "--click_bot", "0", "--json")
	if err != nil {
		t.Fatalf("click list: %v", err)
	}
	want := map[string]string{"keyword": "shoes", "show": "real", "period": "last7", "device_type": "2",
		"ip": "203.0.113.9", "referer": "news", "ppc_network_id": "4", "method_of_promotion": "directlink", "click_bot": "0"}
	for k, v := range want {
		if got[k] != v {
			t.Errorf("query %s = %q, want %q (sent %v)", k, got[k], v, got)
		}
	}
	if _, _, err := executeCommand("click", "list", "--show", "everything"); err == nil || exitCodeForError(err) != ExitValidation {
		t.Errorf("--show outside its values must be refused before any request: %v", err)
	}
}

// --stop-after is the window watched. With an interval longer than the
// watch no tick ever fires, so a click that arrives after the backlog is
// printed only by the read the deadline itself makes; before it, a click in
// the last interval of every bounded watch was dropped.
func TestClickFollowReadsOnceMoreWhenTheTimeRunsOut(t *testing.T) {
	lowerFollowLimits(t, 500)
	feed := &clickFeed{}
	for id := int64(1); id <= 3; id++ {
		feed.add(id, 3000+id)
	}
	feed.onRequest = func(n int, f *clickFeed) {
		if n == 3 { // after the backlog and its window
			f.add(4, 3004)
		}
	}
	srv := httptest.NewServer(feed)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("click", "list", "--follow", "--ndjson", "--interval", "1h", "--stop-after", "100ms")
	if err != nil {
		t.Fatalf("follow: %v", err)
	}
	got := followedIDs(t, stdout)
	if len(got) != 4 || got[3] != 4 {
		t.Fatalf("printed %v, want the backlog [1 2 3] and then 4, read when the time ran out", got)
	}
	feed.mu.Lock()
	defer feed.mu.Unlock()
	if feed.requests != 3 {
		t.Fatalf("%d requests, want 3: the backlog, its window, and one read at the deadline", feed.requests)
	}
}
