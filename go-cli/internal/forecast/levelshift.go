package forecast

import (
	"math"
	"time"
)

// Level-shift handling. A campaign pause, a new traffic source, or an offer
// cap changes the series' level; fitting all history equally then splits the
// difference between the old and new regimes.
//
// Detection is a CUSUM statistic on one-step residuals of a trailing-mean
// baseline: a steady trend produces a roughly constant one-step residual
// (which centering removes), while a level shift produces a burst of large
// residuals that the CUSUM accumulates. Once a shift is confirmed, its
// location is the two-segment mean split minimizing SSE, and its magnitude
// is measured against the pre-shift segment's own linear fit extrapolated
// forward (the counterfactual level). The forecast is then fitted either
// on the post-shift history alone (when that segment is long enough to
// model on its own) or on all history re-leveled to the new regime (when
// it is not, so the old data's shape still helps without dragging the
// level). Detection and handling run per training prefix (trainingView),
// so backtest folds neither see held-out data nor know of a shift before
// their own history reveals it.

const (
	// levelShiftMinPoints is the shortest series checked for level shifts:
	// below it segment statistics are noise and a false detection rewrites
	// most of the training data.
	levelShiftMinPoints = 20
	// levelShiftBaselineWindow is the trailing-mean window for one-step
	// residuals.
	levelShiftBaselineWindow = 7
	// levelShiftMinSegment keeps the change point away from the series
	// edges, where segment statistics are unstable.
	levelShiftMinSegment = 5
	// levelShiftCUSUMThreshold is the significance bound for the normalized
	// CUSUM statistic max|S_j| / (σ√n). The classic 1.36 Kolmogorov bound
	// assumes independent residuals; trailing-mean one-step residuals are
	// autocorrelated and the shift burst inflates σ, so the bound is tuned
	// empirically instead: flat noise, steady trends, and weekly patterns
	// measure ≤ ~0.7 while genuine 3σ+ level shifts measure ≥ ~0.9. The
	// magnitude gate below is the second line of defense.
	levelShiftCUSUMThreshold = 0.9
	// levelShiftSigmaMultiple is the minimum shift magnitude, in units of
	// pre-shift residual noise, worth acting on: smaller shifts cost less
	// than discarding or rewriting history does.
	levelShiftSigmaMultiple = 3.0
	// levelShiftPostSpreadMultiple bounds the post-shift segment's own
	// spread relative to pre-shift noise; a transient burst (outage, promo
	// spike) mixed with normal days blows past it.
	levelShiftPostSpreadMultiple = 2.0
	// levelShiftAgreeSigma is how far (in pre-shift sigmas) a post-shift
	// point must sit beyond the old level to count as agreeing with the
	// shift; a majority must agree.
	levelShiftAgreeSigma = 1.5
)

// minPostShiftPoints returns how many post-shift observations are enough to
// forecast from the new regime alone (two seasonal cycles for intervals
// with a natural cycle).
func minPostShiftPoints(interval Interval) int {
	switch interval {
	case IntervalHour:
		return 48
	case IntervalDay:
		return 14
	default:
		return 8
	}
}

// levelShift describes a detected level change: idx is the first post-shift
// observation, delta the level jump (post-shift actuals minus the pre-shift
// linear fit extrapolated forward), and slope/intercept/base that pre-shift
// fit, kept so the jump can be re-estimated from any prefix of the post-shift
// data (see relevelPrefix).
type levelShift struct {
	idx              int
	delta            float64
	slope, intercept float64
	base             time.Time
}

// detectLevelShift scans for the strongest level change in the series.
// It returns nil when no shift passes the CUSUM significance test, the
// magnitude threshold, and the post-segment consistency check.
func detectLevelShift(s Series) *levelShift {
	n := len(s)
	if n < levelShiftMinPoints {
		return nil
	}

	// One-step residuals against a trailing-mean baseline.
	resid := make([]float64, 0, n-1)
	for i := 1; i < n; i++ {
		lo := i - levelShiftBaselineWindow
		if lo < 0 {
			lo = 0
		}
		sum := 0.0
		for j := lo; j < i; j++ {
			sum += s[j].V
		}
		resid = append(resid, s[i].V-sum/float64(i-lo))
	}

	sigmaR := stddev(resid)
	if sigmaR <= 0 {
		return nil
	}
	m := mean(resid)
	maxAbs, cum := 0.0, 0.0
	for _, r := range resid {
		cum += r - m
		if a := math.Abs(cum); a > maxAbs {
			maxAbs = a
		}
	}
	if maxAbs/(sigmaR*math.Sqrt(float64(len(resid)))) < levelShiftCUSUMThreshold {
		return nil
	}

	// Locate the change point: the two-segment mean split minimizing SSE.
	idx := bestMeanSplit(s)
	if idx < levelShiftMinSegment || n-idx < levelShiftMinSegment {
		return nil
	}

	// Magnitude: post-shift actuals vs the pre-shift segment's own linear
	// fit extrapolated forward, gated against pre-shift noise.
	pre := s[:idx]
	slope, intercept := olsFit(pre)
	base := pre[0].T
	postResid := make([]float64, 0, n-idx)
	for i := idx; i < n; i++ {
		postResid = append(postResid, s[i].V-(intercept+slope*s[i].T.Sub(base).Hours()))
	}
	delta := mean(postResid)

	preResid := make([]float64, idx)
	for i, p := range pre {
		preResid[i] = p.V - (intercept + slope*p.T.Sub(base).Hours())
	}
	// Noise floor: an exactly-fitted pre-segment (sigma 0) must still
	// require a non-trivial jump, otherwise a clean trend "shifts" by 0.
	sigmaPre := stddev(preResid)
	if sigmaPre <= 0 {
		sigmaPre = 1e-6 * (math.Abs(mean(seriesValues(pre))) + 1)
	}
	if math.Abs(delta) < levelShiftSigmaMultiple*sigmaPre {
		return nil
	}

	// Consistency: a genuine regime change moves the whole post segment,
	// with noise comparable to before. A transient burst (a two-day tracking
	// outage, a promo spike) at the end of history leaves the post segment
	// internally inconsistent — its own spread dwarfs the pre-shift noise,
	// or only a minority of its points actually sit beyond the old level —
	// and must not rewrite the entire training history.
	if stddev(postResid) > levelShiftPostSpreadMultiple*sigmaPre {
		return nil
	}
	agree := 0
	for _, r := range postResid {
		if (delta > 0 && r > levelShiftAgreeSigma*sigmaPre) || (delta < 0 && r < -levelShiftAgreeSigma*sigmaPre) {
			agree++
		}
	}
	if agree*2 <= len(postResid) {
		return nil
	}

	return &levelShift{idx: idx, delta: delta, slope: slope, intercept: intercept, base: base}
}

