package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/output"
)

// reportNow is the clock report windows resolve "now" against; tests pin it.
var reportNow = time.Now

// analyticsFilterFlags are the filters analytics passes to reports/breakdown:
// every report filter (reportFilterFlags).
var analyticsFilterFlags = reportFilterFlags

// splitDefaultPeriod is the window --split-at compares when none is given.
const splitDefaultPeriod = "last90"

// splitPeriodDays are the periods whose bounds the CLI can compute exactly
// (now minus N days, as ReportsController::applyTimeFilters does).
var splitPeriodDays = map[string]int64{"last7": 7, "last14": 14, "last30": 30, "last90": 90}

// splitPerDaySorts are the --sort keys that rank --split-at rows by per-day change.
var splitPerDaySorts = []string{"clicks_per_day", "conversions_per_day", "revenue_per_day"}

// splitMetrics are the breakdown fields --split-at compares, by output name.
var splitMetrics = []struct{ name, field string }{
	{"clicks", "total_clicks"},
	{"conversions", "total_leads"},
	{"revenue", "total_income"},
}

// splitSpan is an inclusive range of unix seconds, as the server applies
// time_from (>=) and time_to (<=).
type splitSpan struct{ from, to int64 }

func (s splitSpan) seconds() int64 { return s.to - s.from + 1 }
func (s splitSpan) days() float64  { return float64(s.seconds()) / 86400 }

type splitPlan struct {
	at            int64
	window        splitSpan
	windowSource  string
	before, after splitSpan
	sortMetric    string // clicks, conversions or revenue
	sortPerDay    bool   // rank by the change in the per-day rate, not the total
	sortDir       string
	limit, offset int // limit 0 = every row
}

func (p *splitPlan) sortField() string {
	if p.sortPerDay {
		return p.sortMetric + "_per_day_change"
	}
	return p.sortMetric + "_change"
}

// splitPlanFromFlags validates --split-at and everything it depends on,
// before any client is built. It returns nil without --split-at.
func splitPlanFromFlags(cmd *cobra.Command) (*splitPlan, error) {
	if !cmd.Flags().Changed("split-at") {
		return nil, nil
	}
	raw, _ := cmd.Flags().GetString("split-at")
	at, err := parseSplitAt(raw)
	if err != nil {
		return nil, err
	}
	window, source, err := splitWindowFromFlags(cmd)
	if err != nil {
		return nil, err
	}
	if at <= window.from {
		return nil, validationError("--split-at %s is not after the window start %s (%s), so the before side would be empty", utcStamp(at), utcStamp(window.from), source).
			WithHint("Start the window earlier (--days N, --period last90, or --time_from <unix>) or pick a later --split-at.")
	}
	if at > window.to {
		return nil, validationError("--split-at %s is after the window end %s (%s), so the after side would be empty", utcStamp(at), utcStamp(window.to), source).
			WithHint("Pick a --split-at inside the window, or end the window later with --time_to <unix> (the default end is now).")
	}
	plan := &splitPlan{
		at:           at,
		window:       window,
		windowSource: source,
		before:       splitSpan{window.from, at - 1},
		after:        splitSpan{at, window.to},
		sortMetric:   "clicks",
	}
	if err := plan.readSortFlags(cmd); err != nil {
		return nil, err
	}
	return plan, nil
}

// parseSplitAt reads YYYY-MM-DD as 00:00 UTC, or unix seconds.
func parseSplitAt(raw string) (int64, error) {
	raw = strings.TrimSpace(raw)
	if t, err := time.Parse("2006-01-02", raw); err == nil {
		return t.Unix(), nil
	}
	if n, err := strconv.ParseInt(raw, 10, 64); err == nil && n > 0 {
		if n > 99999999999 {
			return 0, validationError("--split-at %s looks like milliseconds; it takes unix seconds or a date", raw).
				WithHint("Drop the last three digits (--split-at %d), or pass a date: --split-at %s.", n/1000, time.Unix(n/1000, 0).UTC().Format("2006-01-02"))
		}
		return n, nil
	}
	return 0, validationError("invalid --split-at %q: pass a date YYYY-MM-DD (read as 00:00 UTC) or unix seconds", raw).
		WithHint("Example: --split-at 2026-09-04 or --split-at 1788480000.")
}

