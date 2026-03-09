package forecast

import (
	"math"
	"sort"
	"time"
)

// Event represents a calendar event that affects forecasting.
type Event struct {
	ID               int       `json:"event_id"`
	Name             string    `json:"event_name"`
	Date             time.Time `json:"event_date"`
	EndDate          time.Time `json:"end_date,omitempty"`
	Recurrence       string    `json:"recurrence"`
	ImpactType       string    `json:"impact_type"`
	ExpectedImpactPct float64  `json:"expected_impact_pct"`
	LeadDays         int       `json:"lead_days"`
	LagDays          int       `json:"lag_days"`
	Tags             string    `json:"tags"`
}

// EventWindow is the full date range an event influences:
// [Date - LeadDays, EndDate + LagDays].
type EventWindow struct {
	Event     Event
	Start     time.Time // event.Date minus lead_days
	End       time.Time // event.EndDate plus lag_days
	CoreStart time.Time // event.Date
	CoreEnd   time.Time // event.EndDate (or event.Date if no end_date)
}

// ZoneImpact holds the learned multiplier for one zone (lead, core, lag).
type ZoneImpact struct {
	Zone       string  // "lead", "core", "lag"
	Multiplier float64 // actual / baseline ratio
	Samples    int     // how many data points contributed
}

// LearnedImpact holds the complete impact profile for one event name,
// learned from historical data.
type LearnedImpact struct {
	EventName  string
	Lead       ZoneImpact
	Core       ZoneImpact
	Lag        ZoneImpact
	Occurrences int // how many past occurrences were used
}

// EventAdjustment is the final multiplier to apply to a specific prediction date.
type EventAdjustment struct {
	Date       time.Time
	Multiplier float64
	EventNames []string // which events contribute to this date
}

// priorWeight controls Bayesian blending of user hint vs learned data.
// A priorWeight of 1 means 1 occurrence of user hint = 1 occurrence of data.
const priorWeight = 1.0

// EventWindow computes the full influence window for an event.
func (e Event) Window() EventWindow {
	coreEnd := e.Date
	if !e.EndDate.IsZero() && e.EndDate.After(e.Date) {
		coreEnd = e.EndDate
	}
	return EventWindow{
		Event:     e,
		Start:     e.Date.AddDate(0, 0, -e.LeadDays),
		End:       coreEnd.AddDate(0, 0, e.LagDays),
		CoreStart: e.Date,
		CoreEnd:   coreEnd,
	}
}

// MaskEventDays returns a copy of the series with event-influenced days removed.
// This produces "clean" training data for the baseline model.
func MaskEventDays(series Series, events []Event) Series {
	if len(events) == 0 {
		return series
	}

	windows := make([]EventWindow, len(events))
	for i, e := range events {
		windows[i] = e.Window()
	}

	clean := make(Series, 0, len(series))
	for _, p := range series {
		day := truncateToDay(p.T)
		masked := false
		for _, w := range windows {
			if !day.Before(w.Start) && !day.After(w.End) {
				masked = true
				break
			}
		}
		if !masked {
			clean = append(clean, p)
		}
	}

	return clean
}

