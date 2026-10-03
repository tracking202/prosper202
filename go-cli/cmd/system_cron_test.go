package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

// cronNow is the pinned clock for the cron tests: 2026-09-30 15:12:00 UTC.
var cronNow = time.Date(2026, 9, 30, 15, 12, 0, 0, time.UTC)

func withSystemNow(t *testing.T, now time.Time) {
	t.Helper()
	old := systemNow
	systemNow = func() time.Time { return now }
	t.Cleanup(func() { systemNow = old })
}

// cronRows builds n 202_cronjobs rows of typ, as PHP's mysqli returns them
// (strings); times gives each row's cronjob_time.
func cronRows(typ string, n int, times func(i int) time.Time) []map[string]interface{} {
	rows := make([]map[string]interface{}, n)
	for i := range rows {
		ts := times(i).Unix()
		rows[i] = map[string]interface{}{
			"cronjob_type":   typ,
			"cronjob_time":   strconv.FormatInt(ts, 10),
			"last_run_human": time.Unix(ts, 0).UTC().Format("2006-01-02 15:04:05"),
		}
	}
	return rows
}

// setupCronServer serves system/cron with jobs and a single log row (none
// when lastExec is zero) and points a fresh HOME at it.
func setupCronServer(t *testing.T, jobs []map[string]interface{}, lastExec time.Time) {
	t.Helper()
	logs := []map[string]interface{}{}
	if !lastExec.IsZero() {
		logs = append(logs, map[string]interface{}{"id": 1, "last_execution_time": strconv.FormatInt(lastExec.Unix(), 10)})
	}
	body, err := json.Marshal(map[string]interface{}{"data": map[string]interface{}{"jobs": jobs, "recent_logs": logs}})
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/system/cron" {
			w.WriteHeader(404)
			return
		}
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	withSystemNow(t, cronNow)
}

func decodeCron(t *testing.T, stdout string) (cronSummary, map[string]json.RawMessage) {
	t.Helper()
	var resp struct {
		Data json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%.500s", err, stdout)
	}
	var sum cronSummary
	var keys map[string]json.RawMessage
	if err := json.Unmarshal(resp.Data, &sum); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(resp.Data, &keys); err != nil {
		t.Fatal(err)
	}
	return sum, keys
}

func minutesAgo(n int) func(int) time.Time {
	return func(i int) time.Time { return cronNow.Add(-time.Duration(n+i) * time.Minute) }
}

func TestSystemCronSummaryWhenCronIsTicking(t *testing.T) {
	var jobs []map[string]interface{}
	jobs = append(jobs, cronRows("daily", 2, func(i int) time.Time {
		return time.Date(2026, 9, 30-i, 12, 0, 0, 0, time.UTC)
	})...)
	jobs = append(jobs, cronRows("hourly", 3, func(i int) time.Time { return cronNow.Truncate(time.Hour).Add(-time.Duration(i) * time.Hour) })...)
	jobs = append(jobs, cronRows("second", 50, minutesAgo(1))...)
	setupCronServer(t, jobs, cronNow.Add(-40*time.Second))

	stdout, _, err := executeCommand("system", "cron", "--json")
	assertExit(t, err, ExitOK)
	sum, keys := decodeCron(t, stdout)
	if sum.Status != cronStatusOK || *sum.LastExecution != "2026-09-30T15:11:20Z" || *sum.LastExecutionAgeSeconds != 40 ||
		*sum.LastExecutionAge != "40s" || sum.StaleAfterSeconds != 300 || sum.TotalRows != 55 {
		t.Errorf("summary = %+v", sum)
	}
	want := []struct {
		typ  string
		rows int
		last string
		age  string
	}{
		{"daily", 2, "2026-09-30T12:00:00Z", "3h12m"},
		{"hourly", 3, "2026-09-30T15:00:00Z", "12m00s"},
		{"second", 50, "2026-09-30T15:11:00Z", "1m00s"},
	}
	if len(sum.Types) != len(want) {
		t.Fatalf("types = %+v", sum.Types)
	}
	for i, w := range want {
		got := sum.Types[i]
		if got.Type != w.typ || got.Label != w.typ || got.TruncatedFrom != nil || got.Rows != w.rows || *got.LastRun != w.last || *got.LastRunAge != w.age {
			t.Errorf("types[%d] = %+v (last %s, age %s), want %+v", i, got, *got.LastRun, *got.LastRunAge, w)
		}
	}
	if len(sum.Warnings) != 0 || len(sum.Notes) != 0 {
		t.Errorf("warnings %v, notes %v; want none", sum.Warnings, sum.Notes)
	}
	for _, k := range []string{"jobs", "recent_logs"} {
		if _, ok := keys[k]; ok {
			t.Errorf("default JSON carries %q; raw rows belong behind --raw", k)
		}
	}
	for _, k := range []string{"status", "last_execution", "last_execution_age_seconds", "stale_after_seconds", "total_rows", "types", "warnings", "notes"} {
		if _, ok := keys[k]; !ok {
			t.Errorf("JSON summary lacks %q", k)
		}
	}
}

