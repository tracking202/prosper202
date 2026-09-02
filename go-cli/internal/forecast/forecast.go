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
// the quantile pair nearest the configured confidence level (P05/P95 for the
// 0.95 default — a 90% interval, see boundPair) and are asymmetric by
// nature. Quantiles holds the full set ("p05" … "p95"); it is empty when the
// series is too short for a rolling backtest, in which case bounds fall back
// to symmetric Gaussian estimates.
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
	// LevelShiftAt is the timestamp of the first observation after a
	// detected level shift (offer paused, traffic source added). When set,
	// pre-shift history was truncated or re-leveled before fitting.
	LevelShiftAt string `json:"level_shift_at,omitempty"`
	// AnomaliesMasked lists the timestamps of transient outliers (short
	// runs abnormal for this series at that point of its cycle) that were
	// excluded from fitting. The alerting layer should still compare the
	// observed values on those dates against the bands.
	AnomaliesMasked []string `json:"anomalies_masked,omitempty"`
	// SeasonalApplied reports whether any supplied seasonal profile
	// actually adjusted the predictions: weekday and hourly weights are
	// gated on detrended autocorrelation at the seasonal lag, so weights
	// built from a series with no real weekly/daily structure are ignored.
	// SeasonalProfiles names the profiles that applied ("weekday",
	// "hourly", "monthday").
	SeasonalApplied  bool     `json:"seasonal_applied,omitempty"`
	SeasonalProfiles []string `json:"seasonal_profiles,omitempty"`
	// BoundsSource reports how bounds were produced: "conformal" (rolling
	// residual quantiles — for RunCoherent derived metrics, residuals of the
	// composed forecast itself), "gaussian" (short-series symmetric
	// fallback), or "composed" (a derived metric whose operands had too few
	// paired rolling predictions to recalibrate: its band pairs the
	// operands' band endpoints at worst-case dependence, which is valid but
	// carries no single nominal coverage).
	BoundsSource string `json:"bounds_source,omitempty"`

	// rolling holds the deployed forecaster's rolling-backtest predictions
	// on the reporting scale (transform inverted, zero-clipped like the
	// output, no seasonal profile), keyed by the training window they were
	// made from and the point they targeted. RunCoherent composes operands'
	// entries to backtest derived metrics (see finishComposed).
	rolling map[rollingKey]float64
}

// Bounds sources reported in Result.BoundsSource.
const (
	BoundsConformal = "conformal"
	BoundsGaussian  = "gaussian"
	BoundsComposed  = "composed"
)

// rollingKey identifies one rolling-backtest prediction by the last training
// timestamp and the target timestamp. Timestamps are compared as map keys,
// so operands must share the calendar the report buckets were parsed in
// (they do: RunCoherent derives every series from the same buckets).
type rollingKey struct {
	trainEnd, target time.Time
}

// SeasonalWeights maps day-of-week (time.Weekday) to a multiplier.
// A weight of 1.0 means average; 1.2 means 20% above average for that day.
type SeasonalWeights map[time.Weekday]float64

