package cmd

import (
	"encoding/json"
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/forecast"

	"github.com/spf13/cobra"
)

var forecastAllowedMetrics = map[string]bool{
	"total_clicks":         true,
	"total_click_throughs": true,
	"total_leads":          true,
	"total_income":         true,
	"total_cost":           true,
	"total_net":            true,
	"epc":                  true,
	"avg_cpc":              true,
	"conv_rate":            true,
	"roi":                  true,
	"cpa":                  true,
}

// forecastSignedMetrics can legitimately go negative; all other metrics
// are clamped at zero in the forecast output.
var forecastSignedMetrics = map[string]bool{
	"total_net": true,
	"roi":       true,
}

var forecastMetricAliases = map[string]string{
	"clicks":      "total_clicks",
	"conversions": "total_leads",
	"leads":       "total_leads",
	"revenue":     "total_income",
	"income":      "total_income",
	"cost":        "total_cost",
	"profit":      "total_net",
	"net":         "total_net",
}

var forecastCmd = &cobra.Command{
	Use:   "forecast",
	Short: "Forecast future performance metrics using historical data",
	Long: `Forecast future values for any tracked metric using statistical methods.

Fetches historical time-series data from your Prosper202 instance and projects
it forward using the selected algorithm. Supports linear regression, simple
and weighted moving averages, Holt-Winters exponential smoothing, and automatic
method selection that picks the best fit via backtesting.

With --seasonal, predictions are modulated by day-of-week weights derived from
weekpart report data to account for weekly patterns (e.g., "Tuesdays always
convert better"). Seasonal adjustment requires --interval day or hour.

Event-aware forecasting (--events, --event-tag) uses stored calendar events and
requires --interval day.

Examples:
  p202 forecast --metric revenue --horizon 7
  p202 forecast --metric clicks --history last90 --method linear
  p202 forecast --metric profit --horizon 14 --method auto --seasonal
  p202 forecast --metric conv_rate --history last30 --interval week --horizon 4
  p202 forecast --metric revenue --aff_campaign_id 5 --horizon 7
  p202 forecast --metric revenue --events --horizon 14
  p202 forecast --metric clicks --events --event-tag us-holidays`,
	Aliases: []string{"predict"},
	RunE:    runForecast,
}