// LearnEventImpacts computes impact multipliers for each unique event name
// by comparing actual values against baseline predictions on event days.
//
// Algorithm:
//  1. Train baseline model on clean (non-event) data
//  2. For each historical event occurrence, predict what baseline would have been
//  3. Compute ratio = actual / baseline for lead, core, and lag zones
//  4. Average ratios across all occurrences of the same event name
//  5. Blend with user hint via Bayesian shrinkage if hint is provided
func LearnEventImpacts(series Series, events []Event, cfg Config) map[string]LearnedImpact {
	if len(series) < 3 || len(events) == 0 {
		return nil
	}

	// Sort series by time.
	sorted := make(Series, len(series))
	copy(sorted, series)
	sort.Slice(sorted, func(i, j int) bool {
		return sorted[i].T.Before(sorted[j].T)
	})

	// Build a day-indexed lookup for fast value retrieval.
	dayValues := map[string]float64{}
	for _, p := range sorted {
		key := truncateToDay(p.T).Format("2006-01-02")
		dayValues[key] = p.V
	}

	// Train baseline model on clean data.
	clean := MaskEventDays(sorted, events)
	if len(clean) < 3 {
		return nil
	}

	// Build baseline predictions for all days in the original series range.
	baselinePreds := buildBaselinePredictions(clean, sorted, cfg)

	// Group events by name to find all occurrences.
	eventsByName := map[string][]Event{}
	for _, e := range events {
		eventsByName[e.Name] = append(eventsByName[e.Name], e)
	}

	impacts := map[string]LearnedImpact{}

	for name, occurrences := range eventsByName {
		var leadRatios, coreRatios, lagRatios []float64
		var userHint float64
		hasHint := false

		for _, occ := range occurrences {
			w := occ.Window()

			// Collect hint from any occurrence that has one.
			if occ.ExpectedImpactPct != 0 && !hasHint {
				userHint = occ.ExpectedImpactPct
				hasHint = true
			}

			// Collect ratios for each zone.
			leadRatios = append(leadRatios, collectZoneRatios(dayValues, baselinePreds, w.Start, w.CoreStart.AddDate(0, 0, -1))...)
			coreRatios = append(coreRatios, collectZoneRatios(dayValues, baselinePreds, w.CoreStart, w.CoreEnd)...)
			lagRatios = append(lagRatios, collectZoneRatios(dayValues, baselinePreds, w.CoreEnd.AddDate(0, 0, 1), w.End)...)
		}

		impact := LearnedImpact{
			EventName:   name,
			Occurrences: len(occurrences),
		}

		// Convert user hint from percentage to multiplier.
		// expected_impact_pct = +200 means 3x multiplier (1 + 200/100).
		hintMultiplier := 1.0
		if hasHint {
			hintMultiplier = 1.0 + userHint/100.0
		}

		// The user hint describes core impact only. Lead and lag zones use
		// a neutral prior (1.0) — ramp-up and decay patterns must be learned
		// from data, not assumed from the core hint.
		impact.Lead = blendZone("lead", leadRatios, 1.0, false)
		impact.Core = blendZone("core", coreRatios, hintMultiplier, hasHint)
		impact.Lag = blendZone("lag", lagRatios, 1.0, false)

		impacts[name] = impact
	}

	return impacts
}

// ApplyEventAdjustments computes per-date multipliers for the forecast horizon
// based on learned impacts and any future events in the window.
func ApplyEventAdjustments(predictions []Prediction, events []Event, impacts map[string]LearnedImpact) []Prediction {
	if len(events) == 0 {
		return predictions
	}
	if impacts == nil {
		impacts = map[string]LearnedImpact{}
	}

	// Build a map of date -> combined multiplier.
	adjustments := map[string]EventAdjustment{}

	for _, event := range events {
		impact, ok := impacts[event.Name]
		if !ok {
			// No learned impact — use hint directly if available.
			if event.ExpectedImpactPct != 0 {
				impact = LearnedImpact{
					EventName: event.Name,
					Lead:      ZoneImpact{Zone: "lead", Multiplier: 1.0},
					Core:      ZoneImpact{Zone: "core", Multiplier: 1.0 + event.ExpectedImpactPct/100.0},
					Lag:       ZoneImpact{Zone: "lag", Multiplier: 1.0},
				}
			} else {
				continue
			}
		}

		w := event.Window()

		applyZoneMultiplier(adjustments, w.Start, w.CoreStart.AddDate(0, 0, -1), impact.Lead.Multiplier, event.Name)
		applyZoneMultiplier(adjustments, w.CoreStart, w.CoreEnd, impact.Core.Multiplier, event.Name)
		applyZoneMultiplier(adjustments, w.CoreEnd.AddDate(0, 0, 1), w.End, impact.Lag.Multiplier, event.Name)
	}

	// Apply multipliers to predictions.
	adjusted := make([]Prediction, len(predictions))
	copy(adjusted, predictions)

	for i := range adjusted {
		key := truncateToDay(adjusted[i].T).Format("2006-01-02")
		if adj, ok := adjustments[key]; ok {
			adjusted[i].Value *= adj.Multiplier
			adjusted[i].LowerBound *= adj.Multiplier
			adjusted[i].UpperBound *= adj.Multiplier
			// Multiplicative adjustment can invert bounds when the multiplier
			// is negative (e.g., learned from negative-profit actuals).
			// Guarantee Lower ≤ Upper.
			if adjusted[i].LowerBound > adjusted[i].UpperBound {
				adjusted[i].LowerBound, adjusted[i].UpperBound = adjusted[i].UpperBound, adjusted[i].LowerBound
			}
		}
	}

	return adjusted
}

// FutureEvents filters events to those whose influence window overlaps with
// the forecast horizon [start, end].
func FutureEvents(events []Event, start, end time.Time) []Event {
	var future []Event
	for _, e := range events {
		w := e.Window()
		// Window overlaps if it doesn't end before start and doesn't start after end.
		if !w.End.Before(start) && !w.Start.After(end) {
			future = append(future, e)
		}
	}
	return future
}

