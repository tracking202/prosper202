package cmd

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
)

// depthServer answers every request with body (status 200) unless respond
// says otherwise, and records each request's path and query.
type recordedRequest struct {
	path  string
	query url.Values
}

func depthServer(t *testing.T, respond func(path string, q url.Values) (int, string)) (*httptest.Server, func() []recordedRequest) {
	t.Helper()
	var mu sync.Mutex
	var got []recordedRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/capabilities") || strings.HasSuffix(r.URL.Path, "/versions") {
			w.WriteHeader(404)
			return
		}
		mu.Lock()
		got = append(got, recordedRequest{path: strings.TrimPrefix(r.URL.Path, "/api/v3/"), query: r.URL.Query()})
		mu.Unlock()
		status, body := 200, `{"data":[]}`
		if respond != nil {
			status, body = respond(r.URL.Path, r.URL.Query())
		}
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv, func() []recordedRequest {
		mu.Lock()
		defer mu.Unlock()
		return append([]recordedRequest(nil), got...)
	}
}

// reportFilterArgs sets every report filter flag, and the query each must send.
var reportFilterArgs = []string{
	"--text_ad_id", "3", "--region_id", "4", "--isp_id", "5", "--browser_id", "6", "--platform_id", "7",
	"--device_type", "2", "--method_of_promotion", "directlink", "--show", "real",
	"--keyword", "blue widgets", "--ip", "2001:db8::1", "--referer", "news.example",
	"--aff_campaign_id", "11", "--ppc_account_id", "12",
}

var reportFilterQuery = map[string]string{
	"text_ad_id": "3", "region_id": "4", "isp_id": "5", "browser_id": "6", "platform_id": "7",
	"device_type": "2", "method_of_promotion": "directlink", "show": "real",
	"keyword": "blue widgets", "ip": "2001:db8::1", "referer": "news.example",
	"aff_campaign_id": "11", "ppc_account_id": "12",
}

// Every report command that reads the reports takes the Analyze pages'
// filters and sends each one as given (CLAUDE.md #12: a filter the CLI drops
// is a filter the server never sees, and the answer covers everything).
func TestTheAnalyzeFiltersReachTheServerFromEveryReportCommand(t *testing.T) {
	commands := []struct {
		args []string
		path string
	}{
		{[]string{"report", "summary"}, "reports/summary"},
		{[]string{"report", "breakdown", "--breakdown", "referer"}, "reports/breakdown"},
		{[]string{"report", "timeseries"}, "reports/timeseries"},
		{[]string{"report", "daypart"}, "reports/daypart"},
		{[]string{"report", "weekpart"}, "reports/weekpart"},
		{[]string{"analytics", "--group-by", "utm_source"}, "reports/breakdown"},
		{[]string{"dashboard"}, "reports/summary"},
		{[]string{"rotator", "stats", "3"}, "rotators/3/stats"},
	}
	for _, c := range commands {
		t.Run(strings.Join(c.args, " "), func(t *testing.T) {
			_, requests := depthServer(t, func(path string, q url.Values) (int, string) {
				if strings.HasSuffix(path, "/stats") || strings.HasSuffix(path, "/summary") {
					return 200, `{"data":{}}`
				}
				return 200, `{"data":[]}`
			})
			if _, _, err := executeCommand(append(append(append([]string{}, c.args...), reportFilterArgs...), "--json")...); err != nil {
				t.Fatalf("%v: %v", c.args, err)
			}
			got := requests()
			if len(got) != 1 || got[0].path != c.path {
				t.Fatalf("requests = %+v, want one to %s", got, c.path)
			}
			for k, want := range reportFilterQuery {
				if v := got[0].query.Get(k); v != want {
					t.Errorf("%s = %q, want %q", k, v, want)
				}
			}
		})
	}
}

func TestTheNewPeriodsAndDimensionsAreAccepted(t *testing.T) {
	for _, period := range []string{"last14", "thismonth", "lastmonth", "thisyear", "lastyear", "alltime"} {
		_, requests := depthServer(t, nil)
		if _, _, err := executeCommand("report", "summary", "--period", period, "--json"); err != nil {
			t.Fatalf("--period %s: %v", period, err)
		}
		if got := requests(); len(got) != 1 || got[0].query.Get("period") != period {
			t.Errorf("--period %s sent %+v", period, got)
		}
	}
	for _, dim := range []string{"ip", "referer", "referer_url", "device_type", "c1", "c4", "utm_source", "utm_content", "rotator", "rotator_rule"} {
		_, requests := depthServer(t, nil)
		if _, _, err := executeCommand("report", "breakdown", "--breakdown", dim, "--sort", "cpa", "--json"); err != nil {
			t.Fatalf("--breakdown %s: %v", dim, err)
		}
		if got := requests(); len(got) != 1 || got[0].query.Get("breakdown") != dim || got[0].query.Get("sort") != "cpa" {
			t.Errorf("--breakdown %s sent %+v", dim, got)
		}
	}
	// The aliases name the new dimensions too.
	for alias, dim := range map[string]string{"referrer": "referer", "rule": "rotator_rule"} {
		_, requests := depthServer(t, nil)
		if _, _, err := executeCommand("analytics", "--group-by", alias, "--json"); err != nil {
			t.Fatalf("--group-by %s: %v", alias, err)
		}
		if got := requests(); len(got) != 1 || got[0].query.Get("breakdown") != dim {
			t.Errorf("--group-by %s sent %+v, want breakdown=%s", alias, got, dim)
		}
	}
}

