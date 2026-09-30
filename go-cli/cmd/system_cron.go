package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/output"
)

// cronStaleAfter: 202-cronjobs/index.php is scheduled every minute, so a
// last execution older than this means cron is not ticking.
const cronStaleAfter = 5 * time.Minute

// cronPileUpRows: a truncated type with more rows than this is the
// char(5) bug adding a row every minute.
const cronPileUpRows = 100

// cronTruncated maps a type cut to 202_cronjobs.cronjob_type's char(5) back
// to the type the scheduler wrote.
var cronTruncated = map[string]string{"hourl": "hourly", "secon": "second"}

// Cron summary statuses.
const (
	cronStatusOK       = "ok"
	cronStatusStale    = "stale"
	cronStatusNeverRan = "never_ran"
)

// cronColumns is the human table: one row per cronjob_type.
var cronColumns = []string{"type", "rows", "last_run", "age"}

type cronTypeSummary struct {
	Type              string  `json:"cronjob_type"`
	Label             string  `json:"label"`
	TruncatedFrom     *string `json:"truncated_from"`
	Rows              int     `json:"rows"`
	LastRun           *string `json:"last_run"`
	LastRunUnix       *int64  `json:"last_run_unix"`
	LastRunAge        *string `json:"last_run_age"`
	LastRunAgeSeconds *int64  `json:"last_run_age_seconds"`
}

// cronSummary is `system cron`'s JSON data object; Jobs and RecentLogs are
// the server's rows, included only with --raw.
type cronSummary struct {
	Status                  string            `json:"status"`
	LastExecution           *string           `json:"last_execution"`
	LastExecutionUnix       *int64            `json:"last_execution_unix"`
	LastExecutionAge        *string           `json:"last_execution_age"`
	LastExecutionAgeSeconds *int64            `json:"last_execution_age_seconds"`
	StaleAfterSeconds       int64             `json:"stale_after_seconds"`
	TotalRows               int               `json:"total_rows"`
	Types                   []cronTypeSummary `json:"types"`
	Warnings                []string          `json:"warnings"`
	Notes                   []string          `json:"notes"`
	Jobs                    json.RawMessage   `json:"jobs,omitempty"`
	RecentLogs              json.RawMessage   `json:"recent_logs,omitempty"`
}

// unixOf reads a unix time the API sends as a number or a numeric string.
func unixOf(v interface{}) (int64, bool) {
	switch x := v.(type) {
	case float64:
		return int64(x), true
	case string:
		n, err := strconv.ParseInt(strings.TrimSpace(x), 10, 64)
		return n, err == nil
	}
	return 0, false
}

// formatAge renders a duration as 40s, 3m20s, 3h12m or 2d04h; a negative
// one (a time still ahead) as "in ...".
func formatAge(d time.Duration) string {
	if d < 0 {
		return "in " + formatAge(-d)
	}
	s := int64(d / time.Second)
	switch {
	case s < 60:
		return fmt.Sprintf("%ds", s)
	case s < 3600:
		return fmt.Sprintf("%dm%02ds", s/60, s%60)
	case s < 48*3600:
		return fmt.Sprintf("%dh%02dm", s/3600, s%3600/60)
	}
	return fmt.Sprintf("%dd%02dh", s/86400, s%86400/3600)
}

// cronTime is the human form of a unix time; rfc3339 the JSON form.
func cronTime(unix int64) string {
	return time.Unix(unix, 0).UTC().Format("2006-01-02 15:04:05") + " UTC"
}

func rfc3339(unix int64) string { return time.Unix(unix, 0).UTC().Format(time.RFC3339) }