func runForecast(cmd *cobra.Command, args []string) error {
	metric, _ := cmd.Flags().GetString("metric")
	metric = strings.ToLower(strings.TrimSpace(metric))
	if metric == "" {
		return validationError("--metric is required. Choose from: %s", forecastMetricList())
	}
	if mapped, ok := forecastMetricAliases[metric]; ok {
		metric = mapped
	}
	if !forecastAllowedMetrics[metric] {
		return validationError("unsupported metric %q. Choose from: %s", metric, forecastMetricList())
	}

	methodStr, _ := cmd.Flags().GetString("method")
	methodStr = strings.ToLower(strings.TrimSpace(methodStr))
	if methodStr == "" {
		methodStr = "auto"
	}
	method := forecast.Method(methodStr)
	validMethods := map[string]bool{}
	for _, m := range forecast.ValidMethods() {
		validMethods[m] = true
	}
	if !validMethods[methodStr] {
		return validationError("unsupported method %q. Choose from: %s", methodStr, strings.Join(forecast.ValidMethods(), ", "))
	}

	horizon, _ := cmd.Flags().GetInt("horizon")
	if horizon <= 0 {
		horizon = 7
	}
	if horizon > 365 {
		return validationError("--horizon cannot exceed 365")
	}

	interval, _ := cmd.Flags().GetString("interval")
	interval = strings.ToLower(strings.TrimSpace(interval))
	if interval == "" {
		interval = "day"
	}
	validIntervals := map[string]bool{}
	for _, iv := range forecast.ValidIntervals() {
		validIntervals[iv] = true
	}
	if !validIntervals[interval] {
		return validationError("unsupported interval %q. Choose from: %s", interval, strings.Join(forecast.ValidIntervals(), ", "))
	}

	// --history is the canonical flag; --period/--days are accepted aliases so
	// the time-window flag name is consistent with the report commands. Because
	// --history carries a non-empty default, only an *explicitly set* alias must
	// win — so resolve by Changed(), not by the default value.
	history := "last90"
	for _, name := range []string{"history", "period", "days"} {
		if cmd.Flags().Changed(name) {
			if v, _ := cmd.Flags().GetString(name); strings.TrimSpace(v) != "" {
				history = strings.TrimSpace(v)
				break
			}
		}
	}

	// reports/timeseries returns at most 2000 buckets ordered oldest-first,
	// so hourly windows longer than last30 (720 buckets) would silently drop
	// the most recent hours — the ones forecasts anchor on.
	if interval == "hour" {
		if !cmd.Flags().Changed("history") && !cmd.Flags().Changed("period") && !cmd.Flags().Changed("days") {
			history = "last30"
		} else {
			switch history {
			case "today", "yesterday", "last7", "last30":
			default:
				return validationError("--interval hour supports --history today, yesterday, last7, or last30; longer windows exceed the API's 2000-bucket limit and would drop the most recent hours")
			}
		}
	}

	smaWindow, _ := cmd.Flags().GetInt("window")
	seasonal, _ := cmd.Flags().GetBool("seasonal")
	if seasonal && interval != "day" && interval != "hour" {
		// Week/month predictions are aggregate buckets containing every
		// weekday; scaling them by the bucket-start day's weight would
		// systematically skew the totals.
		return validationError("--seasonal requires --interval day or hour")
	}
	useEvents, _ := cmd.Flags().GetBool("events")
	eventTag, _ := cmd.Flags().GetString("event-tag")
	if (useEvents || eventTag != "") && interval != "day" {
		// Events are calendar-day entities and impact learning operates at day
		// granularity; other intervals would silently produce wrong adjustments.
		return validationError("--events and --event-tag require --interval day")
	}
	confidence, _ := cmd.Flags().GetFloat64("confidence")
	if confidence <= 0 || confidence >= 1 {
		confidence = 0.95
	}

	// Build API client.
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}

	// Fetch historical time-series data.
	params := collectForecastFilters(cmd)
	params["period"] = history
	params["interval"] = interval

	data, err := c.Get("reports/timeseries", params)
	if err != nil {
		return fmt.Errorf("fetching historical data: %w", err)
	}

	series, err := parseTimeseries(data, metric)
	if err != nil {
		return err
	}

	if len(series) < 3 {
		return validationError("not enough data points (%d) for forecasting — need at least 3. Try a longer --history period", len(series))
	}

	// Build forecast config.
	cfg := forecast.Config{
		Method:          method,
		Horizon:         horizon,
		Interval:        forecast.Interval(interval),
		Metric:          metric,
		SMAWindow:       smaWindow,
		ConfidenceLevel: confidence,
	}

	// Optionally fetch weekpart data for seasonal adjustment.
	if seasonal {
		weekpartParams := collectForecastFilters(cmd)
		weekpartParams["period"] = history
		wpData, wpErr := c.Get("reports/weekpart", weekpartParams)
		if wpErr == nil {
			weights := parseWeekpartWeights(wpData, metric)
			if weights != nil {
				cfg.SeasonalWeights = weights
			}
		}
	}

	// ── Event-aware forecasting pipeline ──────────────────────────────
	var allEvents []forecast.Event
	var learnedImpacts map[string]forecast.LearnedImpact
	var futureEvents []forecast.Event

	if useEvents || eventTag != "" {
		// Fetch all forecast events from the API.
		allEvents, err = fetchAllForecastEvents(c)
		if err != nil {
			return err
		}

		// Filter by tag if specified.
		if eventTag != "" {
			allEvents = filterEventsByTag(allEvents, eventTag)
		}

		if len(allEvents) > 0 {
			// Select past events within the training range.
			trainStart := series[0].T
			trainEnd := series[len(series)-1].T
			pastEvents := forecast.PastEvents(allEvents, trainStart, trainEnd)

			// Learn impacts from historical event data.
			if len(pastEvents) > 0 {
				// LearnEventImpacts needs the unmasked series (actual event-day
				// values); the baseline forecast then trains on the masked series.
				learnedImpacts = forecast.LearnEventImpacts(series, pastEvents, cfg)

				// Mask event days from training data for clean baseline fitting.
				series = forecast.MaskEventDays(series, pastEvents)
				if len(series) < 3 {
					return validationError("after masking event days, only %d data points remain — need at least 3", len(series))
				}

				// Masking may drop the most recent observations, but predictions
				// must still start after the original training end — not inside
				// already-observed (masked) history. Anchoring also keeps the
				// horizon aligned with futureEvents below.
				cfg.Anchor = trainEnd
			}

			// Determine forecast horizon dates (events imply --interval day).
			forecastStart := trainEnd.AddDate(0, 0, 1)
			forecastEnd := trainEnd.AddDate(0, 0, horizon)
			futureEvents = forecast.FutureEvents(allEvents, forecastStart, forecastEnd)
		}
	}

	// Run the baseline forecast on clean data.
	result, err := forecast.Run(series, cfg)
	if err != nil {
		return fmt.Errorf("forecast failed: %w", err)
	}

	// Apply event adjustments to predictions.
	if len(futureEvents) > 0 {
		result.Predictions = forecast.ApplyEventAdjustments(result.Predictions, futureEvents, learnedImpacts)
	}

	// Trending methods can project below zero on declining series; clamp
	// metrics that cannot be negative (clicks, income, rates) at zero.
	if !forecastSignedMetrics[metric] {
		clampNonNegative(result.Predictions)
	}

	// Render output.
	output, err := buildForecastOutput(result, metric, seasonal, useEvents || eventTag != "", futureEvents, learnedImpacts)
	if err != nil {
		return err
	}
	render(output)
	return nil
}

