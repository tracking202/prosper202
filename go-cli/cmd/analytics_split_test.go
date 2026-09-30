package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"
	"time"
)

const (
	splitTestNow   = 1790769600 // 2026-09-30T12:00:00Z
	splitTestSplit = 1788480000 // 2026-09-04T00:00:00Z
	splitTestFrom  = 1787616000 // 2026-08-25T00:00:00Z: 10 days before the split
	splitTestTo    = 1788911999 // 2026-09-08T23:59:59Z: 5 days from the split
)

// pinReportClock fixes reportNow for the test.
func pinReportClock(t *testing.T, unix int64) {
	t.Helper()
	old := reportNow
	reportNow = func() time.Time { return time.Unix(unix, 0) }
	t.Cleanup(func() { reportNow = old })
}

// splitFake serves reports/breakdown like ReportsController::breakdown
// (limit defaults to 50, capped at 500, no pagination block, SUM columns as
// strings), answering from before or after by where time_from falls.
type splitFake struct {
	split         int64
	before, after []map[string]interface{}
	queries       []url.Values
}

func (f *splitFake) setup(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/reports/breakdown" {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}
		q := r.URL.Query()
		f.queries = append(f.queries, q)
		rows := f.after
		if from, _ := strconv.ParseInt(q.Get("time_from"), 10, 64); from < f.split {
			rows = f.before
		}
		limit, _ := strconv.Atoi(q.Get("limit"))
		if limit == 0 {
			limit = 50
		}
		limit = max(1, min(500, limit))
		offset, _ := strconv.Atoi(q.Get("offset"))
		page := []map[string]interface{}{}
		for i := offset; i < len(rows) && i < offset+limit; i++ {
			page = append(page, rows[i])
		}
		_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": page, "breakdown": q.Get("breakdown")})
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
}

func bdRow(id int, name, clicks, leads, income string) map[string]interface{} {
	return map[string]interface{}{"id": id, "name": name, "total_clicks": clicks, "total_click_throughs": clicks,
		"total_leads": leads, "total_income": income, "total_cost": "0.0000", "total_net": income,
		"epc": "0.1", "avg_cpc": "0", "conv_rate": "1", "roi": "0", "cpa": "0"}
}

type splitOutput struct {
	Data []map[string]interface{} `json:"data"`
	Meta map[string]interface{}   `json:"meta"`
}

func decodeSplit(t *testing.T, stdout string) splitOutput {
	t.Helper()
	var out splitOutput
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	return out
}

func splitIDs(rows []map[string]interface{}) []int {
	ids := make([]int, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, int(r["id"].(float64)))
	}
	return ids
}

func assertFields(t *testing.T, row map[string]interface{}, want map[string]interface{}) {
	t.Helper()
	for k, w := range want {
		got, ok := row[k]
		if !ok {
			t.Errorf("row %v has no %s", row["id"], k)
			continue
		}
		if w == nil {
			if got != nil {
				t.Errorf("row %v %s = %v, want null", row["id"], k, got)
			}
			continue
		}
		if got != w {
			t.Errorf("row %v %s = %#v, want %#v", row["id"], k, got, w)
		}
	}
}

// fourCountries: US grows per day but shrinks in total, GB exists only
// before, DE only after, CA is flat in total.
func fourCountries() *splitFake {
	return &splitFake{
		split: splitTestSplit,
		before: []map[string]interface{}{
			bdRow(1, "US", "1000", "10", "100.0000"),
			bdRow(2, "CA", "50", "0", "0.0000"),
			bdRow(3, "GB", "30", "3", "9.5000"),
		},
		after: []map[string]interface{}{
			bdRow(1, "US", "600", "9", "90.0000"),
			bdRow(4, "DE", "200", "2", "20.0000"),
			bdRow(2, "CA", "50", "1", "4.0000"),
		},
	}
}

var splitWindowArgs = []string{"--time_from", strconv.Itoa(splitTestFrom), "--time_to", strconv.Itoa(splitTestTo)}

