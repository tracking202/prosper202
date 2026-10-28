package forecast

// holtWintersForecast implements damped-trend double exponential smoothing
// (Holt's method with Gardner–McKenzie damping).
//
// It models both level and trend, making it superior to SMA/WMA for series
// with a clear directional trend. The damping parameter phi shrinks the
// trend's contribution geometrically with horizon, so a recent growth spurt
// is not extrapolated forever; phi = 1 recovers the classic undamped method.
// The smoothing parameters (alpha, beta, phi) are selected automatically via
// grid search over the training data to minimize the sum of squared
// one-step-ahead forecast errors.
//
// Model:
//
//	level(t) = alpha * y(t) + (1 - alpha) * (level(t-1) + phi * trend(t-1))
//	trend(t) = beta * (level(t) - level(t-1)) + (1 - beta) * phi * trend(t-1)
//	forecast(t+h) = level(t) + (phi + phi^2 + ... + phi^h) * trend(t)
//
// The returned per-period trend is phi*trend — the first forecast step's
// increment — which equals the raw trend when phi = 1.
func holtWintersForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	alpha, beta, phi := optimizeParams(s)

	level, trend := initHoltWinters(s)

	// Run the smoother over all observations.
	for i := 1; i < len(s); i++ {
		prevLevel := level
		level = alpha*s[i].V + (1-alpha)*(level+phi*trend)
		trend = beta*(level-prevLevel) + (1-beta)*phi*trend
	}

	// Project forward. The anchor offset advances the projection so values
	// line up with the anchored timestamps when trailing points were masked.
	anchor := anchorTime(s, cfg)
	offset := anchorOffset(s, cfg)
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		h := offset + i + 1
		val := level + dampedSum(phi, h)*trend
		t := nextTime(anchor, cfg.Interval, i+1)
		preds[i] = Prediction{T: t, Value: val}
	}

	return preds, phi * trend, nil
}

// dampedSum returns phi + phi^2 + ... + phi^h, the damped multiplier applied
// to the trend h steps out. It equals h when phi = 1.
func dampedSum(phi float64, h int) float64 {
	if phi == 1 {
		return float64(h)
	}
	sum := 0.0
	p := phi
	for i := 0; i < h; i++ {
		sum += p
		p *= phi
	}
	return sum
}

// initHoltWinters sets the initial level and trend from the first few points.
func initHoltWinters(s Series) (level, trend float64) {
	level = s[0].V
	// Average slope over the first min(5, n-1) intervals.
	nInit := 5
	if nInit > len(s)-1 {
		nInit = len(s) - 1
	}
	if nInit > 0 {
		trend = (s[nInit].V - s[0].V) / float64(nInit)
	}
	return level, trend
}

// phiGrid holds the candidate damping parameters; 1.0 preserves the classic
// undamped behavior so the optimizer can still choose it.
var phiGrid = []float64{0.85, 0.90, 0.95, 0.98, 1.0}

// optimizeParams performs a grid search over alpha, beta, and phi to minimize
// in-sample SSE (sum of squared one-step-ahead errors).
func optimizeParams(s Series) (bestAlpha, bestBeta, bestPhi float64) {
	bestAlpha = 0.3
	bestBeta = 0.1
	bestPhi = 1.0
	bestSSE := -1.0

	for a := 0.05; a <= 0.95; a += 0.05 {
		for b := 0.01; b <= 0.50; b += 0.05 {
			for _, p := range phiGrid {
				sse := computeSSE(s, a, b, p)
				if bestSSE < 0 || sse < bestSSE {
					bestSSE = sse
					bestAlpha = a
					bestBeta = b
					bestPhi = p
				}
			}
		}
	}

	return bestAlpha, bestBeta, bestPhi
}

// computeSSE returns the sum of squared one-step-ahead forecast errors
// for the given smoothing parameters.
func computeSSE(s Series, alpha, beta, phi float64) float64 {
	level, trend := initHoltWinters(s)

	sse := 0.0
	for i := 1; i < len(s); i++ {
		forecast := level + phi*trend
		err := s[i].V - forecast
		sse += err * err

		prevLevel := level
		level = alpha*s[i].V + (1-alpha)*(level+phi*trend)
		trend = beta*(level-prevLevel) + (1-beta)*phi*trend
	}

	return sse
}
