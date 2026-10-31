package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// timeseriesBody builds a reports/timeseries response with n buckets. extra is
// spliced in after "interval" (e.g. `"truncated":true,"limit":2000`); "" is a
// server that predates the flag.
func timeseriesBody(n int, interval, extra string) string {
	rows := make([]string, n)
	for i := range rows {
		rows[i] = fmt.Sprintf(`{"period":"p%04d","total_clicks":1}`, i)
	}
	body := `{"data":[` + strings.Join(rows, ",") + `],"interval":"` + interval + `"`
	if extra != "" {
		body += "," + extra
	}
	return body + "}"
}

func runTimeseries(t *testing.T, body string, args ...string) (string, string) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/reports/timeseries" {
			t.Errorf("path = %q, want /api/v3/reports/timeseries", r.URL.Path)
		}
		w.WriteHeader(200)
		_, _ = w.Write([]byte(body))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, stderr, err := executeCommand(append([]string{"report", "timeseries"}, args...)...)
	if err != nil {
		t.Fatalf("report timeseries: %v", err)
	}
	return stdout, stderr
}

func TestReportTimeseriesWarnsWhenServerSaysTruncated(t *testing.T) {
	stdout, stderr := runTimeseries(t, timeseriesBody(2000, "hour", `"truncated":true,"limit":2000`),
		"--interval", "hour", "--period", "last90", "--json")

	for _, want := range []string{
		"Warning: the series was cut at the server's 2000-bucket limit; buckets after p1999 are missing.",
		"Hint: Narrow --time-from/--time-to (or use a shorter --period), or use --interval day|week|month",
	} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr missing %q, got:\n%s", want, stderr)
		}
	}

	// --json passes the response through: the flag and the cap are there,
	// beside the unchanged data and interval.
	var got struct {
		Data      []map[string]interface{} `json:"data"`
		Interval  string                   `json:"interval"`
		Truncated *bool                    `json:"truncated"`
		Limit     *int                     `json:"limit"`
	}
	if err := json.Unmarshal([]byte(stdout), &got); err != nil {
		t.Fatalf("stdout is not one JSON object: %v\n%s", err, stdout)
	}
	if len(got.Data) != 2000 || got.Interval != "hour" {
		t.Errorf("data=%d interval=%q, want 2000 rows of hour", len(got.Data), got.Interval)
	}
	if got.Truncated == nil || !*got.Truncated || got.Limit == nil || *got.Limit != 2000 {
		t.Errorf("JSON output must carry truncated=true and limit=2000, got truncated=%v limit=%v", got.Truncated, got.Limit)
	}
	if strings.Contains(stdout, "Warning") {
		t.Error("the warning must go to stderr, not into the JSON on stdout")
	}
}

func TestReportTimeseriesSilentWhenComplete(t *testing.T) {
	for name, body := range map[string]string{
		"flag false at the cap": timeseriesBody(2000, "day", `"truncated":false,"limit":2000`),
		"old server, few rows":  timeseriesBody(5, "day", ""),
		"empty":                 `{"data":[],"interval":"day","truncated":false,"limit":2000}`,
	} {
		t.Run(name, func(t *testing.T) {
			_, stderr := runTimeseries(t, body, "--json")
			if strings.Contains(stderr, "Warning") {
				t.Errorf("unexpected warning:\n%s", stderr)
			}
		})
	}
}

func TestReportTimeseriesWarnsOnOldServerAtTheCap(t *testing.T) {
	_, stderr := runTimeseries(t, timeseriesBody(2000, "day", ""))

	want := "Warning: 2000 buckets is this server's limit and it does not report truncation; buckets after p1999 may be missing."
	if !strings.Contains(stderr, want) {
		t.Errorf("stderr missing %q, got:\n%s", want, stderr)
	}
	if !strings.Contains(stderr, "--interval week|month") {
		t.Errorf("the default interval (day) should suggest week|month, got:\n%s", stderr)
	}
}

func TestTimeseriesTruncationWarningHintFollowsInterval(t *testing.T) {
	body := []byte(timeseriesBody(3, "x", `"truncated":true,"limit":3`))
	cases := map[string]string{
		"":      ", or use --interval week|month,",
		"hour":  ", or use --interval day|week|month,",
		"day":   ", or use --interval week|month,",
		"week":  ", or use --interval month,",
		"month": "Narrow --time-from/--time-to (or use a shorter --period).",
	}
	noPeriod := timeseriesTruncationWarning([]byte(`{"data":[{"total_clicks":1}],"truncated":true,"limit":1}`), "day")
	if !strings.Contains(noPeriod, "buckets after the last bucket returned are missing") {
		t.Errorf("a bucket without period should not print <nil>, got %q", noPeriod)
	}
	for interval, want := range cases {
		got := timeseriesTruncationWarning(body, interval)
		if !strings.Contains(got, "3-bucket limit; buckets after p0002 are missing") {
			t.Errorf("interval %q: warning should use the server's limit and last bucket, got %q", interval, got)
		}
		if !strings.Contains(got, want) {
			t.Errorf("interval %q: want %q in %q", interval, want, got)
		}
	}
}