func TestSystemCronHumanSummaryIsShort(t *testing.T) {
	var jobs []map[string]interface{}
	jobs = append(jobs, cronRows("daily", 1, func(int) time.Time { return time.Date(2026, 9, 30, 12, 0, 0, 0, time.UTC) })...)
	jobs = append(jobs, cronRows("hourl", 1281, minutesAgo(0))...)
	jobs = append(jobs, cronRows("secon", 1281, minutesAgo(0))...)
	setupCronServer(t, jobs, cronNow.Add(-40*time.Second))

	stdout, stderr, err := executeCommand("system", "cron")
	assertExit(t, err, ExitOK)
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) != 5 { // header, rule, three types
		t.Fatalf("stdout has %d lines, want 5:\n%s", len(lines), stdout)
	}
	for _, want := range []string{"type", "rows", "last_run", "age", "hourl (hourly, truncated)", "secon (every minute)", "1281", "2026-09-30 15:12:00 UTC", "daily"} {
		if !strings.Contains(stdout, want) {
			t.Errorf("table lacks %q:\n%s", want, stdout)
		}
	}
	if !strings.Contains(stderr, "Last cron execution: 2026-09-30 15:11:20 UTC (40s ago); 2563 row(s) in 202_cronjobs.") {
		t.Errorf("stderr = %q, want the last execution line", stderr)
	}
	notes := 0
	for _, l := range strings.Split(stderr, "\n") {
		if strings.HasPrefix(l, "Note: ") {
			notes++
			// secon's row a minute is the per-minute tier working; only
			// hourl is a pile-up.
			if !strings.Contains(l, "holds 1281 hourl rows") || strings.Contains(l, "secon") || !strings.Contains(l, "char(5)") {
				t.Errorf("note = %q", l)
			}
		}
	}
	if notes != 1 {
		t.Errorf("stderr has %d note lines, want 1:\n%s", notes, stderr)
	}
}

// A day of a working scheduler: 'hour' once an hour, 'secon' once a minute.
// The minute rows are the per-minute tier's design, not the char(5) pile-up,
// so nothing is noted; the old summary called 1440 'secon' rows a bug.
func TestSystemCronWorkingSchedulerIsNotAPileUp(t *testing.T) {
	var jobs []map[string]interface{}
	jobs = append(jobs, cronRows("daily", 1, func(int) time.Time { return time.Date(2026, 9, 30, 12, 0, 0, 0, time.UTC) })...)
	jobs = append(jobs, cronRows("hour", 24, func(i int) time.Time { return cronNow.Truncate(time.Hour).Add(-time.Duration(i) * time.Hour) })...)
	jobs = append(jobs, cronRows("secon", 1440, minutesAgo(1))...)
	setupCronServer(t, jobs, cronNow.Add(-time.Minute))

	stdout, _, err := executeCommand("system", "cron", "--json")
	assertExit(t, err, ExitOK)
	sum, _ := decodeCron(t, stdout)
	want := map[string]string{"daily": "daily", "hour": "hour (hourly)", "secon": "secon (every minute)"}
	if len(sum.Types) != 3 {
		t.Fatalf("types = %+v", sum.Types)
	}
	for _, ts := range sum.Types {
		if ts.Label != want[ts.Type] || ts.TruncatedFrom != nil {
			t.Errorf("%s: label %q, truncated_from %v; want %q and none", ts.Type, ts.Label, ts.TruncatedFrom, want[ts.Type])
		}
	}
	if len(sum.Notes) != 0 || len(sum.Warnings) != 0 {
		t.Errorf("notes %v, warnings %v; want none", sum.Notes, sum.Warnings)
	}
}

