package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"sync"
	"testing"
)

// optimizeLosingSummary is campaign 9 losing money: $20 earned on 10 clicks (EPC $2) at a cost of $50.
const optimizeLosingSummary = `{"data":{"total_clicks":10,"total_click_throughs":10,"total_leads":2,"total_income":20,
 "total_cost":50,"total_net":-30,"epc":2,"avg_cpc":5,"conv_rate":20,"roi":-60,"cpa":25}}`

// optimizeRecordingServer answers campaign 9's payout and summary, and the campaign breakdown with breakdown(w);
// it records each request's path and query.
type optimizeRecordingServer struct {
	mu        sync.Mutex
	requests  []string
	summary   string
	breakdown func(w http.ResponseWriter)
}

func (s *optimizeRecordingServer) start(t *testing.T) *httptest.Server {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		s.requests = append(s.requests, r.URL.Path+"?"+r.URL.RawQuery)
		s.mu.Unlock()
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/9"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_payout":10}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(s.summary))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			s.breakdown(w)
		default:
			t.Errorf("unexpected request %s", r.URL)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv
}

func (s *optimizeRecordingServer) breakdownRequests() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []string
	for _, r := range s.requests {
		if strings.Contains(r, "/reports/breakdown?") {
			out = append(out, r)
		}
	}
	return out
}

// TestCampaignOptimizeFailsWhenItsRankingCannotBeRead: the command dropped the error of its campaign breakdown
// (`offers, _ :=`), so a 500, a scope refusal or an unreachable server answered as a campaign with no salvage offer,
// exit 0. Each failure is the command's error now, with its category, exit code and hint; nothing is printed.
func TestCampaignOptimizeFailsWhenItsRankingCannotBeRead(t *testing.T) {
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
			_, _ = w.Write([]byte(`{"error":true,"message":"Breakdown query failed"}`))
		}, ExitServer, "server", "p202 system health", 500, "Breakdown query failed"},
		{"a scope refusal", func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'reports:read' (key has: campaigns:read)."}`))
		}, ExitAuth, "auth", "p202 user apikey create", 403, "requires 'reports:read'"},
		{"a dropped connection", func(w http.ResponseWriter) {
			conn, _, err := w.(http.Hijacker).Hijack()
			if err == nil {
				_ = conn.Close()
			}
		}, ExitNetwork, "network", "p202 config test", 0, "network error"},
		{"an answer with no data list", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`{"ok":true}`))
		}, ExitServer, "server", "p202 system health", 0, "is not a breakdown"},
		{"an answer that is not JSON", func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(`<html>proxy error</html>`))
		}, ExitServer, "server", "p202 system health", 0, "is not a breakdown"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			fake := &optimizeRecordingServer{summary: optimizeLosingSummary, breakdown: tc.answer}
			fake.start(t)

			stdout, stderr, err := executeCommand("campaign", "optimize", "9", "--json")
			if len(fake.breakdownRequests()) == 0 {
				t.Fatalf("the ranking was never asked for: %v", fake.requests)
			}
			if err == nil {
				t.Fatalf("a failed ranking read answered as a ranking: stdout %q stderr %q", stdout, stderr)
			}
			if got := exitCodeForError(err); got != tc.exit {
				t.Errorf("exit code %d, want %d (%v)", got, tc.exit, err)
			}
			if got := hintFor(err); !strings.Contains(got, tc.hint) {
				t.Errorf("hint %q, want one naming %q", got, tc.hint)
			}
			if !strings.Contains(err.Error(), "ranking the other campaigns") || !strings.Contains(err.Error(), tc.inMessage) {
				t.Errorf("the error should say which read failed and why: %v", err)
			}
			if stdout != "" || stderr != "" {
				t.Errorf("a failed run printed: stdout %q stderr %q", stdout, stderr)
			}

			// As main runs it: stdout empty, the envelope alone on stderr.
			stdout, stderr, code := runAsMain("campaign", "optimize", "9", "--json")
			if code != tc.exit || stdout != "" {
				t.Fatalf("exit %d, stdout %q; want exit %d and nothing on stdout", code, stdout, tc.exit)
			}
			var env struct {
				Error struct {
					Category   string `json:"category"`
					Message    string `json:"message"`
					Hint       string `json:"hint"`
					ExitCode   int    `json:"exit_code"`
					Command    string `json:"command"`
					HTTPStatus int    `json:"http_status"`
				} `json:"error"`
			}
			if err := json.Unmarshal([]byte(stderr), &env); err != nil {
				t.Fatalf("stderr is not the error envelope alone: %v\n%s", err, stderr)
			}
			e := env.Error
			if e.Category != tc.category || e.ExitCode != tc.exit || e.HTTPStatus != tc.httpCode ||
				e.Command != "p202 campaign optimize" || !strings.Contains(e.Hint, tc.hint) {
				t.Errorf("envelope %+v", e)
			}

			// The MCP tool runs the same command.
			if _, _, err := mcpExecute(t, "mcp", "call", "campaign_optimize", "--input", `{"campaign_id":"9"}`); err == nil || exitCodeForError(err) != tc.exit {
				t.Errorf("campaign_optimize: %v, want exit %d", err, tc.exit)
			}
		})
	}
}