// splitWindowFromFlags resolves the analytics window flags (via
// applyReportWindow, so --period still wins over --days) to explicit bounds.
func splitWindowFromFlags(cmd *cobra.Command) (splitSpan, string, error) {
	period, _ := cmd.Flags().GetString("period")
	days, _ := cmd.Flags().GetInt("days")
	timeFrom, _ := cmd.Flags().GetString("time_from")
	timeTo, _ := cmd.Flags().GetString("time_to")
	params := map[string]string{}
	if err := applyReportWindow(params, strings.TrimSpace(period), days, strings.TrimSpace(timeFrom), strings.TrimSpace(timeTo)); err != nil {
		return splitSpan{}, "", err
	}
	now := reportNow().Unix()
	if p := params["period"]; p != "" {
		n, ok := splitPeriodDays[p]
		if !ok {
			if p == "alltime" {
				return splitSpan{}, "", validationError("--period alltime cannot be split: it has no start, so the before side would be unbounded").
					WithHint("Start the window with --time_from <unix> (the end defaults to now), or use --days N or --period last90.")
			}
			if containsString(reportPeriods, p) {
				return splitSpan{}, "", validationError("--period %s cannot be split: its bounds follow the account's midnight, which the CLI cannot see", p).
					WithHint("Pass the window as unix seconds (--time_from <unix> --time_to <unix>), or use --days N or --period last7, last14, last30 or last90.")
			}
			return splitSpan{}, "", validationError("invalid --period %q with --split-at; valid: last7, last14, last30, last90", p).
				WithHint("For any other window use --days N or --time_from/--time_to (unix seconds).")
		}
		return splitSpan{now - n*86400, now}, "--period " + p, nil
	}
	if params["time_from"] == "" && params["time_to"] == "" {
		n := splitPeriodDays[splitDefaultPeriod]
		return splitSpan{now - n*86400, now}, "default " + splitDefaultPeriod, nil
	}
	if params["time_from"] == "" {
		return splitSpan{}, "", validationError("--time_to without --time_from leaves the before side of --split-at unbounded").
			WithHint("Add --time_from <unix>, or use --days N or --period last90 instead.")
	}
	from, err := parseUnixFlag("--time_from", params["time_from"])
	if err != nil {
		return splitSpan{}, "", err
	}
	to := now
	if params["time_to"] != "" {
		if to, err = parseUnixFlag("--time_to", params["time_to"]); err != nil {
			return splitSpan{}, "", err
		}
	}
	source := "--time_from/--time_to"
	switch {
	case timeFrom == "" && timeTo == "":
		source = fmt.Sprintf("--days %d", days)
	case params["time_to"] == "":
		source = "--time_from to now"
	}
	if to < from {
		return splitSpan{}, "", validationError("the window is empty: --time_to %s is before --time_from %s", utcStamp(to), utcStamp(from)).
			WithHint("Swap the two values; both are unix seconds and --time_to is the later one.")
	}
	return splitSpan{from, to}, source, nil
}

func parseUnixFlag(flag, raw string) (int64, error) {
	n, err := strconv.ParseInt(strings.TrimSpace(raw), 10, 64)
	if err != nil || n < 0 {
		return 0, validationError("%s must be unix seconds with --split-at, got %q", flag, raw).
			WithHint("Pass unix seconds (2026-06-01 00:00 UTC is 1780272000), or use --days N.")
	}
	return n, nil
}

