package cmd

import (
	"encoding/json"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"testing"
	"time"
)

// statsFake adds GET /reports/breakdown to campaignFake, shaped like
// ReportsController::breakdown: limit defaults to 50 and is capped at 500,
// there is no pagination block, and SUM() columns arrive as strings.
type statsFake struct {
	*campaignFake
	stats      []map[string]interface{}
	breakdowns []url.Values
	status     int // when set, every breakdown request fails with it
}

func newStatsFake() *statsFake {
	f := &statsFake{campaignFake: newCampaignFake()}
	f.extra = func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/api/v3/reports/breakdown" {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}
		q := r.URL.Query()
		f.breakdowns = append(f.breakdowns, q)
		if f.status != 0 {
			w.WriteHeader(f.status)
			_, _ = w.Write([]byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'reports:read' (key has: campaigns:read).","status":403}`))
			return
		}
		limit, _ := strconv.Atoi(q.Get("limit"))
		if limit == 0 {
			limit = 50
		}
		limit = max(1, min(500, limit))
		offset, _ := strconv.Atoi(q.Get("offset"))
		page := []map[string]interface{}{}
		for i := offset; i < len(f.stats) && i < offset+limit; i++ {
			page = append(page, f.stats[i])
		}
		_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": page, "breakdown": "campaign"})
	}
	return f
}

func statRow(id int, clicks, leads, income, cost, net string) map[string]interface{} {
	return map[string]interface{}{"id": id, "name": "c" + strconv.Itoa(id), "total_clicks": clicks,
		"total_click_throughs": clicks, "total_leads": leads, "total_income": income, "total_cost": cost, "total_net": net,
		"epc": "0.1", "avg_cpc": "0.1", "conv_rate": "1", "roi": "1", "cpa": "1"}
}

func decodeListRows(t *testing.T, stdout string) map[int]map[string]interface{} {
	t.Helper()
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	out := map[int]map[string]interface{}{}
	for _, row := range resp.Data {
		out[int(row["aff_campaign_id"].(float64))] = row
	}
	return out
}

func assertStats(t *testing.T, row map[string]interface{}, want ...float64) {
	t.Helper()
	if row == nil {
		t.Fatal("campaign row missing from output")
	}
	for i, f := range listStatsFields {
		got, ok := row[f].(float64)
		if !ok {
			t.Errorf("campaign %v %s = %#v, want the number %v", row["aff_campaign_id"], f, row[f], want[i])
			continue
		}
		if got != want[i] {
			t.Errorf("campaign %v %s = %v, want %v", row["aff_campaign_id"], f, got, want[i])
		}
	}
}

func TestCampaignListWithStatsMergesPerCampaignByID(t *testing.T) {
	f := newStatsFake()
	f.stats = []map[string]interface{}{
		statRow(281, "7", "0", "0.0000", "1.4000", "-1.4000"),
		statRow(279, "120", "4", "45.5000", "12.0000", "33.5000"),
		statRow(999, "50", "1", "9.0000", "0", "9.0000"), // a campaign not in the list
	}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--json")
	if err != nil {
		t.Fatalf("campaign list --with-stats: %v", err)
	}
	rows := decodeListRows(t, stdout)
	if len(rows) != 4 {
		t.Fatalf("got %d rows, want the 4 campaigns: %v", len(rows), rows)
	}
	assertStats(t, rows[279], 120, 4, 45.5, 12, 33.5)
	assertStats(t, rows[281], 7, 0, 0, 1.4, -1.4)
	if len(f.breakdowns) != 1 {
		t.Fatalf("breakdown requests = %d, want 1", len(f.breakdowns))
	}
	if q := f.breakdowns[0]; q.Get("breakdown") != "campaign" || q.Get("aff_network_id") != "" {
		t.Errorf("breakdown query = %v, want breakdown=campaign and no network filter", q)
	}
}

