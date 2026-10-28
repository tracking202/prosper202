package cmd

import (
	"encoding/json"
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"testing"
)

// pagedBreakdown answers reports/breakdown as the server does: n rows, most
// clicks first, the page limit/offset select (50 and 0 by default). Each row
// spent $1 and converted nothing, so every one is a loser. withFlag false is a
// server from before `truncated`, which says nothing about the rows after a page.
func pagedBreakdown(n int, withFlag bool) func(string, url.Values) (int, string) {
	return func(path string, q url.Values) (int, string) {
		if !strings.HasSuffix(path, "/reports/breakdown") {
			return 200, `{"data":{"aff_campaign_payout":"2"}}`
		}
		limit, offset := 50, 0
		if v := q.Get("limit"); v != "" {
			limit, _ = strconv.Atoi(v)
		}
		if v := q.Get("offset"); v != "" {
			offset, _ = strconv.Atoi(v)
		}
		var rows []string
		for i := offset; i < n && i < offset+limit; i++ {
			rows = append(rows, fmt.Sprintf(`{"id":%d,"name":"kw%04d","total_clicks":%d,"total_leads":0,"total_cost":1,"total_net":-1}`, i+1, i, n-i+1))
		}
		body := `{"data":[` + strings.Join(rows, ",") + `],"breakdown":"keyword"`
		if withFlag {
			body += fmt.Sprintf(`,"truncated":%t,"limit":%d,"offset":%d`, offset+limit < n, limit, offset)
		}
		return 200, body + "}"
	}
}

// One page of `report breakdown` with rows after it: --json passes the
// server's truncated/limit/offset through and keeps stderr for errors,
// --ndjson stamps every line, and a table gets one note on stderr naming
// the --offset of the next page.
func TestReportBreakdownSaysRowsRemain(t *testing.T) {
	depthServer(t, pagedBreakdown(120, true))

	stdout, stderr, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var page struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
		Offset    *int                     `json:"offset"`
	}
	if err := json.Unmarshal([]byte(stdout), &page); err != nil {
		t.Fatalf("--json is not one object: %v\n%s", err, stdout)
	}
	if len(page.Data) != 50 || page.Truncated == nil || !*page.Truncated || page.Limit == nil || *page.Limit != 50 || page.Offset == nil || *page.Offset != 0 {
		t.Errorf("--json: want 50 rows with truncated=true limit=50 offset=0, got %d rows %s", len(page.Data), stdout[len(stdout)-80:])
	}
	if stderr != "" {
		t.Errorf("--json keeps stderr for errors, got %q", stderr)
	}

	stdout, stderr, err = executeCommand("report", "breakdown", "--breakdown", "keyword", "--ndjson")
	if err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) != 50 {
		t.Fatalf("--ndjson: want 50 lines, got %d", len(lines))
	}
	for _, l := range lines {
		if !strings.Contains(l, `"truncated":true`) {
			t.Fatalf("--ndjson: every line carries truncated, got %s", l)
		}
	}
	if stderr != "" {
		t.Errorf("--ndjson keeps stderr for errors, got %q", stderr)
	}

	stdout, stderr, err = executeCommand("report", "breakdown", "--breakdown", "keyword")
	if err != nil {
		t.Fatal(err)
	}
	want := "Note: more rows remain after rows 1-50; --offset 50 reads the next page (--limit takes up to 500).\n"
	if stderr != want {
		t.Errorf("table: stderr = %q, want %q", stderr, want)
	}
	if strings.Contains(stdout, "Note") {
		t.Errorf("table: stdout stays data, got the note in it")
	}

	_, stderr, err = executeCommand("report", "breakdown", "--breakdown", "keyword", "--limit", "500", "--offset", "0", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	if stderr != "" {
		t.Errorf("the last page has no note, got %q", stderr)
	}
	_, stderr, err = executeCommand("report", "breakdown", "--breakdown", "keyword", "--limit", "20", "--offset", "60", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	if want := "Note: more rows remain after rows 61-80; --offset 80 reads the next page (--limit takes up to 500).\n"; stderr != want {
		t.Errorf("csv: stderr = %q, want %q", stderr, want)
	}
}

// A client-side filter keeps rows of the page it read: the server's flag goes
// out with them, and the note says the filter saw only that page.
func TestReportBreakdownFiltersKeepTheFlag(t *testing.T) {
	depthServer(t, pagedBreakdown(120, true))

	stdout, _, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--min-clicks", "100", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var page struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Offset    *int                     `json:"offset"`
		Breakdown string                   `json:"breakdown"`
	}
	if err := json.Unmarshal([]byte(stdout), &page); err != nil {
		t.Fatal(err)
	}
	if len(page.Data) != 22 || page.Truncated == nil || !*page.Truncated || page.Offset == nil || page.Breakdown != "keyword" {
		t.Errorf("--min-clicks 100: want the 22 rows with 100+ clicks and the answer's other keys, got %s", stdout)
	}

	_, stderr, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--having", "total_clicks>100")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stderr, "--offset 50 reads the next page") || !strings.Contains(stderr, "--having filter only the rows read.") {
		t.Errorf("a filtered page's note names the next page and what the filter saw, got %q", stderr)
	}
}