func TestAnalyticsSplitSendsBothWindowsAndMergesOneSidedValues(t *testing.T) {
	pinReportClock(t, splitTestNow)
	f := fourCountries()
	f.setup(t)

	args := append([]string{"analytics", "--group-by", "country", "--split-at", "2026-09-04", "--aff_campaign_id", "7", "--json"}, splitWindowArgs...)
	stdout, stderr, err := executeCommand(args...)
	if err != nil {
		t.Fatalf("analytics --split-at: %v", err)
	}
	if strings.TrimSpace(stderr) != "" {
		t.Errorf("--json should keep stderr empty on success, got %q", stderr)
	}
	if len(f.queries) != 2 {
		t.Fatalf("breakdown requests = %d, want one per side: %v", len(f.queries), f.queries)
	}
	for i, want := range [][2]string{
		{strconv.Itoa(splitTestFrom), strconv.Itoa(splitTestSplit - 1)},
		{strconv.Itoa(splitTestSplit), strconv.Itoa(splitTestTo)},
	} {
		q := f.queries[i]
		if q.Get("time_from") != want[0] || q.Get("time_to") != want[1] {
			t.Errorf("side %d window = [%s, %s], want [%s, %s]", i, q.Get("time_from"), q.Get("time_to"), want[0], want[1])
		}
		if q.Get("breakdown") != "country" || q.Get("aff_campaign_id") != "7" || q.Get("limit") != "500" || q.Get("offset") != "0" {
			t.Errorf("side %d query = %v, want breakdown=country, aff_campaign_id=7, limit=500, offset=0", i, q)
		}
		if q.Get("period") != "" || q.Get("sort") != "" {
			t.Errorf("side %d query = %v, want explicit bounds and no period/sort", i, q)
		}
	}

	out := decodeSplit(t, stdout)
	if got := splitIDs(out.Data); len(got) != 4 || got[0] != 1 || got[1] != 4 || got[2] != 3 || got[3] != 2 {
		t.Fatalf("row order = %v, want [1 4 3 2] (|clicks change| 400, 200, 30, 0)", got)
	}
	byID := map[int]map[string]interface{}{}
	for _, r := range out.Data {
		byID[int(r["id"].(float64))] = r
	}
	assertFields(t, byID[1], map[string]interface{}{
		"name": "US", "clicks_before": 1000.0, "clicks_after": 600.0, "clicks_change": -400.0, "clicks_change_pct": -40.0,
		"clicks_per_day_before": 100.0, "clicks_per_day_after": 120.0, "clicks_per_day_change": 20.0, "clicks_per_day_change_pct": 20.0,
		"conversions_before": 10.0, "conversions_after": 9.0, "conversions_change": -1.0, "conversions_change_pct": -10.0,
		"conversions_per_day_before": 1.0, "conversions_per_day_after": 1.8, "conversions_per_day_change_pct": 80.0,
		"revenue_before": 100.0, "revenue_after": 90.0, "revenue_change": -10.0, "revenue_change_pct": -10.0,
		"revenue_per_day_before": 10.0, "revenue_per_day_after": 18.0, "revenue_per_day_change_pct": 80.0,
	})
	assertFields(t, byID[3], map[string]interface{}{
		"name": "GB", "clicks_before": 30.0, "clicks_after": 0.0, "clicks_change": -30.0, "clicks_change_pct": -100.0,
		"clicks_per_day_after": 0.0, "revenue_before": 9.5, "revenue_after": 0.0, "revenue_change_pct": -100.0,
	})
	assertFields(t, byID[4], map[string]interface{}{
		"name": "DE", "clicks_before": 0.0, "clicks_after": 200.0, "clicks_change": 200.0, "clicks_change_pct": nil,
		"clicks_per_day_before": 0.0, "clicks_per_day_after": 40.0, "clicks_per_day_change_pct": nil, "conversions_change_pct": nil,
	})
	assertFields(t, byID[2], map[string]interface{}{
		"clicks_change": 0.0, "clicks_change_pct": 0.0, "clicks_per_day_before": 5.0, "clicks_per_day_after": 10.0,
		"clicks_per_day_change_pct": 100.0, "conversions_change_pct": nil,
	})

	m := out.Meta
	if m["split_at"] != float64(splitTestSplit) || m["split_at_utc"] != "2026-09-04T00:00:00Z" || m["group_by"] != "country" {
		t.Errorf("meta split = %v / %v / %v", m["split_at"], m["split_at_utc"], m["group_by"])
	}
	before, _ := m["before"].(map[string]interface{})
	after, _ := m["after"].(map[string]interface{})
	assertFields(t, before, map[string]interface{}{
		"time_from": float64(splitTestFrom), "time_to": float64(splitTestSplit - 1), "time_from_utc": "2026-08-25T00:00:00Z",
		"time_to_utc": "2026-09-03T23:59:59Z", "seconds": 864000.0, "days": 10.0, "rows": 3.0, "clicks": 1080.0, "clicks_per_day": 108.0,
	})
	assertFields(t, after, map[string]interface{}{
		"time_from": float64(splitTestSplit), "time_to": float64(splitTestTo), "seconds": 432000.0, "days": 5.0,
		"rows": 3.0, "clicks": 850.0, "clicks_per_day": 170.0, "revenue": 114.0,
	})
	window, _ := m["window"].(map[string]interface{})
	if window["source"] != "--time_from/--time_to" || window["time_from"] != float64(splitTestFrom) || window["time_to"] != float64(splitTestTo) {
		t.Errorf("meta window = %v", window)
	}
	if m["sort"] != "clicks_change" || m["sort_dir"] != "DESC" || m["rows"] != 4.0 || m["returned"] != 4.0 {
		t.Errorf("meta sort/rows = %v %v %v %v", m["sort"], m["sort_dir"], m["rows"], m["returned"])
	}
}