func TestCampaignListWithStatsZeroFillsCampaignsWithoutTraffic(t *testing.T) {
	f := newStatsFake()
	f.stats = []map[string]interface{}{statRow(279, "3", "1", "2.0000", "0", "2.0000")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--json")
	if err != nil {
		t.Fatalf("campaign list --with-stats: %v", err)
	}
	rows := decodeListRows(t, stdout)
	assertStats(t, rows[279], 3, 1, 2, 0, 2)
	for _, id := range []int{60, 281, 5} {
		assertStats(t, rows[id], 0, 0, 0, 0, 0)
	}
}

func TestCampaignListWithStatsAndURLContains(t *testing.T) {
	f := newStatsFake()
	for i := 0; i < 120; i++ {
		f.rows = append([]map[string]interface{}{{"aff_campaign_id": 1000 + i, "aff_campaign_name": "filler", "aff_network_id": 1, "aff_campaign_url": "https://filler.example/"}}, f.rows...)
	}
	f.stats = []map[string]interface{}{statRow(281, "9", "2", "10.0000", "1.0000", "9.0000"), statRow(1000, "40", "0", "0", "0", "0")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--url-contains", "g2afse", "--with-stats", "--json")
	if err != nil {
		t.Fatalf("campaign list --url-contains --with-stats: %v", err)
	}
	rows := decodeListRows(t, stdout)
	if len(rows) != 2 || rows[279] == nil || rows[281] == nil {
		t.Fatalf("rows = %v, want campaigns 279 and 281 only", rows)
	}
	assertStats(t, rows[281], 9, 2, 10, 1, 9)
	assertStats(t, rows[279], 0, 0, 0, 0, 0)
	if len(f.gets) < 2 || len(f.breakdowns) != 1 {
		t.Errorf("campaign pages = %d (want >1), breakdown requests = %d (want 1)", len(f.gets), len(f.breakdowns))
	}
}

func TestCampaignListWithStatsKeepsPagingAndNetworkFilter(t *testing.T) {
	f := newStatsFake()
	f.stats = []map[string]interface{}{statRow(281, "7", "0", "0", "0", "0")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--aff-network-id", "32", "--limit", "2", "--json")
	if err != nil {
		t.Fatalf("campaign list --with-stats --limit: %v", err)
	}
	var resp struct {
		Data       []map[string]interface{} `json:"data"`
		Pagination map[string]interface{}   `json:"pagination"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	if len(resp.Data) != 2 || resp.Pagination["total"] != float64(3) {
		t.Fatalf("got %d rows, pagination %v; want the server's page of 2 of 3", len(resp.Data), resp.Pagination)
	}
	assertStats(t, resp.Data[1], 7, 0, 0, 0, 0)
	if len(f.gets) != 1 || !strings.Contains(f.gets[0], "limit=2") || !strings.Contains(f.gets[0], "filter%5Baff_network_id%5D=32") {
		t.Errorf("campaign requests = %v, want one page with limit=2 and the network filter", f.gets)
	}
	// Clicks follow the campaign, not the network it had at click time.
	if len(f.breakdowns) != 1 || f.breakdowns[0].Get("aff_network_id") != "" {
		t.Errorf("breakdown queries = %v, want one without a network filter", f.breakdowns)
	}
}

func TestCampaignListWithStatsPagesThroughTheBreakdown(t *testing.T) {
	f := newStatsFake()
	for i := 0; i < 1202; i++ {
		f.stats = append(f.stats, statRow(10000+i, "900", "0", "0", "0", "0"))
	}
	// Campaign 5 is the 1203rd row: only a third request at offset 1000 reaches it.
	f.stats = append(f.stats, statRow(5, "1", "1", "3.0000", "0", "3.0000"))
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--all", "--json")
	if err != nil {
		t.Fatalf("campaign list --with-stats --all: %v", err)
	}
	assertStats(t, decodeListRows(t, stdout)[5], 1, 1, 3, 0, 3)
	var got []string
	for _, q := range f.breakdowns {
		got = append(got, q.Get("limit")+"@"+q.Get("offset"))
	}
	if strings.Join(got, ",") != "500@0,500@500,500@1000" {
		t.Errorf("breakdown pages = %v, want 500@0,500@500,500@1000", got)
	}
}

func TestCampaignListWithStatsMinClicksKeepsBusyCampaigns(t *testing.T) {
	f := newStatsFake()
	f.stats = []map[string]interface{}{statRow(279, "5", "0", "0", "0", "0"), statRow(281, "4", "0", "0", "0", "0")}
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--min-clicks", "5", "--json")
	if err != nil {
		t.Fatalf("campaign list --min-clicks: %v", err)
	}
	rows := decodeListRows(t, stdout)
	if len(rows) != 1 || rows[279] == nil {
		t.Fatalf("rows = %v, want only campaign 279 (5 clicks)", rows)
	}
}

// The window reaches the breakdown exactly as `analytics` sends it; with no
// window flag the default is last30.
func TestCampaignListWithStatsWindowMatchesAnalytics(t *testing.T) {
	window := func(q url.Values) string {
		from, _ := strconv.ParseInt(q.Get("time_from"), 10, 64)
		to, _ := strconv.ParseInt(q.Get("time_to"), 10, 64)
		if to != 0 && (to < time.Now().Unix()-5 || to > time.Now().Unix()+5) {
			return "time_to not now: " + q.Get("time_to")
		}
		return "period=" + q.Get("period") + " span=" + strconv.FormatInt(to-from, 10)
	}
	f := newStatsFake()
	setupCampaignFake(t, f.campaignFake)

	if _, _, err := executeCommand("campaign", "list", "--with-stats", "--json"); err != nil {
		t.Fatal(err)
	}
	if got := window(f.breakdowns[0]); got != "period=last30 span=0" {
		t.Errorf("default window = %q, want period=last30", got)
	}
	for _, flags := range [][]string{
		{"--period", "last90"},
		{"--days", "7"},
		{"--period", "last7", "--days", "30"},
	} {
		f.breakdowns = nil
		if _, _, err := executeCommand(append([]string{"analytics", "--group-by", "campaign", "--json"}, flags...)...); err != nil {
			t.Fatalf("analytics %v: %v", flags, err)
		}
		if _, _, err := executeCommand(append([]string{"campaign", "list", "--with-stats", "--json"}, flags...)...); err != nil {
			t.Fatalf("campaign list %v: %v", flags, err)
		}
		if len(f.breakdowns) != 2 {
			t.Fatalf("%v: breakdown requests = %d, want 2", flags, len(f.breakdowns))
		}
		want, got := window(f.breakdowns[0]), window(f.breakdowns[1])
		if got != want {
			t.Errorf("%v: campaign list window %q, analytics window %q", flags, got, want)
		}
	}
	if got := window(f.breakdowns[1]); got != "period=last7 span=0" {
		t.Errorf("--period with --days = %q, want --period to win", got)
	}
}

func TestCampaignListWithStatsForbiddenNamesTheScope(t *testing.T) {
	f := newStatsFake()
	f.status = 403
	setupCampaignFake(t, f.campaignFake)

	stdout, _, err := executeCommand("campaign", "list", "--with-stats", "--json")
	if err == nil {
		t.Fatal("expected the 403 to fail the command")
	}
	if got := exitCodeForError(err); got != ExitAuth {
		t.Errorf("exit code = %d, want %d (auth)", got, ExitAuth)
	}
	hint := hintFor(err)
	for _, want := range []string{"reports:read", "`p202 campaign list` without it still works", "--scope campaigns:read,reports:read"} {
		if !strings.Contains(hint, want) {
			t.Errorf("hint = %q, want it to contain %q", hint, want)
		}
	}
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("stdout must stay empty on failure, got %q", stdout)
	}
}

func TestCampaignListStatsFlagsAreValidatedBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args        []string
		msg, hint   string
		unconfigged bool
	}{
		{[]string{"--with-stats", "--period", "last14"}, "invalid --period \"last14\"; valid: today, yesterday, last7, last30, last90", "--days N", false},
		{[]string{"--with-stats", "--period", "last14"}, "invalid --period", "--days N", true},
		{[]string{"--period", "last30"}, "--period only applies with --with-stats", "Add --with-stats", false},
		{[]string{"--days", "7"}, "--days only applies with --with-stats", "Add --with-stats", true},
		{[]string{"--min-clicks", "3"}, "--min-clicks only applies with --with-stats", "Add --with-stats", false},
		{[]string{"--with-stats", "--days", "-1"}, "--days must be 0 or greater", "--days 30", false},
		{[]string{"--with-stats", "--min-clicks", "-1"}, "--min-clicks must be 0 or greater", "--min-clicks 1", false},
		{[]string{"--with-stats", "--min-clicks", "2", "--offset", "5"}, "--min-clicks searches every page, so it cannot be combined with --offset", "Drop --page/--limit/--offset", false},
	}
	for _, tc := range cases {
		t.Run(strings.Join(tc.args, " "), func(t *testing.T) {
			f := newStatsFake()
			if tc.unconfigged {
				setTestHome(t, t.TempDir()) // no config: validation must not need a client
			} else {
				setupCampaignFake(t, f.campaignFake)
			}
			_, _, err := executeCommand(append([]string{"campaign", "list"}, tc.args...)...)
			if err == nil {
				t.Fatal("expected a validation error")
			}
			if !strings.Contains(err.Error(), tc.msg) {
				t.Errorf("message = %q, want %q", err.Error(), tc.msg)
			}
			if got := exitCodeForError(err); got != ExitValidation {
				t.Errorf("exit code = %d, want %d", got, ExitValidation)
			}
			if h := hintFor(err); !strings.Contains(h, tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", h, tc.hint)
			}
			if len(f.gets) != 0 || len(f.breakdowns) != 0 {
				t.Errorf("requests were made: campaigns %v, breakdowns %v", f.gets, f.breakdowns)
			}
		})
	}
}
