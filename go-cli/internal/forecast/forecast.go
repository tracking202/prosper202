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
	// MethodEnsemble combines all applicable methods as a weighted average,
	// weighted by inverse squared recency-discounted rolling-backtest RMSE
	// (see ensembleWeights). MethodAuto is an alias for it.
	MethodEnsemble Method = "ensemble"
	MethodAuto     Method = "auto"
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
//
// Bounds come from rolling-origin conformal prediction: empirical quantiles
// of held-out residuals bucketed by horizon step. LowerBound/UpperBound carry
// the quantile pair nearest the configured confidence level (P10/P90 for the
// default levels — an 80% interval) and are asymmetric by nature. Quantiles
// holds the full set ("p10", "p25", "p50", "p75", "p90"); it is empty when
// the series is too short for a rolling backtest, in which case bounds fall
// back to symmetric Gaussian estimates.
type Prediction struct {
	T          time.Time          `json:"time"`
	Value      float64            `json:"value"`
	LowerBound float64            `json:"lower_bound"`
	UpperBound float64            `json:"upper_bound"`
	Quantiles  map[string]float64 `json:"quantiles,omitempty"`
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
	// Weights reports each member method's share of an ensemble forecast
	// (summing to 1); empty for single-method runs.
	Weights map[string]float64 `json:"weights,omitempty"`
	// Composition reports how a RunCoherent result was produced: "derived"
	// (composed from driver forecasts) or "direct" (the metric's own series
	// forecast directly). Empty for plain Run results.
	Composition string `json:"composition,omitempty"`
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

	// NonNegative marks metrics that cannot go below zero (clicks, leads,
	// cost, income). Prediction values, bounds, and quantiles are clipped
	// at zero.
	NonNegative bool

	// Anchor, when set, is the timestamp predictions step forward from
	// instead of the series' last point. Used when training data has been
	// masked (e.g. event days removed) so predictions still start after
	// the last real observation rather than inside masked history.
	Anchor time.Time
}

// anchorTime returns the reference point predictions step forward from.
func anchorTime(s Series, cfg Config) time.Time {
	if !cfg.Anchor.IsZero() {
		return cfg.Anchor
	}
	return s[len(s)-1].T
}

// anchorOffset returns how many whole interval steps cfg.Anchor lies beyond
// the series' last point (0 when Anchor is unset or not ahead). Trend models
// must advance their projection by this many steps so values line up with
// the anchored timestamps.
func anchorOffset(s Series, cfg Config) int {
	if cfg.Anchor.IsZero() {
		return 0
	}
	last := s[len(s)-1].T
	if !cfg.Anchor.After(last) {
		return 0
	}
	d := cfg.Anchor.Sub(last)
	var step time.Duration
	switch cfg.Interval {
	case IntervalHour:
		step = time.Hour
	case IntervalWeek:
		step = 7 * 24 * time.Hour
	case IntervalMonth:
		step = 30 * 24 * time.Hour // approximate; Anchor is only set for day interval
	default:
		step = 24 * time.Hour
	}
	return int(d / step)
}

// ValidMethods returns all supported method names.
func ValidMethods() []string {
	return []string{
		string(MethodLinear),
		string(MethodSMA),
		string(MethodWMA),
		string(MethodHoltWinters),
		string(MethodEnsemble),
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
		method = MethodEnsemble
	}

	var core forecastCore
	var err error
	if method == MethodEnsemble {
		core, err = computeEnsemble(series, cfg)
	} else {
		core, err = computeSingle(series, cfg, method, nil)
	}
	if err != nil {
		return nil, err
	}

	// Apply seasonal adjustment if weights are provided.
	if len(cfg.SeasonalWeights) > 0 {
		core.preds = applySeasonalWeights(core.preds, cfg.SeasonalWeights)
	}

	if cfg.NonNegative {
		clipNonNegative(core.preds)
	}

	// Compute trend percentage.
	trendPct := 0.0
	if len(series) > 0 {
		mean := seriesMean(series)
		if mean != 0 {
			trendPct = (core.trend / mean) * 100
		}
	}

	return &Result{
		Method:      core.method,
		Metric:      cfg.Metric,
		Horizon:     cfg.Horizon,
		Interval:    cfg.Interval,
		Predictions: core.preds,
		Trend:       core.trend,
		TrendPct:    trendPct,
		MAE:         core.mae,
		RMSE:        core.rmse,
		DataPoints:  len(series),
		Weights:     core.weights,
	}, nil
}

// forecastCore is a computed forecast before the shared post-processing
// (seasonal adjustment, zero clipping, trend percentage) in Run.
type forecastCore struct {
	method    Method
	preds     []Prediction
	trend     float64
	mae, rmse float64
	weights   map[string]float64 // ensemble only
}

