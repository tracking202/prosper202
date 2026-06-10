package forecast

// holtWintersForecast implements double exponential smoothing (Holt's method).
//
// It models both level and trend, making it superior to SMA/WMA for series
// with a clear directional trend. The smoothing parameters (alpha, beta) are
// selected automatically via grid search over the training data to minimize
// the sum of squared one-step-ahead forecast errors.
//
// Model:
//   level(t) = alpha * y(t) + (1 - alpha) * (level(t-1) + trend(t-1))
//   trend(t) = beta * (level(t) - level(t-1)) + (1 - beta) * trend(t-1)
//   forecast(t+h) = level(t) + h * trend(t)
func holtWintersForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	alpha, beta := optimizeParams(s)

	level, trend := initHoltWinters(s)

	// Run the smoother over all observations.
	for i := 1; i < len(s); i++ {
		prevLevel := level
		level = alpha*s[i].V + (1-alpha)*(level+trend)
		trend = beta*(level-prevLevel) + (1-beta)*trend
	}

	// Project forward.
	last := s[len(s)-1]
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		h := float64(i + 1)
		val := level + h*trend
		t := nextTime(last.T, cfg.Interval, i+1)
		preds[i] = Prediction{T: t, Value: val}
	}

	stddev := residualStdDev(s, MethodHoltWinters, cfg)
	addBounds(preds, stddev, cfg.ConfidenceLevel)
	return preds, trend, nil
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

// optimizeParams performs a grid search over alpha and beta to minimize
// in-sample SSE (sum of squared one-step-ahead errors).
func optimizeParams(s Series) (bestAlpha, bestBeta float64) {
	bestAlpha = 0.3
	bestBeta = 0.1
	bestSSE := -1.0

	for a := 0.05; a <= 0.95; a += 0.05 {
		for b := 0.01; b <= 0.50; b += 0.05 {
			sse := computeSSE(s, a, b)
			if bestSSE < 0 || sse < bestSSE {
				bestSSE = sse
				bestAlpha = a
				bestBeta = b
			}
		}
	}

	return bestAlpha, bestBeta
}

// computeSSE returns the sum of squared one-step-ahead forecast errors
// for the given smoothing parameters.
func computeSSE(s Series, alpha, beta float64) float64 {
	level, trend := initHoltWinters(s)

	sse := 0.0
	for i := 1; i < len(s); i++ {
		forecast := level + trend
		err := s[i].V - forecast
		sse += err * err

		prevLevel := level
		level = alpha*s[i].V + (1-alpha)*(level+trend)
		trend = beta*(level-prevLevel) + (1-beta)*trend
	}

	return sse
}