func TestAnalyticsSplitSortAndLimit(t *testing.T) {
	pinReportClock(t, splitTestNow)
	cases := []struct {
		args []string
		want []int
		sort string
	}{
		// per-day change: DE +40, US +20, CA +5, GB -3
		{[]string{"--sort", "clicks_per_day"}, []int{4, 1, 2, 3}, "clicks_per_day_change"},
		{[]string{"--sort", "clicks_per_day", "--limit", "2"}, []int{4, 1}, "clicks_per_day_change"},
		{[]string{"--sort", "clicks_per_day", "--limit", "2", "--offset", "1"}, []int{1, 2}, "clicks_per_day_change"},
		{[]string{"--sort-dir", "asc"}, []int{2, 3, 4, 1}, "clicks_change"},
		// |conversions change|: GB 3, DE 2, US 1, CA 1 (tie -> id)
		{[]string{"--sort", "conversions"}, []int{3, 4, 1, 2}, "conversions_change"},
		// |revenue change|: DE 20, US 10, GB 9.5, CA 4
		{[]string{"--sort", "total_income"}, []int{4, 1, 3, 2}, "revenue_change"},
		{[]string{"--offset", "9"}, []int{}, "clicks_change"},
	}
	for _, tc := range cases {
		t.Run(strings.Join(tc.args, " "), func(t *testing.T) {
			f := fourCountries()
			f.setup(t)
			args := append([]string{"analytics", "--group-by", "country", "--split-at", strconv.Itoa(splitTestSplit), "--json"}, splitWindowArgs...)
			stdout, _, err := executeCommand(append(args, tc.args...)...)
			if err != nil {
				t.Fatalf("analytics: %v", err)
			}
			out := decodeSplit(t, stdout)
			got := splitIDs(out.Data)
			if len(got) != len(tc.want) {
				t.Fatalf("ids = %v, want %v", got, tc.want)
			}
			for i := range got {
				if got[i] != tc.want[i] {
					t.Fatalf("ids = %v, want %v", got, tc.want)
				}
			}
			if out.Meta["sort"] != tc.sort || out.Meta["rows"] != 4.0 {
				t.Errorf("meta sort = %v rows = %v, want %s and 4", out.Meta["sort"], out.Meta["rows"], tc.sort)
			}
		})
	}
}