// PastEvents filters events to those whose core date falls within the
// training data time range [start, end].
func PastEvents(events []Event, start, end time.Time) []Event {
	var past []Event
	for _, e := range events {
		day := truncateToDay(e.Date)
		if !day.Before(start) && !day.After(end) {
			past = append(past, e)
		}
	}
	return past
}

// buildBaselinePredictions creates a day-indexed map of what the baseline model
// would predict for each day in the original series range.
func buildBaselinePredictions(clean, full Series, cfg Config) map[string]float64 {
	if len(clean) < 3 {
		return nil
	}

	// Fit OLS regression in calendar-time space (days since first clean point).
	// Using calendar time instead of sequential indices ensures correct
	// counterfactual predictions around gaps created by event masking.
	cleanStart := truncateToDay(clean[0].T)
	n := float64(len(clean))
	sumX := 0.0
	sumY := 0.0
	sumXY := 0.0
	sumXX := 0.0

	for _, p := range clean {
		x := truncateToDay(p.T).Sub(cleanStart).Hours() / 24.0
		sumX += x
		sumY += p.V
		sumXY += x * p.V
		sumXX += x * x
	}

	denom := n*sumXX - sumX*sumX
	var slope, intercept float64
	if math.Abs(denom) < 1e-12 {
		intercept = sumY / n
		slope = 0
	} else {
		slope = (n*sumXY - sumX*sumY) / denom
		intercept = (sumY - slope*sumX) / n
	}

	// Predict for every day in the full series using the same calendar-time
	// x-axis. No index-to-calendar interpolation needed.
	preds := map[string]float64{}

	for _, p := range full {
		day := truncateToDay(p.T)
		key := day.Format("2006-01-02")
		x := day.Sub(cleanStart).Hours() / 24.0
		pred := intercept + slope*x
		// Non-positive baselines are skipped by collectZoneRatios (baseline > 0).
		preds[key] = pred
	}

	return preds
}

// collectZoneRatios gathers actual/baseline ratios for days in [from, to].
func collectZoneRatios(actuals, baselines map[string]float64, from, to time.Time) []float64 {
	if from.After(to) {
		return nil
	}

	var ratios []float64
	day := from
	for !day.After(to) {
		key := day.Format("2006-01-02")
		actual, aOK := actuals[key]
		baseline, bOK := baselines[key]
		if aOK && bOK && baseline > 0 {
			ratios = append(ratios, actual/baseline)
		}
		day = day.AddDate(0, 0, 1)
	}
	return ratios
}

// blendZone computes the final multiplier for a zone by blending data-learned
// ratios with the user hint via Bayesian shrinkage.
func blendZone(zone string, ratios []float64, hintMultiplier float64, hasHint bool) ZoneImpact {
	n := float64(len(ratios))

	if n == 0 && !hasHint {
		return ZoneImpact{Zone: zone, Multiplier: 1.0, Samples: 0}
	}

	if n == 0 && hasHint {
		return ZoneImpact{Zone: zone, Multiplier: hintMultiplier, Samples: 0}
	}

	// Mean of observed ratios.
	sum := 0.0
	for _, r := range ratios {
		sum += r
	}
	dataMean := sum / n

	if !hasHint {
		return ZoneImpact{Zone: zone, Multiplier: dataMean, Samples: int(n)}
	}

	// Bayesian blend: posterior = (hint * priorWeight + dataMean * n) / (priorWeight + n)
	blended := (hintMultiplier*priorWeight + dataMean*n) / (priorWeight + n)
	return ZoneImpact{Zone: zone, Multiplier: blended, Samples: int(n)}
}

// applyZoneMultiplier sets the multiplier for all days in [from, to],
// combining multiplicatively with any existing adjustments.
func applyZoneMultiplier(adjustments map[string]EventAdjustment, from, to time.Time, multiplier float64, eventName string) {
	if from.After(to) || multiplier == 1.0 {
		return
	}

	day := from
	for !day.After(to) {
		key := day.Format("2006-01-02")
		adj, exists := adjustments[key]
		if !exists {
			adj = EventAdjustment{
				Date:       day,
				Multiplier: 1.0,
			}
		}
		// Multiplicative combination of overlapping event effects.
		adj.Multiplier *= multiplier
		adj.EventNames = append(adj.EventNames, eventName)
		adjustments[key] = adj
		day = day.AddDate(0, 0, 1)
	}
}

// truncateToDay strips time components, keeping only the date.
func truncateToDay(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, t.Location())
}
