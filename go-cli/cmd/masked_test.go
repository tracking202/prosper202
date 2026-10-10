package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"testing"

	"p202/internal/output"
)

// maskedReports answers every report as the server does to a role without
// access_to_campaign_data: absolute figures null, ratios kept, masked: true.
func maskedReports(t *testing.T) *httptest.Server {
	t.Helper()
	row := `{"id":3,"name":"A","total_clicks":null,"total_click_throughs":null,"total_leads":null,` +
		`"total_income":null,"total_cost":null,"total_net":null,"epc":2.5,"avg_cpc":0.5,"conv_rate":10,"roi":20,"cpa":5}`
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(`{"data":` + row + `,"masked":true}`))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			if r.URL.Query().Get("offset") != "" && r.URL.Query().Get("offset") != "0" {
				_, _ = w.Write([]byte(`{"data":[],"masked":true}`))
				return
			}
			_, _ = w.Write([]byte(`{"data":[` + row + `],"masked":true}`))
		case strings.HasSuffix(r.URL.Path, "/reports/timeseries"):
			_, _ = w.Write([]byte(`{"data":[{"period":"2026-10-01","total_clicks":null,"total_income":null,"epc":1}],"interval":"day","truncated":false,"limit":2000,"masked":true}`))
		case strings.Contains(r.URL.Path, "/campaigns/"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":3,"aff_campaign_name":"A"}}`))
		default:
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
}