// A value outside --show's or --method_of_promotion's list is refused before
// anything is sent, and the message lists the values.
func TestShowAndMethodOfPromotionRefuseAnUnknownValueOffline(t *testing.T) {
	cases := []struct {
		args []string
		want string
	}{
		{[]string{"report", "summary", "--show", "bots"}, "all, real, filtered, filtered_bot, leads"},
		{[]string{"analytics", "--group-by", "ip", "--show", "everything"}, "all, real, filtered, filtered_bot, leads"},
		{[]string{"report", "breakdown", "--method_of_promotion", "email"}, "directlink, landingpage"},
		{[]string{"report", "summary", "--period", "last60"}, "last14"},
	}
	for _, c := range cases {
		_, requests := depthServer(t, nil)
		_, _, err := executeCommand(append(c.args, "--json")...)
		if err == nil {
			t.Fatalf("%v was accepted", c.args)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d", c.args, code, ExitValidation)
		}
		if !strings.Contains(err.Error(), c.want) {
			t.Errorf("%v: %q does not list %q", c.args, err.Error(), c.want)
		}
		if got := requests(); len(got) != 0 {
			t.Errorf("%v reached the server: %+v", c.args, got)
		}
	}
}

// The server's 422 for a malformed filter value (an IP that is not one)
// arrives as a validation error naming the field.
func TestAMalformedFilterValueIsTheServersValidationError(t *testing.T) {
	depthServer(t, func(string, url.Values) (int, string) {
		return 422, `{"error":true,"message":"Invalid ip","status":422,"field_errors":{"ip":"One IPv4 or IPv6 address, e.g. 203.0.113.7 or 2001:db8::1; got \"999.1.1.1\""}}`
	})
	_, _, err := executeCommand("report", "summary", "--ip", "999.1.1.1", "--json")
	if err == nil {
		t.Fatal("a 422 was rendered as data")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit %d, want %d", code, ExitValidation)
	}
	if !strings.Contains(err.Error(), "Invalid ip") {
		t.Errorf("error = %q", err.Error())
	}
}

func TestRotatorStatsReadsTheStatsEndpoint(t *testing.T) {
	_, requests := depthServer(t, func(string, url.Values) (int, string) {
		return 200, `{"data":{"rotator":{"id":3,"name":"Geo"},"totals":{"total_clicks":13},"rules":[],"default":{"total_clicks":4}}}`
	})
	out, _, err := executeCommand("rotator", "stats", "3", "--period", "yesterday", "--show", "real", "--json")
	if err != nil {
		t.Fatalf("rotator stats: %v", err)
	}
	got := requests()
	if len(got) != 1 || got[0].path != "rotators/3/stats" || got[0].query.Get("period") != "yesterday" || got[0].query.Get("show") != "real" {
		t.Fatalf("requests = %+v", got)
	}
	if !strings.Contains(out, `"total_clicks": 13`) {
		t.Errorf("output = %s", out)
	}
}

func TestRotatorStatsErrorsSayWhatToDo(t *testing.T) {
	cases := []struct {
		name, body string
		hint       string
	}{
		{"a rotator the account lacks", `{"error":true,"message":"Rotator not found","status":404}`, "p202 rotator list"},
		{"a server without the endpoint", `{"error":true,"message":"Not found","status":404}`, "upgrade it"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			depthServer(t, func(string, url.Values) (int, string) { return 404, c.body })
			_, _, err := executeCommand("rotator", "stats", "9", "--json")
			if err == nil {
				t.Fatal("a 404 was rendered as data")
			}
			if code := exitCodeForError(err); code != 1 {
				t.Errorf("exit %d, want 1 (a 404)", code)
			}
			if !strings.Contains(hintFor(err), c.hint) {
				t.Errorf("hint = %q, want it to mention %q", hintFor(err), c.hint)
			}
		})
	}

	_, requests := depthServer(t, nil)
	_, _, err := executeCommand("rotator", "stats", "geo", "--json")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 rotator list") {
		t.Errorf("a non-numeric id: %v / %q", err, hintFor(err))
	}
	if got := requests(); len(got) != 0 {
		t.Errorf("a non-numeric id reached the server: %+v", got)
	}
}

