package forecast

import (
	"math"
	"math/rand"
	"testing"
	"time"
)

func TestShrinkMultiplier(t *testing.T) {
	tests := []struct {
		raw  float64
		n    int
		want float64
	}{
		{1.9, 2, 1.3},    // 1 + 0.9 * 2/6: two samples barely move the needle
		{1.9, 96, 1.864}, // 1 + 0.9 * 96/100: plenty of data keeps the signal
		{1.0, 0, 1.0},
		{0.4, 4, 0.7}, // 1 - 0.6 * 4/8
	}
	for _, tc := range tests {
		got := shrinkMultiplier(tc.raw, tc.n)
		if math.Abs(got-tc.want) > 0.001 {
			t.Errorf("shrinkMultiplier(%v, %d) = %.4f, want %.4f", tc.raw, tc.n, got, tc.want)
		}
	}
}

func TestShrinkWeekdayWeights(t *testing.T) {
	weights := SeasonalWeights{time.Monday: 1.9, time.Friday: 0.5}
	counts := map[time.Weekday]int{time.Monday: 2, time.Friday: 12}
	shrunk := ShrinkWeekdayWeights(weights, counts)
	if math.Abs(shrunk[time.Monday]-1.3) > 0.001 {
		t.Errorf("Monday shrunk to %.3f, want 1.3 (2 samples)", shrunk[time.Monday])
	}
	if math.Abs(shrunk[time.Friday]-0.625) > 0.001 {
		t.Errorf("Friday shrunk to %.3f, want 0.625 (12 samples)", shrunk[time.Friday])
	}
	// Input untouched.
	if weights[time.Monday] != 1.9 {
		t.Error("ShrinkWeekdayWeights modified its input")
	}
}

func TestWeekdayCounts(t *testing.T) {
	s := makeSeries(15, func(i int) float64 { return 1 }) // 2026-01-01 is a Thursday
	counts := WeekdayCounts(s)
	total := 0
	for _, n := range counts {
		total += n
	}
	if total != 15 {
		t.Errorf("counts sum to %d, want 15", total)
	}
	if counts[time.Thursday] != 3 { // Jan 1, 8, 15
		t.Errorf("Thursday count = %d, want 3", counts[time.Thursday])
	}
}

func TestBuildHourlyWeights(t *testing.T) {
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	s := make(Series, 0, 240)
	for i := 0; i < 240; i++ { // 10 days of hourly data
		t := base.Add(time.Duration(i) * time.Hour)
		v := 100.0
		if t.Hour() == 12 {
			v = 200 // lunchtime spike
		}
		s = append(s, Point{T: t, V: v})
	}
	weights := BuildHourlyWeights(s)
	if weights == nil {
		t.Fatal("expected non-nil hourly weights")
	}
	if weights[12] < 1.3 {
		t.Errorf("hour-12 weight = %.3f, want clearly above 1 (spike hour)", weights[12])
	}
	if weights[3] > 1.0 {
		t.Errorf("hour-3 weight = %.3f, want below 1", weights[3])
	}
}

func TestBuildMonthDayWeights(t *testing.T) {
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	s := make(Series, 0, 90)
	for i := 0; i < 90; i++ {
		t := base.AddDate(0, 0, i)
		v := 100.0
		if t.Day() == 1 {
			v = 300 // budget reset spike
		}
		s = append(s, Point{T: t, V: v})
	}
	weights := BuildMonthDayWeights(s)
	if weights == nil {
		t.Fatal("expected non-nil month-day weights")
	}
	if weights[1] < 1.5 {
		t.Errorf("day-1 weight = %.3f, want clearly above 1", weights[1])
	}
}

