package forecast

import (
	"fmt"
	"math"
	"sort"
	"time"
)

// Coherent multi-metric forecasting via ratio decomposition.
//
// Forecasting totals independently can produce internally inconsistent
// results (an income path and a cost path implying an ROI nothing supports).
// RunCoherent instead forecasts drivers — clicks and the rates that link the
// totals (conversion rate, average CPC, average payout), which are far more
// stationary than the totals themselves — and composes the derived metrics
// per timestep:
//
//	leads  = clicks × conv_rate
//	cost   = clicks × avg_cpc
//	income = leads × avg_payout
//	net    = income − cost
//
// so the reported forecasts satisfy those identities exactly.

// Metric keys accepted and returned by RunCoherent, matching the
// reports/timeseries API field names.
const (
	MetricClicks = "total_clicks"
	MetricLeads  = "total_leads"
	MetricIncome = "total_income"
	MetricCost   = "total_cost"
	MetricNet    = "total_net"
)

// CompositionDirect and CompositionDerived label how each RunCoherent result
// was produced (Result.Composition).
const (
	CompositionDirect  = "direct"
	CompositionDerived = "derived"
)

// coherentSparseThreshold is the minimum fraction of clicks buckets a driver
// rate must be defined on; below it the dependent metric falls back to
// direct forecasting rather than composing from a rate estimated on scraps.
const coherentSparseThreshold = 0.7

// RunCoherent forecasts the core metrics coherently. The input map must hold
// the four observed totals series under the MetricClicks/MetricLeads/
// MetricIncome/MetricCost keys (values from the same report buckets);
// driver rates are derived per bucket from those totals, so the composed
// forecasts' identities hold against the series actually supplied.
//
// The returned map holds results for the four totals plus MetricNet. Each
// result's Composition field reports "derived" (composed from drivers) or
// "direct" (fallback when a driver rate is too sparse — e.g. conv_rate
// undefined on many days). Derived results are backtested as compositions:
// their bands, quantiles, and MAE/RMSE come from the composed forecast's
// own rolling residuals against the observed derived series (see
// finishComposed), so a derived p05-p95 band is calibrated the same way a
// direct one is. cfg.Metric, cfg.SeasonalWeights, and cfg.NonNegative are
// ignored: metric-appropriate values are set per driver.
func RunCoherent(series map[string]Series, cfg Config) (map[string]*Result, error) {
	for _, key := range []string{MetricClicks, MetricLeads, MetricIncome, MetricCost} {
		if len(series[key]) < 3 {
			return nil, fmt.Errorf("coherent forecast needs at least 3 data points for %s, got %d", key, len(series[key]))
		}
	}
	cfg = withDefaults(cfg)

	// Work on sorted copies: the anchor below reads the last bucket, and
	// callers (unlike Run's own sort) are not required to pre-sort.
	sorted := make(map[string]Series, 4)
	for _, key := range []string{MetricClicks, MetricLeads, MetricIncome, MetricCost} {
		cp := make(Series, len(series[key]))
		copy(cp, series[key])
		sort.Slice(cp, func(i, j int) bool { return cp[i].T.Before(cp[j].T) })
		sorted[key] = cp
	}
	series = sorted

	clicks := series[MetricClicks]
	// Anchor every forecast at the latest bucket any input carries (or a
	// later configured anchor) so all metrics share prediction timestamps
	// and no forecast starts on a date another metric already observed —
	// a rejected or missing clicks bucket must not hide the newest leads,
	// cost, or income data. anchorOffset bridges each part's own gap.
	anchor := cfg.Anchor
	for _, key := range []string{MetricClicks, MetricLeads, MetricIncome, MetricCost} {
		if last := series[key][len(series[key])-1].T; last.After(anchor) {
			anchor = last
		}
	}
	cfg.Anchor = anchor

	convRate, rateOK := deriveRate(series[MetricLeads], clicks)
	avgCPC, cpcOK := deriveRate(series[MetricCost], clicks)
	avgPayout, payoutOK := deriveRate(series[MetricIncome], series[MetricLeads])

	out := map[string]*Result{}

	clicksRes, err := runCoherentPart(clicks, cfg, MetricClicks, anchor)
	if err != nil {
		return nil, fmt.Errorf("forecasting %s: %w", MetricClicks, err)
	}
	clicksRes.Composition = CompositionDirect
	out[MetricClicks] = clicksRes

	// Leads: clicks × conv_rate, or direct when the rate is too sparse.
	leadsRes, err := composeOrDirect(series[MetricLeads], cfg, MetricLeads, anchor, rateOK,
		func() (*Result, error) {
			rateRes, err := runCoherentPart(convRate, cfg, "conv_rate", anchor)
			if err != nil {
				return nil, err
			}
			return composeProduct(clicksRes, rateRes, MetricLeads, series[MetricLeads], cfg), nil
		})
	if err != nil {
		return nil, fmt.Errorf("forecasting %s: %w", MetricLeads, err)
	}
	out[MetricLeads] = leadsRes

	// Cost: clicks × avg_cpc.
	costRes, err := composeOrDirect(series[MetricCost], cfg, MetricCost, anchor, cpcOK,
		func() (*Result, error) {
			cpcRes, err := runCoherentPart(avgCPC, cfg, "avg_cpc", anchor)
			if err != nil {
				return nil, err
			}
			return composeProduct(clicksRes, cpcRes, MetricCost, series[MetricCost], cfg), nil
		})
	if err != nil {
		return nil, fmt.Errorf("forecasting %s: %w", MetricCost, err)
	}
	out[MetricCost] = costRes

	// Income: leads result × avg_payout. Composing from the reported leads
	// forecast (derived or direct) keeps income = leads × payout in the
	// output either way; only a sparse payout forces income direct.
	incomeRes, err := composeOrDirect(series[MetricIncome], cfg, MetricIncome, anchor, payoutOK,
		func() (*Result, error) {
			payoutRes, err := runCoherentPart(avgPayout, cfg, "avg_payout", anchor)
			if err != nil {
				return nil, err
			}
			return composeProduct(leadsRes, payoutRes, MetricIncome, series[MetricIncome], cfg), nil
		})
	if err != nil {
		return nil, fmt.Errorf("forecasting %s: %w", MetricIncome, err)
	}
	out[MetricIncome] = incomeRes

	// Net: always income − cost, so the identity holds whatever the
	// components' composition.
	out[MetricNet] = composeDifference(incomeRes, costRes, MetricNet, series[MetricIncome], series[MetricCost], cfg)

	return out, nil
}