// computeSingle produces a single-method forecast with conformal bounds.
// eval may carry a rolling backtest that already covers the method; pass nil
// to have one run here.
func computeSingle(series Series, cfg Config, method Method, eval *rollingEval) (forecastCore, error) {
	predictions, trend, err := methodForecast(method, series, cfg)
	if err != nil {
		return forecastCore{}, err
	}

	if eval == nil {
		eval = runRollingBacktest(series, cfg, []Method{method})
	}
	offset := anchorOffset(series, cfg)
	byStep := eval.residualsByStep(method)
	mae, rmse, _ := eval.errorStats(method)
	if totalResiduals(byStep) >= minTotalResiduals {
		sq := stepQuantiles(byStep, offset+cfg.Horizon)
		applyConformalBounds(predictions, sq, cfg, offset)
	} else {
		// Series too short for a rolling backtest: symmetric Gaussian
		// bounds from a single-holdout residual estimate, and single-split
		// accuracy metrics.
		stddev := residualStdDev(series, method, cfg)
		addBounds(predictions, stddev, cfg.ConfidenceLevel, offset)
		mae, rmse = backtest(series, cfg, method)
	}

	return forecastCore{
		method: method,
		preds:  predictions,
		trend:  trend,
		mae:    mae,
		rmse:   rmse,
	}, nil
}

// computeEnsemble produces the ensemble forecast: a weighted average of all
// applicable methods' point forecasts, weighted and pruned by their
// recency-discounted rolling-backtest RMSE (see ensembleWeights).
// Conformal bounds are computed on the ensemble's own rolling
// residuals, so the band reflects the combined forecaster that is actually
// deployed. When the series is too short for any rolling cut, it falls back
// to the best single method by single-split backtest.
func computeEnsemble(series Series, cfg Config) (forecastCore, error) {
	candidates := autoCandidates(series)
	eval := runRollingBacktest(series, cfg, candidates)
	weights := ensembleWeights(eval, candidates)
	if len(weights) == 0 {
		return computeSingle(series, cfg, selectBestMethod(series, cfg), eval)
	}

	// Fit each member on the full series. A member that fails to fit here
	// (despite backtesting) is dropped and weights renormalize.
	memberPreds := map[Method][]Prediction{}
	memberTrend := map[Method]float64{}
	members := make([]Method, 0, len(weights))
	for _, m := range candidates {
		if _, ok := weights[m]; !ok {
			continue
		}
		preds, tr, err := methodForecast(m, series, cfg)
		if err != nil || len(preds) != cfg.Horizon {
			delete(weights, m)
			continue
		}
		memberPreds[m] = preds
		memberTrend[m] = tr
		members = append(members, m)
	}
	if len(members) == 0 {
		return computeSingle(series, cfg, selectBestMethod(series, cfg), eval)
	}
	normalizeWeights(weights, members)

	// Combine point forecasts and trends.
	combined := make([]Prediction, cfg.Horizon)
	for i := 0; i < cfg.Horizon; i++ {
		combined[i].T = memberPreds[members[0]][i].T
		v := 0.0
		for _, m := range members {
			v += weights[m] * memberPreds[m][i].Value
		}
		combined[i].Value = v
	}
	trend := 0.0
	for _, m := range members {
		trend += weights[m] * memberTrend[m]
	}

	// Conformal bounds on the ensemble's rolling residuals.
	offset := anchorOffset(series, cfg)
	byStep := eval.ensembleResidualsByStep(weights, members)
	mae, rmse := eval.ensembleErrorStats(weights, members)
	if totalResiduals(byStep) >= minTotalResiduals {
		sq := stepQuantiles(byStep, offset+cfg.Horizon)
		applyConformalBounds(combined, sq, cfg, offset)
	} else {
		stddev := seriesStdDev(series)
		addBounds(combined, stddev, cfg.ConfidenceLevel, offset)
	}

	named := make(map[string]float64, len(weights))
	for _, m := range members {
		named[string(m)] = weights[m]
	}

	return forecastCore{
		method:  MethodEnsemble,
		preds:   combined,
		trend:   trend,
		mae:     mae,
		rmse:    rmse,
		weights: named,
	}, nil
}

// ensembleDropFactor excludes members whose recency-weighted rolling RMSE
// exceeds this multiple of the best member's: a clearly-worse method only
// adds noise to the mix. The tight factor keeps the ensemble concentrated on
// near-best methods — a stabilized selection with hedging between equals —
// which measured better out of sample than softer mixes.
const ensembleDropFactor = 1.15

// ensembleWeights derives member weights from the recency-weighted
// rolling-backtest RMSE: w ∝ 1/(rmse + ε)², dropping methods whose RMSE
// exceeds ensembleDropFactor times the best. Returns nil when no candidate
// produced rolling predictions.
func ensembleWeights(eval *rollingEval, candidates []Method) map[Method]float64 {
	const eps = 1e-9
	rmses := eval.recencyRMSE(candidates)
	if len(rmses) == 0 {
		return nil
	}
	best := math.MaxFloat64
	for _, rmse := range rmses {
		if rmse < best {
			best = rmse
		}
	}
	weights := map[Method]float64{}
	for _, m := range candidates {
		rmse, ok := rmses[m]
		if !ok || rmse > ensembleDropFactor*best {
			continue
		}
		// Inverse-MSE (Bates–Granger) weighting on the recency-discounted
		// error, so the mix concentrates on methods that are accurate in the
		// current regime.
		weights[m] = 1 / ((rmse + eps) * (rmse + eps))
	}
	return weights
}

