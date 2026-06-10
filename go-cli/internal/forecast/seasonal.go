package forecast

import (
	"strconv"
	"strings"
	"time"
)

// BuildWeekdayWeights constructs SeasonalWeights from day-of-week report data.
//
// The input is a slice of maps. Each row is expected to have a day identifier
// and a metric value. Day is resolved in order of preference:
//   - "day_name" (string): "Monday", "Tuesday", etc.  — weekpart API primary field
//   - "day_of_week" (string): same day name format
//   - "day_of_week" (numeric 0–6): 0=Sunday … 6=Saturday — weekpart API index
//
// Weights are computed as (day_value / overall_mean). A day with exactly
// average performance gets weight 1.0. A day with 20% more gets 1.2.
// Zero-value days are included and receive weight 0.0.
//
// Returns nil if no valid data is found.
func BuildWeekdayWeights(rows []map[string]interface{}, metric string) SeasonalWeights {
	dayNames := map[string]time.Weekday{
		"Sunday":    time.Sunday,
		"Monday":    time.Monday,
		"Tuesday":   time.Tuesday,
		"Wednesday": time.Wednesday,
		"Thursday":  time.Thursday,
		"Friday":    time.Friday,
		"Saturday":  time.Saturday,
	}

	dayValues := map[time.Weekday]float64{}
	total := 0.0
	count := 0

	for _, row := range rows {
		dow, ok := resolveDayOfWeek(row, dayNames)
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
// It tries day_name (string), then day_of_week (string), then
// day_of_week as a numeric index (0=Sunday…6=Saturday).
func resolveDayOfWeek(row map[string]interface{}, dayNames map[string]time.Weekday) (time.Weekday, bool) {
	if nameStr, ok := row["day_name"].(string); ok {
		if dow, ok := dayNames[nameStr]; ok {
			return dow, true
		}
	}
	if dayStr, ok := row["day_of_week"].(string); ok {
		if dow, ok := dayNames[dayStr]; ok {
			return dow, true
		}
		return 0, false
	}
	// Numeric index path: only when day_of_week exists and is not a string.
	if raw, exists := row["day_of_week"]; exists {
		idx := int(extractFloat(raw))
		if idx >= 0 && idx <= 6 {
			return time.Weekday(idx), true
		}
	}
	return 0, false
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