func TestAnalyticsSplitPagesPastTheServerCap(t *testing.T) {
	pinReportClock(t, splitTestNow)
	f := &splitFake{split: splitTestSplit}
	for i := 1; i <= 500; i++ {
		f.before = append(f.before, bdRow(i, "kw"+strconv.Itoa(i), "1", "0", "0"))
	}
	// Only the second page carries the value that moved most.
	f.before = append(f.before, bdRow(501, "kw501", "5", "0", "0"))
	f.after = []map[string]interface{}{bdRow(501, "kw501", "900", "0", "0"), bdRow(9000, "new", "1", "0", "0")}
	f.setup(t)

	args := append([]string{"analytics", "--group-by", "keyword", "--split-at", "2026-09-04", "--json"}, splitWindowArgs...)
	stdout, _, err := executeCommand(args...)
	if err != nil {
		t.Fatalf("analytics: %v", err)
	}
	var offsets []string
	for _, q := range f.queries {
		offsets = append(offsets, q.Get("time_from")+"@"+q.Get("offset"))
	}
	want := []string{strconv.Itoa(splitTestFrom) + "@0", strconv.Itoa(splitTestFrom) + "@500", strconv.Itoa(splitTestSplit) + "@0"}
	if strings.Join(offsets, " ") != strings.Join(want, " ") {
		t.Errorf("requests = %v, want %v", offsets, want)
	}
	out := decodeSplit(t, stdout)
	if len(out.Data) != 502 {
		t.Fatalf("rows = %d, want 501 before-side values + 1 after-only value", len(out.Data))
	}
	if out.Data[0]["id"] != 501.0 || out.Data[0]["clicks_change"] != 895.0 {
		t.Errorf("first row = %v, want kw501 (the 501st before row, +895 clicks)", out.Data[0])
	}
	if before := out.Meta["before"].(map[string]interface{}); before["rows"] != 501.0 {
		t.Errorf("meta before rows = %v, want 501", before["rows"])
	}
}

func TestAnalyticsSplitWindowSources(t *testing.T) {
	pinReportClock(t, splitTestNow)
	cases := []struct {
		args     []string
		from, to int64
		source   string
	}{
		{nil, splitTestNow - 90*86400, splitTestNow, "default last90"},
		{[]string{"--period", "last30"}, splitTestNow - 30*86400, splitTestNow, "--period last30"},
		{[]string{"--period", "last30", "--days", "7"}, splitTestNow - 30*86400, splitTestNow, "--period last30"},
		{[]string{"--days", "40"}, splitTestNow - 40*86400, splitTestNow, "--days 40"},
		{[]string{"--time_from", strconv.Itoa(splitTestFrom)}, splitTestFrom, splitTestNow, "--time_from to now"},
	}
	for _, tc := range cases {
		t.Run(strings.Join(tc.args, " "), func(t *testing.T) {
			f := fourCountries()
			f.setup(t)
			args := append([]string{"analytics", "--group-by", "country", "--split-at", "2026-09-04", "--json"}, tc.args...)
			stdout, _, err := executeCommand(args...)
			if err != nil {
				t.Fatalf("analytics: %v", err)
			}
			if len(f.queries) != 2 || f.queries[0].Get("time_from") != strconv.FormatInt(tc.from, 10) ||
				f.queries[1].Get("time_to") != strconv.FormatInt(tc.to, 10) {
				t.Errorf("requests = %v, want the window [%d, %d]", f.queries, tc.from, tc.to)
			}
			window := decodeSplit(t, stdout).Meta["window"].(map[string]interface{})
			if window["source"] != tc.source {
				t.Errorf("window source = %v, want %q", window["source"], tc.source)
			}
		})
	}
}