// A crosstab row whose column breakdown fails used to be skipped, so the pivot
// read as that row having no traffic.
func TestCrosstabFailsWhenARowsBreakdownFails(t *testing.T) {
	depthServer(t, func(_ string, q url.Values) (int, string) {
		if q.Get("breakdown") == "device_type" {
			return 200, `{"data":[{"id":1,"name":"Desktop","total_net":"5"},{"id":2,"name":"Mobile","total_net":"3"}]}`
		}
		if q.Get("device_type") == "2" {
			return 500, `{"error":true,"message":"Internal error","status":500}`
		}
		return 200, `{"data":[{"id":7,"name":"US","total_net":"5"}]}`
	})
	_, _, err := executeCommand("report", "crosstab", "--rows", "device_type", "--cols", "country", "--json")
	if err == nil {
		t.Fatal("a failed row was left out of the pivot without a word")
	}
	if !strings.Contains(err.Error(), "device_type 2") {
		t.Errorf("error = %q, want it to name the row", err.Error())
	}
}

func TestCrosstabRowsByTheNewFilterDimensions(t *testing.T) {
	for dim, param := range map[string]string{"device_type": "device_type", "browser": "browser_id", "region": "region_id", "text_ad": "text_ad_id"} {
		_, requests := depthServer(t, func(_ string, q url.Values) (int, string) {
			if q.Get("breakdown") == dim {
				return 200, `{"data":[{"id":4,"name":"x","total_net":"1"}]}`
			}
			return 200, `{"data":[]}`
		})
		if _, _, err := executeCommand("report", "crosstab", "--rows", dim, "--cols", "country", "--json"); err != nil {
			t.Fatalf("--rows %s: %v", dim, err)
		}
		got := requests()
		if len(got) != 2 || got[1].query.Get(param) != "4" || got[1].query.Get("breakdown") != "country" {
			t.Errorf("--rows %s sent %+v, want the column breakdown narrowed by %s=4", dim, got, param)
		}
	}
}

// A forecast anchors on the most recent buckets, the ones a cut series lacks.
func TestForecastRefusesACutHistory(t *testing.T) {
	depthServer(t, func(string, url.Values) (int, string) {
		return 200, timeseriesBody(2000, "day", `"truncated":true,"limit":2000`)
	})
	_, _, err := executeCommand("forecast", "--metric", "clicks", "--history", "alltime", "--json")
	if err == nil {
		t.Fatal("a forecast was made from a cut series")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit %d, want %d", code, ExitValidation)
	}
	if !strings.Contains(hintFor(err), "--interval") {
		t.Errorf("hint = %q", hintFor(err))
	}
}

func TestForecastHourlyTakesTheShortNewPeriods(t *testing.T) {
	for _, history := range []string{"last14", "thismonth", "lastmonth"} {
		_, requests := depthServer(t, func(string, url.Values) (int, string) {
			return 200, timeseriesBody(48, "hour", `"truncated":false,"limit":2000`)
		})
		_, _, err := executeCommand("forecast", "--metric", "clicks", "--interval", "hour", "--history", history, "--json")
		if err != nil && strings.Contains(err.Error(), "--interval hour supports") {
			t.Errorf("--history %s refused for hourly: %v", history, err)
		}
		if got := requests(); len(got) == 0 || got[0].query.Get("period") != history {
			t.Errorf("--history %s sent %+v", history, got)
		}
	}
	_, _, err := executeCommand("forecast", "--metric", "clicks", "--interval", "hour", "--history", "thisyear", "--json")
	if err == nil || !strings.Contains(err.Error(), "--interval hour supports") {
		t.Errorf("--history thisyear hourly: %v", err)
	}
}

func TestSplitAtTakesLast14AndExplainsTheCalendarPeriods(t *testing.T) {
	_, requests := depthServer(t, func(string, url.Values) (int, string) { return 200, `{"data":[]}` })
	at := reportNow().Unix() - 3*86400
	if _, _, err := executeCommand("analytics", "--group-by", "country", "--split-at", fmt.Sprint(at), "--period", "last14", "--json"); err != nil {
		t.Fatalf("--period last14 --split-at: %v", err)
	}
	if got := requests(); len(got) != 2 {
		t.Errorf("want a breakdown per side, got %+v", got)
	}
	for _, period := range []string{"thismonth", "yesterday", "alltime"} {
		_, _, err := executeCommand("analytics", "--group-by", "country", "--split-at", fmt.Sprint(at), "--period", period, "--json")
		if err == nil || exitCodeForError(err) != ExitValidation || hintFor(err) == "" {
			t.Errorf("--period %s --split-at: %v / %q", period, err, hintFor(err))
		}
	}
}