// collectForecastFilters gathers entity filter flags.
func collectForecastFilters(cmd *cobra.Command) map[string]string {
	params := map[string]string{}
	for _, f := range []string{"aff_campaign_id", "ppc_account_id", "aff_network_id",
		"ppc_network_id", "landing_page_id", "country_id"} {
		if v, _ := cmd.Flags().GetString(f); v != "" {
			params[f] = v
		}
	}
	return params
}

// parseTimeseries extracts a forecast.Series from the API timeseries response.
func parseTimeseries(data []byte, metric string) (forecast.Series, error) {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil, fmt.Errorf("invalid timeseries response: %w", err)
	}

	rawItems, ok := parsed["data"].([]interface{})
	if !ok {
		return nil, fmt.Errorf("timeseries response missing data array")
	}

	if len(rawItems) == 0 {
		return nil, fmt.Errorf("timeseries returned empty data")
	}

	series := make(forecast.Series, 0, len(rawItems))
	for _, raw := range rawItems {
		obj, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}

		t, tErr := parseBucketTime(obj)
		if tErr != nil {
			continue
		}

		val, vOk := extractMetricValue(obj, metric)
		if !vOk {
			continue
		}

		series = append(series, forecast.Point{T: t, V: val})
	}

	if len(series) == 0 {
		return nil, fmt.Errorf("no valid data points found for metric %q", metric)
	}

	// Sort by time.
	sort.Slice(series, func(i, j int) bool {
		return series[i].T.Before(series[j].T)
	})

	return series, nil
}

// parseBucketTime extracts a time from a timeseries bucket.
// Tries: "bucket_start" (unix), "bucket" (date string), "period_start".
func parseBucketTime(obj map[string]interface{}) (time.Time, error) {
	// Try unix timestamp fields first.
	for _, key := range []string{"bucket_start", "period_start", "timestamp"} {
		if raw, ok := obj[key]; ok {
			if ts, ok := toUnixTimestamp(raw); ok {
				return time.Unix(ts, 0).UTC(), nil
			}
		}
	}

	// Try date string fields.
	for _, key := range []string{"bucket", "date", "period"} {
		if raw, ok := obj[key].(string); ok && raw != "" {
			for _, layout := range []string{
				"2006-01-02 15:04:05",
				"2006-01-02 15:04", // hour interval: %Y-%m-%d %H:00
				"2006-01-02T15:04:05Z",
				"2006-01-02",
				"2006-01",
			} {
				if t, err := time.Parse(layout, raw); err == nil {
					return t, nil
				}
			}
			// week interval: %x-W%v (e.g. "2026-W03")
			if t, ok := parseISOWeek(raw); ok {
				return t, nil
			}
		}
	}

	return time.Time{}, fmt.Errorf("no parseable time field in bucket")
}

// parseISOWeek parses MySQL's %x-W%v week format (e.g. "2026-W03"),
// returning the Monday of that ISO week.
func parseISOWeek(s string) (time.Time, bool) {
	var year, week int
	if n, err := fmt.Sscanf(s, "%d-W%d", &year, &week); err != nil || n != 2 {
		return time.Time{}, false
	}
	if week < 1 || week > 53 {
		return time.Time{}, false
	}
	// January 4th is always in ISO week 1; walk back to that week's
	// Monday, then advance by (week-1) weeks.
	jan4 := time.Date(year, 1, 4, 0, 0, 0, 0, time.UTC)
	daysSinceMonday := (int(jan4.Weekday()) + 6) % 7
	week1Monday := jan4.AddDate(0, 0, -daysSinceMonday)
	return week1Monday.AddDate(0, 0, (week-1)*7), true
}

