package forecast

import (
	"math"
	"math/rand"
	"testing"
	"time"
)

// makeCoherentSeries builds aligned totals series from per-bucket clicks and
// driver rates: leads = clicks*rate, cost = clicks*cpc, income = leads*payout.
func makeCoherentSeries(n int, clicks func(i int) float64, rate, cpc, payout func(i int) float64) map[string]Series {
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	out := map[string]Series{
		MetricClicks: {}, MetricLeads: {}, MetricIncome: {}, MetricCost: {},
	}
	for i := 0; i < n; i++ {
		t := base.AddDate(0, 0, i)
		c := clicks(i)
		l := c * rate(i)
		out[MetricClicks] = append(out[MetricClicks], Point{T: t, V: c})
		out[MetricLeads] = append(out[MetricLeads], Point{T: t, V: l})
		out[MetricCost] = append(out[MetricCost], Point{T: t, V: c * cpc(i)})
		out[MetricIncome] = append(out[MetricIncome], Point{T: t, V: l * payout(i)})
	}
	return out
}

func constFn(v float64) func(int) float64 { return func(int) float64 { return v } }

func TestRunCoherent_ExactIdentitiesOnCleanData(t *testing.T) {
	// Constant rates and linearly growing clicks: every driver forecast is
	// exact, so the composed metrics must satisfy the identities exactly.
	series := makeCoherentSeries(40,
		func(i int) float64 { return 100 + 10*float64(i) },
		constFn(0.1), constFn(2.0), constFn(50.0))

	results, err := RunCoherent(series, Config{Horizon: 7, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	for _, key := range []string{MetricClicks, MetricLeads, MetricIncome, MetricCost, MetricNet} {
		if results[key] == nil {
			t.Fatalf("missing result for %s", key)
		}
		if len(results[key].Predictions) != 7 {
			t.Fatalf("%s: got %d predictions, want 7", key, len(results[key].Predictions))
		}
	}

	for i := 0; i < 7; i++ {
		clicks := results[MetricClicks].Predictions[i].Value
		leads := results[MetricLeads].Predictions[i].Value
		income := results[MetricIncome].Predictions[i].Value
		cost := results[MetricCost].Predictions[i].Value
		net := results[MetricNet].Predictions[i].Value

		if math.Abs(leads-clicks*0.1) > 1e-6*clicks {
			t.Errorf("step %d: leads %.6f != clicks*rate %.6f", i, leads, clicks*0.1)
		}
		if math.Abs(income-leads*50) > 1e-6*income {
			t.Errorf("step %d: income %.6f != leads*payout %.6f", i, income, leads*50)
		}
		if math.Abs(cost-clicks*2) > 1e-6*cost {
			t.Errorf("step %d: cost %.6f != clicks*cpc %.6f", i, cost, clicks*2)
		}
		if math.Abs(net-(income-cost)) > 1e-9*math.Abs(income) {
			t.Errorf("step %d: net %.6f != income-cost %.6f", i, net, income-cost)
		}
	}
}

func TestRunCoherent_NetIdentityUnderNoise(t *testing.T) {
	rng := rand.New(rand.NewSource(19))
	series := makeCoherentSeries(60,
		func(i int) float64 { return 200 + 40*rng.Float64() },
		func(i int) float64 { return 0.08 + 0.04*rng.Float64() },
		func(i int) float64 { return 1.5 + rng.Float64() },
		func(i int) float64 { return 40 + 20*rng.Float64() })

	results, err := RunCoherent(series, Config{Horizon: 10, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	for i := range results[MetricNet].Predictions {
		income := results[MetricIncome].Predictions[i].Value
		cost := results[MetricCost].Predictions[i].Value
		net := results[MetricNet].Predictions[i].Value
		if math.Abs(net-(income-cost)) > 1e-9*(math.Abs(income)+math.Abs(cost)) {
			t.Errorf("step %d: net %.9f != income-cost %.9f", i, net, income-cost)
		}
	}

	// Derived compositions with dense drivers.
	for _, key := range []string{MetricLeads, MetricIncome, MetricCost, MetricNet} {
		if results[key].Composition != CompositionDerived {
			t.Errorf("%s: composition = %q, want derived", key, results[key].Composition)
		}
	}
	if results[MetricClicks].Composition != CompositionDirect {
		t.Errorf("clicks composition = %q, want direct", results[MetricClicks].Composition)
	}
}

func TestRunCoherent_BoundsAndQuantilesOrdered(t *testing.T) {
	rng := rand.New(rand.NewSource(23))
	series := makeCoherentSeries(60,
		func(i int) float64 { return 150 + 2*float64(i) + 20*rng.Float64() },
		func(i int) float64 { return 0.1 + 0.02*rng.Float64() },
		func(i int) float64 { return 2 + 0.4*rng.Float64() },
		func(i int) float64 { return 50 + 5*rng.Float64() })

	results, err := RunCoherent(series, Config{Horizon: 7, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	order := []string{"p05", "p10", "p25", "p50", "p75", "p90", "p95"}
	for key, res := range results {
		for i, p := range res.Predictions {
			if p.LowerBound > p.UpperBound {
				t.Errorf("%s step %d: lower %.4f > upper %.4f", key, i, p.LowerBound, p.UpperBound)
			}
			if len(p.Quantiles) == 0 {
				continue
			}
			for j := 1; j < len(order); j++ {
				if p.Quantiles[order[j-1]] > p.Quantiles[order[j]]+1e-9 {
					t.Errorf("%s step %d: %s > %s", key, i, order[j-1], order[j])
				}
			}
		}
	}
}

func TestRunCoherent_SparseDriverFallsBackToDirect(t *testing.T) {
	// Clicks are zero on 40% of days, so conv_rate is defined on fewer than
	// 70% of buckets: leads must fall back to direct forecasting. Income
	// still composes (payout defined whenever leads > 0... also sparse here),
	// and net always composes from the other two results.
	rng := rand.New(rand.NewSource(29))
	series := makeCoherentSeries(60,
		func(i int) float64 {
			if i%5 < 2 {
				return 0
			}
			return 100 + 10*rng.Float64()
		},
		constFn(0.1), constFn(2.0), constFn(50.0))

	results, err := RunCoherent(series, Config{Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got := results[MetricLeads].Composition; got != CompositionDirect {
		t.Errorf("leads composition = %q, want direct (sparse conv_rate)", got)
	}
	if got := results[MetricNet].Composition; got != CompositionDerived {
		t.Errorf("net composition = %q, want derived", got)
	}
	// Net must still equal income − cost.
	for i := range results[MetricNet].Predictions {
		income := results[MetricIncome].Predictions[i].Value
		cost := results[MetricCost].Predictions[i].Value
		net := results[MetricNet].Predictions[i].Value
		if math.Abs(net-(income-cost)) > 1e-9 {
			t.Errorf("step %d: net %.9f != income-cost %.9f", i, net, income-cost)
		}
	}
}

func TestRunCoherent_MissingSeriesErrors(t *testing.T) {
	series := makeCoherentSeries(20,
		constFn(100), constFn(0.1), constFn(2), constFn(50))
	delete(series, MetricIncome)
	if _, err := RunCoherent(series, Config{Horizon: 5}); err == nil {
		t.Fatal("expected error for missing income series")
	}
}

func TestRunCoherent_TimestampsAligned(t *testing.T) {
	// The last two buckets have zero clicks, so conv_rate's last defined
	// bucket is older than the series end; predictions must still share
	// timestamps across all metrics, starting after the last bucket.
	series := makeCoherentSeries(40,
		func(i int) float64 {
			if i >= 38 {
				return 0
			}
			return 100
		},
		constFn(0.1), constFn(2.0), constFn(50.0))

	results, err := RunCoherent(series, Config{Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	clicksSeries := series[MetricClicks]
	wantFirst := clicksSeries[len(clicksSeries)-1].T.AddDate(0, 0, 1)
	for key, res := range results {
		if !res.Predictions[0].T.Equal(wantFirst) {
			t.Errorf("%s: first prediction at %v, want %v", key, res.Predictions[0].T, wantFirst)
		}
		for i := range res.Predictions {
			if !res.Predictions[i].T.Equal(results[MetricClicks].Predictions[i].T) {
				t.Errorf("%s: prediction[%d] timestamp misaligned", key, i)
			}
		}
	}
}

// TestCoherentCompositionAccuracy enforces the WS3 acceptance bar: rolling
// backtest accuracy for leads and income via composition is at least as good
// as direct forecasting on the majority of scenarios with stationary drivers.
func TestCoherentCompositionAccuracy(t *testing.T) {
	if testing.Short() {
		t.Skip("skipping rolling-suite comparison in -short mode")
	}
	type scenario struct {
		name   string
		series map[string]Series
	}
	rng1 := rand.New(rand.NewSource(101))
	rng2 := rand.New(rand.NewSource(102))
	rng3 := rand.New(rand.NewSource(103))
	rng4 := rand.New(rand.NewSource(104))
	scenarios := []scenario{
		{"trending_clicks_stable_rate", makeCoherentSeries(90,
			func(i int) float64 { return 200 + 6*float64(i) + rng1.NormFloat64()*25 },
			func(i int) float64 { return 0.10 + 0.02*rng1.NormFloat64() },
			func(i int) float64 { return 2.0 + 0.2*rng1.NormFloat64() },
			func(i int) float64 { return 50 + 4*rng1.NormFloat64() })},
		{"noisy_clicks_stable_rate", makeCoherentSeries(90,
			func(i int) float64 { return 300 + rng2.NormFloat64()*60 },
			func(i int) float64 { return 0.08 + 0.015*rng2.NormFloat64() },
			func(i int) float64 { return 1.8 + 0.15*rng2.NormFloat64() },
			func(i int) float64 { return 45 + 3*rng2.NormFloat64() })},
		{"weekly_clicks", makeCoherentSeries(90,
			func(i int) float64 {
				weekly := []float64{0.7, 1.1, 1.25, 1.2, 1.15, 1.0, 0.6}
				return 250*weekly[i%7] + rng3.NormFloat64()*20
			},
			func(i int) float64 { return 0.12 + 0.02*rng3.NormFloat64() },
			func(i int) float64 { return 2.2 + 0.2*rng3.NormFloat64() },
			func(i int) float64 { return 55 + 4*rng3.NormFloat64() })},
		{"plateau_clicks", makeCoherentSeries(90,
			func(i int) float64 {
				v := 400.0
				if i < 45 {
					v = 150 + (250.0/45.0)*float64(i)
				}
				return v + rng4.NormFloat64()*20
			},
			func(i int) float64 { return 0.09 + 0.015*rng4.NormFloat64() },
			func(i int) float64 { return 2.0 + 0.15*rng4.NormFloat64() },
			func(i int) float64 { return 48 + 4*rng4.NormFloat64() })},
	}

	wins, total := 0, 0
	for _, sc := range scenarios {
		scores := rollingCompare(t, sc.series, []string{MetricLeads, MetricIncome, MetricNet})
		for _, metric := range []string{MetricLeads, MetricIncome} {
			sc0 := scores[metric]
			total++
			if sc0.composedRMSE <= sc0.directRMSE*1.02 {
				wins++
			}
			t.Logf("%s/%s: composed rmse %.2f (p05-p95 coverage %.1f%%) vs direct %.2f (coverage %.1f%%)",
				sc.name, metric, sc0.composedRMSE, sc0.composedCoverage*100, sc0.directRMSE, sc0.directCoverage*100)
		}
		t.Logf("%s/%s: composed rmse %.2f (p05-p95 coverage %.1f%%) vs direct %.2f (coverage %.1f%%)",
			sc.name, MetricNet, scores[MetricNet].composedRMSE, scores[MetricNet].composedCoverage*100,
			scores[MetricNet].directRMSE, scores[MetricNet].directCoverage*100)
	}
	if wins*2 <= total {
		t.Errorf("composition matched direct forecasting on only %d/%d cases, want a majority", wins, total)
	}
}

// rollingScore holds rolling-backtest RMSE and default-band (p05-p95)
// coverage for the composed and the direct forecast of one metric.
type rollingScore struct {
	composedRMSE, directRMSE         float64
	composedCoverage, directCoverage float64
}

// rollingCompare measures rolling RMSE and band coverage of the composed vs
// direct forecast of each requested derived metric over held-out data,
// running RunCoherent once per cut for all of them. Net (income − cost) is
// scored against the observed difference; its direct counterpart is a
// signed forecast of that difference series.
func rollingCompare(t *testing.T, series map[string]Series, metrics []string) map[string]rollingScore {
	t.Helper()
	sumSqC := map[string]float64{}
	sumSqD := map[string]float64{}
	inC := map[string]int{}
	inD := map[string]int{}
	n := 0
	full := series[MetricClicks]
	netSeries := diffSeries(series[MetricIncome], series[MetricCost])
	observedSeries := func(metric string) Series {
		if metric == MetricNet {
			return netSeries
		}
		return series[metric]
	}
	observed := func(metric string, i int) float64 { return observedSeries(metric)[i].V }
	for c := 30; c < len(full); c += 4 {
		steps := len(full) - c
		if steps > 7 {
			steps = 7
		}
		prefix := map[string]Series{}
		for k, s := range series {
			cp := make(Series, c)
			copy(cp, s[:c])
			prefix[k] = cp
		}
		results, err := RunCoherent(prefix, Config{Horizon: steps, Interval: IntervalDay})
		if err != nil {
			t.Fatalf("RunCoherent failed at cut %d: %v", c, err)
		}
		for _, metric := range metrics {
			directIn := make(Series, c)
			copy(directIn, observedSeries(metric)[:c])
			directRes, err := Run(directIn, Config{Horizon: steps, Interval: IntervalDay, NonNegative: metric != MetricNet})
			if err != nil {
				t.Fatalf("Run failed at cut %d: %v", c, err)
			}
			for i := 0; i < steps; i++ {
				actual := observed(metric, c+i)
				pc := results[metric].Predictions[i]
				dc := actual - pc.Value
				sumSqC[metric] += dc * dc
				if actual >= pc.LowerBound && actual <= pc.UpperBound {
					inC[metric]++
				}
				pd := directRes.Predictions[i]
				dd := actual - pd.Value
				sumSqD[metric] += dd * dd
				if actual >= pd.LowerBound && actual <= pd.UpperBound {
					inD[metric]++
				}
			}
		}
		n += steps
	}
	if n == 0 {
		t.Fatal("no rolling evaluations")
	}
	out := map[string]rollingScore{}
	for _, metric := range metrics {
		out[metric] = rollingScore{
			composedRMSE:     math.Sqrt(sumSqC[metric] / float64(n)),
			directRMSE:       math.Sqrt(sumSqD[metric] / float64(n)),
			composedCoverage: float64(inC[metric]) / float64(n),
			directCoverage:   float64(inD[metric]) / float64(n),
		}
	}
	return out
}

// TestComposedBandsCalibrated enforces that derived metrics' bands are
// calibrated like direct ones. Coverage is measured on the rolling suite
// and averaged over seeds (a single 90-point series yields only ~15
// effectively independent cuts). The primary claim is parity: a composed
// p05-p95 band must not trail the direct forecast of the same metric,
// since composition is backtested with the same machinery (both sit in
// the low-to-mid 80s on a growing series, where pooled residuals lag the
// error scale, and the low 90s on a flat one). The absolute window rules
// out the 94–100% the worst-case endpoint pairing produced, needlessly
// wide for alerting, as well as a real shortfall. Full-history results
// must report conformal bounds, quantiles, and rolling errors.
func TestComposedBandsCalibrated(t *testing.T) {
	if testing.Short() {
		t.Skip("skipping rolling-suite calibration in -short mode")
	}
	shapes := []struct {
		name string
		make func(rng *rand.Rand) map[string]Series
	}{
		{"trending", func(rng *rand.Rand) map[string]Series {
			return makeCoherentSeries(90,
				func(i int) float64 { return 200 + 6*float64(i) + rng.NormFloat64()*25 },
				func(i int) float64 { return 0.10 + 0.02*rng.NormFloat64() },
				func(i int) float64 { return 2.0 + 0.2*rng.NormFloat64() },
				func(i int) float64 { return 50 + 4*rng.NormFloat64() })
		}},
		{"noisy_flat", func(rng *rand.Rand) map[string]Series {
			return makeCoherentSeries(90,
				func(i int) float64 { return 300 + rng.NormFloat64()*60 },
				func(i int) float64 { return 0.08 + 0.015*rng.NormFloat64() },
				func(i int) float64 { return 1.8 + 0.15*rng.NormFloat64() },
				func(i int) float64 { return 45 + 3*rng.NormFloat64() })
		}},
	}
	metrics := []string{MetricLeads, MetricCost, MetricIncome, MetricNet}
	seeds := []int64{211, 212, 213}
	for _, shape := range shapes {
		sumC := map[string]float64{}
		sumD := map[string]float64{}
		for _, seed := range seeds {
			scores := rollingCompare(t, shape.make(rand.New(rand.NewSource(seed))), metrics)
			for _, m := range metrics {
				sumC[m] += scores[m].composedCoverage
				sumD[m] += scores[m].directCoverage
			}
		}
		for _, m := range metrics {
			cov := sumC[m] / float64(len(seeds))
			direct := sumD[m] / float64(len(seeds))
			t.Logf("%s/%s: composed p05-p95 coverage %.1f%% vs direct %.1f%% (mean of %d seeds)", shape.name, m, cov*100, direct*100, len(seeds))
			if cov < direct-0.05 {
				t.Errorf("%s/%s: composed coverage %.1f%% trails direct %.1f%%", shape.name, m, cov*100, direct*100)
			}
			if cov < 0.75 || cov > 0.975 {
				t.Errorf("%s/%s: composed coverage %.1f%% is not calibrated to the 90%% band", shape.name, m, cov*100)
			}
		}

		results, err := RunCoherent(shape.make(rand.New(rand.NewSource(seeds[0]))), Config{Horizon: 7, Interval: IntervalDay})
		if err != nil {
			t.Fatalf("%s: %v", shape.name, err)
		}
		for _, m := range metrics {
			res := results[m]
			if res.BoundsSource != BoundsConformal {
				t.Errorf("%s/%s: bounds_source = %q, want conformal (recalibrated on composed residuals)", shape.name, m, res.BoundsSource)
			}
			if res.RMSE <= 0 || res.MAE <= 0 {
				t.Errorf("%s/%s: derived result should carry rolling MAE/RMSE, got %.2f/%.2f", shape.name, m, res.MAE, res.RMSE)
			}
			for i, p := range res.Predictions {
				if len(p.Quantiles) != len(quantileLevels) {
					t.Errorf("%s/%s step %d: %d quantiles, want %d", shape.name, m, i, len(p.Quantiles), len(quantileLevels))
				}
			}
		}
	}
}

func TestRunCoherent_ShortHistoryKeepsComposedBounds(t *testing.T) {
	// Ten points give at most two rolling cuts: too few paired residuals to
	// recalibrate, so the conservative endpoint pairing stands and is
	// labelled as such rather than as a calibrated band.
	series := makeCoherentSeries(10,
		func(i int) float64 { return 100 + 5*float64(i) },
		constFn(0.1), constFn(2.0), constFn(50.0))
	results, err := RunCoherent(series, Config{Horizon: 3, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for _, metric := range []string{MetricLeads, MetricCost, MetricIncome, MetricNet} {
		res := results[metric]
		if res.BoundsSource != BoundsComposed {
			t.Errorf("%s: bounds_source = %q, want composed", metric, res.BoundsSource)
		}
		for i, p := range res.Predictions {
			if p.LowerBound > p.Value+1e-9 || p.UpperBound < p.Value-1e-9 {
				t.Errorf("%s step %d: value %.3f outside [%.3f, %.3f]", metric, i, p.Value, p.LowerBound, p.UpperBound)
			}
		}
	}
	// With the pairing kept, net's band is exactly the worst-corner
	// combination of the income and cost bands.
	for i := range results[MetricNet].Predictions {
		income := results[MetricIncome].Predictions[i]
		cost := results[MetricCost].Predictions[i]
		net := results[MetricNet].Predictions[i]
		if math.Abs(net.LowerBound-(income.LowerBound-cost.UpperBound)) > 1e-9 {
			t.Errorf("step %d: net lower %.4f != income lower − cost upper %.4f", i, net.LowerBound, income.LowerBound-cost.UpperBound)
		}
	}
}

func TestRunCoherent_AnchorsAfterLatestInput(t *testing.T) {
	// The clicks series is missing its newest bucket (rejected upstream)
	// while leads, cost, and income carry it: forecasts must start after
	// the latest observed bucket, not on a date the other metrics already
	// have data for, and stay aligned across metrics.
	series := makeCoherentSeries(40,
		func(i int) float64 { return 100 + float64(i%3) },
		constFn(0.1), constFn(2.0), constFn(50.0))
	series[MetricClicks] = series[MetricClicks][:39]

	results, err := RunCoherent(series, Config{Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	leads := series[MetricLeads]
	wantFirst := leads[len(leads)-1].T.AddDate(0, 0, 1)
	for key, res := range results {
		if !res.Predictions[0].T.Equal(wantFirst) {
			t.Errorf("%s: first prediction at %v, want %v (day after the latest input bucket)", key, res.Predictions[0].T, wantFirst)
		}
		if len(res.Predictions) != 5 {
			t.Errorf("%s: %d predictions, want 5", key, len(res.Predictions))
		}
	}
	// A configured anchor further ahead still wins.
	later := wantFirst.AddDate(0, 0, 2)
	results, err = RunCoherent(series, Config{Horizon: 5, Interval: IntervalDay, Anchor: later})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got := results[MetricNet].Predictions[0].T; !got.Equal(later.AddDate(0, 0, 1)) {
		t.Errorf("configured anchor ignored: first prediction at %v, want %v", got, later.AddDate(0, 0, 1))
	}
}

func TestRunCoherent_ComposedInheritsAnomalies(t *testing.T) {
	// A two-day tracking outage in clicks is masked for the clicks forecast;
	// every metric composed from it must report the same masked dates, so
	// an agent reading only total_leads still learns which observations
	// were excluded.
	rng := rand.New(rand.NewSource(31))
	series := makeCoherentSeries(60,
		func(i int) float64 {
			if i == 40 || i == 41 {
				return 0
			}
			return 500 + rng.NormFloat64()*20
		},
		constFn(0.1), constFn(2.0), constFn(50.0))

	results, err := RunCoherent(series, Config{Horizon: 5, Interval: IntervalDay})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	want := results[MetricClicks].AnomaliesMasked
	if len(want) != 2 {
		t.Fatalf("clicks masked %v, want the two outage days", want)
	}
	for _, metric := range []string{MetricLeads, MetricCost, MetricIncome, MetricNet} {
		got := results[metric].AnomaliesMasked
		for _, ts := range want {
			found := false
			for _, g := range got {
				if g == ts {
					found = true
				}
			}
			if !found {
				t.Errorf("%s: masked anomalies %v missing %s inherited from clicks", metric, got, ts)
			}
		}
		for i := 1; i < len(got); i++ {
			if got[i] <= got[i-1] {
				t.Errorf("%s: masked anomalies not sorted/deduplicated: %v", metric, got)
			}
		}
	}
}

func TestUnionSorted(t *testing.T) {
	got := unionSorted([]string{"2026-03-02", "2026-03-01"}, []string{"2026-03-02", "2026-02-28"})
	want := []string{"2026-02-28", "2026-03-01", "2026-03-02"}
	if len(got) != len(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("got %v, want %v", got, want)
		}
	}
	if unionSorted(nil, nil) != nil {
		t.Error("union of nothing should be nil (omitted from JSON)")
	}
}
