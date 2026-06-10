package forecast

import (
	"strconv"
	"strings"
	"time"
)

// dayNames maps both the short names emitted by the weekpart API ("Mon")
// and full names ("Monday") to time.Weekday.
var dayNames = map[string]time.Weekday{
	"Sun": time.Sunday, "Sunday": time.Sunday,
	"Mon": time.Monday, "Monday": time.Monday,
	"Tue": time.Tuesday, "Tuesday": time.Tuesday,
	"Wed": time.Wednesday, "Wednesday": time.Wednesday,
	"Thu": time.Thursday, "Thursday": time.Thursday,
	"Fri": time.Friday, "Friday": time.Friday,
	"Sat": time.Saturday, "Saturday": time.Saturday,
}

// BuildWeekdayWeights constructs SeasonalWeights from day-of-week report data.
//
// The input is a slice of maps. Each row is expected to have a day identifier
// and a metric value. Day is resolved in order of preference:
//   - "day_name" (string): "Mon"/"Monday" etc. — weekpart API primary field
//   - "day_of_week" (string): a day name, or a numeric MySQL WEEKDAY() index
//   - "day_of_week" (numeric 0–6): MySQL WEEKDAY() — 0=Monday … 6=Sunday
//
// Weights are computed as (day_value / overall_mean). A day with exactly
// average performance gets weight 1.0. A day with 20% more gets 1.2.
// Zero-value days are included and receive weight 0.0.
//
// Returns nil if no valid data is found.
func BuildWeekdayWeights(rows []map[string]interface{}, metric string) SeasonalWeights {
	dayValues := map[time.Weekday]float64{}
	total := 0.0
	count := 0

	for _, row := range rows {
		dow, ok := resolveDayOfWeek(row)
		if !ok {
			continue
		}
		rawVal, exists := row[metric]
		if !exists {
			continue
		}
		val := extractFloat(rawVal)
		dayValues[dow] = val
		total += val
		count++
	}

	if count == 0 {
		return nil
	}

	mean := total / float64(count)
	if mean == 0 {
		return nil
	}

	weights := SeasonalWeights{}
	for dow, val := range dayValues {
		weights[dow] = val / mean
	}

	return weights
}

// resolveDayOfWeek extracts the day of week from a weekpart API row.
// It tries day_name (string), then day_of_week as a day name, a numeric
// string, or a number — numeric values use the MySQL WEEKDAY() convention
// (0=Monday…6=Sunday) that the weekpart endpoint emits.
func resolveDayOfWeek(row map[string]interface{}) (time.Weekday, bool) {
	if nameStr, ok := row["day_name"].(string); ok {
		if dow, ok := dayNames[nameStr]; ok {
			return dow, true
		}
	}
	switch raw := row["day_of_week"].(type) {
	case string:
		if dow, ok := dayNames[raw]; ok {
			return dow, true
		}
		if idx, err := strconv.Atoi(strings.TrimSpace(raw)); err == nil {
			return weekdayFromAPIIndex(idx)
		}
		return 0, false
	case float64, float32, int, int64:
		return weekdayFromAPIIndex(int(extractFloat(raw)))
	default:
		return 0, false
	}
}

// weekdayFromAPIIndex converts a MySQL WEEKDAY() index (0=Monday…6=Sunday)
// to a time.Weekday (0=Sunday…6=Saturday).
func weekdayFromAPIIndex(idx int) (time.Weekday, bool) {
	if idx < 0 || idx > 6 {
		return 0, false
	}
	return time.Weekday((idx + 1) % 7), true
}

// extractFloat tries to pull a float64 from an interface{} value,
// handling the common JSON number types.
func extractFloat(v interface{}) float64 {
	switch val := v.(type) {
	case float64:
		return val
	case float32:
		return float64(val)
	case int:
		return float64(val)
	case int64:
		return float64(val)
	case string:
		trimmed := strings.TrimSpace(val)
		if trimmed == "" {
			return 0
		}
		f, err := strconv.ParseFloat(trimmed, 64)
		if err != nil {
			return 0
		}
		return f
	default:
		return 0
	}
}