// readSortFlags maps --sort/--sort-dir/--limit/--offset onto the merged rows.
func (p *splitPlan) readSortFlags(cmd *cobra.Command) error {
	sortBy, _ := cmd.Flags().GetString("sort")
	if sortBy = strings.ToLower(strings.TrimSpace(sortBy)); sortBy != "" {
		base := strings.TrimSuffix(sortBy, "_per_day")
		p.sortPerDay = base != sortBy
		if mapped, ok := analyticsSortAliases[base]; ok {
			base = mapped
		}
		switch base {
		case "total_clicks":
			p.sortMetric = "clicks"
		case "total_leads":
			p.sortMetric = "conversions"
		case "total_income":
			p.sortMetric = "revenue"
		default:
			return validationError("--sort %s is not available with --split-at; valid: clicks, conversions, revenue (absolute change in the total) or clicks_per_day, conversions_per_day, revenue_per_day (absolute change in the per-day rate)", sortBy).
				WithHint("The default ranks by the absolute change in clicks; pass --sort clicks_per_day when the two sides differ in length.")
		}
	}
	sortDir, _ := cmd.Flags().GetString("sort-dir")
	switch sortDir = strings.ToUpper(strings.TrimSpace(sortDir)); sortDir {
	case "":
		p.sortDir = "DESC"
	case "ASC", "DESC":
		p.sortDir = sortDir
	default:
		return validationError("--sort-dir must be ASC or DESC")
	}
	for _, f := range []struct {
		name string
		dst  *int
		min  int
	}{{"limit", &p.limit, 1}, {"offset", &p.offset, 0}} {
		raw, _ := cmd.Flags().GetString(f.name)
		if raw = strings.TrimSpace(raw); raw == "" {
			continue
		}
		n, err := strconv.Atoi(raw)
		if err != nil || n < f.min {
			return validationError("--%s must be a whole number of at least %d, got %q", f.name, f.min, raw).
				WithHint("With --split-at, --limit and --offset apply to the merged, ranked rows.")
		}
		*f.dst = n
	}
	return nil
}

// splitAgg is one dimension value's totals on each side.
type splitAgg struct {
	key           string
	id, name      interface{}
	before, after [3]float64 // in splitMetrics order
}

func perDay(v float64, s splitSpan) float64 { return v / s.days() }

// runAnalyticsSplit fetches the breakdown for both sides, every page, and
// renders one row per dimension value.
func runAnalyticsSplit(cmd *cobra.Command, c *api.Client, groupBy string, plan *splitPlan) error {
	base := map[string]string{"breakdown": groupBy}
	for _, filter := range analyticsFilterFlags {
		if v, _ := cmd.Flags().GetString(filter); v != "" {
			base[filter] = v
		}
	}
	sides := []struct {
		name string
		span splitSpan
		rows []map[string]interface{}
	}{{name: "before", span: plan.before}, {name: "after", span: plan.after}}
	for i := range sides {
		params := make(map[string]string, len(base)+2)
		for k, v := range base {
			params[k] = v
		}
		params["time_from"] = strconv.FormatInt(sides[i].span.from, 10)
		params["time_to"] = strconv.FormatInt(sides[i].span.to, 10)
		rows, err := fetchAllRowsPaged(c, "reports/breakdown", params, breakdownPageSize)
		if err != nil {
			return fmt.Errorf("fetching the %s side (%s .. %s): %w", sides[i].name, utcStamp(sides[i].span.from), utcStamp(sides[i].span.to), err)
		}
		sides[i].rows = rows
	}

	aggs := mergeSplitSides(sides[0].rows, sides[1].rows)
	sortSplitAggs(aggs, plan)
	var totals [2][3]float64
	for _, a := range aggs {
		for i := range splitMetrics {
			totals[0][i] += a.before[i]
			totals[1][i] += a.after[i]
		}
	}
	page := aggs
	if plan.offset >= len(page) {
		page = nil
	} else {
		page = page[plan.offset:]
	}
	if plan.limit > 0 && len(page) > plan.limit {
		page = page[:plan.limit]
	}
	rows := make([]map[string]interface{}, 0, len(page))
	for _, a := range page {
		rows = append(rows, splitRow(a, plan))
	}

	meta := map[string]interface{}{
		"group_by":     groupBy,
		"split_at":     plan.at,
		"split_at_utc": utcStamp(plan.at),
		"window": map[string]interface{}{
			"source":    plan.windowSource,
			"time_from": plan.window.from,
			"time_to":   plan.window.to,
		},
		"before":   splitSideMeta(plan.before, totals[0], len(sides[0].rows)),
		"after":    splitSideMeta(plan.after, totals[1], len(sides[1].rows)),
		"sort":     plan.sortField(),
		"sort_by":  "absolute value",
		"sort_dir": plan.sortDir,
		"rows":     len(aggs),
		"returned": len(rows),
	}
	payload, err := json.Marshal(map[string]interface{}{"data": rows, "meta": meta})
	if err != nil {
		return fmt.Errorf("encoding the comparison: %w", err)
	}
	if !jsonOutput {
		printSplitSummary(os.Stderr, plan, totals, len(aggs), len(rows))
	}
	renderWithColumns(payload, splitTableColumns(), splitAllColumns())
	return nil
}

