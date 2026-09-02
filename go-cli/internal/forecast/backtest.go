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
	{"p05", 0.05},
	{"p10", 0.10},
	{"p25", 0.25},
	{"p50", 0.50},
	{"p75", 0.75},
	{"p90", 0.90},
	{"p95", 0.95},
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

// holtWintersMinPoints is the shortest history Holt-Winters is fitted on;
// its level/trend initialization is unstable below it.
const holtWintersMinPoints = 14

// methodMinPoints returns the minimum training length a method is fitted
// on, both for auto-candidacy and for rolling-backtest cuts.
func methodMinPoints(m Method) int {
	if m == MethodHoltWinters {
		return holtWintersMinPoints
	}
	return minTrainPoints
}

// evalRow is one (cut point, horizon step) observation from the rolling
// backtest: the held-out actual and each method's prediction for it.
type evalRow struct {
	cut      int       // training prefix length c the prediction was made from
	trainEnd time.Time // timestamp of the last training point
	t        time.Time // timestamp of the held-out actual
	step     int       // 1-based horizon step
	actual   float64
	preds    map[Method]float64
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
		train := trainingView(s, cfg, c)
		if len(train) == 0 {
			continue
		}
		trainEnd := train[len(train)-1].T

		// This cut's horizon is the calendar distance to the farthest
		// held-out point (masked gaps make it exceed the observation
		// count), capped at the configured horizon, so residuals around a
		// gap are kept rather than rejected as out of range.
		steps := calendarSteps(trainEnd, s[len(s)-1].T, cfg.Interval)
		if steps > horizon {
			steps = horizon
		}

		testCfg := cfg
		testCfg.Horizon = steps
		testCfg.Anchor = time.Time{}

		// This fold's seasonal profile, from its own training view.
		profile, _ := profileFor(train, cfg)

		// Map each held-out point to its horizon step by calendar distance.
		// Rows are created lazily so methods share the same row set.
		rowIdx := map[int]int{} // step -> index into eval.rows (this cut only)
		stepFor := func(t time.Time) (int, bool) { return stepIndex(trainEnd, t, cfg.Interval, steps) }

		for _, m := range methods {
			// Respect each method's minimum history: a fit the deployed
			// model would never make must not feed residuals or weights.
			// (The view can be shorter than c after a level-shift
			// truncation inside the prefix.)
			if len(train) < methodMinPoints(m) {
				continue
			}
			preds, _, err := methodForecast(m, train, testCfg)
			if err != nil || len(preds) == 0 {
				continue
			}
			applyProfile(preds, profile, cfg.LogTransform)
			clipPointPredictions(preds, cfg)
			for i := c; i < len(s); i++ {
				// Masked transients are not scored (see Config.excluded).
				if cfg.excluded[s[i].T] {
					continue
				}
				h, ok := stepFor(s[i].T)
				if !ok || h > len(preds) {
					continue
				}
				idx, exists := rowIdx[h]
				if !exists {
					eval.rows = append(eval.rows, evalRow{
						cut:      c,
						trainEnd: trainEnd,
						t:        s[i].T,
						step:     h,
						actual:   s[i].V,
						preds:    map[Method]float64{},
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

// rowPredictor reads one forecaster's prediction for a backtest row; ok is
// false when that forecaster produced no prediction for the row.
type rowPredictor func(r evalRow) (pred float64, ok bool)

// methodPredictor returns the rowPredictor for a single method.
func (e *rollingEval) methodPredictor(m Method) rowPredictor {
	return func(r evalRow) (float64, bool) {
		pred, ok := r.preds[m]
		return pred, ok
	}
}

// errorStats returns MAE and RMSE for a forecaster across the n rolling-
// backtest rows it produced predictions for. n == 0 (with zero errors)
// means it produced no rolling predictions at all — an RMSE of 0 with n > 0
// is a genuinely perfect fit, so callers must branch on n, not the RMSE.
func (e *rollingEval) errorStats(predict rowPredictor) (mae, rmse float64, n int) {
	sumAbs, sumSq := 0.0, 0.0
	for _, r := range e.rows {
		pred, ok := predict(r)
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

// residualsByStep buckets a forecaster's residuals (actual − predicted, on
// the model scale) by horizon step.
func (e *rollingEval) residualsByStep(predict rowPredictor) map[int][]float64 {
	out := map[int][]float64{}
	for _, r := range e.rows {
		pred, ok := predict(r)
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

// recencyRMSE returns each candidate's recency-weighted rolling RMSE (on
// the model scale, where members are compared) over the rows include
// accepts (all rows when include is nil); candidates that produced no
// rolling predictions on those rows are absent from the result.
func (e *rollingEval) recencyRMSE(candidates []Method, include func(evalRow) bool) map[Method]float64 {
	maxCut := 0
	for _, r := range e.rows {
		if include != nil && !include(r) {
			continue
		}
		if r.cut > maxCut {
			maxCut = r.cut
		}
	}
	out := map[Method]float64{}
	for _, m := range candidates {
		sumW, sumSq := 0.0, 0.0
		n := 0
		for _, r := range e.rows {
			if include != nil && !include(r) {
				continue
			}
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
// LowerBound/UpperBound — the nearest emitted pair. P25/P75 form a 50%
// interval, P10/P90 an 80% one, and P05/P95 a 90% one; levels of 0.85 and
// above (including the 0.95 default and 0.99) snap to P05/P95, the widest
// band the residual pool supports.
func boundPair(confidence float64) (lower, upper string) {
	switch {
	case confidence < 0.65:
		return "p25", "p75"
	case confidence < 0.85:
		return "p10", "p90"
	default:
		return "p05", "p95"
	}
}

// BoundLevels reports the nominal coverage of the band a confidence level
// maps to (see boundPair), for documentation and CLI metadata.
func BoundLevels(confidence float64) (lower, upper string, coverage float64) {
	lower, upper = boundPair(confidence)
	switch lower {
	case "p25":
		return lower, upper, 0.50
	case "p10":
		return lower, upper, 0.80
	default:
		return lower, upper, 0.90
	}
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

// ClipNonNegative floors prediction values, bounds, and quantiles at zero.
// Run applies it when cfg.NonNegative marks the metric as a count/amount
// that cannot go below zero (clicks, leads, cost, income); callers that
// scale predictions afterwards (event adjustments) re-apply it.
func ClipNonNegative(preds []Prediction) {
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
