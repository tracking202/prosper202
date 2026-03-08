// Package forecast provides time-series forecasting for Prosper202 metrics.
//
// It implements multiple forecasting methods (linear regression, moving averages,
// Holt-Winters exponential smoothing) and a seasonal adjustment layer that uses
// day-of-week weights to modulate predictions. All math is done in pure Go with
// no external dependencies.
//
// Typical usage:
//
//	series := forecast.Series{
//	    {T: t1, V: 100.0},
//	    {T: t2, V: 120.0},
//	    ...
//	}
//	result, err := forecast.Run(series, forecast.Config{
//	    Method:   forecast.MethodAuto,
//	    Horizon:  7,
//	    Interval: forecast.IntervalDay,
//	})
package forecast

import (
	"fmt"
	"math"
	"sort"
	"time"
)

// Method identifies a forecasting algorithm.
type Method string

const (
	MethodLinear      Method = "linear"
	MethodSMA         Method = "sma"
	MethodWMA         Method = "wma"
	MethodHoltWinters Method = "holtwinters"
	MethodAuto        Method = "auto"
)

// Interval defines the time granularity of forecasted points.
type Interval string

const (
	IntervalHour  Interval = "hour"
	IntervalDay   Interval = "day"
	IntervalWeek  Interval = "week"
	IntervalMonth Interval = "month"
)

// Point is a single observed data point.
type Point struct {
	T time.Time
	V float64
}

// Series is a chronologically ordered slice of data points.
type Series []Point

// Prediction is a single forecasted value with confidence bounds.
type Prediction struct {
	T          time.Time `json:"time"`
	Value      float64   `json:"value"`
	LowerBound float64   `json:"lower_bound"`
	UpperBound float64   `json:"upper_bound"`
}

// Result holds the complete output of a forecast run.
type Result struct {
	Method      Method       `json:"method"`
	Metric      string       `json:"metric"`
	Horizon     int          `json:"horizon"`
	Interval    Interval     `json:"interval"`
	Predictions []Prediction `json:"predictions"`
	Trend       float64      `json:"trend_per_period"`
	TrendPct    float64      `json:"trend_pct"`
	MAE         float64      `json:"mae"`
	RMSE        float64      `json:"rmse"`
	DataPoints  int          `json:"data_points_used"`
}

// SeasonalWeights maps day-of-week (time.Weekday) to a multiplier.
// A weight of 1.0 means average; 1.2 means 20% above average for that day.
type SeasonalWeights map[time.Weekday]float64

// Config controls a forecast run.
type Config struct {
	Method          Method
	Horizon         int
	Interval        Interval
	Metric          string
	SMAWindow       int
	SeasonalWeights SeasonalWeights
	ConfidenceLevel float64 // 0.0-1.0, default 0.95
}

// ValidMethods returns all supported method names.
func ValidMethods() []string {
	return []string{
		string(MethodLinear),
		string(MethodSMA),
		string(MethodWMA),
		string(MethodHoltWinters),
		string(MethodAuto),
	}
}

// ValidIntervals returns all supported interval names.
func ValidIntervals() []string {
	return []string{
		string(IntervalHour),
		string(IntervalDay),
		string(IntervalWeek),
		string(IntervalMonth),
	}
}

// Run executes a forecast on the given series with the provided configuration.
func Run(series Series, cfg Config) (*Result, error) {
	if len(series) < 3 {
		return nil, fmt.Errorf("need at least 3 data points for forecasting, got %d", len(series))
	}

	// Sort chronologically.
	sort.Slice(series, func(i, j int) bool {
		return series[i].T.Before(series[j].T)
	})

	if cfg.Horizon <= 0 {
		cfg.Horizon = 7
	}
	if cfg.Interval == "" {
		cfg.Interval = IntervalDay
	}
	if cfg.ConfidenceLevel <= 0 || cfg.ConfidenceLevel >= 1 {
		cfg.ConfidenceLevel = 0.95
	}
	if cfg.SMAWindow <= 0 {
		cfg.SMAWindow = defaultSMAWindow(len(series))
	}

	method := cfg.Method
	if method == "" || method == MethodAuto {
		method = selectBestMethod(series, cfg)
	}

	var predictions []Prediction
	var trend float64
	var err error

	switch method {
	case MethodLinear:
		predictions, trend, err = linearForecast(series, cfg)
	case MethodSMA:
		predictions, trend, err = smaForecast(series, cfg)
	case MethodWMA:
		predictions, trend, err = wmaForecast(series, cfg)
	case MethodHoltWinters:
		predictions, trend, err = holtWintersForecast(series, cfg)
	default:
		return nil, fmt.Errorf("unknown method %q", method)
	}
	if err != nil {
		return nil, err
	}

	// Apply seasonal adjustment if weights are provided.
	if len(cfg.SeasonalWeights) > 0 {
		predictions = applySeasonalWeights(predictions, cfg.SeasonalWeights)
	}

	// Compute accuracy metrics via leave-last-out backtest.
	mae, rmse := backtest(series, cfg, method)

	// Compute trend percentage.
	trendPct := 0.0
	if len(series) > 0 {
		mean := seriesMean(series)
		if mean != 0 {
			trendPct = (trend / mean) * 100
		}
	}

	return &Result{
		Method:      method,
		Metric:      cfg.Metric,
		Horizon:     cfg.Horizon,
		Interval:    cfg.Interval,
		Predictions: predictions,
		Trend:       trend,
		TrendPct:    trendPct,
		MAE:         mae,
		RMSE:        rmse,
		DataPoints:  len(series),
	}, nil
}

// defaultSMAWindow picks a reasonable SMA window based on series length.
func defaultSMAWindow(n int) int {
	w := n / 4
	if w < 3 {
		w = 3
	}
	if w > 30 {
		w = 30
	}
	return w
}

