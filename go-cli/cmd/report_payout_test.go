package cmd

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"net/http/httptest"
	"sort"
	"strings"
	"sync"
	"testing"
)

// payoutBreakdown is a keyword breakdown: "overbid" pays $3.00 a click against a break-even of $1.60 at a $160 payout,
// "no sales" spends without converting, "winner" profits.
const payoutBreakdown = `{"data":[
 {"id":21,"name":"overbid","total_clicks":100,"total_click_throughs":100,"total_leads":1,"total_income":160,"total_cost":300,"total_net":-140},
 {"id":22,"name":"no sales","total_clicks":100,"total_click_throughs":100,"total_leads":0,"total_income":0,"total_cost":50,"total_net":-50},
 {"id":23,"name":"winner","total_clicks":100,"total_click_throughs":100,"total_leads":5,"total_income":800,"total_cost":100,"total_net":700}]}`

// campaignRecord is GET /campaigns/7 as the API answers it, with payout as the raw JSON of aff_campaign_payout ("" to
// leave the field out). The column is DECIMAL(8,2) NOT NULL: 1.9.77 and later send a number, older servers a string.
func campaignRecord(payout string) string {
	field := ""
	if payout != "" {
		field = `"aff_campaign_payout":` + payout + `,`
	}
	return `{"data":{"aff_campaign_id":7,"aff_network_id":2,"aff_campaign_name":"Offer A","aff_campaign_url":"https://offer.example/?s=[[subid]]",` +
		field + `"aff_campaign_currency":"USD","aff_campaign_foreign_payout":0,"aff_campaign_cloaking":0,"payout_mode":"replace",` +
		`"version":"0f1e","etag":"\"0f1e\""}}`
}

// payoutFake answers GET /campaigns/7 with campaign and the classic breakdown with breakdown, recording each path.
type payoutFake struct {
	mu        sync.Mutex
	paths     []string
	campaign  func(w http.ResponseWriter)
	breakdown string
}

func (f *payoutFake) start(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.paths = append(f.paths, r.URL.Path)
		f.mu.Unlock()
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/7"):
			f.campaign(w)
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown") && !strings.Contains(r.URL.Path, "attribution"):
			_, _ = w.Write([]byte(f.breakdown))
		default:
			t.Errorf("unexpected request %s", r.URL)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
}

func (f *payoutFake) read(suffix string) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, p := range f.paths {
		if strings.HasSuffix(p, suffix) {
			n++
		}
	}
	return n
}

// payoutCommands are the commands that price clicks with the payout of --aff-campaign-id, and the MCP tool of each.
var payoutCommands = []struct {
	args []string
	tool string
}{
	{[]string{"report", "losers", "--aff-campaign-id", "7"}, "report_losers"},
	{[]string{"report", "winners", "--aff-campaign-id", "7"}, "report_winners"},
	{[]string{"report", "breakeven", "--aff-campaign-id", "7"}, "report_breakeven"},
}