// A command that sums, ranks or forecasts from absolute figures refuses a
// masked answer -- its nulls are hidden values, and read as zeros they
// would print confident wrong numbers -- with the auth exit code and a
// hint; a command that shows the answer shows it.
func TestCommandsThatComputeRefuseAMaskedAnswer(t *testing.T) {
	srv := maskedReports(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	for _, args := range [][]string{
		{"campaign", "optimize", "3"},
		{"report", "losers", "--breakdown", "ppc_account"},
		{"forecast", "--metric", "clicks"},
		{"analytics", "--group-by", "country", "--split-at", "2026-09-04"},
	} {
		stdout, _, err := executeCommand(append(args, "--json")...)
		if err == nil {
			t.Errorf("%v: computed from a masked answer: %s", args, stdout)
			continue
		}
		if code := exitCodeForError(err); code != ExitAuth {
			t.Errorf("%v: exit %d, want %d (%v)", args, code, ExitAuth, err)
		}
		if !strings.Contains(err.Error(), "access_to_campaign_data") || !strings.Contains(hintFor(err), "p202 whoami") {
			t.Errorf("%v: the refusal must name the permission and the next step: %v / %q", args, err, hintFor(err))
		}
	}

	stdout, _, err := executeCommand("report", "summary", "--json")
	if err != nil {
		t.Fatalf("report summary shows a masked answer: %v", err)
	}
	if !strings.Contains(stdout, `"masked": true`) || !strings.Contains(stdout, `"epc": 2.5`) {
		t.Errorf("report summary --json: the flag and the ratios, got %s", stdout)
	}
}

// Summing a summary across profiles names a masked profile as failed rather
// than leaving its hidden totals out of the sum without a word.
func TestAMaskedProfileIsNamedNotSummedAsZero(t *testing.T) {
	plain := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":{"total_clicks":7,"total_leads":2}}`))
	}))
	defer plain.Close()
	masked := maskedReports(t)
	defer masked.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":   {"url": plain.URL, "api_key": "prod-key-123456"},
		"viewer": {"url": masked.URL, "api_key": "viewer-key-123456"},
	})

	stdout, _, err := executeCommand("report", "summary", "--profiles", "prod,viewer", "--json")
	if err != nil {
		t.Fatalf("a partial answer still answers: %v", err)
	}
	var parsed struct {
		Errors     []string               `json:"errors"`
		Aggregated map[string]interface{} `json:"aggregated"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON: %v\n%s", err, stdout)
	}
	if len(parsed.Errors) != 1 || !strings.HasPrefix(parsed.Errors[0], "viewer:") || !strings.Contains(parsed.Errors[0], "access_to_campaign_data") {
		t.Errorf("the masked profile is named as failed, got %v", parsed.Errors)
	}
	if got, _ := parsed.Aggregated["total_clicks"].(float64); got != 7 {
		t.Errorf("aggregated total_clicks = %v, want prod's 7", parsed.Aggregated["total_clicks"])
	}
}

// report breakdown's client-side filters read absolute figures, which a
// masked answer holds null: read as 0, --min-clicks 10 answered {"data": []}
// and --having total_leads=0 every row, exit 0. A filter on a hidden figure
// is refused as `report losers` refuses one (exit 2, naming the permission
// and the filter); a filter on a ratio still runs and keeps the flag beside
// its rows; no filter shows the answer as sent.
func TestBreakdownFiltersRefuseAHiddenFigure(t *testing.T) {
	srv := maskedReports(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	for _, filter := range [][]string{
		{"--min-clicks", "10"},
		{"--min-cost", "1"},
		{"--zero-leads"},
		{"--having", "total_leads=0"},
		{"--having", "clicks>5"}, // an alias of total_clicks
		{"--min-clicks", "1", "--having", "roi>0"},
	} {
		args := append([]string{"report", "breakdown", "--breakdown", "campaign", "--json"}, filter...)
		stdout, _, err := executeCommand(args...)
		if err == nil {
			t.Errorf("%v: filtered hidden figures as zeros: %s", filter, stdout)
			continue
		}
		if code := exitCodeForError(err); code != ExitAuth {
			t.Errorf("%v: exit %d, want %d (%v)", filter, code, ExitAuth, err)
		}
		if !strings.Contains(err.Error(), "access_to_campaign_data") || !strings.Contains(err.Error(), filter[0]) {
			t.Errorf("%v: the refusal must name the permission and the filter: %v", filter, err)
		}
		if h := hintFor(err); !strings.Contains(h, "p202 whoami") || !strings.Contains(h, "--having 'roi<0'") {
			t.Errorf("%v: the hint must name a key that sees them and a filter that works: %q", filter, h)
		}
		if stdout != "" {
			t.Errorf("%v: stdout must stay empty on a refusal, got %q", filter, stdout)
		}
	}

	stdout, _, err := executeCommand("report", "breakdown", "--breakdown", "campaign", "--having", "roi>10", "--ndjson")
	if err != nil {
		t.Fatalf("a filter on a ratio runs on a masked answer: %v", err)
	}
	if lines := strings.Split(strings.TrimSpace(stdout), "\n"); len(lines) != 1 || !strings.Contains(lines[0], `"masked":true`) || !strings.Contains(lines[0], `"roi":20`) {
		t.Errorf("--having roi>10 --ndjson: want the one row with masked:true, got %q", stdout)
	}
	stdout, _, err = executeCommand("report", "breakdown", "--breakdown", "campaign", "--having", "roi<10", "--json")
	if err != nil || !strings.Contains(stdout, `"masked": true`) || !strings.Contains(stdout, `"data": []`) {
		t.Errorf("--having roi<10 --json: want no rows and the flag, got %v %s", err, stdout)
	}
	stdout, _, err = executeCommand("report", "breakdown", "--breakdown", "campaign", "--json")
	if err != nil || !strings.Contains(stdout, `"masked": true`) || !strings.Contains(stdout, `"total_clicks": null`) {
		t.Errorf("unfiltered: the answer as sent, got %v %s", err, stdout)
	}
}

// --all rebuilt the clicks' and conversions' answer as {data, pagination}
// and dropped the server's masked flag: --ndjson rows lost "masked":true and
// the table its note, so a hidden cost read as a missing one. Every page is
// masked; the rebuilt answer says so, as one page does.
func TestListAllKeepsTheMaskedFlag(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		row := `{"click_id":7,"click_cpc":null,"click_payout":null}`
		if strings.HasSuffix(r.URL.Path, "/conversions") {
			row = `{"conv_id":1,"click_id":7,"amount":null}`
		}
		if off := r.URL.Query().Get("offset"); off != "" && off != "0" {
			_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":1,"limit":100,"offset":` + off + `},"masked":true}`))
			return
		}
		_, _ = w.Write([]byte(`{"data":[` + row + `],"pagination":{"total":1,"limit":100,"offset":0},"masked":true}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	for _, list := range [][]string{{"click", "list", "--all"}, {"conversion", "list", "--all"}} {
		stdout, _, err := executeCommand(append(list, "--ndjson")...)
		if err != nil {
			t.Fatalf("%v --ndjson: %v", list, err)
		}
		if lines := strings.Split(strings.TrimSpace(stdout), "\n"); len(lines) != 1 || !strings.Contains(lines[0], `"masked":true`) {
			t.Errorf("%v --ndjson: every row carries masked:true, got %q", list, stdout)
		}
		stdout, _, err = executeCommand(append(list, "--json")...)
		if err != nil || !strings.Contains(stdout, `"masked": true`) {
			t.Errorf("%v --json: the answer keeps masked:true, got %v %s", list, err, stdout)
		}
		_, stderr, err := executeCommand(append(list, "--table")...)
		if err != nil || !strings.Contains(stderr, output.MaskedNote) {
			t.Errorf("%v --table: the note goes under the table, got %v %q", list, err, stderr)
		}
	}
}

// The fields a filter may not read from a masked answer are the ones the
// server masks; a figure it starts to mask and the CLI does not know would
// be read as 0 again.
func TestMaskedReportFiguresAreTheServers(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Support", "CampaignFigures.php"))
	if err != nil {
		t.Fatalf("reading CampaignFigures.php: %v", err)
	}
	m := regexp.MustCompile(`(?s)public const array REPORT = \[(.*?)\];`).FindSubmatch(src)
	if m == nil {
		t.Fatal("CampaignFigures::REPORT not found: the scan is blind")
	}
	var server []string
	for _, q := range regexp.MustCompile(`'([a-z_]+)'`).FindAllSubmatch(m[1], -1) {
		server = append(server, string(q[1]))
	}
	if len(server) == 0 || strings.Join(server, ",") != strings.Join(maskedReportFigures, ",") {
		t.Errorf("maskedReportFigures = %v, the server masks %v", maskedReportFigures, server)
	}
}