func TestSystemCronLabelsTruncatedTypesAndNotesOnlyAPileUp(t *testing.T) {
	cases := []struct {
		name  string
		rows  int
		notes int
	}{
		{"a few truncated rows", cronPileUpRows, 0},
		{"a pile-up", cronPileUpRows + 1, 1},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			var jobs []map[string]interface{}
			jobs = append(jobs, cronRows("hourl", tc.rows, minutesAgo(0))...)
			jobs = append(jobs, cronRows("secon", 3, minutesAgo(0))...)
			setupCronServer(t, jobs, cronNow.Add(-time.Minute))

			stdout, _, err := executeCommand("system", "cron", "--json")
			assertExit(t, err, ExitOK)
			sum, _ := decodeCron(t, stdout)
			if len(sum.Types) != 2 || sum.Types[0].Label != "hourl (hourly, truncated)" || *sum.Types[0].TruncatedFrom != "hourly" ||
				sum.Types[1].Label != "secon (every minute)" || sum.Types[1].TruncatedFrom != nil {
				t.Fatalf("types = %+v", sum.Types)
			}
			if len(sum.Notes) != tc.notes {
				t.Errorf("notes = %v, want %d", sum.Notes, tc.notes)
			}
			if tc.notes == 1 && !strings.Contains(sum.Notes[0], fmt.Sprintf("holds %d hourl rows: cronjob_type is char(5)", tc.rows)) {
				t.Errorf("note = %q", sum.Notes[0])
			}
		})
	}
}

func TestSystemCronStaleOrNeverRanExitsPartialFailure(t *testing.T) {
	jobs := cronRows("secon", 10, minutesAgo(192))
	cases := []struct {
		name     string
		lastExec time.Time
		status   string
		message  string
	}{
		{"stale", cronNow.Add(-3*time.Hour - 12*time.Minute), cronStatusStale, "cron is not ticking: the last execution was 3h12m ago (2026-09-30 12:00:00 UTC)"},
		{"just past 5 minutes", cronNow.Add(-5*time.Minute - time.Second), cronStatusStale, "the last execution was 5m01s ago"},
		{"never ran", time.Time{}, cronStatusNeverRan, "cron has never run"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			setupCronServer(t, jobs, tc.lastExec)
			stdout, _, err := executeCommand("system", "cron", "--json")
			assertExit(t, err, ExitPartialFailure)
			if !strings.Contains(err.Error(), tc.message) || !strings.Contains(hintFor(err), "202-cronjobs/index.php") {
				t.Errorf("error = %q, hint = %q", err, hintFor(err))
			}
			sum, _ := decodeCron(t, stdout)
			if sum.Status != tc.status || len(sum.Warnings) != 1 || sum.Warnings[0] != err.Error() {
				t.Errorf("summary = %+v, want %s with the warning on stdout too", sum, tc.status)
			}
			if tc.status == cronStatusNeverRan && sum.LastExecution != nil {
				t.Errorf("last_execution = %v, want null", *sum.LastExecution)
			}
		})
	}

	t.Run("exactly 5 minutes is still ticking", func(t *testing.T) {
		setupCronServer(t, jobs, cronNow.Add(-5*time.Minute))
		_, _, err := executeCommand("system", "cron", "--json")
		assertExit(t, err, ExitOK)
	})
}

