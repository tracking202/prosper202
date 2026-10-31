package forecast

import (
	"math"
	"math/rand"
	"testing"
	"time"
)

func TestQuantileSorted(t *testing.T) {
	tests := []struct {
		name   string
		sorted []float64
		q      float64
		want   float64
	}{
		{"empty", nil, 0.5, 0},
		{"single", []float64{7}, 0.1, 7},
		{"median_even", []float64{1, 2, 3, 4}, 0.5, 2.5},
		{"median_odd", []float64{1, 2, 3}, 0.5, 2},
		{"p10_interp", []float64{0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100}, 0.10, 10},
		{"min", []float64{1, 2, 3}, 0.0, 1},
		{"max", []float64{1, 2, 3}, 1.0, 3},
	}
	for _, tc := range tests {
		got := quantileSorted(tc.sorted, tc.q)
		if math.Abs(got-tc.want) > 1e-9 {
			t.Errorf("%s: quantileSorted(%v, %.2f) = %v, want %v", tc.name, tc.sorted, tc.q, got, tc.want)
		}
	}
}

func TestBoundPair(t *testing.T) {
	tests := []struct {
		conf         float64
		lower, upper string
	}{
		{0.99, "p05", "p95"},
		{0.95, "p05", "p95"},
		{0.90, "p05", "p95"},
		{0.85, "p05", "p95"},
		{0.80, "p10", "p90"},
		{0.65, "p10", "p90"},
		{0.50, "p25", "p75"},
		{0.60, "p25", "p75"},
	}
	for _, tc := range tests {
		lo, hi := boundPair(tc.conf)
		if lo != tc.lower || hi != tc.upper {
			t.Errorf("boundPair(%.2f) = %s/%s, want %s/%s", tc.conf, lo, hi, tc.lower, tc.upper)
		}
	}
}

func TestStepQuantiles_MergesThinBuckets(t *testing.T) {
	// Step 1 has plenty of residuals; step 2 has only two extreme ones.
	// Step 2's quantiles must be computed from the merged pool, not from
	// the two extremes alone.
	byStep := map[int][]float64{
		1: {-4, -3, -2, -1, 0, 1, 2, 3, 4, 5},
		2: {-100, 100},
	}
	sq := stepQuantiles(byStep, 2)
	if sq == nil || sq[1] == nil || sq[2] == nil {
		t.Fatal("expected quantiles for both steps")
	}
	// From the two extremes alone, p25 would be -50; the merged pool keeps
	// it near the dense bucket's range.
	if sq[2]["p25"] < -50 || sq[2]["p75"] > 50 {
		t.Errorf("step-2 quantiles not merged with adjacent bucket: p25=%v p75=%v",
			sq[2]["p25"], sq[2]["p75"])
	}
}

func TestStepQuantiles_MonotoneWidening(t *testing.T) {
	// Step 2's residuals are (spuriously) tighter than step 1's; the emitted
	// intervals must not narrow with horizon.
	byStep := map[int][]float64{
		1: {-20, -15, -10, -5, 0, 5, 10, 15, 20, 25},
		2: {-2, -1.5, -1, -0.5, 0, 0.5, 1, 1.5, 2, 2.5},
	}
	sq := stepQuantiles(byStep, 3)
	for h := 2; h <= 3; h++ {
		if sq[h]["p10"] > sq[h-1]["p10"] {
			t.Errorf("step %d p10 (%v) narrower than step %d (%v)", h, sq[h]["p10"], h-1, sq[h-1]["p10"])
		}
		if sq[h]["p90"] < sq[h-1]["p90"] {
			t.Errorf("step %d p90 (%v) narrower than step %d (%v)", h, sq[h]["p90"], h-1, sq[h-1]["p90"])
		}
	}
}

func TestStepQuantiles_ExtendsBeyondObserved(t *testing.T) {
	byStep := map[int][]float64{
		1: {-3, -2, -1, 0, 1, 2, 3, 4},
	}
	sq := stepQuantiles(byStep, 5)
	if sq[5] == nil {
		t.Fatal("expected quantiles for steps beyond the observed range")
	}
	if sq[5]["p10"] != sq[1]["p10"] || sq[5]["p90"] != sq[1]["p90"] {
		t.Errorf("far-step quantiles should reuse the farthest observed pool")
	}
}