// toUnixTimestamp converts an interface to a unix timestamp int64.
func toUnixTimestamp(v interface{}) (int64, bool) {
	switch val := v.(type) {
	case float64:
		return int64(val), true
	case int64:
		return val, true
	case int:
		return int64(val), true
	case string:
		trimmed := strings.TrimSpace(val)
		if trimmed == "" {
			return 0, false
		}
		ts, err := strconv.ParseInt(trimmed, 10, 64)
		if err != nil {
			return 0, false
		}
		return ts, true
	default:
		return 0, false
	}
}

// extractMetricValue pulls the named metric from a timeseries bucket.
func extractMetricValue(obj map[string]interface{}, metric string) (float64, bool) {
	raw, ok := obj[metric]
	if !ok {
		return 0, false
	}
	switch val := raw.(type) {
	case float64:
		return val, true
	case int:
		return float64(val), true
	case int64:
		return float64(val), true
	case string:
		trimmed := strings.TrimSpace(val)
		if trimmed == "" {
			return 0, false
		}
		f, err := strconv.ParseFloat(trimmed, 64)
		if err != nil {
			return 0, false
		}
		return f, true
	default:
		return 0, false
	}
}

// parseWeekpartWeights extracts seasonal weights from the weekpart API response.
func parseWeekpartWeights(data []byte, metric string) forecast.SeasonalWeights {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil
	}

	rawItems, ok := parsed["data"].([]interface{})
	if !ok {
		return nil
	}

	rows := make([]map[string]interface{}, 0, len(rawItems))
	for _, raw := range rawItems {
		if obj, ok := raw.(map[string]interface{}); ok {
			rows = append(rows, obj)
		}
	}

	return forecast.BuildWeekdayWeights(rows, metric)
}

// buildForecastOutput constructs the JSON output for rendering.
func buildForecastOutput(result *forecast.Result, metric string, seasonal bool, eventsActive bool, futureEvents []forecast.Event, impacts map[string]forecast.LearnedImpact) ([]byte, error) {
	predictions := make([]map[string]interface{}, len(result.Predictions))
	for i, p := range result.Predictions {
		row := map[string]interface{}{
			"date":        formatPredictionTime(p.T, result.Interval),
			metric:        roundTo(p.Value, 2),
			"lower_bound": roundTo(p.LowerBound, 2),
			"upper_bound": roundTo(p.UpperBound, 2),
		}

		// Add trend indicator for first row.
		if i == 0 {
			if result.TrendPct > 0 {
				row["trend"] = fmt.Sprintf("+%.1f%%", result.TrendPct)
			} else {
				row["trend"] = fmt.Sprintf("%.1f%%", result.TrendPct)
			}
		} else {
			row["trend"] = ""
		}

		predictions[i] = row
	}

	// Build metadata.
	meta := map[string]interface{}{
		"method":           string(result.Method),
		"metric":           result.Metric,
		"horizon":          result.Horizon,
		"interval":         string(result.Interval),
		"data_points_used": result.DataPoints,
		"trend_per_period": roundTo(result.Trend, 4),
		"trend_pct":        roundTo(result.TrendPct, 2),
		"seasonal":         seasonal,
		"events_active":    eventsActive,
	}
	if result.MAE > 0 {
		meta["mae"] = roundTo(result.MAE, 2)
		meta["rmse"] = roundTo(result.RMSE, 2)
	}

	if eventsActive && len(futureEvents) > 0 {
		names := make([]string, 0, len(futureEvents))
		seen := map[string]bool{}
		var unquantified []string
		seenUnq := map[string]bool{}
		for _, e := range futureEvents {
			if !seen[e.Name] {
				names = append(names, e.Name)
				seen[e.Name] = true
			}
			// An event only adjusts values when its impact was learned from
			// history or given via expected_impact_pct. We deliberately never
			// invent a magnitude from impact_type alone — flag such events so
			// users know they were seen but did not move the numbers.
			if _, learned := impacts[e.Name]; !learned && e.ExpectedImpactPct == 0 && !seenUnq[e.Name] {
				unquantified = append(unquantified, e.Name)
				seenUnq[e.Name] = true
			}
		}
		sort.Strings(names)
		meta["events_in_horizon"] = names
		if len(unquantified) > 0 {
			sort.Strings(unquantified)
			meta["events_unquantified"] = unquantified
		}
	}

	output := map[string]interface{}{
		"data": predictions,
		"meta": meta,
	}

	payload, err := json.Marshal(output)
	if err != nil {
		return nil, fmt.Errorf("marshalling forecast output: %w", err)
	}
	return payload, nil
}