func TestSystemCronRawPrintsEveryRow(t *testing.T) {
	jobs := append(cronRows("hourl", 150, minutesAgo(0)), cronRows("secon", 150, minutesAgo(0))...)

	t.Run("human", func(t *testing.T) {
		setupCronServer(t, jobs, cronNow.Add(-time.Minute))
		stdout, _, err := executeCommand("system", "cron", "--raw")
		assertExit(t, err, ExitOK)
		if n := strings.Count(stdout, `"cronjob_type":"hourl"`) + strings.Count(stdout, `"cronjob_type":"secon"`); n != 300 {
			t.Errorf("raw output shows %d rows, want all 300", n)
		}
		if !strings.Contains(stdout, "recent_logs:") || strings.Contains(stdout, "truncated)") {
			t.Errorf("--raw should be the server's output, not the summary:\n%.300s", stdout)
		}
	})
	t.Run("json", func(t *testing.T) {
		setupCronServer(t, jobs, cronNow.Add(-time.Minute))
		stdout, _, err := executeCommand("system", "cron", "--raw", "--json")
		assertExit(t, err, ExitOK)
		sum, keys := decodeCron(t, stdout)
		var rawJobs, rawLogs []map[string]interface{}
		if err := json.Unmarshal(keys["jobs"], &rawJobs); err != nil || len(rawJobs) != 300 || rawJobs[0]["cronjob_type"] != "hourl" {
			t.Errorf("jobs = %d rows (%v), want the server's 300", len(rawJobs), err)
		}
		if err := json.Unmarshal(keys["recent_logs"], &rawLogs); err != nil || len(rawLogs) != 1 {
			t.Errorf("recent_logs = %v (%v)", rawLogs, err)
		}
		if sum.Status != cronStatusOK || len(sum.Types) != 2 || len(sum.Notes) != 1 {
			t.Errorf("the summary must stay alongside the raw rows: %+v", sum)
		}
	})
	t.Run("stale still exits 5", func(t *testing.T) {
		setupCronServer(t, jobs, cronNow.Add(-time.Hour))
		stdout, _, err := executeCommand("system", "cron", "--raw")
		assertExit(t, err, ExitPartialFailure)
		if !strings.Contains(stdout, "jobs:") {
			t.Errorf("stdout = %.200s", stdout)
		}
	})
}

func TestSystemCronAcceptsNumericTimes(t *testing.T) {
	jobs := []map[string]interface{}{{"cronjob_type": "daily", "cronjob_time": float64(cronNow.Add(-time.Hour).Unix())}}
	logs := []map[string]interface{}{{"id": 1, "last_execution_time": float64(cronNow.Add(-2 * time.Minute).Unix())}}
	sum := summarizeCron(jobs, logs, cronNow)
	if sum.Status != cronStatusOK || *sum.LastExecutionAgeSeconds != 120 || *sum.Types[0].LastRunAgeSeconds != 3600 {
		t.Errorf("summary = %+v", sum)
	}
}

func TestSystemCronLastExecutionAheadOfTheLocalClock(t *testing.T) {
	setupCronServer(t, cronRows("secon", 1, minutesAgo(0)), cronNow.Add(3*time.Second))
	_, stderr, err := executeCommand("system", "cron")
	assertExit(t, err, ExitOK)
	if !strings.Contains(stderr, "(in 3s: the server's clock is ahead of this machine's)") {
		t.Errorf("stderr = %q", stderr)
	}
}

func TestSystemCronLastExecutionIsTheNewestLogRow(t *testing.T) {
	// recent_logs holds up to 20 rows, newest id first, not newest time first.
	logs := []map[string]interface{}{
		{"id": "3", "last_execution_time": strconv.FormatInt(cronNow.Add(-10*time.Minute).Unix(), 10)},
		{"id": "2", "last_execution_time": strconv.FormatInt(cronNow.Add(-40*time.Second).Unix(), 10)},
		{"id": "1", "last_execution_time": strconv.FormatInt(cronNow.Add(-2*time.Hour).Unix(), 10)},
	}
	sum := summarizeCron(nil, logs, cronNow)
	if sum.Status != cronStatusOK || *sum.LastExecutionAgeSeconds != 40 || sum.TotalRows != 0 || len(sum.Types) != 0 {
		t.Errorf("summary = %+v, want ok from the 40s-old row", sum)
	}
}

func TestFormatAge(t *testing.T) {
	cases := map[time.Duration]string{
		40 * time.Second:                      "40s",
		200 * time.Second:                     "3m20s",
		3*time.Hour + 12*time.Minute:          "3h12m",
		47*time.Hour + 59*time.Minute:         "47h59m",
		52 * time.Hour:                        "2d04h",
		-(2*time.Hour + 48*time.Minute):       "in 2h48m",
		1500 * time.Millisecond:               "1s",
		5*time.Minute + 1*time.Second:         "5m01s",
		26*24*time.Hour + 30*time.Minute:      "26d00h",
		0:                                     "0s",
		59*time.Second + 999*time.Millisecond: "59s",
	}
	for d, want := range cases {
		if got := formatAge(d); got != want {
			t.Errorf("formatAge(%s) = %q, want %q", d, got, want)
		}
	}
}
