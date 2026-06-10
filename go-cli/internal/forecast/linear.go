package forecast

import "math"

// linearForecast fits an ordinary least-squares regression line to the series
// and projects it forward. It returns the per-period slope as the trend value.
//
// The model: V(t) = intercept + slope * t, where t is the number of interval
// steps since the first point (calendar time). Fitting against calendar steps
// instead of dense indexes keeps the slope unbiased when masked event days
// leave gaps in the series, and lets projection compute each prediction's x
// directly from its own timestamp.
func linearForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	n := float64(len(s))
	base := s[0].T

	xs := make([]float64, len(s))
	for i, p := range s {
		xs[i] = intervalSteps(base, p.T, cfg.Interval)
	}

	// Compute regression coefficients using the normal equations.
	sumX := 0.0
	sumY := 0.0
	sumXY := 0.0
	sumXX := 0.0

	for i, p := range s {
		x := xs[i]
		sumX += x
		sumY += p.V
		sumXY += x * p.V
		sumXX += x * x
	}

	denominator := n*sumXX - sumX*sumX
	if math.Abs(denominator) < 1e-12 {
		// Flat line — all x values identical (shouldn't happen with distinct timestamps).
		mean := sumY / n
		return constantForecast(s, cfg, mean), 0, nil
	}

	slope := (n*sumXY - sumX*sumY) / denominator
	intercept := (sumY - slope*sumX) / n

	// Compute residual standard deviation for confidence bounds.
	sumResidSq := 0.0
	for i, p := range s {
		predicted := intercept + slope*xs[i]
		diff := p.V - predicted
		sumResidSq += diff * diff
	}
	residStd := 0.0
	if n > 2 {
		residStd = math.Sqrt(sumResidSq / (n - 2))
	}

	// Project forward. Each prediction's x derives from its own timestamp,
	// so anchor gaps after masked trailing days are handled exactly.
	anchor := anchorTime(s, cfg)
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		t := nextTime(anchor, cfg.Interval, i+1)
		val := intercept + slope*intervalSteps(base, t, cfg.Interval)
		preds[i] = Prediction{T: t, Value: val}
	}

	addBounds(preds, residStd, cfg.ConfidenceLevel, anchorOffset(s, cfg))
	return preds, slope, nil
}

// constantForecast returns a flat-line forecast at the given value.
func constantForecast(s Series, cfg Config, value float64) []Prediction {
	anchor := anchorTime(s, cfg)
	preds := make([]Prediction, cfg.Horizon)
	for i := range preds {
		t := nextTime(anchor, cfg.Interval, i+1)
		preds[i] = Prediction{
			T:          t,
			Value:      value,
			LowerBound: value,
			UpperBound: value,
		}
	}
	return preds
}
