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
	"total_clicks":        true,
	"total_click_throughs": true,
	"total_leads":         true,
	"total_income":        true,
	"total_cost":          true,
	"total_net":           true,
	"epc":                 true,
	"avg_cpc":             true,
	"conv_rate":           true,
	"roi":                 true,
	"cpa":                 true,
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

Seasonal adjustment is applied automatically when day-of-week data is available,
modulating predictions to account for weekly patterns (e.g., "Tuesdays always
convert better").

Examples:
  p202 forecast --metric revenue --horizon 7
  p202 forecast --metric clicks --history last90 --method linear
  p202 forecast --metric profit --horizon 14 --method auto --seasonal
  p202 forecast --metric conv_rate --history last30 --interval week --horizon 4
  p202 forecast --metric revenue --aff_campaign_id 5 --horizon 7`,
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

	history, _ := cmd.Flags().GetString("history")
	history = strings.TrimSpace(history)
	if history == "" {
		history = "last90"
	}

	smaWindow, _ := cmd.Flags().GetInt("window")
	seasonal, _ := cmd.Flags().GetBool("seasonal")
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

	// Run the forecast.
	result, err := forecast.Run(series, cfg)
	if err != nil {
		return fmt.Errorf("forecast failed: %w", err)
	}

	// Render output.
	output := buildForecastOutput(result, metric, seasonal)
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
				"2006-01-02T15:04:05Z",
				"2006-01-02",
				"2006-01",
			} {
				if t, err := time.Parse(layout, raw); err == nil {
					return t, nil
				}
			}
		}
	}

	return time.Time{}, fmt.Errorf("no parseable time field in bucket")
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
func buildForecastOutput(result *forecast.Result, metric string, seasonal bool) []byte {
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
	}
	if result.MAE > 0 {
		meta["mae"] = roundTo(result.MAE, 2)
		meta["rmse"] = roundTo(result.RMSE, 2)
	}

	output := map[string]interface{}{
		"data": predictions,
		"meta": meta,
	}

	payload, _ := json.Marshal(output)
	return payload
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

func init() {
	forecastCmd.Flags().StringP("metric", "m", "", "Metric to forecast (clicks, revenue, profit, roi, epc, conv_rate, cost, conversions, cpa)")
	forecastCmd.Flags().String("method", "auto", "Forecasting method: auto, linear, sma, wma, holtwinters")
	forecastCmd.Flags().IntP("horizon", "n", 7, "Number of periods to forecast forward")
	forecastCmd.Flags().StringP("interval", "i", "day", "Forecast granularity: hour, day, week, month")
	forecastCmd.Flags().String("history", "last90", "Historical data period: today, yesterday, last7, last30, last90")
	forecastCmd.Flags().Int("window", 0, "SMA/WMA window size (0 = auto-select)")
	forecastCmd.Flags().Bool("seasonal", false, "Apply day-of-week seasonal adjustment from weekpart data")
	forecastCmd.Flags().Float64("confidence", 0.95, "Confidence level for prediction bounds (0.80, 0.90, 0.95, 0.99)")

	// Entity filters — same as report commands for consistency.
	forecastCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
	forecastCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
	forecastCmd.Flags().String("aff_network_id", "", "Filter by affiliate network ID")
	forecastCmd.Flags().String("ppc_network_id", "", "Filter by PPC network ID")
	forecastCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
	forecastCmd.Flags().String("country_id", "", "Filter by country ID")

	rootCmd.AddCommand(forecastCmd)
}