func TestSeasonalLagAutocorrelation(t *testing.T) {
	weekly := []float64{0.6, 1.1, 1.3, 1.2, 1.1, 1.0, 0.7}
	strong := makeSeries(56, func(i int) float64 { return 100 * weekly[i%7] })
	if ac, ok := seasonalLagAutocorrelation(strong, 7*24*time.Hour); !ok || ac < 0.8 {
		t.Errorf("weekly series lag-7 autocorr = %.3f, want > 0.8", ac)
	}

	rng := rand.New(rand.NewSource(71))
	flat := makeSeries(56, func(i int) float64 { return 100 + rng.NormFloat64()*10 })
	if ac, ok := seasonalLagAutocorrelation(flat, 7*24*time.Hour); !ok || math.Abs(ac) > 0.3 {
		t.Errorf("flat series lag-7 autocorr = %.3f, want near 0", ac)
	}

	// A strong trend must not fake weekly structure (detrended first).
	trend := makeSeries(56, func(i int) float64 { return 100 + 10*float64(i) })
	// (An exact line has no residual variance, so the gate reports "not
	// measurable" rather than a high correlation.)
	if ac, ok := seasonalLagAutocorrelation(trend, 7*24*time.Hour); ok && ac > 0.5 {
		t.Errorf("pure trend lag-7 autocorr = %.3f, want low after detrending", ac)
	}
}

func TestDetectLevelShift(t *testing.T) {
	rng := rand.New(rand.NewSource(73))
	shifted := makeSeries(60, func(i int) float64 {
		lvl := 100.0
		if i >= 30 {
			lvl = 170
		}
		return lvl + rng.NormFloat64()*8
	})
	ls := detectLevelShift(shifted)
	if ls == nil {
		t.Fatal("no shift detected on a 70-unit step")
	}
	if ls.idx < 25 || ls.idx > 35 {
		t.Fatalf("shift detected at index %d, want near 30", ls.idx)
	}
	if ls.delta < 30 {
		t.Errorf("shift delta = %.1f, want strongly positive", ls.delta)
	}

	rng2 := rand.New(rand.NewSource(74))
	flat := makeSeries(60, func(i int) float64 { return 100 + rng2.NormFloat64()*8 })
	if ls := detectLevelShift(flat); ls != nil {
		t.Errorf("false-positive shift at index %d on flat series", ls.idx)
	}

	// A steady trend is not a level shift.
	rng3 := rand.New(rand.NewSource(75))
	trend := makeSeries(60, func(i int) float64 { return 100 + 3*float64(i) + rng3.NormFloat64()*8 })
	if ls := detectLevelShift(trend); ls != nil {
		t.Errorf("false-positive shift at index %d on trending series", ls.idx)
	}
}