// A server from before the flag cannot say; a page it filled to the limit
// may have rows after it, and the note says so. A short page is the last.
func TestReportBreakdownOnAServerWithoutTheFlag(t *testing.T) {
	depthServer(t, pagedBreakdown(120, false))
	_, stderr, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--limit", "100")
	if err != nil {
		t.Fatal(err)
	}
	if want := "Note: this server does not say whether rows remain after rows 1-100; --offset 100 reads the next page (--limit takes up to 500).\n"; stderr != want {
		t.Errorf("stderr = %q, want %q", stderr, want)
	}
	_, stderr, err = executeCommand("report", "breakdown", "--breakdown", "keyword", "--offset", "100")
	if err != nil {
		t.Fatal(err)
	}
	if stderr != "" {
		t.Errorf("a short page is the last; stderr = %q", stderr)
	}
}

func TestAnalyticsSaysRowsRemain(t *testing.T) {
	depthServer(t, pagedBreakdown(51, true))
	stdout, stderr, err := executeCommand("analytics", "--group-by", "keyword")
	if err != nil {
		t.Fatal(err)
	}
	if want := "Note: more rows remain after rows 1-50; --offset 50 reads the next page (--limit takes up to 500).\n"; stderr != want {
		t.Errorf("stderr = %q, want %q", stderr, want)
	}
	if strings.Contains(stdout, "Note") {
		t.Error("stdout stays data")
	}
	stdout, stderr, err = executeCommand("analytics", "--group-by", "keyword", "--offset", "50", "--json")
	if err != nil || stderr != "" || !strings.Contains(stdout, `"truncated": false`) {
		t.Errorf("the last page: --json carries truncated=false and stderr stays empty, got %v %q %s", err, stderr, stdout)
	}
}

// report breakeven reads one page, as report breakdown does, ranks it by
// margin, and says when rows remain after it.
func TestReportBreakevenPagesAndSaysRowsRemain(t *testing.T) {
	_, requests := depthServer(t, pagedBreakdown(70, true))
	stdout, stderr, err := executeCommand("report", "breakeven", "--aff-campaign-id", "3", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var page struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
		Offset    *int                     `json:"offset"`
	}
	if err := json.Unmarshal([]byte(stdout), &page); err != nil {
		t.Fatal(err)
	}
	if len(page.Data) != 50 || page.Truncated == nil || !*page.Truncated || *page.Limit != 50 || *page.Offset != 0 || stderr != "" {
		t.Errorf("--json: want 50 rows, truncated=true limit=50 offset=0 and no stderr; got %d rows, stderr %q, %s", len(page.Data), stderr, stdout[len(stdout)-60:])
	}
	if page.Data[0]["margin"] == nil {
		t.Errorf("rows carry their margin: %v", page.Data[0])
	}

	_, stderr, err = executeCommand("report", "breakeven", "--aff-campaign-id", "3", "--limit", "20", "--offset", "40")
	if err != nil {
		t.Fatal(err)
	}
	want := "Note: more rows remain after rows 41-60, most clicks first (each page ranked by margin); --offset 60 reads the next page (--limit takes up to 500).\n"
	if stderr != want {
		t.Errorf("table: stderr = %q, want %q", stderr, want)
	}
	last := requests()[len(requests())-1]
	if last.query.Get("limit") != "20" || last.query.Get("offset") != "40" || last.query.Get("breakdown") != "keyword" {
		t.Errorf("--limit and --offset go to the breakdown: %v", last.query)
	}
}

