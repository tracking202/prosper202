package forecast

import (
	"fmt"
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
// undefined on many days). Derived results compose quantiles pairwise
// (P50 from P50s; band endpoints combine conservatively, assuming
// worst-case dependence between factors, so composed bands are valid but
// can over-cover); their MAE/RMSE are zero since rolling errors are only
// measured for directly fitted series. cfg.Metric, cfg.SeasonalWeights, and
// cfg.NonNegative are ignored: metric-appropriate values are set per driver.
func RunCoherent(series map[string]Series, cfg Config) (map[string]*Result, error) {
	for _, key := range []string{MetricClicks, MetricLeads, MetricIncome, MetricCost} {
		if len(series[key]) < 3 {
			return nil, fmt.Errorf("coherent forecast needs at least 3 data points for %s, got %d", key, len(series[key]))
		}
	}

	clicks := series[MetricClicks]
	// Anchor every forecast at the clicks series' last bucket so all metrics
	// share prediction timestamps even when a driver's last defined bucket
	// is older (anchorOffset bridges the gap).
	anchor := clicks[len(clicks)-1].T
	if !cfg.Anchor.IsZero() && cfg.Anchor.After(anchor) {
		anchor = cfg.Anchor
	}

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
	partCfg.HourlyWeights = nil
	partCfg.MonthDayWeights = nil
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

// composeProduct multiplies two forecasts per timestep: value×value,
// P50×P50, and conservatively paired band endpoints (lower×lower,
// upper×upper — exact under worst-case positive dependence since both
// factors are non-negative). Quantiles compose only when both operands
// carry complete sets. Trend statistics are recomputed from the composed
// path against the observed derived series.
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
	return finishComposed(a, preds, metric, observed, cfg)
}

// composeDifference subtracts forecast b from forecast a per timestep with
// conservative band pairing: lower = a.lower − b.upper, upper = a.upper −
// b.lower. The result can be negative (net profit), so nothing is clipped.
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
	return finishComposed(a, preds, metric, observed, cfg)
}

// composeQuantiles pairs two complete quantile sets: for products, level
// with level (both operands non-negative keeps the order); for differences,
// level with the mirrored level (p10 = a.p10 − b.p90 etc.), the conservative
// combination. Returns nil unless both sets are complete.
func composeQuantiles(qa, qb map[string]float64, difference bool) map[string]float64 {
	if len(qa) == 0 || len(qb) == 0 {
		return nil
	}
	mirror := map[string]string{"p10": "p90", "p25": "p75", "p50": "p50", "p75": "p25", "p90": "p10"}
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

// finishComposed assembles a derived Result: trend statistics come from the
// composed path (mean step-to-step change) relative to the observed series'
// mean, and rolling error metrics are left zero — they are only measured
// for directly fitted series.
func finishComposed(template *Result, preds []Prediction, metric string, observed Series, cfg Config) *Result {
	trend := 0.0
	if len(preds) > 1 {
		trend = (preds[len(preds)-1].Value - preds[0].Value) / float64(len(preds)-1)
	}
	trendPct := 0.0
	if mean := seriesMean(observed); mean != 0 {
		trendPct = (trend / mean) * 100
	}
	return &Result{
		Method:      template.Method,
		Metric:      metric,
		Horizon:     template.Horizon,
		Interval:    template.Interval,
		Predictions: preds,
		Trend:       trend,
		TrendPct:    trendPct,
		DataPoints:  len(observed),
		Composition: CompositionDerived,
	}
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
