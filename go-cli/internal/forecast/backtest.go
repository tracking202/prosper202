package forecast

import (
	"fmt"
	"math"
	"sort"
	"time"
)

// Rolling-origin backtesting and conformal prediction bounds.
//
// Instead of a single train/test split, the series is re-fit at many cut
// points stepping back from the end. Residuals (actual − predicted) are
// collected per horizon step h, giving an empirical error distribution for
// "h steps out" that makes no symmetry or normality assumptions. Prediction
// bounds are then the empirical quantiles of those residuals added to the
// point forecast (split conformal prediction).

const (
	// minTrainPoints is the smallest training prefix a rolling cut may use.
	minTrainPoints = 8
	// maxRefits caps the number of rolling-origin refits per method.
	maxRefits = 50
	// minBucketSamples is the target residual count per horizon-step bucket;
	// thinner buckets are merged with adjacent steps until they reach it.
	minBucketSamples = 8
	// minTotalResiduals is the smallest residual pool conformal bounds are
	// computed from; below it Run falls back to Gaussian bounds.
	minTotalResiduals = 8
)

// quantileLevels are the emitted quantiles, ascending. LowerBound/UpperBound
// map to the pair nearest the configured confidence level (see boundPair).
var quantileLevels = []struct {
	name string
	q    float64
}{
	{"p10", 0.10},
	{"p25", 0.25},
	{"p50", 0.50},
	{"p75", 0.75},
	{"p90", 0.90},
}

// methodForecast dispatches to the forecaster for m. Forecasters return raw
// point predictions; bounds are attached afterwards by the caller.
func methodForecast(m Method, s Series, cfg Config) ([]Prediction, float64, error) {
	switch m {
	case MethodLinear:
		return linearForecast(s, cfg)
	case MethodSMA:
		return smaForecast(s, cfg)
	case MethodWMA:
		return wmaForecast(s, cfg)
	case MethodHoltWinters:
		return holtWintersForecast(s, cfg)
	default:
		return nil, 0, fmt.Errorf("unknown method %q", m)
	}
}

// evalRow is one (cut point, horizon step) observation from the rolling
// backtest: the held-out actual and each method's prediction for it.
type evalRow struct {
	cut    int // training prefix length c the prediction was made from
	step   int // 1-based horizon step
	actual float64
	preds  map[Method]float64
}

// rollingEval aggregates rolling-origin backtest results for one or more
// methods evaluated on identical cuts.
type rollingEval struct {
	rows []evalRow
	// invert maps stored values back to the original scale for reported
	// error statistics when the series was fitted under a transform (log1p).
	// Conformal residuals deliberately stay on the model scale — quantiles
	// are computed there and inverted with the predictions.
	invert func(float64) float64
}

// errDiff returns actual − predicted on the reporting scale.
func (e *rollingEval) errDiff(actual, pred float64) float64 {
	if e.invert != nil {
		return e.invert(actual) - e.invert(pred)
	}
	return actual - pred
}