// losers classifies every row: it reads page after page, 500 a request,
// until none remain, and answers truncated=false.
func TestLosersReadsEveryPage(t *testing.T) {
	for name, withFlag := range map[string]bool{"server says": true, "server from before the flag": false} {
		t.Run(name, func(t *testing.T) {
			_, requests := depthServer(t, pagedBreakdown(1200, withFlag))
			stdout, stderr, err := executeCommand("report", "losers", "--breakdown", "keyword", "--no-attribution-check", "--json")
			if err != nil {
				t.Fatal(err)
			}
			var answer struct {
				Data      []map[string]interface{} `json:"data"`
				Truncated *bool                    `json:"truncated"`
			}
			if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
				t.Fatal(err)
			}
			if len(answer.Data) != 1200 || answer.Truncated == nil || *answer.Truncated || stderr != "" {
				t.Errorf("want all 1200 losers and truncated=false, got %d rows, truncated %v, stderr %q", len(answer.Data), answer.Truncated, stderr)
			}
			var pages []string
			for _, r := range requests() {
				pages = append(pages, r.query.Get("offset")+"+"+r.query.Get("limit"))
			}
			if got := strings.Join(pages, " "); got != "0+500 500+500 1000+500" {
				t.Errorf("pages read: %s", got)
			}
		})
	}
}

// Past breakdownReadRows a ranking command stops: the answer says it covers
// the rows read, in --json and in a table's note. Without the flag, one row
// more is asked for to tell the exact count from more.
func TestTriageStopsAtTheReadBoundAndSaysSo(t *testing.T) {
	cases := []struct {
		rows      int
		withFlag  bool
		truncated bool
		requests  int
	}{
		{5001, true, true, 10},
		{5000, true, false, 10},
		{5001, false, true, 11},
		{5000, false, false, 11},
	}
	for _, c := range cases {
		t.Run(fmt.Sprintf("%d rows, flag %t", c.rows, c.withFlag), func(t *testing.T) {
			_, requests := depthServer(t, pagedBreakdown(c.rows, c.withFlag))
			stdout, _, err := executeCommand("report", "losers", "--breakdown", "keyword", "--no-attribution-check", "--json")
			if err != nil {
				t.Fatal(err)
			}
			var answer struct {
				Data      []map[string]interface{} `json:"data"`
				Truncated *bool                    `json:"truncated"`
			}
			if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
				t.Fatal(err)
			}
			if len(answer.Data) != 5000 || answer.Truncated == nil || *answer.Truncated != c.truncated {
				t.Errorf("want 5000 rows and truncated=%t, got %d and %v", c.truncated, len(answer.Data), answer.Truncated)
			}
			if n := len(requests()); n != c.requests {
				t.Errorf("requests = %d, want %d", n, c.requests)
			}
		})
	}

	depthServer(t, pagedBreakdown(5001, true))
	stdout, stderr, err := executeCommand("report", "winners", "--breakdown", "keyword", "--no-attribution-check")
	if err != nil {
		t.Fatal(err)
	}
	want := "Note: this answer covers the first 5000 keyword rows (most clicks first); the breakdown has more. " +
		"Narrow the window (--period, --time-from/--time-to) or add a filter to cover every row.\n"
	if !strings.Contains(stderr, want) {
		t.Errorf("stderr = %q, want %q in it", stderr, want)
	}
	if strings.Contains(stdout, "Note") {
		t.Error("stdout stays data")
	}
}