// truncates reports whether the post-shift segment is long enough to be
// modeled on its own (so pre-shift history is dropped rather than re-leveled).
func (ls *levelShift) truncates(n int, interval Interval) bool {
	return n-ls.idx >= minPostShiftPoints(interval)
}

// relevelPrefix returns the first c points of s with pre-shift values
// re-leveled to the new regime, using only the post-shift observations
// inside the prefix to estimate the jump. A rolling-backtest cut therefore
// never sees the actuals it holds out (no leakage); a prefix ending before
// any post-shift point is returned unchanged. The input is not modified.
func (ls *levelShift) relevelPrefix(s Series, c int) Series {
	if ls == nil || c <= ls.idx {
		return s[:c]
	}
	sum := 0.0
	for i := ls.idx; i < c; i++ {
		sum += s[i].V - (ls.intercept + ls.slope*s[i].T.Sub(ls.base).Hours())
	}
	delta := sum / float64(c-ls.idx)
	out := make(Series, c)
	copy(out, s[:c])
	for i := 0; i < ls.idx; i++ {
		out[i].V += delta
	}
	return out
}

// trainingView returns the series a model should be fitted on for a prefix
// of length c, applying the same level-shift handling the deployed forecast
// gets, detected on the prefix alone. A backtest fold therefore never knows
// about a shift its own history could not yet reveal: a fold ending on the
// first post-shift point trains on the unmodified prefix, exactly as the
// deployed model would have at that time, so rolling residuals and
// ensemble weights are not flattered around the regime change. A shift
// whose post segment is long enough to model on its own truncates the
// prefix; a shorter one re-levels the older history to the new regime.
func trainingView(s Series, cfg Config, c int) Series {
	prefix := s[:c]
	if cfg.DisableLevelShift {
		return prefix
	}
	ls := detectLevelShift(prefix)
	if ls == nil {
		return prefix
	}
	if ls.truncates(c, cfg.Interval) {
		return prefix[ls.idx:]
	}
	return ls.relevelPrefix(s, c)
}

// formatShiftTime renders a detected shift's timestamp for Result metadata.
func formatShiftTime(t time.Time, interval Interval) string {
	if interval == IntervalHour {
		return t.Format("2006-01-02 15:04")
	}
	return t.Format("2006-01-02")
}

// bestMeanSplit returns the split index minimizing the two-segment
// sum of squared deviations from each segment's mean, using prefix sums.
func bestMeanSplit(s Series) int {
	n := len(s)
	prefix := make([]float64, n+1)
	prefixSq := make([]float64, n+1)
	for i, p := range s {
		prefix[i+1] = prefix[i] + p.V
		prefixSq[i+1] = prefixSq[i] + p.V*p.V
	}
	sse := func(lo, hi int) float64 { // [lo, hi)
		cnt := float64(hi - lo)
		sum := prefix[hi] - prefix[lo]
		sumSq := prefixSq[hi] - prefixSq[lo]
		return sumSq - sum*sum/cnt
	}
	best, bestIdx := math.MaxFloat64, -1
	for j := levelShiftMinSegment; j <= n-levelShiftMinSegment; j++ {
		if total := sse(0, j) + sse(j, n); total < best {
			best = total
			bestIdx = j
		}
	}
	return bestIdx
}

// olsFit returns the least-squares slope and intercept of the series
// against calendar-hours since its first point.
func olsFit(s Series) (slope, intercept float64) {
	base := s[0].T
	n := float64(len(s))
	sumX, sumY, sumXY, sumXX := 0.0, 0.0, 0.0, 0.0
	for _, p := range s {
		x := p.T.Sub(base).Hours()
		sumX += x
		sumY += p.V
		sumXY += x * p.V
		sumXX += x * x
	}
	denom := n*sumXX - sumX*sumX
	if math.Abs(denom) < 1e-12 {
		return 0, sumY / n
	}
	slope = (n*sumXY - sumX*sumY) / denom
	intercept = (sumY - slope*sumX) / n
	return slope, intercept
}

func mean(v []float64) float64 {
	if len(v) == 0 {
		return 0
	}
	sum := 0.0
	for _, x := range v {
		sum += x
	}
	return sum / float64(len(v))
}

func stddev(v []float64) float64 {
	if len(v) < 2 {
		return 0
	}
	m := mean(v)
	sumSq := 0.0
	for _, x := range v {
		d := x - m
		sumSq += d * d
	}
	return math.Sqrt(sumSq / float64(len(v)))
}