// runRollingBacktest refits each method on training prefixes s[:c] for cut
// points c stepping back one point at a time from the end of the series
// (capped at maxRefits cuts, so residuals reflect the most recent regime),
// and records held-out residuals bucketed by horizon step. Test points are
// matched to prediction steps by calendar distance from the training end, so
// masked-day gaps in the series don't misalign residuals.
func runRollingBacktest(s Series, cfg Config, methods []Method) *rollingEval {
	nCuts := len(s) - minTrainPoints
	if nCuts <= 0 {
		return &rollingEval{}
	}
	lowestCut := minTrainPoints
	if nCuts > maxRefits {
		lowestCut = len(s) - maxRefits
	}

	horizon := cfg.Horizon
	if horizon < 1 {
		horizon = 1
	}

	eval := &rollingEval{}
	if cfg.LogTransform {
		eval.invert = math.Expm1
	}
	for c := len(s) - 1; c >= lowestCut; c-- {
		steps := len(s) - c
		if steps > horizon {
			steps = horizon
		}

		testCfg := cfg
		testCfg.Horizon = steps
		testCfg.SeasonalWeights = nil
		testCfg.Anchor = time.Time{}

		train := s[:c]
		trainEnd := train[len(train)-1].T

		// Map each held-out point to its horizon step by calendar distance.
		// Rows are created lazily so methods share the same row set.
		rowIdx := map[int]int{} // step -> index into eval.rows (this cut only)
		stepFor := func(t time.Time) (int, bool) {
			exact := intervalSteps(trainEnd, t, cfg.Interval)
			h := int(math.Round(exact))
			if math.Abs(exact-float64(h)) > 0.01 || h < 1 || h > steps {
				return 0, false
			}
			return h, true
		}

		for _, m := range methods {
			preds, _, err := methodForecast(m, train, testCfg)
			if err != nil || len(preds) == 0 {
				continue
			}
			for i := c; i < len(s); i++ {
				h, ok := stepFor(s[i].T)
				if !ok || h > len(preds) {
					continue
				}
				idx, exists := rowIdx[h]
				if !exists {
					eval.rows = append(eval.rows, evalRow{
						cut:    c,
						step:   h,
						actual: s[i].V,
						preds:  map[Method]float64{},
					})
					idx = len(eval.rows) - 1
					rowIdx[h] = idx
				}
				eval.rows[idx].preds[m] = preds[h-1].Value
			}
		}
	}
	return eval
}

// errorStats returns MAE and RMSE for a method across the n rolling-backtest
// rows it produced predictions for. n == 0 (with zero errors) means the
// method produced no rolling predictions at all — an RMSE of 0 with n > 0 is
// a genuinely perfect fit, so callers must branch on n, not on the RMSE.
func (e *rollingEval) errorStats(m Method) (mae, rmse float64, n int) {
	sumAbs, sumSq := 0.0, 0.0
	for _, r := range e.rows {
		pred, ok := r.preds[m]
		if !ok {
			continue
		}
		diff := e.errDiff(r.actual, pred)
		sumAbs += math.Abs(diff)
		sumSq += diff * diff
		n++
	}
	if n == 0 {
		return 0, 0, 0
	}
	return sumAbs / float64(n), math.Sqrt(sumSq / float64(n)), n
}

// residualsByStep buckets a method's residuals (actual − predicted) by
// horizon step.
func (e *rollingEval) residualsByStep(m Method) map[int][]float64 {
	out := map[int][]float64{}
	for _, r := range e.rows {
		pred, ok := r.preds[m]
		if !ok {
			continue
		}
		out[r.step] = append(out[r.step], r.actual-pred)
	}
	return out
}

// recencyDecay discounts rolling-backtest errors per cut point of age when
// scoring methods for ensemble weights: a cut k points older than the newest
// counts decay^k. This makes weights track each method's skill in the
// current regime rather than its average over all history.
const recencyDecay = 0.85

// recencyRMSE returns each method's recency-weighted rolling RMSE and, per
// method, whether it produced any rolling predictions at all. maxCut is the
// newest cut in the eval (0 when there are no rows).
func (e *rollingEval) recencyRMSE(candidates []Method) map[Method]float64 {
	maxCut := 0
	for _, r := range e.rows {
		if r.cut > maxCut {
			maxCut = r.cut
		}
	}
	out := map[Method]float64{}
	for _, m := range candidates {
		sumW, sumSq := 0.0, 0.0
		n := 0
		for _, r := range e.rows {
			pred, ok := r.preds[m]
			if !ok {
				continue
			}
			w := math.Pow(recencyDecay, float64(maxCut-r.cut))
			diff := r.actual - pred
			sumSq += w * diff * diff
			sumW += w
			n++
		}
		if n == 0 || sumW <= 0 {
			continue
		}
		out[m] = math.Sqrt(sumSq / sumW)
	}
	return out
}