// crosstab asks the server for the rows with the highest metric over every
// row (one more than --limit-rows, to know whether more remain), reads every
// column of each, and says when rows were left out.
func TestCrosstabRanksRowsOnTheServerAndSaysWhenRowsRemain(t *testing.T) {
	_, requests := depthServer(t, func(_ string, q url.Values) (int, string) {
		if q.Get("breakdown") == "ppc_account" {
			return pagedBreakdown(25, true)("/reports/breakdown", q)
		}
		return pagedBreakdown(3, true)("/reports/breakdown", q)
	})
	stdout, stderr, err := executeCommand("report", "crosstab", "--rows", "ppc_account", "--cols", "country", "--metric", "profit", "--limit-rows", "4", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var answer struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
	}
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
		t.Fatal(err)
	}
	if len(answer.Data) != 4 || answer.Truncated == nil || !*answer.Truncated || stderr != "" {
		t.Errorf("want 4 rows and truncated=true, got %d, %v, stderr %q", len(answer.Data), answer.Truncated, stderr)
	}
	first := requests()[0].query
	if first.Get("sort") != "total_net" || first.Get("sort_dir") != "DESC" || first.Get("limit") != "5" {
		t.Errorf("the row axis is ranked by the server: %v", first)
	}
	if n := len(requests()); n != 5 {
		t.Errorf("one request for the rows and one per row: %d", n)
	}

	_, stderr, err = executeCommand("report", "crosstab", "--rows", "ppc_account", "--cols", "country", "--metric", "profit", "--limit-rows", "4")
	if err != nil {
		t.Fatal(err)
	}
	if want := "Note: these are the 4 ppc_account rows with the highest total_net; more remain. --limit-rows sets how many (one request each; 0 for up to 5000).\n"; stderr != want {
		t.Errorf("stderr = %q, want %q", stderr, want)
	}

	_, stderr, err = executeCommand("report", "crosstab", "--rows", "ppc_account", "--cols", "country", "--limit-rows", "25")
	if err != nil || stderr != "" {
		t.Errorf("every row shown: no note, got %v %q", err, stderr)
	}
}

// A crosstab column breakdown past the read bound says which rows' columns it cut.
func TestCrosstabSaysWhenARowsColumnsWereCut(t *testing.T) {
	depthServer(t, func(_ string, q url.Values) (int, string) {
		if q.Get("breakdown") == "ppc_account" {
			return pagedBreakdown(2, true)("/reports/breakdown", q)
		}
		if q.Get("ppc_account_id") == "1" {
			return pagedBreakdown(5001, true)("/reports/breakdown", q)
		}
		return pagedBreakdown(3, true)("/reports/breakdown", q)
	})
	stdout, stderr, err := executeCommand("report", "crosstab", "--rows", "ppc_account", "--cols", "keyword", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	want := "Note: for 1 of these rows, keyword has more than 5000 values; their columns cover the 5000 with the most clicks. " +
		"Narrow the window (--period, --time-from/--time-to) or add a filter to cover every value.\n"
	if stderr != want {
		t.Errorf("stderr = %q, want %q", stderr, want)
	}
	if strings.Contains(stdout, "Note") {
		t.Error("stdout stays data")
	}
}

// campaign optimize ranks every other campaign by EPC; a failed read of them
// was dropped and read as "no alternative".
func TestCampaignOptimizeReadsEveryCampaignAndFailsLoudly(t *testing.T) {
	summary := `{"data":{"total_clicks":10,"total_leads":0,"total_cost":5,"total_income":0}}`
	depthServer(t, func(path string, q url.Values) (int, string) {
		switch {
		case strings.HasSuffix(path, "/reports/summary"):
			return 200, summary
		case strings.HasSuffix(path, "/reports/breakdown"):
			return pagedBreakdown(700, true)(path, q)
		}
		return 200, `{"data":{"aff_campaign_payout":"2"}}`
	})
	stdout, _, err := executeCommand("campaign", "optimize", "3", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var answer struct {
		Data      []map[string]interface{} `json:"data"`
		Truncated *bool                    `json:"truncated"`
	}
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
		t.Fatal(err)
	}
	if len(answer.Data) != 699 || answer.Truncated == nil || *answer.Truncated {
		t.Errorf("want the 699 other campaigns and truncated=false, got %d, %v", len(answer.Data), answer.Truncated)
	}

	depthServer(t, func(path string, q url.Values) (int, string) {
		switch {
		case strings.HasSuffix(path, "/reports/summary"):
			return 200, summary
		case strings.HasSuffix(path, "/reports/breakdown"):
			return 500, `{"error":true,"message":"Internal error","status":500}`
		}
		return 200, `{"data":{"aff_campaign_payout":"2"}}`
	})
	if _, _, err := executeCommand("campaign", "optimize", "3", "--json"); err == nil || !strings.Contains(err.Error(), "other campaigns") {
		t.Errorf("a failed read of the other campaigns must fail the command, got %v", err)
	}
}