// formatPredictionTime formats a time for display based on the forecast interval.
func formatPredictionTime(t time.Time, interval forecast.Interval) string {
	switch interval {
	case forecast.IntervalHour:
		return t.Format("2006-01-02 15:04")
	case forecast.IntervalMonth:
		return t.Format("2006-01")
	case forecast.IntervalWeek:
		return t.Format("2006-01-02") + " (wk)"
	default:
		return t.Format("2006-01-02")
	}
}

// clampNonNegative floors prediction values and bounds at zero.
func clampNonNegative(preds []forecast.Prediction) {
	for i := range preds {
		if preds[i].Value < 0 {
			preds[i].Value = 0
		}
		if preds[i].LowerBound < 0 {
			preds[i].LowerBound = 0
		}
		if preds[i].UpperBound < 0 {
			preds[i].UpperBound = 0
		}
	}
}

// roundTo rounds a float to n decimal places.
func roundTo(v float64, n int) float64 {
	pow := 1.0
	for i := 0; i < n; i++ {
		pow *= 10
	}
	return math.Round(v*pow) / pow
}

// forecastMetricList returns a sorted comma-separated list of valid metrics.
func forecastMetricList() string {
	keys := make([]string, 0, len(forecastAllowedMetrics))
	for k := range forecastAllowedMetrics {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return strings.Join(keys, ", ")
}

// maxForecastEventPages caps pagination at 50 pages × 500 events. Hitting it
// means a pathological event count (or a server cursor bug); erroring is
// safer than silently forecasting with a truncated event list.
const maxForecastEventPages = 50

// fetchAllForecastEvents retrieves every forecast event from the API,
// following the list endpoint's pagination cursor across pages.
func fetchAllForecastEvents(c *api.Client) ([]forecast.Event, error) {
	var all []forecast.Event
	params := map[string]string{"limit": "500"}

	for page := 0; page < maxForecastEventPages; page++ {
		data, err := c.Get("forecast-events", params)
		if err != nil {
			return nil, fmt.Errorf("fetching forecast events: %w", err)
		}

		events, err := parseForecastEvents(data)
		if err != nil {
			return nil, fmt.Errorf("parsing forecast events: %w", err)
		}
		all = append(all, events...)

		cursor := parseListCursor(data)
		if cursor == "" {
			return all, nil
		}
		params = map[string]string{"limit": "500", "cursor": cursor}
	}

	return nil, fmt.Errorf("forecast events exceed %d pages — aborting rather than forecasting with a truncated event list", maxForecastEventPages)
}

// parseListCursor extracts the next-page cursor from a V3 list response,
// returning "" when there are no more pages.
func parseListCursor(data []byte) string {
	var parsed struct {
		Pagination struct {
			Cursor string `json:"cursor"`
		} `json:"pagination"`
	}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return ""
	}
	return parsed.Pagination.Cursor
}