// seriesMean returns the arithmetic mean of all values.
func seriesMean(s Series) float64 {
	if len(s) == 0 {
		return 0
	}
	sum := 0.0
	for _, p := range s {
		sum += p.V
	}
	return sum / float64(len(s))
}

// seriesStdDev returns the population standard deviation.
func seriesStdDev(s Series) float64 {
	if len(s) < 2 {
		return 0
	}
	mean := seriesMean(s)
	sumSq := 0.0
	for _, p := range s {
		diff := p.V - mean
		sumSq += diff * diff
	}
	return math.Sqrt(sumSq / float64(len(s)))
}

// residualStdDev computes std dev of forecast residuals over the last holdout points.
func residualStdDev(s Series, method Method, cfg Config) float64 {
	holdout := len(s) / 5
	if holdout < 2 {
		holdout = 2
	}
	if holdout > len(s)-3 {
		return seriesStdDev(s)
	}

	train := s[:len(s)-holdout]
	test := s[len(s)-holdout:]

	testCfg := cfg
	testCfg.Horizon = holdout
	testCfg.SeasonalWeights = nil

	var preds []Prediction
	var err error
	switch method {
	case MethodLinear:
		preds, _, err = linearForecast(train, testCfg)
	case MethodSMA:
		preds, _, err = smaForecast(train, testCfg)
	case MethodWMA:
		preds, _, err = wmaForecast(train, testCfg)
	case MethodHoltWinters:
		preds, _, err = holtWintersForecast(train, testCfg)
	default:
		return seriesStdDev(s)
	}
	if err != nil || len(preds) == 0 {
		return seriesStdDev(s)
	}

	n := len(preds)
	if n > len(test) {
		n = len(test)
	}
	sumSq := 0.0
	for i := 0; i < n; i++ {
		diff := test[i].V - preds[i].Value
		sumSq += diff * diff
	}
	return math.Sqrt(sumSq / float64(n))
}

// zScore returns the z-score for a given confidence level (two-tailed).
func zScore(confidence float64) float64 {
	// Common values, avoids needing an inverse-normal function.
	switch {
	case confidence >= 0.99:
		return 2.576
	case confidence >= 0.95:
		return 1.960
	case confidence >= 0.90:
		return 1.645
	case confidence >= 0.80:
		return 1.282
	default:
		return 1.960
	}
}

// addBounds applies confidence interval bounds to predictions.
func addBounds(preds []Prediction, stddev, confidence float64) {
	z := zScore(confidence)
	for i := range preds {
		// Widen bounds as we forecast further out.
		spread := stddev * z * math.Sqrt(1.0+float64(i)/5.0)
		preds[i].LowerBound = preds[i].Value - spread
		preds[i].UpperBound = preds[i].Value + spread
	}
}

// nextTime computes the next time step from a reference time at the given interval.
func nextTime(ref time.Time, interval Interval, steps int) time.Time {
	switch interval {
	case IntervalHour:
		return ref.Add(time.Duration(steps) * time.Hour)
	case IntervalDay:
		return ref.AddDate(0, 0, steps)
	case IntervalWeek:
		return ref.AddDate(0, 0, steps*7)
	case IntervalMonth:
		return ref.AddDate(0, steps, 0)
	default:
		return ref.AddDate(0, 0, steps)
	}
}

// backtest splits data into train/test and measures forecast accuracy.
func backtest(s Series, cfg Config, method Method) (mae, rmse float64) {
	holdout := len(s) / 5
	if holdout < 2 {
		holdout = 2
	}
	if holdout > len(s)-3 {
		return 0, 0
	}

	train := s[:len(s)-holdout]
	test := s[len(s)-holdout:]

	testCfg := cfg
	testCfg.Horizon = holdout
	testCfg.SeasonalWeights = nil

	var preds []Prediction
	var err error
	switch method {
	case MethodLinear:
		preds, _, err = linearForecast(train, testCfg)
	case MethodSMA:
		preds, _, err = smaForecast(train, testCfg)
	case MethodWMA:
		preds, _, err = wmaForecast(train, testCfg)
	case MethodHoltWinters:
		preds, _, err = holtWintersForecast(train, testCfg)
	default:
		return 0, 0
	}
	if err != nil || len(preds) == 0 {
		return 0, 0
	}

	n := len(preds)
	if n > len(test) {
		n = len(test)
	}

	sumAbsErr := 0.0
	sumSqErr := 0.0
	for i := 0; i < n; i++ {
		diff := test[i].V - preds[i].Value
		sumAbsErr += math.Abs(diff)
		sumSqErr += diff * diff
	}

	mae = sumAbsErr / float64(n)
	rmse = math.Sqrt(sumSqErr / float64(n))
	return mae, rmse
}

// selectBestMethod runs all methods via backtest and picks lowest RMSE.
func selectBestMethod(s Series, cfg Config) Method {
	candidates := []Method{MethodLinear, MethodSMA, MethodWMA}
	if len(s) >= 14 {
		candidates = append(candidates, MethodHoltWinters)
	}

	best := MethodLinear
	bestRMSE := math.MaxFloat64

	for _, m := range candidates {
		_, rmse := backtest(s, cfg, m)
		if rmse < bestRMSE {
			bestRMSE = rmse
			best = m
		}
	}

	return best
}

// applySeasonalWeights adjusts prediction values by day-of-week multipliers.
func applySeasonalWeights(preds []Prediction, weights SeasonalWeights) []Prediction {
	for i := range preds {
		dow := preds[i].T.Weekday()
		if w, ok := weights[dow]; ok {
			preds[i].Value *= w
			preds[i].LowerBound *= w
			preds[i].UpperBound *= w
		}
	}
	return preds
}