// The diagnosis's summary read the same way: an answer with no data object read every figure as 0, and a campaign
// with real traffic was diagnosed as one with none (net 0, "profitable"); one that is not JSON exited 1 with a hint
// to read --help.
func TestCampaignOptimizeFailsWhenItsSummaryIsNotOne(t *testing.T) {
	for _, summary := range []string{`{"ok":true}`, `{"data":null}`, `<html>proxy error</html>`} {
		fake := &optimizeRecordingServer{summary: summary, breakdown: func(w http.ResponseWriter) {
			_, _ = w.Write([]byte(optimizeRankedRows))
		}}
		fake.start(t)
		stdout, stderr, code := runAsMain("campaign", "optimize", "9", "--json")
		if code != ExitServer || stdout != "" {
			t.Errorf("summary %s: exit %d, stdout %q; want exit %d and nothing on stdout", summary, code, stdout, ExitServer)
			continue
		}
		var env struct {
			Error struct{ Category, Message, Hint string } `json:"error"`
		}
		if err := json.Unmarshal([]byte(stderr), &env); err != nil {
			t.Fatalf("summary %s: stderr is not the error envelope alone: %v\n%s", summary, err, stderr)
		}
		if env.Error.Category != "server" || !strings.Contains(env.Error.Message, "is not a summary") ||
			!strings.Contains(env.Error.Hint, "p202 report summary --aff-campaign-id 9 --json") {
			t.Errorf("summary %s: envelope %+v", summary, env.Error)
		}
	}
}

// optimizeRankedRows: campaign 9 itself, campaign 12 (EPC $1), campaign 11 (EPC $3, earning less in all, so ranked
// below 12 by the server's sort on income), and a row with no campaign (clicks whose campaign is not the account's).
const optimizeRankedRows = `{"data":[
 {"id":9,"name":"this offer","total_clicks":10,"total_leads":2,"total_income":20,"total_cost":50},
 {"id":12,"name":"wide offer","total_clicks":100,"total_leads":5,"total_income":100,"total_cost":40},
 {"id":null,"name":null,"total_clicks":1,"total_leads":1,"total_income":90,"total_cost":0},
 {"id":"11","name":"salvage offer","total_clicks":20,"total_leads":3,"total_income":60,"total_cost":10}]}`

var defaultCampaignLine = regexp.MustCompile(`--default-campaign (\S+)`)

// TestCampaignOptimizeNextNamesARankedCampaign: the Next step said `--default-campaign <salvage_id>`, and no ranked
// row carried an id, so an agent could not follow it. Each row carries its aff_campaign_id, and the Next step names
// the first row's.
func TestCampaignOptimizeNextNamesARankedCampaign(t *testing.T) {
	fake := &optimizeRecordingServer{summary: optimizeLosingSummary, breakdown: func(w http.ResponseWriter) {
		_, _ = w.Write([]byte(optimizeRankedRows))
	}}
	fake.start(t)

	stdout, stderr, err := executeCommand("campaign", "optimize", "9", "--json")
	if err != nil {
		t.Fatalf("campaign optimize: %v\n%s", err, stderr)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	var ids []string
	for _, r := range resp.Data {
		id, ok := r["aff_campaign_id"].(float64)
		if !ok {
			t.Fatalf("row %v: aff_campaign_id should be a number", r)
		}
		ids = append(ids, fmt.Sprint(id))
	}
	if strings.Join(ids, ",") != "11,12" {
		t.Errorf("ranked ids %v, want 11 (EPC $3) then 12 (EPC $1): not campaign 9 itself, nor the row with no campaign", ids)
	}

	m := defaultCampaignLine.FindStringSubmatch(stderr)
	if m == nil {
		t.Fatalf("no --default-campaign in the Next step:\n%s", stderr)
	}
	if m[1] != ids[0] {
		t.Errorf("Next names --default-campaign %s; the top ranked row is %s", m[1], ids[0])
	}
	if !strings.Contains(stderr, `"salvage offer" (campaign 11, EPC $3.000, vs $2.000 here)`) ||
		!strings.Contains(stderr, "--redirect-campaign 9\n") {
		t.Errorf("the recommendation should name the salvage campaign and route back to this one:\n%s", stderr)
	}
	if strings.Contains(stderr, "<salvage_id>") {
		t.Errorf("a placeholder is left in the Next step:\n%s", stderr)
	}
}

// With no other campaign earning more per click, there is no salvage offer: the Next step names none (routing the
// traffic to a campaign that earns less would lose more), and says so, while the rows still rank what there is. The
// step it names instead reads the diagnosis's window and filters.
func TestCampaignOptimizeNamesNoSalvageOfferWhenNoneEarnsMore(t *testing.T) {
	const losers = "Next: cut the sources that lose: p202 report losers --aff-campaign-id 9 --period last30 --country-id 5 --keyword 'red shoes'\n"
	for _, tc := range []struct{ name, rows, ids string }{
		{"only campaigns earning less", `{"data":[{"id":12,"name":"wide offer","total_clicks":100,"total_income":100}]}`, "12"},
		{"no other campaign", `{"data":[{"id":9,"name":"this offer","total_clicks":10,"total_income":20}]}`, ""},
		{"an empty breakdown", `{"data":[]}`, ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			fake := &optimizeRecordingServer{summary: optimizeLosingSummary, breakdown: func(w http.ResponseWriter) {
				_, _ = w.Write([]byte(tc.rows))
			}}
			fake.start(t)
			stdout, stderr, err := executeCommand("campaign", "optimize", "9", "--period", "last30", "--country-id", "5", "--keyword", "red shoes", "--json")
			if err != nil {
				t.Fatalf("campaign optimize: %v\n%s", err, stderr)
			}
			if strings.Contains(stderr, "--default-campaign") {
				t.Errorf("a salvage offer was named:\n%s", stderr)
			}
			if !strings.Contains(stderr, "there is no salvage offer") || !strings.Contains(stderr, losers) {
				t.Errorf("the recommendation should say there is none, and what to do instead:\n%s", stderr)
			}
			var resp struct {
				Data []map[string]interface{} `json:"data"`
			}
			if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
				t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
			}
			var ids []string
			for _, r := range resp.Data {
				ids = append(ids, fmt.Sprint(r["aff_campaign_id"]))
			}
			if strings.Join(ids, ",") != tc.ids {
				t.Errorf("rows %v, want %q", resp.Data, tc.ids)
			}
		})
	}
}