// deriveRate builds the per-bucket ratio numerator/denominator series,
// keeping only buckets where the denominator is positive, and reports
// whether the result is dense enough to compose from (defined on at least
// coherentSparseThreshold of the denominator's buckets, minimum 3).
func deriveRate(numerator, denominator Series) (Series, bool) {
	byTime := make(map[time.Time]float64, len(numerator))
	for _, p := range numerator {
		byTime[p.T] = p.V
	}
	rate := make(Series, 0, len(denominator))
	for _, p := range denominator {
		num, ok := byTime[p.T]
		if !ok || p.V <= 0 {
			continue
		}
		rate = append(rate, Point{T: p.T, V: num / p.V})
	}
	if len(denominator) == 0 {
		return rate, false
	}
	dense := len(rate) >= 3 &&
		float64(len(rate)) >= coherentSparseThreshold*float64(len(denominator))
	return rate, dense
}

// runCoherentPart forecasts one series with metric-appropriate settings:
// all drivers and totals here are non-negative, and forecasts anchor at the
// shared timestamp so compositions align per step.
func runCoherentPart(s Series, cfg Config, metric string, anchor time.Time) (*Result, error) {
	partCfg := cfg
	partCfg.Metric = metric
	partCfg.NonNegative = true
	partCfg.SeasonalWeights = nil
	partCfg.WeekdayProfile = false
	partCfg.HourlyProfile = false
	partCfg.MonthDayProfile = false
	// Counts get the log1p treatment (multiplicative growth becomes
	// additive); rates and per-unit amounts stay on their natural scale.
	partCfg.LogTransform = metric == MetricClicks || metric == MetricLeads
	partCfg.Anchor = anchor
	in := make(Series, len(s))
	copy(in, s)
	return Run(in, partCfg)
}

// composeOrDirect returns the derived composition when the driver is dense
// enough, and the direct forecast of the metric's own series otherwise.
func composeOrDirect(observed Series, cfg Config, metric string, anchor time.Time, driverDense bool, compose func() (*Result, error)) (*Result, error) {
	if driverDense {
		res, err := compose()
		if err == nil {
			return res, nil
		}
		// A driver that fails to forecast (e.g. too few defined buckets
		// after all) degrades to the direct path rather than failing the
		// whole coherent run.
	}
	res, err := runCoherentPart(observed, cfg, metric, anchor)
	if err != nil {
		return nil, err
	}
	res.Composition = CompositionDirect
	return res, nil
}