func TestRun_LevelShiftForecastsNewLevel(t *testing.T) {
	// Level shift halfway through: forecasts must land near the NEW level
	// (~170), not between the regimes (~135) as unweighted history implies.
	rng := rand.New(rand.NewSource(77))
	s := makeSeries(90, func(i int) float64 {
		lvl := 100.0
		if i >= 45 {
			lvl = 170
		}
		return lvl + rng.NormFloat64()*8
	})

	result, err := Run(s, Config{Method: MethodAuto, Horizon: 7, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.LevelShiftAt == "" {
		t.Error("expected level_shift_at to be recorded")
	}
	for i, p := range result.Predictions {
		if p.Value < 150 || p.Value > 190 {
			t.Errorf("prediction[%d] = %.1f, want near the new level (~170)", i, p.Value)
		}
	}
}

func TestRun_LevelShiftShortPostSegmentRelevels(t *testing.T) {
	// Shift near the end (8 post-shift points, below the 14-point truncation
	// bar): pre-shift history is re-leveled so forecasts still track the new
	// regime.
	rng := rand.New(rand.NewSource(79))
	s := makeSeries(50, func(i int) float64 {
		lvl := 100.0
		if i >= 42 {
			lvl = 180
		}
		return lvl + rng.NormFloat64()*6
	})

	result, err := Run(s, Config{Method: MethodAuto, Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.LevelShiftAt == "" {
		t.Error("expected level_shift_at to be recorded")
	}
	for i, p := range result.Predictions {
		if p.Value < 155 {
			t.Errorf("prediction[%d] = %.1f, want near the new level (~180), not dragged down by old history", i, p.Value)
		}
	}
}

func TestRun_NoLevelShiftFieldOnStableSeries(t *testing.T) {
	rng := rand.New(rand.NewSource(83))
	s := makeSeries(60, func(i int) float64 { return 100 + rng.NormFloat64()*10 })
	result, err := Run(s, Config{Method: MethodSMA, Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.LevelShiftAt != "" {
		t.Errorf("unexpected level_shift_at %q on stable series", result.LevelShiftAt)
	}
}

func TestRun_LogTransformTracksMultiplicativeGrowth(t *testing.T) {
	// Clean 3%-per-day growth: on log scale this is exactly linear, so the
	// transformed linear forecast continues the compounding — while the
	// untransformed fit undershoots badly at the far horizon.
	s := makeSeries(60, func(i int) float64 { return 50 * math.Pow(1.03, float64(i)) })
	want := 50 * math.Pow(1.03, 69) // 10 steps past the end

	logRes, err := Run(s, Config{Method: MethodLinear, Horizon: 10, Interval: IntervalDay,
		NonNegative: true, LogTransform: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	got := logRes.Predictions[9].Value
	if math.Abs(got-want)/want > 0.02 {
		t.Errorf("log-scale forecast = %.1f, want ~%.1f (exact compounding)", got, want)
	}

	rawRes, err := Run(s, Config{Method: MethodLinear, Horizon: 10, Interval: IntervalDay,
		NonNegative: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	rawGot := rawRes.Predictions[9].Value
	if math.Abs(rawGot-want)/want < math.Abs(got-want)/want {
		t.Errorf("expected log transform to beat raw fit: log err %.3f vs raw err %.3f",
			math.Abs(got-want)/want, math.Abs(rawGot-want)/want)
	}

	// Positive trend reported on the original scale.
	if logRes.Trend <= 0 {
		t.Errorf("trend = %.3f, want positive on growing series", logRes.Trend)
	}
	// Error metrics are on the original scale: a clean exponential fit on
	// log scale has tiny original-scale errors, far below the raw fit's.
	if logRes.RMSE > rawRes.RMSE {
		t.Errorf("log-fit RMSE %.3f should not exceed raw-fit RMSE %.3f", logRes.RMSE, rawRes.RMSE)
	}
}

func TestRun_LogTransformSkippedOnNegativeValues(t *testing.T) {
	s := makeSeries(30, func(i int) float64 { return float64(i) - 10 }) // crosses zero
	result, err := Run(s, Config{Method: MethodLinear, Horizon: 3, Interval: IntervalDay, LogTransform: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	// The untransformed linear fit continues the line into positive values.
	if math.Abs(result.Predictions[0].Value-20) > 0.5 {
		t.Errorf("prediction = %.2f, want ~20 (transform skipped, plain linear)", result.Predictions[0].Value)
	}
}

func TestRun_HourlyWeightsAppliedWithDailyPattern(t *testing.T) {
	// Hourly series with a strong repeating daily pattern: hour weights
	// pass the lag-24 gate and modulate the forecast.
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	s := make(Series, 0, 240)
	pattern := func(h int) float64 {
		if h == 12 {
			return 2.0
		}
		return 1.0
	}
	for i := 0; i < 240; i++ {
		ts := base.Add(time.Duration(i) * time.Hour)
		s = append(s, Point{T: ts, V: 100 * pattern(ts.Hour())})
	}
	weights := BuildHourlyWeights(s)

	result, err := Run(s, Config{
		Method:        MethodSMA,
		Horizon:       24,
		Interval:      IntervalHour,
		HourlyWeights: weights,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !result.SeasonalApplied {
		t.Fatal("expected hourly weights to be applied")
	}
	var noon, offPeak float64
	for _, p := range result.Predictions {
		if p.T.Hour() == 12 {
			noon = p.Value
		} else if p.T.Hour() == 3 {
			offPeak = p.Value
		}
	}
	if noon <= offPeak {
		t.Errorf("noon prediction %.1f not above off-peak %.1f despite daily pattern", noon, offPeak)
	}
}

func TestRun_MonthDayWeightsApplied(t *testing.T) {
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	s := make(Series, 90)
	for i := range s {
		ts := base.AddDate(0, 0, i)
		v := 100.0
		if ts.Day() == 1 {
			v = 300
		}
		s[i] = Point{T: ts, V: v}
	}
	weights := BuildMonthDayWeights(s)

	// Horizon long enough to cross the next month boundary (from Mar 31).
	result, err := Run(s, Config{
		Method:          MethodSMA,
		Horizon:         5,
		Interval:        IntervalDay,
		MonthDayWeights: weights,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !result.SeasonalApplied {
		t.Fatal("expected month-day weights to be applied")
	}
	var first, other float64
	for _, p := range result.Predictions {
		if p.T.Day() == 1 {
			first = p.Value
		} else if other == 0 {
			other = p.Value
		}
	}
	if first == 0 {
		t.Fatal("horizon did not cross a month boundary")
	}
	if first <= other {
		t.Errorf("day-1 prediction %.1f not above other days %.1f", first, other)
	}
}

func TestDetectLevelShift_IgnoresEndOfHistoryBurst(t *testing.T) {
	// A 2-day tracking outage (or promo spike) at the end of history is a
	// transient, not a regime change: the forecast must stay near the
	// established level and no level_shift_at may be reported.
	cases := []struct {
		name string
		tail float64
	}{
		{"outage", 0},
		{"promo_spike", 2000},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			rng := rand.New(rand.NewSource(1))
			s := makeSeries(60, func(i int) float64 {
				if i >= 58 {
					return tc.tail
				}
				return 500 + rng.NormFloat64()*20
			})
			if ls := detectLevelShift(s); ls != nil {
				t.Fatalf("burst misread as level shift at index %d", ls.idx)
			}
			// Through Run the burst is masked as a transient (not a shift),
			// so even the log-scale ensemble forecasts the established level
			// and predictions start after the burst, not inside it.
			for _, logT := range []bool{false, true} {
				in := make(Series, len(s))
				copy(in, s)
				r, err := Run(in, Config{Method: MethodAuto, Horizon: 3, Interval: IntervalDay, NonNegative: true, LogTransform: logT})
				if err != nil {
					t.Fatalf("unexpected error: %v", err)
				}
				if r.LevelShiftAt != "" {
					t.Errorf("logTransform=%v: level_shift_at = %q, want none", logT, r.LevelShiftAt)
				}
				if len(r.AnomaliesMasked) != 2 || r.DataPoints != 58 {
					t.Errorf("logTransform=%v: masked %v, data points %d; want the 2 burst days masked", logT, r.AnomaliesMasked, r.DataPoints)
				}
				if v := r.Predictions[0].Value; v < 400 || v > 620 {
					t.Errorf("logTransform=%v: prediction = %.1f, want near the 500 level", logT, v)
				}
				if want := s[len(s)-1].T.AddDate(0, 0, 1); !r.Predictions[0].T.Equal(want) {
					t.Errorf("logTransform=%v: first prediction at %v, want %v (after the masked tail)", logT, r.Predictions[0].T, want)
				}
			}
		})
	}
}

func TestRun_DisableLevelShift(t *testing.T) {
	rng := rand.New(rand.NewSource(77))
	s := makeSeries(90, func(i int) float64 {
		lvl := 100.0
		if i >= 45 {
			lvl = 170
		}
		return lvl + rng.NormFloat64()*8
	})
	r, err := Run(s, Config{Method: MethodLinear, Horizon: 3, Interval: IntervalDay, DisableLevelShift: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.LevelShiftAt != "" {
		t.Errorf("level shift handled despite DisableLevelShift: %q", r.LevelShiftAt)
	}
	if r.DataPoints != 90 {
		t.Errorf("data points = %d, want 90 (no truncation)", r.DataPoints)
	}
}

func TestRun_DataPointsReflectTruncation(t *testing.T) {
	rng := rand.New(rand.NewSource(77))
	s := makeSeries(90, func(i int) float64 {
		lvl := 100.0
		if i >= 45 {
			lvl = 170
		}
		return lvl + rng.NormFloat64()*8
	})
	r, err := Run(s, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.LevelShiftAt == "" {
		t.Fatal("expected a level shift")
	}
	if r.DataPoints >= 90 || r.DataPoints < 14 {
		t.Errorf("data points = %d, want the post-shift count actually fitted", r.DataPoints)
	}
}

func TestRelevelPrefix_NoLeakage(t *testing.T) {
	// The re-leveled prefix must be estimated from post-shift points inside
	// the prefix only: a prefix ending before the shift is unchanged, and a
	// prefix with one post-shift point uses just that point's jump.
	s := makeSeries(30, func(i int) float64 {
		if i >= 25 {
			return 300
		}
		return 100
	})
	ls := &levelShift{idx: 25, slope: 0, intercept: 100, base: s[0].T}
	pre := ls.relevelPrefix(s, 20)
	if pre[0].V != 100 {
		t.Errorf("prefix before the shift was re-leveled to %.1f", pre[0].V)
	}
	one := ls.relevelPrefix(s, 26)
	if math.Abs(one[0].V-300) > 1e-9 {
		t.Errorf("prefix with one post-shift point re-leveled to %.1f, want 300", one[0].V)
	}
	if s[0].V != 100 {
		t.Error("relevelPrefix modified its input")
	}
}

func TestRun_LogTransformKeepsMovingAverageTrend(t *testing.T) {
	// SMA's trend (avg − prevAvg) must survive the log transform as an
	// absolute per-period change on the original scale, not collapse to 0.
	s := makeSeries(40, func(i int) float64 { return 100 + 5*float64(i) })
	r, err := Run(s, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay, LogTransform: true, NonNegative: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.Trend < 3 || r.Trend > 7 {
		t.Errorf("SMA trend under log transform = %.3f, want ~5", r.Trend)
	}
}

func TestBuildSlotWeights_SignedMetricGetsNoProfile(t *testing.T) {
	rng := rand.New(rand.NewSource(2))
	profit := makeSeries(60, func(i int) float64 { return rng.NormFloat64() * 50 })
	if w := BuildMonthDayWeights(profit); w != nil {
		t.Errorf("expected no month-day profile for a series with non-positive mean, got %v", w)
	}
	// Even a positive-mean series gets no profile once any value is
	// negative: the metric is signed and the multipliers would be too.
	mixed := makeSeries(60, func(i int) float64 {
		if i%30 == 0 {
			return -50
		}
		return 100
	})
	if w := BuildMonthDayWeights(mixed); w != nil {
		t.Errorf("expected no profile for a series with negative values, got %v", w)
	}
}

func TestSeasonalGateAllowsShortHistory(t *testing.T) {
	// Too short to measure the weekly lag: no evidence against the profile,
	// so supplied weights apply.
	s := makeSeries(7, func(i int) float64 { return 100 })
	if !seasonalGateAllows(s, 7*24*time.Hour) {
		t.Error("gate rejected weights on a history too short to measure")
	}
}

func TestAnchorOffset_CalendarMonths(t *testing.T) {
	base := time.Date(2025, 11, 1, 0, 0, 0, 0, time.UTC)
	s := Series{}
	for i := 0; i < 4; i++ { // Nov, Dec, Jan, Feb
		s = append(s, Point{T: base.AddDate(0, i, 0), V: 100})
	}
	// Feb 1 -> Mar 1 is 28 days: exactly one month step, not 0.
	if got := anchorOffset(s, Config{Interval: IntervalMonth, Anchor: base.AddDate(0, 4, 0)}); got != 1 {
		t.Errorf("month offset = %d, want 1", got)
	}
	if got := anchorOffset(s, Config{Interval: IntervalMonth, Anchor: base.AddDate(0, 5, 0)}); got != 2 {
		t.Errorf("two-month offset = %d, want 2", got)
	}
}

func TestDetectTransients_RespectsSeriesOwnPattern(t *testing.T) {
	// Closed on Sundays: zeros every week are this series' normal and must
	// not be masked.
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	closedSunday := makeSeries(56, func(i int) float64 {
		if base.AddDate(0, 0, i).Weekday() == time.Sunday {
			return 0
		}
		return 200
	})
	if idx := detectTransients(closedSunday, IntervalDay, false, 0, 0); len(idx) != 0 {
		t.Errorf("weekly closures masked as anomalies: %v", idx)
	}

	// Low-volume tracker: zeros are within its normal spread.
	rng := rand.New(rand.NewSource(5))
	low := makeSeries(60, func(i int) float64 { return math.Floor(rng.ExpFloat64() * 3) })
	if idx := detectTransients(low, IntervalDay, false, 0, 0); len(idx) != 0 {
		t.Errorf("low-volume zeros masked as anomalies: %v", idx)
	}

	// A zero Tuesday in a 500-a-day series is a transient; so is a spike.
	rng2 := rand.New(rand.NewSource(6))
	outage := makeSeries(60, func(i int) float64 {
		switch i {
		case 30:
			return 0
		case 45:
			return 3000
		}
		return 500 + rng2.NormFloat64()*20
	})
	idx := detectTransients(outage, IntervalDay, false, 0, 0)
	if len(idx) != 2 || idx[0] != 30 || idx[1] != 45 {
		t.Errorf("transients = %v, want [30 45]", idx)
	}

	// A run as long as a regime change is left to the level-shift detector.
	rng3 := rand.New(rand.NewSource(7))
	paused := makeSeries(60, func(i int) float64 {
		if i >= 54 {
			return 0
		}
		return 500 + rng3.NormFloat64()*20
	})
	if idx := detectTransients(paused, IntervalDay, false, 0, 0); len(idx) != 0 {
		t.Errorf("6-day run masked as transient: %v", idx)
	}
}

func TestRun_DisableAnomalyMask(t *testing.T) {
	rng := rand.New(rand.NewSource(1))
	s := makeSeries(60, func(i int) float64 {
		if i >= 58 {
			return 0
		}
		return 500 + rng.NormFloat64()*20
	})
	r, err := Run(s, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay, NonNegative: true, DisableAnomalyMask: true})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.AnomaliesMasked) != 0 || r.DataPoints != 60 {
		t.Errorf("masking ran despite DisableAnomalyMask: %v, %d points", r.AnomaliesMasked, r.DataPoints)
	}
	// Signed metrics are never masked.
	profit := makeSeries(60, func(i int) float64 {
		if i == 30 {
			return -900
		}
		return 100 + rng.NormFloat64()*10
	})
	r, err = Run(profit, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.AnomaliesMasked) != 0 {
		t.Errorf("signed metric masked: %v", r.AnomaliesMasked)
	}
}

func TestDetectTransients_ThresholdIsTunable(t *testing.T) {
	// A 60% dip on a tight series clears the magnitude floor (at least
	// halved) and sits far beyond 5 robust sigmas: masked at the default,
	// left alone once the threshold is raised past it.
	rng := rand.New(rand.NewSource(8))
	s := makeSeries(60, func(i int) float64 {
		if i == 30 {
			return 200
		}
		return 500 + rng.NormFloat64()*10
	})
	if idx := detectTransients(s, IntervalDay, false, 0, 0); len(idx) != 1 {
		t.Errorf("default sigma: transients = %v, want [30]", idx)
	}
	if idx := detectTransients(s, IntervalDay, false, 1000, 0); len(idx) != 0 {
		t.Errorf("sigma 1000: transients = %v, want none", idx)
	}
	// A 15% dip never counts, however tight the noise.
	mild := makeSeries(60, func(i int) float64 {
		if i == 30 {
			return 425
		}
		return 500 + rng.NormFloat64()*2
	})
	if idx := detectTransients(mild, IntervalDay, false, 0, 0); len(idx) != 0 {
		t.Errorf("15%% dip masked: %v", idx)
	}
	// Config threads the knobs through Run.
	r, err := Run(s, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay, NonNegative: true, AnomalySigma: 1000})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.AnomaliesMasked) != 0 {
		t.Errorf("AnomalySigma 1000 still masked %v", r.AnomaliesMasked)
	}
	r, err = Run(s, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay, NonNegative: true, AnomalyCycles: 2})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.AnomaliesMasked) != 1 {
		t.Errorf("AnomalyCycles 2 masked %v, want the one dip", r.AnomaliesMasked)
	}
}