// mergeSplitSides joins both sides by the breakdown's id; a value seen on
// one side only gets zeros on the other.
func mergeSplitSides(before, after []map[string]interface{}) []*splitAgg {
	byKey := map[string]*splitAgg{}
	var aggs []*splitAgg
	for side, rows := range [][]map[string]interface{}{before, after} {
		for _, r := range rows {
			key := scalarString(r["id"])
			if key == "" {
				key = "name:" + scalarString(r["name"])
			}
			a := byKey[key]
			if a == nil {
				a = &splitAgg{key: key, id: normalizeID(r["id"]), name: r["name"]}
				byKey[key] = a
				aggs = append(aggs, a)
			}
			if scalarString(a.name) == "" {
				a.name = r["name"]
			}
			for i, m := range splitMetrics {
				if side == 0 {
					a.before[i] += toFloat(r[m.field])
				} else {
					a.after[i] += toFloat(r[m.field])
				}
			}
		}
	}
	return aggs
}

// sortSplitAggs ranks by the absolute change of the sort metric (total, or
// per-day rate), ties broken by id so the order is stable across runs.
func sortSplitAggs(aggs []*splitAgg, plan *splitPlan) {
	idx := 0
	for i, m := range splitMetrics {
		if m.name == plan.sortMetric {
			idx = i
		}
	}
	key := func(a *splitAgg) float64 {
		if plan.sortPerDay {
			return math.Abs(perDay(a.after[idx], plan.after) - perDay(a.before[idx], plan.before))
		}
		return math.Abs(a.after[idx] - a.before[idx])
	}
	sort.SliceStable(aggs, func(i, j int) bool {
		ki, kj := key(aggs[i]), key(aggs[j])
		if ki != kj {
			if plan.sortDir == "ASC" {
				return ki < kj
			}
			return ki > kj
		}
		return lessID(aggs[i].key, aggs[j].key)
	})
}

// lessID orders numeric ids numerically and anything else as text.
func lessID(a, b string) bool {
	ai, errA := strconv.ParseInt(a, 10, 64)
	bi, errB := strconv.ParseInt(b, 10, 64)
	if errA == nil && errB == nil {
		return ai < bi
	}
	return a < b
}

func splitRow(a *splitAgg, plan *splitPlan) map[string]interface{} {
	row := map[string]interface{}{"id": a.id, "name": a.name}
	for i, m := range splitMetrics {
		b, af := a.before[i], a.after[i]
		pb, pa := perDay(b, plan.before), perDay(af, plan.after)
		row[m.name+"_before"] = roundTo(b, 4)
		row[m.name+"_after"] = roundTo(af, 4)
		row[m.name+"_change"] = roundTo(af-b, 4)
		row[m.name+"_change_pct"] = pctChange(b, af)
		row[m.name+"_per_day_before"] = roundTo(pb, 4)
		row[m.name+"_per_day_after"] = roundTo(pa, 4)
		row[m.name+"_per_day_change"] = roundTo(pa-pb, 4)
		row[m.name+"_per_day_change_pct"] = pctChange(pb, pa)
	}
	return row
}