func TestAnalyticsSplitTableSummaryOnStderr(t *testing.T) {
	pinReportClock(t, splitTestNow)
	f := fourCountries()
	f.setup(t)
	args := append([]string{"analytics", "--group-by", "country", "--split-at", "2026-09-04"}, splitWindowArgs...)
	stdout, stderr, err := executeCommand(args...)
	if err != nil {
		t.Fatalf("analytics: %v", err)
	}
	for _, want := range []string{
		"Split at 2026-09-04T00:00:00Z (1788480000); window: --time_from/--time_to",
		"before 2026-08-25T00:00:00Z .. 2026-09-03T23:59:59Z  10.00 days  clicks 1080 (108/day)",
		"after  2026-09-04T00:00:00Z .. 2026-09-08T23:59:59Z  5.00 days  clicks 850 (170/day)",
		"4 value(s), 4 shown, ranked by |clicks_change| DESC. The sides differ in length: compare the *_per_day columns",
	} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr lacks %q:\n%s", want, stderr)
		}
	}
	header := strings.Fields(strings.SplitN(stdout, "\n", 2)[0])
	if len(header) < 9 || header[0] != "id" || header[1] != "name" || header[2] != "clicks_before" || header[8] != "clicks_per_day_change_pct" {
		t.Errorf("table header = %v, want id, name, clicks_before ... clicks_per_day_change_pct first", header)
	}
}

func TestAnalyticsSplitValidatesBeforeBuildingTheClient(t *testing.T) {
	pinReportClock(t, splitTestNow)
	win := splitWindowArgs
	cases := []struct {
		args []string
		want string
	}{
		{[]string{"--split-at", "2026-01-01"}, "the before side would be empty"},
		{append([]string{"--split-at", strconv.Itoa(splitTestFrom)}, win...), "the before side would be empty"},
		{append([]string{"--split-at", "2026-09-10"}, win...), "the after side would be empty"},
		{[]string{"--split-at", "2026-10-01"}, "the after side would be empty"},
		{[]string{"--split-at", "4th-sept"}, "invalid --split-at"},
		{[]string{"--split-at", "1788480000000"}, "looks like milliseconds"},
		{[]string{"--split-at", "2026-09-04", "--period", "today"}, "cannot be split"},
		{[]string{"--split-at", "2026-09-04", "--period", "last365"}, "--period must be one of"},
		{[]string{"--split-at", "2026-09-04", "--time_to", strconv.Itoa(splitTestTo)}, "unbounded"},
		{[]string{"--split-at", "2026-09-04", "--time_from", "2026-08-25"}, "--time_from must be unix seconds"},
		{[]string{"--split-at", "2026-09-04", "--time_from", strconv.Itoa(splitTestTo), "--time_to", strconv.Itoa(splitTestFrom)}, "the window is empty"},
		{[]string{"--split-at", "2026-09-04", "--days", "-1"}, "--days must be 0 or greater"},
		{[]string{"--split-at", "2026-09-04", "--sort", "roi"}, "not available with --split-at"},
		{[]string{"--sort", "clicks_per_day"}, "it needs --split-at"},
		{[]string{"--split-at", "2026-09-04", "--sort-dir", "sideways"}, "--sort-dir must be one of"},
		{[]string{"--split-at", "2026-09-04", "--limit", "0"}, "--limit must be a whole number"},
		{[]string{"--split-at", "2026-09-04", "--offset", "-1"}, "--offset must be a whole number"},
	}
	for _, tc := range cases {
		t.Run(strings.Join(tc.args, " "), func(t *testing.T) {
			// No config at all: an error from after the client would be the config error.
			setTestHome(t, t.TempDir())
			_, _, err := executeCommand(append([]string{"analytics", "--group-by", "country"}, tc.args...)...)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d", code, ExitValidation)
			}
			if hintFor(err) == "" {
				t.Error("no hint")
			}
		})
	}

	// Control: a valid split in the same empty HOME reaches the client and fails there.
	setTestHome(t, t.TempDir())
	_, _, err := executeCommand("analytics", "--group-by", "country", "--split-at", "2026-09-04")
	if err == nil || !strings.Contains(err.Error(), "no URL configured") {
		t.Fatalf("valid split without config: err = %v, want the config error", err)
	}
}

func TestAnalyticsSplitSendsNoRequestWhenInvalid(t *testing.T) {
	pinReportClock(t, splitTestNow)
	f := fourCountries()
	f.setup(t)
	_, _, err := executeCommand("analytics", "--group-by", "country", "--split-at", "2026-01-01")
	if err == nil {
		t.Fatal("want an error for a split before the window")
	}
	if len(f.queries) != 0 {
		t.Errorf("requests = %d, want none", len(f.queries))
	}
}
