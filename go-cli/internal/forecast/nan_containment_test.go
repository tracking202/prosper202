package forecast

import (
	"math"
	"testing"
	"time"
)

// buildFlatSeries returns n daily points around base, with a small deterministic
// wobble so the models have something to fit.
func buildFlatSeries(n int, base float64) Series {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	s := make(Series, 0, n)
	for i := 0; i < n; i++ {
		s = append(s, Point{
			T: start.AddDate(0, 0, i),
			V: base + float64(i%5),
		})
	}
	return s
}

func assertAllFinite(t *testing.T, preds []Prediction) {
	t.Helper()
	for i, p := range preds {
		if !isFinite(p.Value) || !isFinite(p.LowerBound) || !isFinite(p.UpperBound) {
			t.Fatalf("prediction %d is not finite: value=%v lower=%v upper=%v",
				i, p.Value, p.LowerBound, p.UpperBound)
		}
		for q, v := range p.Quantiles {
			if !isFinite(v) {
				t.Fatalf("prediction %d quantile %v is not finite: %v", i, q, v)
			}
		}
	}
}

// A negative seasonal multiplier drives v*w below -1, which is outside log1p's
// domain. Log1p returned NaN there, and the NaN propagated through the bounds,
// the quantiles and the ensemble weights until every prediction was NaN — which
// serializes straight into the JSON and CSV output. BuildWeekdayWeights is a
// legitimate producer of negative weights (it divides a possibly-negative day
// value by a positive mean), so this is reachable through the exported API.
func TestNegativeSeasonalWeightUnderLogTransformStaysFinite(t *testing.T) {
	weights := SeasonalWeights{}
	for d := time.Sunday; d <= time.Saturday; d++ {
		weights[d] = 1.1
	}
	weights[time.Monday] = -0.6

	res, err := Run(buildFlatSeries(60, 100), Config{
		Horizon:         7,
		Interval:        IntervalDay,
		NonNegative:     true,
		LogTransform:    true,
		SeasonalWeights: weights,
	})
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if len(res.Predictions) != 7 {
		t.Fatalf("got %d predictions, want 7", len(res.Predictions))
	}
	assertAllFinite(t, res.Predictions)
}

// applyProfile is the unit that used to produce the NaN. Every weight, including
// one steep enough to leave log1p's domain, must yield a finite model-scale
// value.
func TestApplyProfileStaysInLog1pDomain(t *testing.T) {
	for _, w := range []float64{-1000, -2, -1, -0.6, 0, 0.5, 2, 1000} {
		preds := []Prediction{{T: time.Now(), Value: math.Log1p(100)}}
		applyProfile(preds, func(time.Time) float64 { return w }, true)
		if !isFinite(preds[0].Value) {
			t.Fatalf("weight %v produced a non-finite model value: %v", w, preds[0].Value)
		}
	}
}

// NaN compares false against everything, so a single unmeasurable member used to
// pass both the best-RMSE and the pruning guard, and 1/(NaN+eps)^2 then poisoned
// every other member through normalizeWeights.
func TestNormalizeWeightsFallsBackWhenTheSumIsNotFinite(t *testing.T) {
	members := []Method{MethodLinear, MethodSMA}

	for name, poisoned := range map[string]float64{
		"NaN":  math.NaN(),
		"+Inf": math.Inf(1),
		"-Inf": math.Inf(-1),
	} {
		weights := map[Method]float64{MethodLinear: poisoned, MethodSMA: 1}
		normalizeWeights(weights, members)
		for _, m := range members {
			if !isFinite(weights[m]) {
				t.Fatalf("%s: member %s weight is not finite: %v", name, m, weights[m])
			}
		}
	}
}