// TestPayoutCommandsFailWhenThePayoutCannotBeRead: losers and winners dropped the error of their read of the
// campaign's payout (`payout, _ = fetchCampaignPayout(…)`), and the read answered 0 for an answer with no payout in
// it, so a server error, a refused key or a proxy's page priced every click at nothing: losers listed only the rows
// that never converted, exit 0. Each failure is the command's error now, with its category, exit code and hint, before
// any row is read.
func TestPayoutCommandsFailWhenThePayoutCannotBeRead(t *testing.T) {
	cases := []struct {
		name      string
		answer    func(w http.ResponseWriter)
		exit      int
		category  string
		hint      string
		httpCode  int
		inMessage string
	}{
		{"a server error", func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusInternalServerError)
			_, _ = w.Write([]byte(`{"error":true,"message":"Internal server error","status":500}`))
		}, ExitServer, "server", "p202 system health", 500, "reading campaign 7's payout: API error (500): Internal server error"},
		{"a role without Setup", func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(`{"error":true,"message":"This account's role does not have the 'access_to_setup_section' permission.","status":403}`))
		}, ExitAuth, "auth", "p202 user role assign", 403, "'access_to_setup_section' permission"},
		{"no such campaign", func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"error":true,"message":"Not found","status":404}`))
		}, ExitValidation, "validation", "p202 campaign list", 404, "reading campaign 7's payout"},
		{"a dropped connection", func(w http.ResponseWriter) {
			conn, _, err := w.(http.Hijacker).Hijack()
			if err == nil {
				_ = conn.Close()
			}
		}, ExitNetwork, "network", "p202 config test", 0, "reading campaign 7's payout"},
		{"an answer with no data object", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`{"ok":true}`))
		}, ExitServer, "server", "p202 campaign get 7 --json", 0, "has no payout to read (it has no data object)"},
		{"a record without its payout", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(campaignRecord("")))
		}, ExitServer, "server", "p202 campaign get 7 --json", 0, "has no payout to read (it has no aff_campaign_payout)"},
		{"a null payout", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(campaignRecord("null")))
		}, ExitServer, "server", "p202 campaign get 7 --json", 0, "has no payout to read (it has no aff_campaign_payout)"},
		{"a payout that is not a number", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(campaignRecord(`"n/a"`)))
		}, ExitServer, "server", "p202 campaign get 7 --json", 0, `aff_campaign_payout is not a number: "n/a"`},
		{"an answer that is not JSON", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`<html>proxy error</html>`))
		}, ExitServer, "server", "p202 system health", 0, "GET campaigns/7 has no payout to read"},
	}
	for _, c := range payoutCommands {
		for _, tc := range cases {
			t.Run(c.args[1]+"/"+tc.name, func(t *testing.T) {
				fake := &payoutFake{campaign: tc.answer, breakdown: payoutBreakdown}
				fake.start(t)

				stdout, stderr, err := executeCommand(append(c.args, "--json")...)
				if fake.read("/campaigns/7") == 0 {
					t.Fatalf("the payout was never asked for: %v", fake.paths)
				}
				if err == nil {
					t.Fatalf("a payout that could not be read priced the rows: stdout %q stderr %q", stdout, stderr)
				}
				if got := exitCodeForError(err); got != tc.exit {
					t.Errorf("exit code %d, want %d (%v)", got, tc.exit, err)
				}
				if got := hintFor(err); !strings.Contains(got, tc.hint) {
					t.Errorf("hint %q, want one naming %q", got, tc.hint)
				}
				if !strings.Contains(err.Error(), tc.inMessage) {
					t.Errorf("error %q, want it to say %q", err, tc.inMessage)
				}
				if stdout != "" || stderr != "" {
					t.Errorf("a failed run printed: stdout %q stderr %q", stdout, stderr)
				}
				if n := fake.read("/reports/breakdown"); n != 0 {
					t.Errorf("the breakdown was read %d time(s) with no payout to price it", n)
				}

				// As main runs it: stdout empty, the envelope alone on stderr.
				stdout, stderr, code := runAsMain(append(c.args, "--json")...)
				if code != tc.exit || stdout != "" {
					t.Fatalf("exit %d, stdout %q; want exit %d and nothing on stdout", code, stdout, tc.exit)
				}
				var env struct {
					Error struct {
						Category   string `json:"category"`
						Hint       string `json:"hint"`
						ExitCode   int    `json:"exit_code"`
						Command    string `json:"command"`
						HTTPStatus int    `json:"http_status"`
					} `json:"error"`
				}
				if err := json.Unmarshal([]byte(stderr), &env); err != nil {
					t.Fatalf("stderr is not the error envelope alone: %v\n%s", err, stderr)
				}
				if e := env.Error; e.Category != tc.category || e.ExitCode != tc.exit || e.HTTPStatus != tc.httpCode ||
					e.Command != "p202 "+strings.Join(c.args[:2], " ") || !strings.Contains(e.Hint, tc.hint) {
					t.Errorf("envelope %+v", e)
				}

				// The MCP tool runs the same command.
				if _, _, err := mcpExecute(t, "mcp", "call", c.tool, "--input", `{"aff_campaign_id":"7"}`); err == nil || exitCodeForError(err) != tc.exit {
					t.Errorf("%s: %v, want exit %d", c.tool, err, tc.exit)
				}
			})
		}
	}
}

// TestPayoutCommandsReadThePayoutAsTheAPIAnswersIt: a number from 1.9.77 on, a numeric string before, and a payout of
// 0 is 0 — not an error, and not a reason to read the payout as unknown.
func TestPayoutCommandsReadThePayoutAsTheAPIAnswersIt(t *testing.T) {
	for _, tc := range []struct {
		payout    string
		losers    string
		breakeven float64
	}{
		{`160`, "no sales,overbid", 1.6},
		{`"160.00"`, "no sales,overbid", 1.6},
		// No payout to price a click: only the spend that never converted is CUT, and "overbid" is WATCH.
		{`0`, "no sales", 0},
		{`"0.00"`, "no sales", 0},
	} {
		t.Run(tc.payout, func(t *testing.T) {
			fake := &payoutFake{breakdown: payoutBreakdown, campaign: func(w http.ResponseWriter) {
				_, _ = w.Write([]byte(campaignRecord(tc.payout)))
			}}
			fake.start(t)

			names := func(args ...string) (string, []map[string]interface{}) {
				t.Helper()
				stdout, stderr, err := executeCommand(append(args, "--aff-campaign-id", "7", "--json")...)
				if err != nil {
					t.Fatalf("%v: %v\n%s", args, err, stderr)
				}
				var resp struct {
					Data []map[string]interface{} `json:"data"`
				}
				if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
					t.Fatalf("%v: stdout is not JSON: %v\n%s", args, err, stdout)
				}
				var got []string
				for _, r := range resp.Data {
					got = append(got, fmt.Sprint(r["name"]))
				}
				sort.Strings(got)
				return strings.Join(got, ","), resp.Data
			}
			if got, _ := names("report", "losers"); got != tc.losers {
				t.Errorf("losers listed %q, want %q", got, tc.losers)
			}
			if got, _ := names("report", "winners"); got != "winner" {
				t.Errorf("winners listed %q, want winner", got)
			}
			_, rows := names("report", "breakeven")
			for _, r := range rows {
				if r["name"] == "overbid" && toFloat(r["breakeven_cpc"]) != tc.breakeven {
					t.Errorf("overbid's breakeven_cpc %v, want %v", r["breakeven_cpc"], tc.breakeven)
				}
			}
			if n := fake.read("/campaigns/7"); n != 3 {
				t.Errorf("the payout was read %d times, want once per command", n)
			}
		})
	}
}

// TestRowsToJSONReturnsItsError: rowsToJSON printed its encoding error and returned nil, so a row JSON cannot carry
// printed nothing on stdout and every caller exited 0.
func TestRowsToJSONReturnsItsError(t *testing.T) {
	out, err := rowsToJSON([]map[string]interface{}{{"name": "a", "total_net": math.Inf(1)}})
	if err == nil || out != nil {
		t.Fatalf("an unencodable row: %q, %v; want no bytes and an error", out, err)
	}
	if exitCodeForError(err) != ExitServer || !strings.Contains(err.Error(), "could not be encoded as JSON") ||
		!strings.Contains(hintFor(err), "p202 system health") {
		t.Errorf("%v: exit %d, hint %q", err, exitCodeForError(err), hintFor(err))
	}
	out, err = rowsToJSON([]map[string]interface{}{{"name": "a", "total_net": 1.5}})
	if err != nil || string(out) != `{"data":[{"name":"a","total_net":1.5}]}` {
		t.Errorf("rows that encode: %q, %v", out, err)
	}
}

// A figure the server sent too large to compute with makes a row JSON cannot carry: the command fails with that,
// having printed nothing, its notes included.
func TestRankingsFailOnRowsTheyCannotEncode(t *testing.T) {
	for _, tc := range []struct {
		args      []string
		breakdown string
	}{
		// sales × --payout overflows to +Inf.
		{[]string{"report", "winners", "--payout", "1000000000", "--no-attribution-check"},
			`{"data":[{"id":23,"name":"winner","total_clicks":100,"total_leads":1e300,"total_income":1,"total_cost":1,"total_net":0}]}`},
		// cost ÷ clicks overflows to +Inf.
		{[]string{"report", "breakeven", "--aff-campaign-id", "7"},
			`{"data":[{"id":21,"name":"overbid","total_clicks":0.5,"total_leads":0,"total_income":0,"total_cost":1.7e308,"total_net":0}]}`},
	} {
		fake := &payoutFake{breakdown: tc.breakdown, campaign: func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(campaignRecord(`160`)))
		}}
		fake.start(t)
		stdout, stderr, err := executeCommand(append(tc.args, "--json")...)
		if err == nil {
			t.Errorf("%v: answered %q", tc.args, stdout)
			continue
		}
		if exitCodeForError(err) != ExitServer || !strings.Contains(err.Error(), "could not be encoded as JSON") ||
			!strings.Contains(hintFor(err), "p202 report breakdown --json") {
			t.Errorf("%v: %v (exit %d, hint %q)", tc.args, err, exitCodeForError(err), hintFor(err))
		}
		if stdout != "" || stderr != "" {
			t.Errorf("%v: a failed run printed: stdout %q stderr %q", tc.args, stdout, stderr)
		}
	}
}
