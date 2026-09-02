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
		rmse := rollingCompare(t, sc.series, []string{MetricLeads, MetricIncome})
		for _, metric := range []string{MetricLeads, MetricIncome} {
			composed, direct := rmse[metric][0], rmse[metric][1]
			total++
			if composed <= direct*1.02 {
				wins++
			}
			t.Logf("%s/%s: composed rmse %.2f vs direct %.2f", sc.name, metric, composed, direct)
		}
	}
	if wins*2 <= total {
		t.Errorf("composition matched direct forecasting on only %d/%d cases, want a majority", wins, total)
	}
}

// rollingCompare measures rolling RMSE of the composed vs direct forecast of
// each requested derived metric over held-out data, running RunCoherent once
// per cut for all of them. The result maps metric -> {composed, direct}.
func rollingCompare(t *testing.T, series map[string]Series, metrics []string) map[string][2]float64 {
	t.Helper()
	sumSqC := map[string]float64{}
	sumSqD := map[string]float64{}
	n := 0
	full := series[metrics[0]]
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
			copy(directIn, series[metric][:c])
			directRes, err := Run(directIn, Config{Horizon: steps, Interval: IntervalDay, NonNegative: true})
			if err != nil {
				t.Fatalf("Run failed at cut %d: %v", c, err)
			}
			for i := 0; i < steps; i++ {
				actual := series[metric][c+i].V
				dc := actual - results[metric].Predictions[i].Value
				dd := actual - directRes.Predictions[i].Value
				sumSqC[metric] += dc * dc
				sumSqD[metric] += dd * dd
			}
		}
		n += steps
	}
	if n == 0 {
		t.Fatal("no rolling evaluations")
	}
	out := map[string][2]float64{}
	for _, metric := range metrics {
		out[metric] = [2]float64{math.Sqrt(sumSqC[metric] / float64(n)), math.Sqrt(sumSqD[metric] / float64(n))}
	}
	return out
}
