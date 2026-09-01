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
// forward (the counterfactual level). Run then either truncates the
// pre-shift history (when the post-shift segment is long enough to model on
// its own) or re-levels it to the new regime (when it is not, so the old
// data's shape still helps without dragging the level).

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
	levelShiftMinSegment = 4
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

// detectLevelShift scans for the strongest level change in the series.
// It returns the index of the first post-shift observation and the level
// delta (post-shift actuals minus the pre-shift fit's extrapolation), or
// (-1, 0) when no shift passes both the CUSUM significance test and the
// magnitude threshold.
func detectLevelShift(s Series) (idx int, delta float64) {
	n := len(s)
	if n < levelShiftMinPoints {
		return -1, 0
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
		return -1, 0
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
		return -1, 0
	}

	// Locate the change point: the two-segment mean split minimizing SSE.
	idx = bestMeanSplit(s)
	if idx < levelShiftMinSegment || n-idx < 2 {
		return -1, 0
	}

	// Magnitude: post-shift actuals vs the pre-shift segment's own linear
	// fit extrapolated forward, gated against pre-shift noise.
	pre := s[:idx]
	slope, intercept := olsFit(pre)
	base := pre[0].T
	sum := 0.0
	for i := idx; i < n; i++ {
		sum += s[i].V - (intercept + slope*s[i].T.Sub(base).Hours())
	}
	delta = sum / float64(n-idx)

	preResid := make([]float64, idx)
	for i, p := range pre {
		preResid[i] = p.V - (intercept + slope*p.T.Sub(base).Hours())
	}
	if sigmaPre := stddev(preResid); sigmaPre > 0 && math.Abs(delta) < levelShiftSigmaMultiple*sigmaPre {
		return -1, 0
	}
	return idx, delta
}

// applyLevelShift returns the training series adjusted for a detected shift
// at idx: the post-shift segment alone when it is long enough, otherwise a
// copy with pre-shift values re-leveled by delta so the model sees a
// shift-free series centered on the new regime. The input is not modified.
func applyLevelShift(s Series, idx int, delta float64, interval Interval) Series {
	if len(s)-idx >= minPostShiftPoints(interval) {
		return s[idx:]
	}
	out := make(Series, len(s))
	copy(out, s)
	for i := 0; i < idx; i++ {
		out[i].V += delta
	}
	return out
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