// normalizeWeights scales the members' weights to sum to 1.
func normalizeWeights(weights map[Method]float64, members []Method) {
	sum := 0.0
	for _, m := range members {
		sum += weights[m]
	}
	if sum <= 0 {
		for _, m := range members {
			weights[m] = 1 / float64(len(members))
		}
		return
	}
	for _, m := range members {
		weights[m] /= sum
	}
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

// residualStdDev computes std dev of forecast residuals over the last holdout
// points. Fallback bound estimate for series too short for a rolling backtest.
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
	testCfg.Anchor = time.Time{}

	preds, _, err := methodForecast(method, train, testCfg)
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

// addBounds applies symmetric Gaussian confidence bounds to predictions.
// Fallback for series too short for conformal bounds. offset is the
// number of steps the first prediction lies beyond the series' last point in
// excess of one (see anchorOffset); it widens bounds across anchor gaps.
func addBounds(preds []Prediction, stddev, confidence float64, offset int) {
	z := zScore(confidence)
	for i := range preds {
		// Widen bounds as we forecast further out.
		spread := stddev * z * math.Sqrt(1.0+float64(offset+i)/5.0)
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

// intervalSteps returns the number of interval steps between two times.
// Month steps count whole calendar months; the other intervals divide the
// elapsed duration by the step length.
func intervalSteps(from, to time.Time, interval Interval) float64 {
	switch interval {
	case IntervalHour:
		return to.Sub(from).Hours()
	case IntervalWeek:
		return to.Sub(from).Hours() / (24 * 7)
	case IntervalMonth:
		return float64((to.Year()-from.Year())*12 + int(to.Month()) - int(from.Month()))
	default:
		return to.Sub(from).Hours() / 24
	}
}

// backtest splits data into a single train/test split and measures forecast
// accuracy. Fallback for series too short for a rolling backtest.
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
	testCfg.Anchor = time.Time{}

	preds, _, err := methodForecast(method, train, testCfg)
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

// autoCandidates lists the methods auto-selection considers for a series.
// Holt-Winters needs enough history to stabilize its level/trend estimates.
func autoCandidates(s Series) []Method {
	candidates := []Method{MethodLinear, MethodSMA, MethodWMA}
	if len(s) >= 14 {
		candidates = append(candidates, MethodHoltWinters)
	}
	return candidates
}

// selectBestMethod runs all methods via single-split backtest and picks the
// lowest RMSE. Fallback for series too short for the rolling backtest.
func selectBestMethod(s Series, cfg Config) Method {
	best := MethodLinear
	bestRMSE := math.MaxFloat64

	for _, m := range autoCandidates(s) {
		_, rmse := backtest(s, cfg, m)
		// RMSE=0 means backtest couldn't run (too few points for holdout),
		// not a perfect fit. Skip these candidates.
		if rmse > 0 && rmse < bestRMSE {
			bestRMSE = rmse
			best = m
		}
	}

	return best
}

// scalePrediction multiplies a prediction's value, bounds, and quantiles by
// mult, restoring ordering afterwards: a negative multiplier inverts bound
// and quantile order.
func scalePrediction(p *Prediction, mult float64) {
	p.Value *= mult
	p.LowerBound *= mult
	p.UpperBound *= mult
	if p.LowerBound > p.UpperBound {
		p.LowerBound, p.UpperBound = p.UpperBound, p.LowerBound
	}
	if len(p.Quantiles) == 0 {
		return
	}
	for name := range p.Quantiles {
		p.Quantiles[name] *= mult
	}
	normalizeQuantileOrder(p.Quantiles)
}

// normalizeQuantileOrder re-sorts quantile values so p10 ≤ p25 ≤ ... ≤ p90
// after a transformation that may have inverted them. Only complete quantile
// sets are reordered.
func normalizeQuantileOrder(qs map[string]float64) {
	vals := make([]float64, 0, len(quantileLevels))
	for _, lv := range quantileLevels {
		v, ok := qs[lv.name]
		if !ok {
			return
		}
		vals = append(vals, v)
	}
	sort.Float64s(vals)
	for i, lv := range quantileLevels {
		qs[lv.name] = vals[i]
	}
}

// applySeasonalWeights adjusts prediction values by day-of-week multipliers.
func applySeasonalWeights(preds []Prediction, weights SeasonalWeights) []Prediction {
	for i := range preds {
		dow := preds[i].T.Weekday()
		if w, ok := weights[dow]; ok {
			scalePrediction(&preds[i], w)
		}
	}
	return preds
}
