package forecast

// Rolling-origin accuracy measurement over a representative-series suite.
// Run with:
//
//	go test ./internal/forecast/ -run TestMeasureSuite -v -measure
//
// It reports, per series and method, the rolling-backtest RMSE/MAE of Run()'s
// point forecasts and the empirical coverage of the [LowerBound, UpperBound]
// band (nominal 80% at ConfidenceLevel 0.80). Used to produce the
// before/after numbers quoted in commit messages; skipped by default so CI
// stays fast.

import (
	"flag"
	"fmt"
	"math"
	"math/rand"
	"testing"
)

var measureFlag = flag.Bool("measure", false, "run the accuracy measurement suite")

// suiteSeries returns the representative test series: trend, plateau, weekly
// seasonal, noisy flat, level shift, short, skewed noise, multiplicative.
// All generators are deterministic (fixed seeds).
func suiteSeries() map[string]Series {
	mk := makeSeries

	weekly := []float64{0.7, 1.1, 1.25, 1.2, 1.15, 1.0, 0.6} // Mon..Sun-ish shape

	out := map[string]Series{}

	rng := rand.New(rand.NewSource(11))
	out["trend_up"] = mk(90, func(i int) float64 {
		return 100 + 3*float64(i) + rng.NormFloat64()*8
	})

	rng2 := rand.New(rand.NewSource(22))
	out["trend_plateau"] = mk(90, func(i int) float64 {
		v := 300.0
		if i < 45 {
			v = 100 + (200.0/45.0)*float64(i)
		}
		return v + rng2.NormFloat64()*6
	})

	rng3 := rand.New(rand.NewSource(33))
	out["weekly_seasonal"] = mk(90, func(i int) float64 {
		return 100*weekly[i%7] + rng3.NormFloat64()*5
	})

	rng4 := rand.New(rand.NewSource(44))
	out["noisy_flat"] = mk(90, func(i int) float64 {
		return 100 + rng4.NormFloat64()*15
	})

	rng5 := rand.New(rand.NewSource(55))
	out["level_shift"] = mk(90, func(i int) float64 {
		lvl := 100.0
		if i >= 45 {
			lvl = 170
		}
		return lvl + rng5.NormFloat64()*8
	})

	rng6 := rand.New(rand.NewSource(66))
	out["short"] = mk(12, func(i int) float64 {
		return 50 + 2*float64(i) + rng6.NormFloat64()*4
	})

	// Skewed (exponential) noise around a flat level: mean-zero but asymmetric.
	rng7 := rand.New(rand.NewSource(77))
	out["skewed_noise"] = mk(90, func(i int) float64 {
		return 100 + (rng7.ExpFloat64()-1)*20
	})

	rng8 := rand.New(rand.NewSource(88))
	out["mult_growth"] = mk(90, func(i int) float64 {
		v := 40 * math.Pow(1.02, float64(i))
		return v * (1 + rng8.NormFloat64()*0.06)
	})

	return out
}

// rollingMeasure runs Run() at multiple cut points and aggregates point-error
// and band-coverage stats against the held-out tail. lowMiss/highMiss are the
// per-tail escape rates (each nominally 10% for an 80% band).
func rollingMeasure(s Series, method Method, horizon int) (rmse, mae, coverage, lowMiss, highMiss float64, n int) {
	minTrain := 9
	sumSq, sumAbs := 0.0, 0.0
	covered, low, high := 0, 0, 0

	for c := minTrain; c < len(s); c += 2 {
		steps := len(s) - c
		if steps > horizon {
			steps = horizon
		}
		train := make(Series, c)
		copy(train, s[:c])
		res, err := Run(train, Config{
			Method:          method,
			Horizon:         steps,
			Interval:        IntervalDay,
			ConfidenceLevel: 0.80,
		})
		if err != nil {
			continue
		}
		for i := 0; i < steps && i < len(res.Predictions); i++ {
			actual := s[c+i].V
			p := res.Predictions[i]
			diff := actual - p.Value
			sumSq += diff * diff
			sumAbs += math.Abs(diff)
			switch {
			case actual < p.LowerBound:
				low++
			case actual > p.UpperBound:
				high++
			default:
				covered++
			}
			n++
		}
	}
	if n == 0 {
		return 0, 0, 0, 0, 0, 0
	}
	fn := float64(n)
	return math.Sqrt(sumSq / fn), sumAbs / fn, float64(covered) / fn, float64(low) / fn, float64(high) / fn, n
}

func TestMeasureSuite(t *testing.T) {
	if !*measureFlag {
		t.Skip("measurement suite disabled; pass -measure to run")
	}
	methods := []Method{MethodAuto, MethodLinear, MethodSMA, MethodWMA, MethodHoltWinters}
	series := suiteSeries()
	names := []string{"trend_up", "trend_plateau", "weekly_seasonal", "noisy_flat",
		"level_shift", "short", "skewed_noise", "mult_growth"}

	for _, name := range names {
		s := series[name]
		for _, m := range methods {
			rmse, mae, cov, low, high, n := rollingMeasure(s, m, 7)
			fmt.Printf("%-16s %-12s rmse=%8.2f mae=%8.2f cover80=%5.1f%% lowMiss=%4.1f%% highMiss=%4.1f%% n=%d\n",
				name, m, rmse, mae, cov*100, low*100, high*100, n)
		}
	}
}
