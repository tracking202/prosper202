package forecast

// smaForecast computes a Simple Moving Average over the last cfg.SMAWindow
// points and projects that average forward for cfg.Horizon periods.
//
// SMA is best for stable, low-variance series where recent performance
// is a good predictor of near-term future.
func smaForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	window := cfg.SMAWindow
	if window > len(s) {
		window = len(s)
	}

	tail := s[len(s)-window:]

	sum := 0.0
	for _, p := range tail {
		sum += p.V
	}
	avg := sum / float64(window)

	// Trend: difference between last SMA and the one before it.
	trend := 0.0
	if len(s) > window {
		prevTail := s[len(s)-window-1 : len(s)-1]
		prevSum := 0.0
		for _, p := range prevTail {
			prevSum += p.V
		}
		prevAvg := prevSum / float64(window)
		trend = avg - prevAvg
	}

	last := s[len(s)-1]
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		t := nextTime(last.T, cfg.Interval, i+1)
		preds[i] = Prediction{T: t, Value: avg}
	}

	stddev := residualStdDev(s, MethodSMA, cfg)
	addBounds(preds, stddev, cfg.ConfidenceLevel)
	return preds, trend, nil
}

// wmaForecast computes a Weighted Moving Average where recent observations
// carry more weight. The weight of point i in the window is (i+1), so the
// most recent point has weight=window, the one before it weight=window-1, etc.
//
// WMA reacts faster to changes than SMA while still smoothing noise.
func wmaForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	window := cfg.SMAWindow
	if window > len(s) {
		window = len(s)
	}

	tail := s[len(s)-window:]

	weightSum := 0.0
	valSum := 0.0
	for i, p := range tail {
		w := float64(i + 1)
		weightSum += w
		valSum += p.V * w
	}
	wma := valSum / weightSum

	// Trend: difference between current WMA and previous WMA.
	trend := 0.0
	if len(s) > window {
		prevTail := s[len(s)-window-1 : len(s)-1]
		prevWeightSum := 0.0
		prevValSum := 0.0
		for i, p := range prevTail {
			w := float64(i + 1)
			prevWeightSum += w
			prevValSum += p.V * w
		}
		prevWMA := prevValSum / prevWeightSum
		trend = wma - prevWMA
	}

	last := s[len(s)-1]
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		t := nextTime(last.T, cfg.Interval, i+1)
		preds[i] = Prediction{T: t, Value: wma}
	}

	stddev := residualStdDev(s, MethodWMA, cfg)
	addBounds(preds, stddev, cfg.ConfidenceLevel)
	return preds, trend, nil
}
