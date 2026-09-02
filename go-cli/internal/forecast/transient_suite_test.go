package forecast

import (
	"fmt"
	"math"
	"math/rand"
	"testing"
	"time"
)

// transientSuite returns representative series that contain transients or
// patterns that look like transients but are not: sparse tracking outages,
// one-off spikes, a business closed on Sundays, and a low-volume tracker.
// Deterministic (fixed seeds).
func transientSuite() (map[string]Series, []string) {
	base := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	out := map[string]Series{}
	names := []string{"outages", "spikes", "outage_on_trend", "closed_sundays", "poisson_low", "weekly_with_outage"}

	rng := rand.New(rand.NewSource(201))
	out["outages"] = makeSeries(120, func(i int) float64 {
		if i == 25 || i == 26 || i == 70 || i == 105 {
			return 0
		}
		return 500 + rng.NormFloat64()*25
	})

	rng2 := rand.New(rand.NewSource(202))
	out["spikes"] = makeSeries(120, func(i int) float64 {
		if i == 40 || i == 88 {
			return 2500
		}
		return 400 + rng2.NormFloat64()*30
	})

	rng3 := rand.New(rand.NewSource(203))
	out["outage_on_trend"] = makeSeries(120, func(i int) float64 {
		if i == 60 || i == 61 {
			return 0
		}
		return 200 + 3*float64(i) + rng3.NormFloat64()*15
	})

	rng4 := rand.New(rand.NewSource(204))
	out["closed_sundays"] = makeSeries(120, func(i int) float64 {
		if base.AddDate(0, 0, i).Weekday() == time.Sunday {
			return 0
		}
		return 300 + rng4.NormFloat64()*20
	})

	rng5 := rand.New(rand.NewSource(205))
	out["poisson_low"] = makeSeries(120, func(i int) float64 {
		// Poisson(3) via inversion.
		l, k, p := math.Exp(-3), 0, 1.0
		for {
			p *= rng5.Float64()
			if p < l {
				return float64(k)
			}
			k++
		}
	})

	rng6 := rand.New(rand.NewSource(206))
	weekly := []float64{0.7, 1.1, 1.25, 1.2, 1.15, 1.0, 0.6}
	out["weekly_with_outage"] = makeSeries(120, func(i int) float64 {
		if i == 80 {
			return 0
		}
		return 300*weekly[i%7] + rng6.NormFloat64()*15
	})
	return out, names
}

// transientStats measures rolling-backtest accuracy and band behavior
// with masking on or off. Evaluation points that are themselves transients
// are excluded from RMSE (no forecaster should be scored on predicting an
// outage) but counted separately as "flagged": actuals outside the band,
// which is what the alerting layer sees.
type transientStats struct {
	rmse, coverage, flaggedTransients float64
	masked, n, transients             int
}

func rollingTransient(s Series, transientIdx map[int]bool, disable bool) transientStats {
	sumSq, covered, flagged, masked, n, nt := 0.0, 0, 0, 0, 0, 0
	for c := 30; c < len(s); c += 2 {
		steps := len(s) - c
		if steps > 7 {
			steps = 7
		}
		train := make(Series, c)
		copy(train, s[:c])
		res, err := Run(train, Config{Method: MethodAuto, Horizon: steps, Interval: IntervalDay,
			ConfidenceLevel: 0.80, NonNegative: true, LogTransform: true, DisableAnomalyMask: disable})
		if err != nil {
			continue
		}
		masked += len(res.AnomaliesMasked)
		for i := 0; i < steps; i++ {
			actual := s[c+i].V
			p := res.Predictions[i]
			if transientIdx[c+i] {
				nt++
				if actual < p.LowerBound || actual > p.UpperBound {
					flagged++
				}
				continue
			}
			d := actual - p.Value
			sumSq += d * d
			if actual >= p.LowerBound && actual <= p.UpperBound {
				covered++
			}
			n++
		}
	}
	st := transientStats{masked: masked, n: n, transients: nt}
	if n > 0 {
		st.rmse = math.Sqrt(sumSq / float64(n))
		st.coverage = float64(covered) / float64(n)
	}
	if nt > 0 {
		st.flaggedTransients = float64(flagged) / float64(nt)
	}
	return st
}

// transientIndices marks the injected transient points per suite series.
func transientIndices(name string) map[int]bool {
	m := map[int]bool{}
	switch name {
	case "outages":
		for _, i := range []int{25, 26, 70, 105} {
			m[i] = true
		}
	case "spikes":
		m[40], m[88] = true, true
	case "outage_on_trend":
		m[60], m[61] = true, true
	case "weekly_with_outage":
		m[80] = true
	}
	return m
}

func TestMeasureTransientSuite(t *testing.T) {
	if !*measureFlag {
		t.Skip("measurement suite disabled; pass -measure to run")
	}
	series, names := transientSuite()
	for _, name := range names {
		idx := transientIndices(name)
		for _, disable := range []bool{true, false} {
			st := rollingTransient(series[name], idx, disable)
			label := "masked"
			if disable {
				label = "unmasked"
			}
			fmt.Printf("%-20s %-8s rmse=%8.2f cover80=%5.1f%% masked_pts=%3d transients_flagged=%5.1f%% (n=%d, transients=%d)\n",
				name, label, st.rmse, st.coverage*100, st.masked, st.flaggedTransients*100, st.n, st.transients)
		}
	}
}

// TestTransientMaskingHelpsAndDoesNoHarm enforces the masking contract on
// a rolling backtest: on series with injected transients, masking must not
// worsen point accuracy on normal days and must keep the transients
// outside the band (so alerting sees them); on series whose "outliers" are
// their own pattern, masking must fire rarely and leave accuracy intact.
func TestTransientMaskingHelpsAndDoesNoHarm(t *testing.T) {
	if testing.Short() {
		t.Skip("skipping rolling-suite comparison in -short mode")
	}
	series, names := transientSuite()
	for _, name := range names {
		idx := transientIndices(name)
		off := rollingTransient(series[name], idx, true)
		on := rollingTransient(series[name], idx, false)
		t.Logf("%s: rmse %.2f -> %.2f, coverage %.1f%% -> %.1f%%, masked %d, transients flagged %.0f%% -> %.0f%%",
			name, off.rmse, on.rmse, off.coverage*100, on.coverage*100, on.masked,
			off.flaggedTransients*100, on.flaggedTransients*100)
		if on.rmse > off.rmse*1.02 {
			t.Errorf("%s: masking worsened rolling RMSE %.2f -> %.2f", name, off.rmse, on.rmse)
		}
		if len(idx) > 0 && on.flaggedTransients < 0.9 {
			t.Errorf("%s: only %.0f%% of transients fall outside the band with masking on", name, on.flaggedTransients*100)
		}
		if len(idx) == 0 && on.masked > 2 {
			t.Errorf("%s: %d points masked on a series with no transients", name, on.masked)
		}
	}
}