// pctChange is the percent change from before to after; nil (JSON null)
// when before is 0, where no percentage exists.
func pctChange(before, after float64) interface{} {
	if before == 0 {
		return nil
	}
	return roundTo((after-before)/before*100, 2)
}

func splitSideMeta(s splitSpan, totals [3]float64, rows int) map[string]interface{} {
	m := map[string]interface{}{
		"time_from":     s.from,
		"time_to":       s.to,
		"time_from_utc": utcStamp(s.from),
		"time_to_utc":   utcStamp(s.to),
		"seconds":       s.seconds(),
		"days":          roundTo(s.days(), 4),
		"rows":          rows,
	}
	for i, metric := range splitMetrics {
		m[metric.name] = roundTo(totals[i], 4)
		m[metric.name+"_per_day"] = roundTo(perDay(totals[i], s), 4)
	}
	return m
}

func printSplitSummary(w io.Writer, plan *splitPlan, totals [2][3]float64, rows, shown int) {
	fmt.Fprintf(w, "Split at %s (%d); window: %s\n", utcStamp(plan.at), plan.at, plan.windowSource)
	for i, side := range []struct {
		name string
		span splitSpan
	}{{"before", plan.before}, {"after", plan.after}} {
		fmt.Fprintf(w, "  %-6s %s .. %s  %.2f days", side.name, utcStamp(side.span.from), utcStamp(side.span.to), side.span.days())
		for j, m := range splitMetrics {
			fmt.Fprintf(w, "  %s %s (%s/day)", m.name, strconv.FormatFloat(roundTo(totals[i][j], 2), 'f', -1, 64),
				strconv.FormatFloat(roundTo(perDay(totals[i][j], side.span), 2), 'f', -1, 64))
		}
		fmt.Fprintln(w)
	}
	fmt.Fprintf(w, "%d value(s), %d shown, ranked by |%s| %s.", rows, shown, plan.sortField(), plan.sortDir)
	if plan.before.seconds() != plan.after.seconds() {
		fmt.Fprint(w, " The sides differ in length: compare the *_per_day columns, not the totals.")
	}
	fmt.Fprintln(w)
}

func splitAllColumns() []string {
	cols := []string{"id", "name"}
	for _, m := range splitMetrics {
		for _, s := range []string{"_before", "_after", "_change", "_change_pct", "_per_day_before", "_per_day_after", "_per_day_change", "_per_day_change_pct"} {
			cols = append(cols, m.name+s)
		}
	}
	return cols
}

// splitTableColumns is the human table's default: every clicks column, the
// totals and percent change for the rest (--json and --csv carry all).
func splitTableColumns() []string {
	return []string{"id", "name",
		"clicks_before", "clicks_after", "clicks_change", "clicks_change_pct",
		"clicks_per_day_before", "clicks_per_day_after", "clicks_per_day_change_pct",
		"conversions_before", "conversions_after", "conversions_change_pct",
		"revenue_before", "revenue_after", "revenue_change_pct"}
}

// renderWithColumns renders data in the given column order unless --fields
// chose the columns; JSON and NDJSON output are unaffected.
func renderWithColumns(data []byte, table, csv []string) {
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		if opts.CSV {
			opts.Fields = csv
		} else {
			opts.Fields = table
		}
	}
	output.RenderWith(data, opts)
}

func utcStamp(unix int64) string {
	return time.Unix(unix, 0).UTC().Format(time.RFC3339)
}

func init() {
	analyticsCmd.Flags().String("split-at", "", "Compare before/after this moment: YYYY-MM-DD (00:00 UTC) or unix seconds. Splits the window "+
		"(--period last7|last30|last90, --days, --time_from/--time_to; default "+splitDefaultPeriod+") into [start, split) and [split, end] "+
		"and returns one row per value: clicks/conversions/revenue before, after, change, change %, and per-day rates. "+
		"--sort clicks|conversions|revenue[_per_day] ranks by absolute change (default clicks)")
}
