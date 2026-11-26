package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"testing"
)

// serveReport answers every request with body and records the queries.
func serveReport(t *testing.T, path, body string) *[]map[string][]string {
	t.Helper()
	var queries []map[string][]string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != path {
			t.Errorf("path = %q, want %s", r.URL.Path, path)
		}
		queries = append(queries, r.URL.Query())
		w.WriteHeader(200)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &queries
}

const comparedSummary = `{"data":{"total_clicks":6,"total_leads":2,"total_income":30,"currency":"USD"},` +
	`"other_currencies":[],"window":{"time_from":1772859600,"time_to":1773287999},` +
	`"previous":{"time_from":1772427600,"time_to":1772859599,"data":{"total_clicks":4,"total_leads":2,"total_income":20,"currency":"USD"},"other_currencies":[]}}`

func TestReportSummaryCompareAsksForThePreviousWindowAndShowsBoth(t *testing.T) {
	queries := serveReport(t, "/api/v3/reports/summary", comparedSummary)
	stdout, _, err := executeCommand("report", "summary", "--time-from", "2026-03-07", "--time-to", "2026-03-11", "--compare", "Previous", "--csv")
	if err != nil {
		t.Fatalf("report summary --compare: %v", err)
	}
	if got := (*queries)[0]["compare"]; len(got) != 1 || got[0] != "previous" {
		t.Errorf("compare = %v, want [previous]", got)
	}
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) != 3 {
		t.Fatalf("want a header and two rows, got:\n%s", stdout)
	}
	if !strings.Contains(lines[1], "current") || !strings.Contains(lines[1], "6") ||
		!strings.Contains(lines[2], "previous") || !strings.Contains(lines[2], "4") {
		t.Errorf("rows are not this window's 6 clicks and the previous one's 4:\n%s", stdout)
	}
}

func TestReportSummaryCompareUnderJSONIsTheServersAnswer(t *testing.T) {
	serveReport(t, "/api/v3/reports/summary", comparedSummary)
	stdout, _, err := executeCommand("report", "summary", "--period", "last7", "--compare", "previous", "--json")
	if err != nil {
		t.Fatalf("report summary --compare --json: %v", err)
	}
	var got struct {
		Window   map[string]int64 `json:"window"`
		Previous struct {
			TimeFrom int64                  `json:"time_from"`
			Data     map[string]interface{} `json:"data"`
		} `json:"previous"`
	}
	if err := json.Unmarshal([]byte(stdout), &got); err != nil {
		t.Fatalf("stdout is not one JSON object: %v\n%s", err, stdout)
	}
	if got.Window["time_from"] != 1772859600 || got.Previous.TimeFrom != 1772427600 || got.Previous.Data["total_clicks"] != float64(4) {
		t.Errorf("--json must pass window and previous through, got %+v", got)
	}
}

func TestReportSummaryCompareRefusesAnAnswerWithoutTheWindows(t *testing.T) {
	serveReport(t, "/api/v3/reports/summary", `{"data":{"total_clicks":6}}`)
	_, _, err := executeCommand("report", "summary", "--period", "last7", "--compare", "previous")
	if err == nil {
		t.Fatal("an answer without window/previous must not render as a comparison")
	}
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("exit code = %d, want %d (server)", code, ExitServer)
	}
	if !strings.Contains(err.Error(), "no `window`") || !strings.Contains(hintFor(err), "--json") {
		t.Errorf("error %q / hint %q must name what is missing and how to see the answer", err, hintFor(err))
	}
}

func TestReportSummaryCompareIsRefusedBeforeAnyRequest(t *testing.T) {
	for name, args := range map[string][]string{
		"an unknown comparison": {"--compare", "next"},
		"several profiles":      {"--compare", "previous", "--profiles", "a,b"},
		"every profile":         {"--compare", "previous", "--all-profiles"},
	} {
		t.Run(name, func(t *testing.T) {
			queries := serveReport(t, "/api/v3/reports/summary", comparedSummary)
			_, _, err := executeCommand(append([]string{"report", "summary", "--period", "last7"}, args...)...)
			if err == nil {
				t.Fatal("want a validation error")
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d", code, ExitValidation)
			}
			if !strings.Contains(err.Error(), "--compare") {
				t.Errorf("error %q must name --compare", err)
			}
			if len(*queries) != 0 {
				t.Errorf("sent %d requests before refusing", len(*queries))
			}
		})
	}
}

func TestReportTimeseriesGroupBySendsTheDimensionAndShowsEachGroup(t *testing.T) {
	body := `{"data":[{"period":"2026-03-07","group_id":4,"group_name":"Alpha","total_clicks":3},` +
		`{"period":"2026-03-07","group_id":null,"group_name":null,"total_clicks":1}],"interval":"day","group_by":"campaign","truncated":false,"limit":2000}`
	queries := serveReport(t, "/api/v3/reports/timeseries", body)
	stdout, _, err := executeCommand("report", "timeseries", "--period", "last7", "--group-by", "offer", "--csv")
	if err != nil {
		t.Fatalf("report timeseries --group-by: %v", err)
	}
	if got := (*queries)[0]["group_by"]; len(got) != 1 || got[0] != "campaign" {
		t.Errorf("group_by = %v, want [campaign] (offer is campaign's alias)", got)
	}
	if !strings.Contains(stdout, "Alpha") || strings.Count(strings.TrimSpace(stdout), "\n") != 2 {
		t.Errorf("want a header and both groups' rows, got:\n%s", stdout)
	}
}

func TestReportTimeseriesGroupByRefusesADimensionTheSeriesDoesNotSplitBy(t *testing.T) {
	queries := serveReport(t, "/api/v3/reports/timeseries", `{"data":[]}`)
	_, _, err := executeCommand("report", "timeseries", "--period", "last7", "--group-by", "keyword")
	if err == nil {
		t.Fatal("want a validation error")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	for _, want := range []string{"--group-by", "ppc_network"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q must name %q", err, want)
		}
	}
	if len(*queries) != 0 {
		t.Errorf("sent %d requests before refusing", len(*queries))
	}
}

func TestATruncatedGroupedSeriesSuggestsFewerGroups(t *testing.T) {
	_, stderr := runTimeseries(t, timeseriesBody(1998, "hour", `"group_by":"ppc_network","truncated":true,"limit":2000`),
		"--interval", "hour", "--period", "last90", "--group-by", "ppc_network", "--json")
	if !strings.Contains(stderr, "or filter to fewer groups: --group-by takes a row per group and period.") {
		t.Errorf("stderr must suggest fewer groups, got:\n%s", stderr)
	}
}

// The groups --group-by offers are the ones the server splits a series by.
func TestTimeseriesGroupsAreTheServersList(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Controllers", "ReportsController.php"))
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`(?s)const array TIMESERIES_GROUPS = \[(.*?)\];`).FindSubmatch(src)
	if m == nil {
		t.Fatal("ReportsController has no TIMESERIES_GROUPS where this test reads it")
	}
	var server []string
	for _, v := range regexp.MustCompile(`'([a-z_]+)'`).FindAllSubmatch(m[1], -1) {
		server = append(server, string(v[1]))
	}
	if strings.Join(server, ",") != strings.Join(timeseriesGroups, ",") {
		t.Errorf("timeseriesGroups = %v, the server's TIMESERIES_GROUPS = %v", timeseriesGroups, server)
	}
}
