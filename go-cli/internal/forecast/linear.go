package forecast

import "math"

// linearForecast fits an ordinary least-squares regression line to the series
// and projects it forward. It returns the per-period slope as the trend value.
//
// The model: V(t) = intercept + slope * t
// where t is the zero-indexed position in the series.
func linearForecast(s Series, cfg Config) ([]Prediction, float64, error) {
	n := float64(len(s))

	// Compute regression coefficients using the normal equations.
	sumX := 0.0
	sumY := 0.0
	sumXY := 0.0
	sumXX := 0.0

	for i, p := range s {
		x := float64(i)
		sumX += x
		sumY += p.V
		sumXY += x * p.V
		sumXX += x * x
	}

	denominator := n*sumXX - sumX*sumX
	if math.Abs(denominator) < 1e-12 {
		// Flat line — all x values identical (shouldn't happen with sequential indices).
		mean := sumY / n
		return constantForecast(s, cfg, mean), 0, nil
	}

	slope := (n*sumXY - sumX*sumY) / denominator
	intercept := (sumY - slope*sumX) / n

	// Compute residual standard deviation for confidence bounds.
	sumResidSq := 0.0
	for i, p := range s {
		predicted := intercept + slope*float64(i)
		diff := p.V - predicted
		sumResidSq += diff * diff
	}
	residStd := 0.0
	if n > 2 {
		residStd = math.Sqrt(sumResidSq / (n - 2))
	}

	// Project forward.
	last := s[len(s)-1]
	preds := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		x := float64(len(s) + i)
		val := intercept + slope*x
		t := nextTime(last.T, cfg.Interval, i+1)
		preds[i] = Prediction{T: t, Value: val}
	}

	addBounds(preds, residStd, cfg.ConfidenceLevel)
	return preds, slope, nil
}

// constantForecast returns a flat-line forecast at the given value.
func constantForecast(s Series, cfg Config, value float64) []Prediction {
	last := s[len(s)-1]
	preds := make([]Prediction, cfg.Horizon)
	for i := range preds {
		t := nextTime(last.T, cfg.Interval, i+1)
		preds[i] = Prediction{
			T:          t,
			Value:      value,
			LowerBound: value,
			UpperBound: value,
		}
	}
	return preds
}