func TestRun_ConformalQuantilesOrdered(t *testing.T) {
	rng := rand.New(rand.NewSource(7))
	s := makeSeries(60, func(i int) float64 { return 100 + 2*float64(i) + rng.NormFloat64()*10 })

	result, err := Run(s, Config{
		Method:          MethodLinear,
		Horizon:         7,
		Interval:        IntervalDay,
		ConfidenceLevel: 0.80,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	order := []string{"p05", "p10", "p25", "p50", "p75", "p90", "p95"}
	for i, p := range result.Predictions {
		if len(p.Quantiles) == 0 {
			t.Fatalf("prediction[%d] missing quantiles", i)
		}
		for j := 1; j < len(order); j++ {
			if p.Quantiles[order[j-1]] > p.Quantiles[order[j]] {
				t.Errorf("prediction[%d]: %s (%v) > %s (%v)", i,
					order[j-1], p.Quantiles[order[j-1]], order[j], p.Quantiles[order[j]])
			}
		}
		if p.LowerBound != p.Quantiles["p10"] {
			t.Errorf("prediction[%d]: LowerBound %v != p10 %v at 0.80 confidence", i, p.LowerBound, p.Quantiles["p10"])
		}
		if p.UpperBound != p.Quantiles["p90"] {
			t.Errorf("prediction[%d]: UpperBound %v != p90 %v at 0.80 confidence", i, p.UpperBound, p.Quantiles["p90"])
		}
	}
}

func TestRun_ConfidenceMapsToQuantilePair(t *testing.T) {
	rng := rand.New(rand.NewSource(9))
	s := makeSeries(60, func(i int) float64 { return 100 + rng.NormFloat64()*10 })

	result, err := Run(s, Config{
		Method:          MethodSMA,
		Horizon:         5,
		Interval:        IntervalDay,
		ConfidenceLevel: 0.50,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for i, p := range result.Predictions {
		if p.LowerBound != p.Quantiles["p25"] || p.UpperBound != p.Quantiles["p75"] {
			t.Errorf("prediction[%d]: bounds %v/%v, want p25/p75 %v/%v at 0.50 confidence",
				i, p.LowerBound, p.UpperBound, p.Quantiles["p25"], p.Quantiles["p75"])
		}
	}
}

// conformalCoverage measures P10–P90 band coverage (and per-tail miss rates)
// on held-out data via an outer rolling evaluation of Run itself.
func conformalCoverage(t *testing.T, s Series, method Method) (coverage, lowMiss, highMiss float64) {
	t.Helper()
	covered, low, high, n := 0, 0, 0, 0
	for c := 20; c < len(s); c += 2 {
		steps := len(s) - c
		if steps > 7 {
			steps = 7
		}
		train := make(Series, c)
		copy(train, s[:c])
		res, err := Run(train, Config{
			Method:          method,
			Horizon:         steps,
			Interval:        IntervalDay,
			ConfidenceLevel: 0.80,
		})
		if err != nil {
			t.Fatalf("Run failed at cut %d: %v", c, err)
		}
		for i := 0; i < steps && i < len(res.Predictions); i++ {
			actual := s[c+i].V
			p := res.Predictions[i]
			switch {
			case actual < p.LowerBound:
				low++
			case actual > p.UpperBound:
				high++
			default:
				covered++
			}
			n++
		}
	}
	if n == 0 {
		t.Fatal("no held-out evaluations")
	}
	fn := float64(n)
	return float64(covered) / fn, float64(low) / fn, float64(high) / fn
}

func TestRun_ConformalCoverage(t *testing.T) {
	// Empirical coverage of the P10–P90 band must be 80% ± 10 points on
	// uniform, Gaussian, and skewed noise. The old Gaussian bounds cannot
	// meet the per-tail balance on skewed noise; conformal bounds can.
	cases := []struct {
		name  string
		noise func(rng *rand.Rand) float64
	}{
		{"uniform", func(rng *rand.Rand) float64 { return (rng.Float64() - 0.5) * 40 }},
		{"gaussian", func(rng *rand.Rand) float64 { return rng.NormFloat64() * 12 }},
		{"skewed", func(rng *rand.Rand) float64 { return (rng.ExpFloat64() - 1) * 15 }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			rng := rand.New(rand.NewSource(101))
			s := makeSeries(100, func(i int) float64 { return 100 + tc.noise(rng) })
			cov, low, high := conformalCoverage(t, s, MethodSMA)
			if cov < 0.70 || cov > 0.90 {
				t.Errorf("%s: coverage = %.1f%%, want 80%% ± 10", tc.name, cov*100)
			}
			// Each tail should miss roughly 10%; require both tails to be
			// live (symmetric Gaussian bounds on skewed noise park one tail
			// near zero).
			if low < 0.02 || low > 0.20 {
				t.Errorf("%s: lower-tail miss = %.1f%%, want ~10%% (2–20)", tc.name, low*100)
			}
			if high < 0.02 || high > 0.20 {
				t.Errorf("%s: upper-tail miss = %.1f%%, want ~10%% (2–20)", tc.name, high*100)
			}
		})
	}
}

func TestRun_NonNegativeClipsBounds(t *testing.T) {
	// Steeply declining count series: unclipped forecasts and lower bounds
	// go negative.
	rng := rand.New(rand.NewSource(5))
	s := makeSeries(40, func(i int) float64 {
		v := 200 - 6*float64(i) + rng.NormFloat64()*10
		if v < 0 {
			v = 0
		}
		return v
	})

	result, err := Run(s, Config{
		Method:      MethodLinear,
		Horizon:     14,
		Interval:    IntervalDay,
		NonNegative: true,
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for i, p := range result.Predictions {
		if p.Value < 0 || p.LowerBound < 0 || p.UpperBound < 0 {
			t.Errorf("prediction[%d]: negative output value=%v lower=%v upper=%v", i, p.Value, p.LowerBound, p.UpperBound)
		}
		for name, v := range p.Quantiles {
			if v < 0 {
				t.Errorf("prediction[%d]: negative quantile %s=%v", i, name, v)
			}
		}
	}
}

func TestRun_ShortSeriesGaussianFallback(t *testing.T) {
	// Too short for any rolling cut: bounds still present (Gaussian
	// fallback), quantiles absent.
	s := makeSeries(6, func(i int) float64 { return 10 + float64(i) })
	result, err := Run(s, Config{Method: MethodLinear, Horizon: 3, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for i, p := range result.Predictions {
		if p.LowerBound > p.Value || p.UpperBound < p.Value {
			t.Errorf("prediction[%d]: fallback bounds not around value", i)
		}
		if len(p.Quantiles) != 0 {
			t.Errorf("prediction[%d]: expected no quantiles for short series", i)
		}
	}
}

func TestRun_AutoSelectionDeterministic(t *testing.T) {
	rng := rand.New(rand.NewSource(31))
	s := makeSeries(50, func(i int) float64 { return 80 + 1.5*float64(i) + rng.NormFloat64()*20 })

	var first Method
	for run := 0; run < 3; run++ {
		in := make(Series, len(s))
		copy(in, s)
		result, err := Run(in, Config{Method: MethodAuto, Horizon: 5, Interval: IntervalDay})
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		if run == 0 {
			first = result.Method
		} else if result.Method != first {
			t.Fatalf("auto selection flipped: run 0 chose %s, run %d chose %s", first, run, result.Method)
		}
	}
}

func TestRun_RollingBacktestReportsErrors(t *testing.T) {
	rng := rand.New(rand.NewSource(17))
	s := makeSeries(60, func(i int) float64 { return 100 + 2*float64(i) + rng.NormFloat64()*8 })

	result, err := Run(s, Config{Method: MethodLinear, Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if result.MAE <= 0 || result.RMSE <= 0 {
		t.Errorf("expected positive rolling-backtest MAE/RMSE, got %v/%v", result.MAE, result.RMSE)
	}
	if result.RMSE < result.MAE {
		t.Errorf("RMSE (%v) should be >= MAE (%v)", result.RMSE, result.MAE)
	}
}

func TestScalePrediction_NegativeMultiplier(t *testing.T) {
	p := Prediction{
		Value:      100,
		LowerBound: 80,
		UpperBound: 120,
		Quantiles: map[string]float64{
			"p05": 75, "p10": 80, "p25": 90, "p50": 100, "p75": 110, "p90": 120, "p95": 125,
		},
	}
	scalePrediction(&p, -1)
	if p.LowerBound > p.UpperBound {
		t.Errorf("bounds inverted after negative scale: %v > %v", p.LowerBound, p.UpperBound)
	}
	order := []string{"p05", "p10", "p25", "p50", "p75", "p90", "p95"}
	for j := 1; j < len(order); j++ {
		if p.Quantiles[order[j-1]] > p.Quantiles[order[j]] {
			t.Errorf("quantiles out of order after negative scale: %s > %s", order[j-1], order[j])
		}
	}
	if p.Quantiles["p05"] != -125 || p.Quantiles["p10"] != -120 || p.Quantiles["p90"] != -80 {
		t.Errorf("unexpected quantiles after scale: %v", p.Quantiles)
	}
}

func TestRun_MaskedGapResidualAlignment(t *testing.T) {
	// A series with a mid-history gap (as left by event masking): the rolling
	// backtest must pair residuals by calendar step, not slice index, so
	// conformal bounds stay tight on an exactly-linear gapped series.
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	var s Series
	for day := 0; day < 50; day++ {
		if day >= 20 && day < 27 {
			continue
		}
		s = append(s, Point{T: base.AddDate(0, 0, day), V: 10 + 5*float64(day)})
	}
	result, err := Run(s, Config{Method: MethodLinear, Horizon: 3, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	// A perfect linear series has ~zero residuals everywhere; misaligned
	// pairing would show phantom errors of 5 units per skipped day.
	if result.MAE > 0.01 {
		t.Errorf("MAE = %v on exact linear gapped series, want ~0 (residual misalignment)", result.MAE)
	}
	for i, p := range result.Predictions {
		if p.UpperBound-p.LowerBound > 1 {
			t.Errorf("prediction[%d]: wide bounds (%v..%v) on exact series", i, p.LowerBound, p.UpperBound)
		}
	}
}

func TestRun_ConfidenceWidensBand(t *testing.T) {
	// Higher confidence must widen the conformal band: 0.50 -> P25/P75,
	// 0.80 -> P10/P90, 0.95 -> P05/P95 (the widest supported band).
	rng := rand.New(rand.NewSource(9))
	s := makeSeries(80, func(i int) float64 { return 100 + rng.NormFloat64()*10 })
	widths := map[float64]float64{}
	for _, c := range []float64{0.50, 0.80, 0.95} {
		in := make(Series, len(s))
		copy(in, s)
		r, err := Run(in, Config{Method: MethodSMA, Horizon: 3, Interval: IntervalDay, ConfidenceLevel: c})
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		widths[c] = r.Predictions[0].UpperBound - r.Predictions[0].LowerBound
		if r.BoundsSource != BoundsConformal {
			t.Errorf("bounds source = %q, want conformal", r.BoundsSource)
		}
	}
	if !(widths[0.50] < widths[0.80] && widths[0.80] < widths[0.95]) {
		t.Errorf("band widths not increasing with confidence: %v", widths)
	}
}

func TestNonNegative_FoldsScoreTheClippedForecast(t *testing.T) {
	// A series that declines linearly and then sits at zero: a linear fold
	// projects below zero across the tail, but the emitted forecast is
	// floored at zero. The rolling errors must describe that floored
	// forecaster, so they are far smaller than the raw model's.
	s := makeSeries(40, func(i int) float64 {
		if v := 100 - 5*float64(i); v > 0 {
			return v
		}
		return 0
	})
	cfg := Config{Method: MethodLinear, Horizon: 5, Interval: IntervalDay}
	raw, err := Run(s, cfg)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	cfg.NonNegative = true
	clipped, err := Run(s, cfg)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !clipped.Backtested() || !raw.Backtested() {
		t.Fatal("both runs should be backtested")
	}
	t.Logf("rolling mae: raw %.2f, clipped %.2f", raw.MAE, clipped.MAE)
	if clipped.MAE >= raw.MAE {
		t.Errorf("clipped forecaster mae %.2f should be below the raw model's %.2f", clipped.MAE, raw.MAE)
	}
	for i, p := range clipped.Predictions {
		if p.Value != 0 || p.LowerBound != 0 {
			t.Errorf("step %d: value %.2f lower %.2f, want 0 for a zero tail", i, p.Value, p.LowerBound)
		}
	}
	for _, v := range clipped.rolling {
		if v < 0 {
			t.Fatalf("rolling prediction %.2f below zero for a non-negative metric", v)
		}
	}
}