// composeProduct multiplies two forecasts per timestep: value×value, and,
// as the starting point finishComposed recalibrates from, P50×P50 with
// conservatively paired band endpoints (lower×lower, upper×upper — valid
// under worst-case positive dependence since both factors are
// non-negative). Quantiles compose only when both operands carry complete
// sets. Trend statistics are recomputed from the composed path against the
// observed derived series.
func composeProduct(a, b *Result, metric string, observed Series, cfg Config) *Result {
	n := len(a.Predictions)
	if len(b.Predictions) < n {
		n = len(b.Predictions)
	}
	preds := make([]Prediction, n)
	for i := 0; i < n; i++ {
		pa, pb := a.Predictions[i], b.Predictions[i]
		preds[i] = Prediction{
			T:          pa.T,
			Value:      pa.Value * pb.Value,
			LowerBound: pa.LowerBound * pb.LowerBound,
			UpperBound: pa.UpperBound * pb.UpperBound,
			Quantiles:  composeQuantiles(pa.Quantiles, pb.Quantiles, false),
		}
	}
	return finishComposed(a, b, preds, metric, observed, cfg,
		func(x, y float64) float64 { return x * y }, true)
}

// composeDifference subtracts forecast b from forecast a per timestep, with
// conservative band pairing as the starting point: lower = a.lower −
// b.upper, upper = a.upper − b.lower. The result can be negative (net
// profit), so nothing is clipped.
func composeDifference(a, b *Result, metric string, observedA, observedB Series, cfg Config) *Result {
	n := len(a.Predictions)
	if len(b.Predictions) < n {
		n = len(b.Predictions)
	}
	preds := make([]Prediction, n)
	for i := 0; i < n; i++ {
		pa, pb := a.Predictions[i], b.Predictions[i]
		preds[i] = Prediction{
			T:          pa.T,
			Value:      pa.Value - pb.Value,
			LowerBound: pa.LowerBound - pb.UpperBound,
			UpperBound: pa.UpperBound - pb.LowerBound,
			Quantiles:  composeQuantiles(pa.Quantiles, pb.Quantiles, true),
		}
	}
	observed := diffSeries(observedA, observedB)
	return finishComposed(a, b, preds, metric, observed, cfg,
		func(x, y float64) float64 { return x - y }, false)
}

// composeQuantiles pairs two complete quantile sets: for products, level
// with level (both operands non-negative keeps the order); for differences,
// level with the mirrored level (p10 = a.p10 − b.p90 etc.), the conservative
// combination. Returns nil unless both sets are complete.
func composeQuantiles(qa, qb map[string]float64, difference bool) map[string]float64 {
	if len(qa) == 0 || len(qb) == 0 {
		return nil
	}
	mirror := map[string]string{"p05": "p95", "p10": "p90", "p25": "p75", "p50": "p50", "p75": "p25", "p90": "p10", "p95": "p05"}
	out := make(map[string]float64, len(quantileLevels))
	for _, lv := range quantileLevels {
		va, okA := qa[lv.name]
		other := lv.name
		if difference {
			other = mirror[lv.name]
		}
		vb, okB := qb[other]
		if !okA || !okB {
			return nil
		}
		if difference {
			out[lv.name] = va - vb
		} else {
			out[lv.name] = va * vb
		}
	}
	normalizeQuantileOrder(out)
	return out
}

// finishComposed assembles a derived Result from its two operands and
// backtests the composition itself. Multiplying or subtracting the
// operands' band endpoints does not yield a band of known coverage (two
// marginal 90% intervals jointly cover as little as 80%, and their product
// under worst-case pairing far more), so the composed point forecast is
// re-evaluated on the operands' paired rolling predictions: wherever both
// operands predicted the same target from the same training end, the
// composed prediction is compared with the observed derived value, and the
// resulting residuals by horizon step give the composed forecast its own
// conformal quantiles, band, and MAE/RMSE — the same calibration a direct
// forecast gets. When too few paired predictions exist, the conservative
// pairing from the caller stands and BoundsSource says "composed".
//
// Trend statistics come from the composed path relative to the observed
// series' mean; a level shift detected in either operand is reported (the
// composition inherits it); masked anomalies are the union of both
// operands' so the excluded observations stay visible; and DataPoints is
// the fewest points either operand was actually fitted on (after masking,
// truncation, and undefined rate buckets), since the observed derived
// series itself is never fitted and the composition is only as informed as
// its thinnest driver.
func finishComposed(a, b *Result, preds []Prediction, metric string, observed Series, cfg Config, combine func(x, y float64) float64, nonNegative bool) *Result {
	trend := predictionTrend(preds, observed)
	trendPct := 0.0
	if mean := seriesMean(observed); mean != 0 {
		trendPct = (trend / mean) * 100
	}
	shiftAt := a.LevelShiftAt
	if shiftAt == "" {
		shiftAt = b.LevelShiftAt
	}

	rolling, byStep, mae, rmse := composeRolling(a, b, observed, cfg.Interval, combine, nonNegative)
	// The composition is only as current as its stalest driver: a payout
	// rate undefined for the last few buckets makes the first composed
	// prediction several steps ahead of that driver's data, so the band
	// offset is measured from the earlier operand's training end, not from
	// where the observed derived series happens to end.
	trainEnd := earlierTrainEnd(a.trainEnd, b.trainEnd)
	source := BoundsComposed
	if totalResiduals(byStep) >= minTotalResiduals {
		offset := composedOffset(trainEnd, cfg)
		sq := stepQuantiles(byStep, offset+len(preds))
		applyConformalBounds(preds, sq, cfg, offset)
		if nonNegative {
			ClipNonNegative(preds)
		}
		source = BoundsConformal
	}

	return &Result{
		Method:          a.Method,
		Metric:          metric,
		Horizon:         a.Horizon,
		Interval:        a.Interval,
		Predictions:     preds,
		Trend:           trend,
		TrendPct:        trendPct,
		MAE:             mae,
		RMSE:            rmse,
		DataPoints:      minInt(a.DataPoints, b.DataPoints),
		Composition:     CompositionDerived,
		LevelShiftAt:    shiftAt,
		AnomaliesMasked: unionSorted(a.AnomaliesMasked, b.AnomaliesMasked),
		BoundsSource:    source,
		rolling:         rolling,
		trainEnd:        trainEnd,
		evaluated:       totalResiduals(byStep) > 0,
	}
}

