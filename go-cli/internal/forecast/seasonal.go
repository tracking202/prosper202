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

// seasonalShrinkK is the shrinkage constant for seasonal multipliers: a
// profile slot with n samples keeps n/(n+k) of its deviation from 1.0, so
// two Mondays of data no longer produce a 1.9x Monday multiplier.
const seasonalShrinkK = 4.0

// shrinkMultiplier pulls a raw multiplier toward 1.0 by n/(n+k).
func shrinkMultiplier(raw float64, n int) float64 {
	factor := float64(n) / (float64(n) + seasonalShrinkK)
	return 1 + (raw-1)*factor
}

// buildWeekdayWeights derives weekday multipliers from a series: each
// weekday's mean over the overall mean, shrunk toward 1.0 by sample count
// (see buildSlotWeights). Being a function of the training data alone, it
// is re-estimated inside every backtest fold (see profileFor), which the
// report-derived BuildWeekdayWeights cannot be.
func buildWeekdayWeights(s Series) SeasonalWeights {
	slots := buildSlotWeights(s, func(t time.Time) int { return int(t.Weekday()) })
	if slots == nil {
		return nil
	}
	out := make(SeasonalWeights, len(slots))
	for k, w := range slots {
		out[time.Weekday(k)] = w
	}
	return out
}

// profileFor builds the seasonal multiplier the forecaster applies when it
// is fitted on the training view train (a backtest prefix, or the deployed
// fit's full history), from that data alone, and names the profiles that
// apply. Every data-derived profile and every gate is re-estimated per
// training view, so a backtest fold never scales a held-out point by a
// multiplier that already contains it, and bands and errors describe the
// profiled forecaster honestly. Explicit SeasonalWeights are applied as
// supplied (after the weekly gate). Values fitted under log1p are inverted
// first: profiles are multiplicative on the reporting scale. Returns nil
// when nothing applies.
func profileFor(train Series, cfg Config) (func(time.Time) float64, []string) {
	prefix := train
	if cfg.LogTransform {
		prefix = expm1Series(prefix)
	}
	var names []string
	var scalers []func(time.Time) float64

	weekday := cfg.SeasonalWeights
	if len(weekday) == 0 && cfg.WeekdayProfile {
		weekday = buildWeekdayWeights(prefix)
	}
	if len(weekday) > 0 && seasonalGateAllows(prefix, 7*24*time.Hour) {
		w := weekday
		scalers = append(scalers, func(t time.Time) float64 { return weekdayWeight(w, t.Weekday()) })
		names = append(names, "weekday")
	}
	if cfg.HourlyProfile && cfg.Interval == IntervalHour && seasonalGateAllows(prefix, 24*time.Hour) {
		if hourly := BuildHourlyWeights(prefix); len(hourly) > 0 {
			scalers = append(scalers, func(t time.Time) float64 { return slotWeight(hourly, t.Hour()) })
			names = append(names, "hourly")
		}
	}
	if cfg.MonthDayProfile {
		if monthDay := BuildMonthDayWeights(prefix); len(monthDay) > 0 {
			scalers = append(scalers, func(t time.Time) float64 { return slotWeight(monthDay, t.Day()) })
			names = append(names, "monthday")
		}
	}
	if len(scalers) == 0 {
		return nil, nil
	}
	return func(t time.Time) float64 {
		f := 1.0
		for _, scale := range scalers {
			f *= scale(t)
		}
		return f
	}, names
}

// weekdayWeight returns the multiplier a weekday profile defines for d, or
// 1 when the day is absent.
func weekdayWeight(weights SeasonalWeights, d time.Weekday) float64 {
	if w, ok := weights[d]; ok {
		return w
	}
	return 1
}

// slotWeight returns the multiplier an integer-slot profile defines for
// slot, or 1 when the slot is absent.
func slotWeight(weights map[int]float64, slot int) float64 {
	if w, ok := weights[slot]; ok {
		return w
	}
	return 1
}

// BuildHourlyWeights derives hour-of-day multipliers from an hourly series:
// each hour's mean over the overall mean, shrunk toward 1.0 by sample count.
// Returns nil when the series has negative values or no positive mean
// (signed metrics get no profile) or covers fewer than two distinct hours.
func BuildHourlyWeights(s Series) map[int]float64 {
	return buildSlotWeights(s, func(t time.Time) int { return t.Hour() })
}

// BuildMonthDayWeights derives day-of-month multipliers (1–31) from a daily
// series — affiliate budgets and payouts often reset monthly. Same
// multiplier-table pattern and shrinkage as the other profiles.
func BuildMonthDayWeights(s Series) map[int]float64 {
	return buildSlotWeights(s, func(t time.Time) int { return t.Day() })
}

// buildSlotWeights groups observations into integer slots, computes each
// slot's mean over the overall mean, and shrinks by sample count.
func buildSlotWeights(s Series, slot func(time.Time) int) map[int]float64 {
	if len(s) == 0 {
		return nil
	}
	sums := map[int]float64{}
	counts := map[int]int{}
	total := 0.0
	for _, p := range s {
		if p.V < 0 {
			// Multiplicative profiles only make sense for non-negative
			// series: a signed metric hovering near zero would produce
			// negative or unbounded multipliers.
			return nil
		}
		k := slot(p.T)
		sums[k] += p.V
		counts[k]++
		total += p.V
	}
	if len(counts) < 2 {
		return nil
	}
	mean := total / float64(len(s))
	if mean <= 0 {
		return nil
	}
	weights := make(map[int]float64, len(sums))
	for k, sum := range sums {
		raw := (sum / float64(counts[k])) / mean
		weights[k] = shrinkMultiplier(raw, counts[k])
	}
	return weights
}

// seasonalGateThreshold is the minimum detrended autocorrelation at the
// seasonal lag for profile weights to be applied; below it the apparent
// seasonality is noise and adjusting would only add error.
const seasonalGateThreshold = 0.3

// seasonalLagAutocorrelation measures autocorrelation at a calendar lag on
// linearly detrended values: observations exactly `lag` apart are paired by
// timestamp (so masked-day gaps don't misalign pairs), and the linear trend
// is removed first so a trending series doesn't fake seasonal structure.
// ok is false when the history is too short to measure the lag (fewer than
// 8 points or 4 pairs) or has no variance.
func seasonalLagAutocorrelation(s Series, lag time.Duration) (ac float64, ok bool) {
	if len(s) < 8 {
		return 0, false
	}

	slope, intercept := olsFit(s)
	base := s[0].T
	resid := make(map[time.Time]float64, len(s))
	variance := 0.0
	for _, p := range s {
		e := p.V - (intercept + slope*p.T.Sub(base).Hours())
		resid[p.T] = e
		variance += e * e
	}
	variance /= float64(len(s))
	if variance <= 0 {
		return 0, false
	}

	cov := 0.0
	pairs := 0
	for _, p := range s {
		if other, ok := resid[p.T.Add(lag)]; ok {
			cov += resid[p.T] * other
			pairs++
		}
	}
	if pairs < 4 {
		return 0, false
	}
	return (cov / float64(pairs)) / variance, true
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