// The ranking covers every campaign: a configured report.aff_campaign_id default narrowed it to that one campaign,
// which then answered that there was no salvage offer, and --aff-campaign-id, which this command does not use for
// its own campaign, did the same. The default is left out of the ranking; the flag is refused before any request.
func TestCampaignOptimizeRanksEveryCampaign(t *testing.T) {
	fake := &optimizeRecordingServer{summary: optimizeLosingSummary, breakdown: func(w http.ResponseWriter) {
		_, _ = w.Write([]byte(optimizeRankedRows))
	}}
	srv := fake.start(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithDefaults(t, tmp, srv.URL, "test-key", map[string]string{"report.aff_campaign_id": "9", "report.period": "last7"})

	if _, stderr, err := executeCommand("campaign", "optimize", "9", "--json"); err != nil {
		t.Fatalf("campaign optimize: %v\n%s", err, stderr)
	}
	reqs := fake.breakdownRequests()
	if len(reqs) != 1 || strings.Contains(reqs[0], "aff_campaign_id") || !strings.Contains(reqs[0], "period=last7") {
		t.Errorf("the ranking's request %v should keep the window and leave out the campaign default", reqs)
	}

	fake.requests = nil
	for _, flag := range []string{"--aff-campaign-id", "--aff_campaign_id"} {
		_, _, err := executeCommand("campaign", "optimize", "9", flag, "12", "--json")
		if err == nil {
			t.Errorf("%s was not refused", flag)
			continue
		}
		if exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 campaign optimize 9") {
			t.Errorf("%s: %v / %q, want a validation refusal naming the command to run", flag, err, hintFor(err))
		}
	}
	if len(fake.requests) != 0 {
		t.Errorf("a refused flag sent %v", fake.requests)
	}
}

// fetchBreakdownRows is every ranking's read (report breakeven, losers, winners and crosstab, and campaign optimize):
// an answer with no data list read as no rows, and one that is not JSON exited 1 with a hint to read --help. Both are
// the server's failure.
func TestBreakdownReadersRefuseAnAnswerThatIsNotOne(t *testing.T) {
	for _, body := range []string{`{"ok":true}`, `<html>proxy error</html>`} {
		srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
			switch {
			case strings.HasSuffix(r.URL.Path, "/campaigns/9"):
				_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_payout":10}}`))
			case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
				_, _ = w.Write([]byte(body))
			default:
				_, _ = w.Write([]byte(`{"data":[]}`))
			}
		}))
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key")
		for _, args := range [][]string{
			{"report", "breakeven", "--aff-campaign-id", "9"},
			{"report", "losers", "--aff-campaign-id", "9", "--no-attribution-check"},
			{"report", "winners", "--no-attribution-check"},
			{"report", "crosstab", "--rows", "campaign", "--cols", "country"},
		} {
			stdout, _, err := executeCommand(append(args, "--json")...)
			if err == nil {
				t.Errorf("%v with %s: answered %s", args, body, stdout)
				continue
			}
			if exitCodeForError(err) != ExitServer || !strings.Contains(err.Error(), "is not a breakdown") ||
				!strings.Contains(hintFor(err), "p202 system health") {
				t.Errorf("%v with %s: %v (exit %d, hint %q)", args, body, err, exitCodeForError(err), hintFor(err))
			}
		}
		srv.Close()
	}
}