// ensembleCombine returns the weighted-average prediction for a row over the
// member methods that predicted it, renormalizing weights over the available
// members. Returns false when none of the members predicted the row.
func ensembleCombine(r evalRow, weights map[Method]float64, members []Method) (float64, bool) {
	sumW, sumV := 0.0, 0.0
	for _, m := range members {
		pred, ok := r.preds[m]
		if !ok {
			continue
		}
		w := weights[m]
		sumW += w
		sumV += w * pred
	}
	if sumW <= 0 {
		return 0, false
	}
	return sumV / sumW, true
}

// ensembleResidualsByStep buckets the weighted ensemble's residuals by
// horizon step, so conformal bounds reflect the combined forecaster that is
// actually deployed rather than any single member.
func (e *rollingEval) ensembleResidualsByStep(weights map[Method]float64, members []Method) map[int][]float64 {
	out := map[int][]float64{}
	for _, r := range e.rows {
		pred, ok := ensembleCombine(r, weights, members)
		if !ok {
			continue
		}
		out[r.step] = append(out[r.step], r.actual-pred)
	}
	return out
}

// ensembleErrorStats returns MAE and RMSE of the weighted ensemble across all
// rolling-backtest rows.
func (e *rollingEval) ensembleErrorStats(weights map[Method]float64, members []Method) (mae, rmse float64) {
	sumAbs, sumSq := 0.0, 0.0
	n := 0
	for _, r := range e.rows {
		pred, ok := ensembleCombine(r, weights, members)
		if !ok {
			continue
		}
		diff := e.errDiff(r.actual, pred)
		sumAbs += math.Abs(diff)
		sumSq += diff * diff
		n++
	}
	if n == 0 {
		return 0, 0
	}
	return sumAbs / float64(n), math.Sqrt(sumSq / float64(n))
}

// totalResiduals counts residuals across all steps.
func totalResiduals(byStep map[int][]float64) int {
	n := 0
	for _, b := range byStep {
		n += len(b)
	}
	return n
}

// stepQuantiles computes empirical residual quantiles for each horizon step
// 1..maxStep. Steps whose bucket holds fewer than minBucketSamples residuals
// are merged with adjacent steps (h±1, widening symmetrically) until the pool
// is large enough or every observed bucket has been absorbed. Steps beyond
// the largest observed step reuse the pool of the farthest observed steps.
//
// To keep uncertainty growing with horizon, each quantile offset is made
// monotone across steps: lower offsets never rise, upper offsets never fall.
func stepQuantiles(byStep map[int][]float64, maxStep int) map[int]map[string]float64 {
	if len(byStep) == 0 || maxStep < 1 {
		return nil
	}
	maxObserved := 0
	for h := range byStep {
		if h > maxObserved {
			maxObserved = h
		}
	}

	out := make(map[int]map[string]float64, maxStep)
	for h := 1; h <= maxStep; h++ {
		center := h
		if center > maxObserved {
			center = maxObserved
		}
		pool := gatherPool(byStep, center, maxObserved)
		if len(pool) == 0 {
			continue
		}
		sort.Float64s(pool)
		qs := make(map[string]float64, len(quantileLevels))
		for _, lv := range quantileLevels {
			qs[lv.name] = quantileConformal(pool, lv.q)
		}
		out[h] = qs
	}

	// Enforce monotone widening across steps.
	for h := 2; h <= maxStep; h++ {
		cur, prev := out[h], out[h-1]
		if cur == nil || prev == nil {
			continue
		}
		for _, lv := range quantileLevels {
			switch {
			case lv.q < 0.5 && cur[lv.name] > prev[lv.name]:
				cur[lv.name] = prev[lv.name]
			case lv.q > 0.5 && cur[lv.name] < prev[lv.name]:
				cur[lv.name] = prev[lv.name]
			}
		}
	}
	return out
}

