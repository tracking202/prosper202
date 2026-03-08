package forecast

import (
	"strconv"
	"strings"
	"time"
)

// BuildWeekdayWeights constructs SeasonalWeights from day-of-week report data.
//
// The input is a slice of maps, each expected to have:
//   - "day_of_week" (string): "Monday", "Tuesday", etc.
//   - a metric key (string): the numeric value for that day
//
// Weights are computed as (day_value / overall_mean). A day with exactly
// average performance gets weight 1.0. A day with 20% more gets 1.2.
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
		dayStr, ok := row["day_of_week"].(string)
		if !ok {
			continue
		}
		dow, ok := dayNames[dayStr]
		if !ok {
			continue
		}
		val := extractFloat(row[metric])
		if val == 0 {
			continue
		}
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