// summarizeCron groups the 202_cronjobs rows by type and reads the last
// execution from 202_cronjob_logs.
func summarizeCron(jobs, logs []map[string]interface{}, now time.Time) cronSummary {
	sum := cronSummary{
		StaleAfterSeconds: int64(cronStaleAfter / time.Second),
		TotalRows:         len(jobs),
		Types:             []cronTypeSummary{},
		Warnings:          []string{},
		Notes:             []string{},
	}
	byType := map[string]*cronTypeSummary{}
	for _, j := range jobs {
		typ, _ := j["cronjob_type"].(string)
		typ = strings.TrimSpace(typ)
		ts := byType[typ]
		if ts == nil {
			ts = &cronTypeSummary{Type: typ, Label: typ}
			if full, ok := cronTruncated[typ]; ok {
				ts.TruncatedFrom = &full
				ts.Label = fmt.Sprintf("%s (%s, truncated)", typ, full)
			}
			byType[typ] = ts
		}
		ts.Rows++
		if t, ok := unixOf(j["cronjob_time"]); ok && (ts.LastRunUnix == nil || t > *ts.LastRunUnix) {
			ts.LastRunUnix = &t
		}
	}
	for _, ts := range byType {
		if ts.LastRunUnix != nil {
			when, age := rfc3339(*ts.LastRunUnix), now.Sub(time.Unix(*ts.LastRunUnix, 0))
			ageStr, ageSec := formatAge(age), int64(age/time.Second)
			ts.LastRun, ts.LastRunAge, ts.LastRunAgeSeconds = &when, &ageStr, &ageSec
		}
		sum.Types = append(sum.Types, *ts)
	}
	sort.Slice(sum.Types, func(i, k int) bool { return sum.Types[i].Type < sum.Types[k].Type })
	var piled []string
	for _, ts := range sum.Types {
		if ts.TruncatedFrom != nil && ts.Rows > cronPileUpRows {
			piled = append(piled, fmt.Sprintf("%d %s", ts.Rows, ts.Type))
		}
	}
	if len(piled) > 0 {
		sum.Notes = append(sum.Notes, fmt.Sprintf("202_cronjobs holds %s rows: cronjob_type is char(5), so 'hourly' and 'second' are stored truncated, the scheduler's already-ran check never matches them, and it adds a row every minute (a server schema bug, not a stopped cron).", strings.Join(piled, " and ")))
	}

	var last int64
	found := false
	for _, l := range logs {
		if t, ok := unixOf(l["last_execution_time"]); ok && (!found || t > last) {
			last, found = t, true
		}
	}
	if !found {
		sum.Status = cronStatusNeverRan
		sum.Warnings = append(sum.Warnings, "cron has never run: 202_cronjob_logs records no execution")
		return sum
	}
	when, age := rfc3339(last), now.Sub(time.Unix(last, 0))
	ageStr, ageSec := formatAge(age), int64(age/time.Second)
	sum.LastExecution, sum.LastExecutionUnix, sum.LastExecutionAge, sum.LastExecutionAgeSeconds = &when, &last, &ageStr, &ageSec
	sum.Status = cronStatusOK
	if age > cronStaleAfter {
		sum.Status = cronStatusStale
		sum.Warnings = append(sum.Warnings, fmt.Sprintf("cron is not ticking: the last execution was %s ago (%s); it runs every minute when scheduled", ageStr, cronTime(last)))
	}
	return sum
}

// cronProblemError is the exit-5 finding for a stale or never-run cron.
func cronProblemError(sum cronSummary) error {
	if sum.Status == cronStatusOK {
		return nil
	}
	return partialFailureError("%s", sum.Warnings[0]).
		WithHint("Check that the server's crontab runs Prosper202's cron every minute (`* * * * * php /path/to/prosper202/202-cronjobs/index.php`), then run that command once by hand to see its output; reports and postbacks stall until it runs. `p202 system cron` then shows the new last execution.")
}

func runSystemCron(cmd *cobra.Command, _ []string) error {
	showRaw, _ := cmd.Flags().GetBool("raw")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	raw, err := c.Get("system/cron", nil)
	if err != nil {
		return err
	}
	var resp struct {
		Data struct {
			Jobs       json.RawMessage `json:"jobs"`
			RecentLogs json.RawMessage `json:"recent_logs"`
		} `json:"data"`
	}
	var jobs, logs []map[string]interface{}
	if err := json.Unmarshal(raw, &resp); err != nil {
		return fmt.Errorf("reading the cron status response: %w", err)
	}
	if len(resp.Data.Jobs) > 0 {
		if err := json.Unmarshal(resp.Data.Jobs, &jobs); err != nil {
			return fmt.Errorf("reading the cron status jobs: %w", err)
		}
	}
	if len(resp.Data.RecentLogs) > 0 {
		if err := json.Unmarshal(resp.Data.RecentLogs, &logs); err != nil {
			return fmt.Errorf("reading the cron status logs: %w", err)
		}
	}
	sum := summarizeCron(jobs, logs, systemNow())

	if jsonOutput || ndjsonOutput {
		if showRaw {
			sum.Jobs, sum.RecentLogs = resp.Data.Jobs, resp.Data.RecentLogs
		}
		out, err := json.Marshal(map[string]interface{}{"data": sum})
		if err != nil {
			return fmt.Errorf("encoding the cron summary: %w", err)
		}
		render(out)
		return cronProblemError(sum)
	}
	if showRaw {
		render(raw)
		return cronProblemError(sum)
	}

	rows := make([]map[string]interface{}, 0, len(sum.Types))
	for _, ts := range sum.Types {
		row := map[string]interface{}{"type": ts.Label, "rows": ts.Rows, "last_run": "", "age": ""}
		if ts.LastRunUnix != nil {
			row["last_run"], row["age"] = cronTime(*ts.LastRunUnix), *ts.LastRunAge
		}
		rows = append(rows, row)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = cronColumns
	}
	output.RenderWith(rowsToJSON(rows), opts)
	if sum.LastExecution != nil {
		ago := *sum.LastExecutionAge + " ago"
		if *sum.LastExecutionAgeSeconds < 0 {
			ago = *sum.LastExecutionAge + ": the server's clock is ahead of this machine's"
		}
		output.Success("Last cron execution: %s (%s); %d row(s) in 202_cronjobs.", cronTime(*sum.LastExecutionUnix), ago, sum.TotalRows)
	}
	for _, n := range sum.Notes {
		fmt.Fprintln(os.Stderr, "Note: "+n)
	}
	return cronProblemError(sum)
}