// Config controls a forecast run.
type Config struct {
	Method    Method
	Horizon   int
	Interval  Interval
	Metric    string
	SMAWindow int
	// SeasonalWeights supplies explicit day-of-week multipliers, applied as
	// given (after the weekly gate) to the deployed fit and to every
	// backtest fold alike. Supply weights estimated independently of the
	// fitted history (a prior period, a business calendar): weights derived
	// from the history itself would leak held-out values into the folds
	// that score them — set WeekdayProfile for that instead.
	SeasonalWeights SeasonalWeights
	ConfidenceLevel float64 // 0.0-1.0, default 0.95

	// NonNegative marks metrics that cannot go below zero (clicks, leads,
	// cost, income). Prediction values, bounds, and quantiles are clipped
	// at zero.
	NonNegative bool

	// WeekdayProfile, HourlyProfile, and MonthDayProfile derive the
	// corresponding multiplier profile from the training data itself,
	// re-estimated (with shrinkage) from each training prefix — the
	// deployed fit and every backtest fold — so bands and errors describe
	// the profiled forecaster without leaking held-out points into their
	// own multiplier. The weekday and hourly profiles are gated on
	// detrended autocorrelation at their lag (measured on the same prefix);
	// the day-of-month profile is an explicit opt-in with no gate.
	// HourlyProfile applies to the hour interval only. Multiplicative
	// profiles need non-negative data: a prefix with negative values gets
	// none. WeekdayProfile is ignored when SeasonalWeights are supplied.
	WeekdayProfile  bool
	HourlyProfile   bool
	MonthDayProfile bool

	// LogTransform fits count-like non-negative series on log1p scale and
	// inverts on output, making multiplicative behavior additive and
	// stabilizing variance. Ignored when the series has negative values.
	LogTransform bool

	// DisableLevelShift turns off level-shift detection, so the full
	// history is always fitted as-is. Useful when the detector misreads a
	// known transient (an untagged outage or promo) as a regime change.
	DisableLevelShift bool

	// DisableAnomalyMask turns off transient masking (see anomaly.go), so
	// short outlier runs — a tracking outage, an untagged spike — are fitted
	// as data rather than looked through. Masking applies to NonNegative
	// series only; signed metrics can legitimately swing.
	DisableAnomalyMask bool

	// AnomalySigma is the deviation, in robust sigma units, beyond which a
	// point counts as a transient (default DefaultAnomalySigma); lower it
	// to mask more aggressively, raise it to mask only extreme departures.
	// AnomalyCycles is how many seasonal cycles on each side supply the
	// same-weekday/hour reference values (default DefaultAnomalyCycles).
	AnomalySigma  float64
	AnomalyCycles int

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
// the anchored timestamps. Steps are counted on the calendar (whole months
// for the month interval), since RunCoherent anchors at every interval.
func anchorOffset(s Series, cfg Config) int {
	if cfg.Anchor.IsZero() {
		return 0
	}
	last := s[len(s)-1].T
	if !cfg.Anchor.After(last) {
		return 0
	}
	steps := int(math.Round(intervalSteps(last, cfg.Anchor, cfg.Interval)))
	if steps < 0 {
		return 0
	}
	return steps
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

// withDefaults fills the Config fields Run and RunCoherent share defaults
// for, so composed forecasts use the same horizon, interval, and band pair
// as the parts they are built from.
func withDefaults(cfg Config) Config {
	if cfg.Horizon <= 0 {
		cfg.Horizon = 7
	}
	if cfg.Interval == "" {
		cfg.Interval = IntervalDay
	}
	if cfg.ConfidenceLevel <= 0 || cfg.ConfidenceLevel >= 1 {
		cfg.ConfidenceLevel = 0.95
	}
	return cfg
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

	cfg = withDefaults(cfg)

	method := cfg.Method
	if method == "" || method == MethodAuto {
		method = MethodEnsemble
	}

	// The original-scale mean anchors the trend percentage regardless of
	// transforms and truncation below.
	originalMean := seriesMean(series)

	// Optional log1p transform: fit multiplicative count series on log
	// scale, invert on output. Skipped when negative values make the
	// transform undefined.
	work := series
	logApplied := false
	if cfg.LogTransform && seriesAllNonNegative(work) {
		work = log1pSeries(work)
		logApplied = true
	} else {
		cfg.LogTransform = false // downstream error stats stay untransformed
	}

	// Transient masking: short outlier runs are excluded from fitting and
	// reported. When the series' last observations are masked, predictions
	// must still start after them, so the anchor moves to the true end.
	var anomalies []string
	if cfg.NonNegative && !cfg.DisableAnomalyMask {
		if idx := detectTransients(work, cfg.Interval, logApplied, cfg.AnomalySigma, cfg.AnomalyCycles); len(idx) > 0 {
			for _, i := range idx {
				anomalies = append(anomalies, formatShiftTime(work[i].T, cfg.Interval))
			}
			last := work[len(work)-1].T
			work = maskIndices(work, idx)
			if cfg.Anchor.IsZero() || cfg.Anchor.Before(last) {
				cfg.Anchor = last
			}
		}
	}

	// Level-shift handling: fit on the current regime, not a blend of
	// regimes. Post-shift history is used alone when long enough; otherwise
	// trainingView re-levels older observations to the new regime. Both the
	// deployed fit and every backtest prefix run their own detection
	// (trainingView), so this full-series pass only reports the shift and
	// trims the data the run describes.
	levelShiftAt := ""
	if !cfg.DisableLevelShift {
		if ls := detectLevelShift(work); ls != nil {
			levelShiftAt = formatShiftTime(work[ls.idx].T, cfg.Interval)
			if ls.truncates(len(work), cfg.Interval) {
				work = work[ls.idx:]
			}
		}
	}

	// Window and data-point count describe the data actually modeled.
	if cfg.SMAWindow <= 0 {
		cfg.SMAWindow = defaultSMAWindow(len(work))
	}

	// Seasonal profiles are estimated from the training data by every fit
	// (profileFor): the deployed fit here and each backtest fold on its own
	// prefix, so the bands calibrate the profiled forecaster that is
	// actually emitted. Weekday and hourly profiles are gated on detrended
	// autocorrelation at their lag so spurious profiles (built from noise)
	// don't degrade the forecast; when the history is too short to measure
	// that lag there is no evidence against the profile and it applies.
	// The names reported in meta are the deployed fit's.
	_, profiles := profileFor(work, cfg, len(work))

	var core forecastCore
	var err error
	if method == MethodEnsemble {
		core, err = computeEnsemble(work, cfg)
	} else {
		core, err = computeSingle(work, cfg, method, nil)
	}
	if err != nil {
		return nil, err
	}

	if logApplied {
		invertLogPredictions(core.preds)
		// The model's trend is a per-period change on log scale, i.e. a
		// multiplicative rate; express it as an absolute change at the
		// first forecast level so every method keeps its own semantics.
		core.trend = math.Expm1(core.trend) * core.preds[0].Value
	}

	// Keep the deployed forecaster's rolling predictions on the reporting
	// scale so compositions of this result can be backtested too.
	var rolling map[rollingKey]float64
	if core.eval != nil && core.predict != nil {
		rolling = make(map[rollingKey]float64, len(core.eval.rows))
		for _, r := range core.eval.rows {
			pred, ok := core.predict(r)
			if !ok {
				continue
			}
			if logApplied {
				pred = math.Expm1(pred)
			}
			if cfg.NonNegative && pred < 0 {
				pred = 0
			}
			rolling[rollingKey{trainEnd: r.trainEnd, target: r.t}] = pred
		}
	}

	if cfg.NonNegative {
		ClipNonNegative(core.preds)
	}

	// Compute trend percentage.
	trendPct := 0.0
	if originalMean != 0 {
		trendPct = (core.trend / originalMean) * 100
	}

	return &Result{
		Method:           core.method,
		Metric:           cfg.Metric,
		Horizon:          cfg.Horizon,
		Interval:         cfg.Interval,
		Predictions:      core.preds,
		Trend:            core.trend,
		TrendPct:         trendPct,
		MAE:              core.mae,
		RMSE:             core.rmse,
		DataPoints:       len(work),
		Weights:          core.weights,
		Composition:      "",
		LevelShiftAt:     levelShiftAt,
		AnomaliesMasked:  anomalies,
		SeasonalApplied:  len(profiles) > 0,
		SeasonalProfiles: profiles,
		BoundsSource:     core.boundsSource,
		rolling:          rolling,
	}, nil
}

// seasonalGateAllows reports whether a seasonal profile at the given lag
// may be applied: yes when the detrended autocorrelation at that lag clears
// the threshold, and also when the history is too short to measure it (no
// evidence against the profile).
func seasonalGateAllows(s Series, lag time.Duration) bool {
	ac, ok := seasonalLagAutocorrelation(s, lag)
	return !ok || ac >= seasonalGateThreshold
}

// seriesAllNonNegative reports whether every value is >= 0.
func seriesAllNonNegative(s Series) bool {
	for _, p := range s {
		if p.V < 0 {
			return false
		}
	}
	return true
}

// log1pSeries returns a copy of the series with values on log1p scale.
func log1pSeries(s Series) Series {
	out := make(Series, len(s))
	for i, p := range s {
		out[i] = Point{T: p.T, V: math.Log1p(p.V)}
	}
	return out
}

// invertLogPredictions maps predictions fitted on log1p scale back to the
// original scale. expm1 is monotone, so bound and quantile order is kept.
func invertLogPredictions(preds []Prediction) {
	for i := range preds {
		preds[i].Value = math.Expm1(preds[i].Value)
		preds[i].LowerBound = math.Expm1(preds[i].LowerBound)
		preds[i].UpperBound = math.Expm1(preds[i].UpperBound)
		for name, v := range preds[i].Quantiles {
			preds[i].Quantiles[name] = math.Expm1(v)
		}
	}
}

// predictionTrend estimates the per-period trend from a forecast path
// (used for composed metrics, which have no model of their own).
func predictionTrend(preds []Prediction, original Series) float64 {
	switch {
	case len(preds) > 1:
		return (preds[len(preds)-1].Value - preds[0].Value) / float64(len(preds)-1)
	case len(preds) == 1 && len(original) > 0:
		return preds[0].Value - original[len(original)-1].V
	default:
		return 0
	}
}

// forecastCore is a computed forecast before the shared post-processing
// (seasonal adjustment, zero clipping, trend percentage) in Run.
type forecastCore struct {
	method       Method
	preds        []Prediction
	trend        float64
	mae, rmse    float64
	weights      map[string]float64 // ensemble only
	boundsSource string
	// eval and predict expose the rolling backtest and the deployed
	// forecaster's view of it (single method or weighted ensemble).
	eval    *rollingEval
	predict rowPredictor
}

// computeSingle produces a single-method forecast with conformal bounds.
// eval may carry a rolling backtest that already covers the method; pass nil
// to have one run here.
func computeSingle(series Series, cfg Config, method Method, eval *rollingEval) (forecastCore, error) {
	predictions, trend, err := methodForecast(method, trainingView(series, cfg, len(series)), cfg)
	if err != nil {
		return forecastCore{}, err
	}
	profile, _ := profileFor(series, cfg, len(series))
	applyProfile(predictions, profile, cfg.LogTransform)

	if eval == nil {
		eval = runRollingBacktest(series, cfg, []Method{method})
	}
	offset := anchorOffset(series, cfg)
	predict := eval.methodPredictor(method)
	byStep := eval.residualsByStep(predict)
	mae, rmse, _ := eval.errorStats(predict)
	source := BoundsConformal
	if totalResiduals(byStep) >= minTotalResiduals {
		sq := stepQuantiles(byStep, offset+cfg.Horizon)
		applyConformalBounds(predictions, sq, cfg, offset)
	} else {
		// Series too short for a rolling backtest: symmetric Gaussian
		// bounds from a single-holdout residual estimate, and single-split
		// accuracy metrics — one fit serves both.
		var stddev float64
		stddev, mae, rmse = holdoutEval(series, cfg, method)
		addBounds(predictions, stddev, cfg.ConfidenceLevel, offset)
		source = BoundsGaussian
	}

	return forecastCore{
		method:       method,
		preds:        predictions,
		trend:        trend,
		mae:          mae,
		rmse:         rmse,
		boundsSource: source,
		eval:         eval,
		predict:      predict,
	}, nil
}

// computeEnsemble produces the ensemble forecast: a weighted average of all
// applicable methods' point forecasts, weighted and pruned by their
// recency-discounted rolling-backtest RMSE (see ensembleWeights).
// Conformal bounds and error statistics come from the ensemble procedure's
// own rolling residuals, evaluated out of sample: each backtest row is
// predicted with weights derived only from rows observed before it (see
// nestedEnsemblePredictor), so the band reflects the combined forecaster
// that is actually deployed without letting a row's own actual shape the
// weights that predict it. When the series is too short for any rolling
// cut, it falls back to the best single method by single-split backtest.
func computeEnsemble(series Series, cfg Config) (forecastCore, error) {
	candidates := autoCandidates(series)
	eval := runRollingBacktest(series, cfg, candidates)
	weights := ensembleWeights(eval, candidates, nil)
	if len(weights) == 0 {
		return computeSingle(series, cfg, selectBestMethod(series, cfg), eval)
	}

	// Fit each member on the full series. A member that fails to fit here
	// (despite backtesting) is dropped and weights renormalize.
	profile, _ := profileFor(series, cfg, len(series))
	memberPreds := map[Method][]Prediction{}
	memberTrend := map[Method]float64{}
	members := make([]Method, 0, len(weights))
	for _, m := range candidates {
		if _, ok := weights[m]; !ok {
			continue
		}
		preds, tr, err := methodForecast(m, trainingView(series, cfg, len(series)), cfg)
		if err != nil || len(preds) != cfg.Horizon {
			delete(weights, m)
			continue
		}
		applyProfile(preds, profile, cfg.LogTransform)
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

	// Conformal bounds on the ensemble procedure's out-of-sample rolling
	// residuals.
	offset := anchorOffset(series, cfg)
	predict := nestedEnsemblePredictor(eval, candidates)
	byStep := eval.residualsByStep(predict)
	mae, rmse, _ := eval.errorStats(predict)
	source := BoundsConformal
	if totalResiduals(byStep) >= minTotalResiduals {
		sq := stepQuantiles(byStep, offset+cfg.Horizon)
		applyConformalBounds(combined, sq, cfg, offset)
	} else {
		stddev := seriesStdDev(series)
		addBounds(combined, stddev, cfg.ConfidenceLevel, offset)
		source = BoundsGaussian
	}

	named := make(map[string]float64, len(weights))
	for _, m := range members {
		named[string(m)] = weights[m]
	}

	return forecastCore{
		method:       MethodEnsemble,
		preds:        combined,
		trend:        trend,
		mae:          mae,
		rmse:         rmse,
		weights:      named,
		boundsSource: source,
		eval:         eval,
		predict:      predict,
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
// exceeds ensembleDropFactor times the best. include, when non-nil,
// restricts the rows the RMSE is measured on. Returns nil when no candidate
// produced rolling predictions on those rows.
func ensembleWeights(eval *rollingEval, candidates []Method, include func(evalRow) bool) map[Method]float64 {
	const eps = 1e-9
	rmses := eval.recencyRMSE(candidates, include)
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

// nestedEnsemblePredictor evaluates the ensemble procedure out of sample.
// Deriving weights from the rolling rows and then scoring those same rows
// with them would let each residual's own actual influence the weights that
// produced it, flattering MAE/RMSE and narrowing the band below its
// nominal coverage, most visibly when the pruning decision hinges on a few
// rows. Instead a row from cut c is predicted with weights derived only
// from rows whose targets were observed by that cut's training end, which
// is what the procedure would have had at the time. The earliest cut, with
// no history to weight from, uses equal weights over the members that
// predicted it. Weights are cached per cut.
func nestedEnsemblePredictor(e *rollingEval, candidates []Method) rowPredictor {
	cache := map[int]map[Method]float64{}
	return func(r evalRow) (float64, bool) {
		w, ok := cache[r.cut]
		if !ok {
			trainEnd := r.trainEnd
			w = ensembleWeights(e, candidates, func(o evalRow) bool { return !o.t.After(trainEnd) })
			if len(w) == 0 {
				w = make(map[Method]float64, len(candidates))
				for _, m := range candidates {
					w[m] = 1
				}
			}
			cache[r.cut] = w
		}
		return ensembleCombine(r, w, candidates)
	}
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

// seriesValues extracts the values of a series.
func seriesValues(s Series) []float64 {
	out := make([]float64, len(s))
	for i, p := range s {
		out[i] = p.V
	}
	return out
}

// seriesMean returns the arithmetic mean of all values.
func seriesMean(s Series) float64 {
	return mean(seriesValues(s))
}

// seriesStdDev returns the population standard deviation.
func seriesStdDev(s Series) float64 {
	return stddev(seriesValues(s))
}

// holdoutEval fits the method once on a single train/test split (the last
// fifth of the series, minimum 2 points) and returns the residual standard
// deviation on the model scale (for Gaussian fallback bounds) plus MAE and
// RMSE on the reporting scale. It is the fallback for series too short for
// a rolling backtest; when even the single split is impossible it returns
// the series' own standard deviation and zero errors.
func holdoutEval(s Series, cfg Config, method Method) (stddev, mae, rmse float64) {
	holdout := len(s) / 5
	if holdout < 2 {
		holdout = 2
	}
	if holdout > len(s)-3 {
		return seriesStdDev(s), 0, 0
	}

	c := len(s) - holdout
	train := trainingView(s, cfg, c)
	trainEnd := train[len(train)-1].T
	test := s[c:]

	// Held-out points are matched to prediction steps by calendar distance
	// (masked gaps make it exceed the observation count), so the horizon
	// reaches the farthest held-out point.
	testCfg := cfg
	testCfg.Horizon = calendarSteps(trainEnd, test[len(test)-1].T, cfg.Interval)
	testCfg.Anchor = time.Time{}

	preds, _, err := methodForecast(method, train, testCfg)
	if err != nil || len(preds) == 0 {
		return seriesStdDev(s), 0, 0
	}
	profile, _ := profileFor(s, cfg, c)
	applyProfile(preds, profile, cfg.LogTransform)

	n := 0
	sumSqModel, sumAbs, sumSq := 0.0, 0.0, 0.0
	for _, p := range test {
		h, ok := stepIndex(trainEnd, p.T, cfg.Interval, len(preds))
		if !ok {
			continue
		}
		actual, pred := p.V, preds[h-1].Value
		modelDiff := actual - pred
		sumSqModel += modelDiff * modelDiff
		if cfg.LogTransform {
			// Values are on log1p scale; report errors on the original.
			actual, pred = math.Expm1(actual), math.Expm1(pred)
		}
		diff := actual - pred
		sumAbs += math.Abs(diff)
		sumSq += diff * diff
		n++
	}
	if n == 0 {
		return seriesStdDev(s), 0, 0
	}
	fn := float64(n)
	return math.Sqrt(sumSqModel / fn), sumAbs / fn, math.Sqrt(sumSq / fn)
}

// calendarSteps returns the number of whole interval steps from from to to,
// at least 1.
func calendarSteps(from, to time.Time, interval Interval) int {
	steps := int(math.Round(intervalSteps(from, to, interval)))
	if steps < 1 {
		return 1
	}
	return steps
}

// stepIndex maps a held-out timestamp to its 1-based horizon step from
// trainEnd; ok is false when it is not a whole step away or lies beyond
// maxStep.
func stepIndex(trainEnd, t time.Time, interval Interval, maxStep int) (int, bool) {
	exact := intervalSteps(trainEnd, t, interval)
	h := int(math.Round(exact))
	if math.Abs(exact-float64(h)) > 0.01 || h < 1 || h > maxStep {
		return 0, false
	}
	return h, true
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

// autoCandidates lists the methods auto-selection considers for a series.
// Holt-Winters needs enough history to stabilize its level/trend estimates.
func autoCandidates(s Series) []Method {
	candidates := []Method{MethodLinear, MethodSMA, MethodWMA}
	if len(s) >= holtWintersMinPoints {
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
		_, _, rmse := holdoutEval(s, cfg, m)
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

// applyProfile scales point predictions by a seasonal profile (nil for
// none) for their timestamps. A profile is multiplicative on the reporting
// scale, so under the log1p transform it is applied through the transform
// (a model-scale value whose reporting value is not positive is left alone:
// scaling it is meaningless and the output clip zeroes it anyway). Bounds
// and quantiles are attached afterwards, so they calibrate the adjusted
// forecaster; the same call runs inside every backtest fold with that
// fold's own profile.
func applyProfile(preds []Prediction, profile func(time.Time) float64, logScale bool) {
	if profile == nil {
		return
	}
	for i := range preds {
		w := profile(preds[i].T)
		if w == 1 {
			continue
		}
		if !logScale {
			preds[i].Value *= w
			continue
		}
		if v := math.Expm1(preds[i].Value); v > 0 {
			preds[i].Value = math.Log1p(v * w)
		}
	}
}

// expm1Series maps a log1p-scale series back to the reporting scale.
func expm1Series(s Series) Series {
	out := make(Series, len(s))
	for i, p := range s {
		out[i] = Point{T: p.T, V: math.Expm1(p.V)}
	}
	return out
}