// gatherPool collects the residual bucket at center, merging adjacent step
// buckets outward until minBucketSamples is reached or the range 1..maxStep
// is exhausted.
func gatherPool(byStep map[int][]float64, center, maxStep int) []float64 {
	pool := append([]float64(nil), byStep[center]...)
	for radius := 1; len(pool) < minBucketSamples; radius++ {
		lo, hi := center-radius, center+radius
		if lo < 1 && hi > maxStep {
			break
		}
		if lo >= 1 {
			pool = append(pool, byStep[lo]...)
		}
		if hi <= maxStep {
			pool = append(pool, byStep[hi]...)
		}
	}
	return pool
}

// quantileConformal returns the q-quantile of an ascending-sorted residual
// slice using the split-conformal finite-sample correction: rank ⌈(n+1)q⌉
// for upper quantiles and ⌊(n+1)q⌋ for lower ones. Compared to interpolated
// quantiles this widens slightly at small n, which is what makes the
// resulting intervals hold their nominal coverage on future observations.
// The median uses plain interpolation (no coverage role).
func quantileConformal(sorted []float64, q float64) float64 {
	n := len(sorted)
	if n == 0 {
		return 0
	}
	switch {
	case q > 0.5:
		k := int(math.Ceil(q * float64(n+1)))
		if k > n {
			k = n
		}
		return sorted[k-1]
	case q < 0.5:
		k := int(math.Floor(q * float64(n+1)))
		if k < 1 {
			k = 1
		}
		return sorted[k-1]
	default:
		return quantileSorted(sorted, 0.5)
	}
}

// quantileSorted returns the q-quantile of an ascending-sorted slice using
// linear interpolation between order statistics (type-7 estimator).
func quantileSorted(sorted []float64, q float64) float64 {
	n := len(sorted)
	if n == 0 {
		return 0
	}
	if n == 1 {
		return sorted[0]
	}
	pos := q * float64(n-1)
	lo := int(math.Floor(pos))
	if lo >= n-1 {
		return sorted[n-1]
	}
	frac := pos - float64(lo)
	return sorted[lo]*(1-frac) + sorted[lo+1]*frac
}

// boundPair maps a confidence level to the quantile pair used for
// LowerBound/UpperBound: the nearest emitted pair. P10/P90 form an 80%
// interval, P25/P75 a 50% one; levels of 0.65 and above snap to P10/P90.
func boundPair(confidence float64) (lower, upper string) {
	if confidence < 0.65 {
		return "p25", "p75"
	}
	return "p10", "p90"
}

// applyConformalBounds attaches empirical quantiles and bounds to
// predictions. offset is the anchor gap (see anchorOffset): prediction i sits
// offset+i+1 steps beyond the last training point, so it draws the quantiles
// for that step.
func applyConformalBounds(preds []Prediction, sq map[int]map[string]float64, cfg Config, offset int) {
	lowerName, upperName := boundPair(cfg.ConfidenceLevel)
	maxStep := 0
	for h := range sq {
		if h > maxStep {
			maxStep = h
		}
	}
	for i := range preds {
		h := offset + i + 1
		if h > maxStep {
			h = maxStep
		}
		qs := sq[h]
		if qs == nil {
			continue
		}
		quantiles := make(map[string]float64, len(qs))
		for name, off := range qs {
			quantiles[name] = preds[i].Value + off
		}
		preds[i].Quantiles = quantiles
		preds[i].LowerBound = quantiles[lowerName]
		preds[i].UpperBound = quantiles[upperName]
	}
}

// clipNonNegative floors prediction values, bounds, and quantiles at zero.
// Applied when cfg.NonNegative marks the metric as a count/amount that
// cannot go below zero (clicks, leads, cost, income).
func clipNonNegative(preds []Prediction) {
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
		for name, v := range preds[i].Quantiles {
			if v < 0 {
				preds[i].Quantiles[name] = 0
			}
		}
	}
}