// parseForecastEvents parses the API response from GET /forecast-events into Event structs.
func parseForecastEvents(data []byte) ([]forecast.Event, error) {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil, fmt.Errorf("invalid forecast events response: %w", err)
	}

	rawItems, ok := parsed["data"].([]interface{})
	if !ok {
		return nil, fmt.Errorf("forecast events response missing data array")
	}

	events := make([]forecast.Event, 0, len(rawItems))
	for _, raw := range rawItems {
		obj, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}

		name, _ := obj["event_name"].(string)
		if name == "" {
			continue
		}

		dateStr, _ := obj["event_date"].(string)
		eventDate, err := parseEventDate(dateStr)
		if err != nil {
			continue
		}

		e := forecast.Event{
			Name:       name,
			Date:       eventDate,
			Recurrence: stringField(obj, "recurrence"),
			ImpactType: stringField(obj, "impact_type"),
			Tags:       stringField(obj, "tags"),
		}

		if idVal, ok := obj["event_id"]; ok {
			if id, ok := toUnixTimestamp(idVal); ok {
				e.ID = int(id)
			}
		}

		if endStr, _ := obj["end_date"].(string); endStr != "" {
			if endDate, err := parseEventDate(endStr); err == nil {
				e.EndDate = endDate
			}
		}

		if v, ok := obj["expected_impact_pct"]; ok {
			switch val := v.(type) {
			case float64:
				e.ExpectedImpactPct = val
			case string:
				if f, err := strconv.ParseFloat(val, 64); err == nil {
					e.ExpectedImpactPct = f
				}
			}
		}

		if v, ok := obj["lead_days"]; ok {
			switch val := v.(type) {
			case float64:
				e.LeadDays = int(val)
			case string:
				if i, err := strconv.Atoi(val); err == nil {
					e.LeadDays = i
				}
			}
		}

		if v, ok := obj["lag_days"]; ok {
			switch val := v.(type) {
			case float64:
				e.LagDays = int(val)
			case string:
				if i, err := strconv.Atoi(val); err == nil {
					e.LagDays = i
				}
			}
		}

		events = append(events, e)
	}

	return events, nil
}

// parseEventDate parses date strings from the API in common formats.
func parseEventDate(s string) (time.Time, error) {
	s = strings.TrimSpace(s)
	if s == "" || s == "0000-00-00" || s == "0000-00-00 00:00:00" {
		return time.Time{}, fmt.Errorf("empty date")
	}
	for _, layout := range []string{
		"2006-01-02 15:04:05",
		"2006-01-02T15:04:05Z",
		"2006-01-02",
	} {
		if t, err := time.Parse(layout, s); err == nil {
			return t, nil
		}
	}
	return time.Time{}, fmt.Errorf("unparseable date: %s", s)
}

// stringField extracts a string value from a map, returning "" if missing.
func stringField(obj map[string]interface{}, key string) string {
	v, _ := obj[key].(string)
	return v
}

// filterEventsByTag keeps only events whose tags field contains at least one
// of the comma-separated tags in the filter string. Matching is case-insensitive.
func filterEventsByTag(events []forecast.Event, tagFilter string) []forecast.Event {
	wantTags := map[string]bool{}
	for _, t := range strings.Split(tagFilter, ",") {
		t = strings.ToLower(strings.TrimSpace(t))
		if t != "" {
			wantTags[t] = true
		}
	}
	if len(wantTags) == 0 {
		return events
	}

	var filtered []forecast.Event
	for _, e := range events {
		for _, t := range strings.Split(e.Tags, ",") {
			t = strings.ToLower(strings.TrimSpace(t))
			if wantTags[t] {
				filtered = append(filtered, e)
				break
			}
		}
	}
	return filtered
}

func init() {
	forecastCmd.Flags().StringP("metric", "m", "", "Metric to forecast (clicks, revenue, profit, roi, epc, conv_rate, cost, conversions, cpa)")
	forecastCmd.Flags().String("method", "auto", "Forecasting method: auto, linear, sma, wma, holtwinters")
	forecastCmd.Flags().IntP("horizon", "n", 7, "Number of periods to forecast forward")
	forecastCmd.Flags().StringP("interval", "i", "day", "Forecast granularity: hour, day, week, month")
	forecastCmd.Flags().String("history", "last90", "Historical data period: today, yesterday, last7, last30, last90 (aliases: --period, --days)")
	forecastCmd.Flags().String("period", "", "Alias of --history")
	forecastCmd.Flags().String("days", "", "Alias of --history")
	forecastCmd.Flags().Int("window", 0, "SMA/WMA window size (0 = auto-select)")
	forecastCmd.Flags().Bool("seasonal", false, "Apply day-of-week seasonal adjustment from weekpart data")
	forecastCmd.Flags().Float64("confidence", 0.95, "Confidence level for prediction bounds (0.80, 0.90, 0.95, 0.99)")
	forecastCmd.Flags().Bool("events", false, "Enable event-aware forecasting using stored forecast events")
	forecastCmd.Flags().String("event-tag", "", "Filter forecast events by tag (comma-separated, e.g. us-holidays,promos)")

	// Entity filters — same as report commands for consistency.
	forecastCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	forecastCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	forecastCmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	forecastCmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	forecastCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	forecastCmd.Flags().String("country_id", "", "Filter by country ID")

	rootCmd.AddCommand(forecastCmd)
}
