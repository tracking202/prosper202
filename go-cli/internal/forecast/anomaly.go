package forecast

import (
	"math"
	"sort"
	"time"
)

// Transient anomaly masking. A tracking outage, a one-off viral day, or an
// untagged promo is not information about the future; fitting it (and, on
// log scale, fitting a zero) drags the forecast and collapses the bands
// exactly when the alerting layer needs a healthy baseline.
//
// The test is "abnormal for this series at this point of its cycle": each
// observation is compared with the same weekday (daily data) or the same
// hour (hourly data) in the surrounding cycles, so a business closed on
// Sundays or a low-volume tracker whose counts hover near zero is never
// masked, while a zero Tuesday in a 500-a-day series is. Deviations are
// measured in robust units (median absolute deviation of all such
// residuals), and only short runs are masked: anything at least
// levelShiftMinSegment long is a regime change and is left to the
// level-shift detector.

const (
	// anomalyMinPoints is the shortest series checked for transients.
	anomalyMinPoints = 20
	// anomalyCycles is how many cycles on each side supply same-slot
	// reference values.
	anomalyCycles = 4
	// anomalyMinRefs is the minimum same-slot references a point needs to
	// be judged; with fewer, the rolling median of neighbors is used.
	anomalyMinRefs = 3
	// anomalyThreshold is the deviation, in robust sigma units (MAD scaled
	// to the normal), beyond which a point is a transient.
	anomalyThreshold = 5.0
	// madToSigma converts a median absolute deviation to a normal sigma.
	madToSigma = 1.4826
)

// anomalyCycle returns the seasonal cycle used for same-slot references, or
// 0 when the interval has none (week/month use a plain rolling median).
func anomalyCycle(interval Interval) time.Duration {
	switch interval {
	case IntervalDay:
		return 7 * 24 * time.Hour
	case IntervalHour:
		return 24 * time.Hour
	default:
		return 0
	}
}

// detectTransients returns the indices of short anomalous runs in s (on
// whatever scale s is expressed in), sorted ascending.
func detectTransients(s Series, interval Interval) []int {
	n := len(s)
	if n < anomalyMinPoints {
		return nil
	}
	byTime := make(map[time.Time]float64, n)
	for _, p := range s {
		byTime[p.T] = p.V
	}
	cycle := anomalyCycle(interval)

	// Reference level per point: median of same-slot values in the
	// surrounding cycles, else median of the nearest neighbors.
	resid := make([]float64, n)
	for i, p := range s {
		var refs []float64
		if cycle > 0 {
			for k := -anomalyCycles; k <= anomalyCycles; k++ {
				if k == 0 {
					continue
				}
				if v, ok := byTime[p.T.Add(time.Duration(k)*cycle)]; ok {
					refs = append(refs, v)
				}
			}
		}
		if len(refs) < anomalyMinRefs {
			refs = refs[:0]
			for j := i - 4; j <= i+4; j++ {
				if j == i || j < 0 || j >= n {
					continue
				}
				refs = append(refs, s[j].V)
			}
		}
		resid[i] = p.V - median(refs)
	}

	abs := make([]float64, n)
	for i, r := range resid {
		abs[i] = math.Abs(r)
	}
	sigma := madToSigma * median(abs)
	if sigma <= 0 {
		// A series with no typical deviation (e.g. constant): use a small
		// floor so only genuine departures stand out.
		sigma = 1e-6 * (math.Abs(mean(seriesValues(s))) + 1)
	}

	flagged := make([]bool, n)
	for i, r := range resid {
		flagged[i] = math.Abs(r) > anomalyThreshold*sigma
	}

	// Keep only runs shorter than a regime change.
	var out []int
	for i := 0; i < n; {
		if !flagged[i] {
			i++
			continue
		}
		j := i
		for j < n && flagged[j] {
			j++
		}
		if j-i < levelShiftMinSegment {
			for k := i; k < j; k++ {
				out = append(out, k)
			}
		}
		i = j
	}
	return out
}

// maskIndices returns a copy of s without the given (ascending) indices.
func maskIndices(s Series, idx []int) Series {
	if len(idx) == 0 {
		return s
	}
	drop := make(map[int]bool, len(idx))
	for _, i := range idx {
		drop[i] = true
	}
	out := make(Series, 0, len(s)-len(idx))
	for i, p := range s {
		if !drop[i] {
			out = append(out, p)
		}
	}
	return out
}

// median returns the median of v (0 for an empty slice) without modifying
// it.
func median(v []float64) float64 {
	if len(v) == 0 {
		return 0
	}
	c := append([]float64(nil), v...)
	sort.Float64s(c)
	return quantileSorted(c, 0.5)
}