// earlierTrainEnd returns the earlier of two training ends, ignoring a
// zero value.
func earlierTrainEnd(a, b time.Time) time.Time {
	switch {
	case a.IsZero():
		return b
	case b.IsZero():
		return a
	case b.Before(a):
		return b
	default:
		return a
	}
}

// composedOffset returns how many whole interval steps the shared anchor
// lies beyond the stalest operand's training end (0 when not ahead): the
// composed forecast's first prediction is that many steps further out than
// the data it was built from, so its residual pool is read from that step.
func composedOffset(trainEnd time.Time, cfg Config) int {
	if trainEnd.IsZero() || cfg.Anchor.IsZero() || !cfg.Anchor.After(trainEnd) {
		return 0
	}
	steps := int(math.Round(intervalSteps(trainEnd, cfg.Anchor, cfg.Interval)))
	if steps < 0 {
		return 0
	}
	return steps
}

// composeRolling pairs the operands' rolling-backtest predictions on shared
// (training end, target) keys, composes them, and scores the compositions
// against the observed derived series: it returns the composed rolling
// predictions (so further compositions can be backtested too), residuals
// bucketed by horizon step, and MAE/RMSE over the scored pairs (zero when
// none exist). Pairs whose target has no observation are kept as rolling
// predictions but not scored.
func composeRolling(a, b *Result, observed Series, interval Interval, combine func(x, y float64) float64, nonNegative bool) (rolling map[rollingKey]float64, byStep map[int][]float64, mae, rmse float64) {
	actualAt := make(map[time.Time]float64, len(observed))
	for _, p := range observed {
		actualAt[p.T] = p.V
	}
	rolling = make(map[rollingKey]float64, len(a.rolling))
	byStep = map[int][]float64{}
	sumAbs, sumSq := 0.0, 0.0
	n := 0
	for key, pa := range a.rolling {
		pb, ok := b.rolling[key]
		if !ok {
			continue
		}
		pred := combine(pa, pb)
		if nonNegative && pred < 0 {
			pred = 0
		}
		rolling[key] = pred
		actual, ok := actualAt[key.target]
		if !ok {
			continue
		}
		exact := intervalSteps(key.trainEnd, key.target, interval)
		h := int(math.Round(exact))
		if math.Abs(exact-float64(h)) > 0.01 || h < 1 {
			continue
		}
		diff := actual - pred
		byStep[h] = append(byStep[h], diff)
		sumAbs += math.Abs(diff)
		sumSq += diff * diff
		n++
	}
	if n > 0 {
		mae = sumAbs / float64(n)
		rmse = math.Sqrt(sumSq / float64(n))
	}
	return rolling, byStep, mae, rmse
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// unionSorted merges two timestamp lists without duplicates, in order (the
// formatted timestamps sort chronologically).
func unionSorted(a, b []string) []string {
	if len(a) == 0 && len(b) == 0 {
		return nil
	}
	seen := make(map[string]struct{}, len(a)+len(b))
	out := make([]string, 0, len(a)+len(b))
	for _, list := range [][]string{a, b} {
		for _, s := range list {
			if _, dup := seen[s]; dup {
				continue
			}
			seen[s] = struct{}{}
			out = append(out, s)
		}
	}
	sort.Strings(out)
	return out
}

// diffSeries subtracts series b from series a on matching timestamps.
func diffSeries(a, b Series) Series {
	byTime := make(map[time.Time]float64, len(b))
	for _, p := range b {
		byTime[p.T] = p.V
	}
	out := make(Series, 0, len(a))
	for _, p := range a {
		vb, ok := byTime[p.T]
		if !ok {
			continue
		}
		out = append(out, Point{T: p.T, V: p.V - vb})
	}
	return out
}
