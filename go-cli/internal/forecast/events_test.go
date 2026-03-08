package forecast

import (
	"math"
	"testing"
	"time"
)

func date(year, month, day int) time.Time {
	return time.Date(year, time.Month(month), day, 0, 0, 0, 0, time.UTC)
}

func TestEventWindow(t *testing.T) {
	e := Event{
		Name:     "Black Friday",
		Date:     date(2025, 11, 28),
		EndDate:  date(2025, 11, 28),
		LeadDays: 14,
		LagDays:  3,
	}
	w := e.Window()

	expectedStart := date(2025, 11, 14)
	expectedEnd := date(2025, 12, 1)

	if !w.Start.Equal(expectedStart) {
		t.Errorf("Start = %v, want %v", w.Start, expectedStart)
	}
	if !w.End.Equal(expectedEnd) {
		t.Errorf("End = %v, want %v", w.End, expectedEnd)
	}
	if !w.CoreStart.Equal(date(2025, 11, 28)) {
		t.Errorf("CoreStart = %v, want 2025-11-28", w.CoreStart)
	}
}

func TestEventWindowMultiDay(t *testing.T) {
	e := Event{
		Name:     "Holiday Week",
		Date:     date(2025, 12, 23),
		EndDate:  date(2025, 12, 27),
		LeadDays: 2,
		LagDays:  1,
	}
	w := e.Window()

	if !w.Start.Equal(date(2025, 12, 21)) {
		t.Errorf("Start = %v, want 2025-12-21", w.Start)
	}
	if !w.End.Equal(date(2025, 12, 28)) {
		t.Errorf("End = %v, want 2025-12-28", w.End)
	}
}

func TestEventWindowNoEndDate(t *testing.T) {
	e := Event{
		Name:     "Single Day",
		Date:     date(2025, 7, 4),
		LeadDays: 1,
		LagDays:  1,
	}
	w := e.Window()

	if !w.CoreEnd.Equal(date(2025, 7, 4)) {
		t.Errorf("CoreEnd = %v, want 2025-07-04 (same as Date when no EndDate)", w.CoreEnd)
	}
}

func TestMaskEventDays(t *testing.T) {
	series := make(Series, 30)
	base := date(2025, 1, 1)
	for i := 0; i < 30; i++ {
		series[i] = Point{T: base.AddDate(0, 0, i), V: float64(100 + i)}
	}

	events := []Event{
		{Name: "Event A", Date: date(2025, 1, 10), LeadDays: 1, LagDays: 1},
	}

	clean := MaskEventDays(series, events)

	// Event A window: Jan 9, 10, 11 = 3 days masked.
	expected := 30 - 3
	if len(clean) != expected {
		t.Errorf("clean series length = %d, want %d", len(clean), expected)
	}

	// Verify the masked days are not present.
	for _, p := range clean {
		day := p.T.Day()
		if day >= 9 && day <= 11 {
			t.Errorf("day %d should have been masked", day)
		}
	}
}

func TestMaskEventDaysNoEvents(t *testing.T) {
	series := makeSeries(10, func(i int) float64 { return float64(i) })
	clean := MaskEventDays(series, nil)
	if len(clean) != len(series) {
		t.Errorf("with no events, clean should equal original")
	}
}

func TestLearnEventImpacts_BoostEvent(t *testing.T) {
	// Create 90 days of data with a consistent baseline of 100,
	// except on day 45 which spikes to 400 (a 4x boost event).
	base := date(2025, 1, 1)
	series := make(Series, 90)
	for i := 0; i < 90; i++ {
		val := 100.0
		if i == 45 {
			val = 400.0
		}
		series[i] = Point{T: base.AddDate(0, 0, i), V: val}
	}

	events := []Event{
		{
			Name:     "Big Sale",
			Date:     date(2025, 2, 15), // day 45
			LeadDays: 0,
			LagDays:  0,
		},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	if impacts == nil {
		t.Fatal("expected non-nil impacts")
	}

	impact, ok := impacts["Big Sale"]
	if !ok {
		t.Fatal("missing impact for 'Big Sale'")
	}

	// Core multiplier should be close to 4.0 (400/100).
	if impact.Core.Multiplier < 3.0 || impact.Core.Multiplier > 5.0 {
		t.Errorf("core multiplier = %.2f, expected ~4.0", impact.Core.Multiplier)
	}
	if impact.Occurrences != 1 {
		t.Errorf("occurrences = %d, want 1", impact.Occurrences)
	}
}

func TestLearnEventImpacts_SuppressEvent(t *testing.T) {
	// Baseline of 100, day 30 drops to 20 (suppress event).
	base := date(2025, 1, 1)
	series := make(Series, 60)
	for i := 0; i < 60; i++ {
		val := 100.0
		if i == 30 {
			val = 20.0
		}
		series[i] = Point{T: base.AddDate(0, 0, i), V: val}
	}

	events := []Event{
		{Name: "Outage", Date: date(2025, 1, 31), LeadDays: 0, LagDays: 0},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	impact := impacts["Outage"]

	// Should learn ~0.2 multiplier (20/100).
	if impact.Core.Multiplier > 0.5 {
		t.Errorf("suppress core multiplier = %.2f, expected < 0.5", impact.Core.Multiplier)
	}
}

func TestLearnEventImpacts_WithHintBlending(t *testing.T) {
	// Baseline 100, event day 200 (2x). User hint says +300% (4x).
	// With 1 occurrence and priorWeight=1:
	// blended = (4.0 * 1.0 + 2.0 * 1) / (1.0 + 1) = 6.0 / 2.0 = 3.0.
	base := date(2025, 1, 1)
	series := make(Series, 30)
	for i := 0; i < 30; i++ {
		val := 100.0
		if i == 15 {
			val = 200.0
		}
		series[i] = Point{T: base.AddDate(0, 0, i), V: val}
	}

	events := []Event{
		{
			Name:              "Promo",
			Date:              date(2025, 1, 16),
			LeadDays:          0,
			LagDays:           0,
			ExpectedImpactPct: 300.0, // +300% = 4x multiplier
		},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	impact := impacts["Promo"]

	// Blended should be between the data (2.0) and the hint (4.0).
	if impact.Core.Multiplier < 2.0 || impact.Core.Multiplier > 4.5 {
		t.Errorf("blended core multiplier = %.2f, expected between 2.0 and 4.5", impact.Core.Multiplier)
	}
}

func TestLearnEventImpacts_HintOnlyNoData(t *testing.T) {
	// Event is in the future — no historical data at all.
	base := date(2025, 1, 1)
	series := make(Series, 30)
	for i := 0; i < 30; i++ {
		series[i] = Point{T: base.AddDate(0, 0, i), V: 100.0}
	}

	events := []Event{
		{
			Name:              "Future Sale",
			Date:              date(2025, 6, 1), // way past the series
			LeadDays:          0,
			LagDays:           0,
			ExpectedImpactPct: 150.0, // +150% = 2.5x
		},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	impact := impacts["Future Sale"]

	// No data for this event window — should fall back to hint.
	if math.Abs(impact.Core.Multiplier-2.5) > 0.01 {
		t.Errorf("hint-only core multiplier = %.2f, want 2.5", impact.Core.Multiplier)
	}
}

func TestLearnEventImpacts_MultipleOccurrences(t *testing.T) {
	// Two occurrences: day 15 = 300 (3x), day 45 = 500 (5x).
	// Average core ratio ~4x.
	base := date(2025, 1, 1)
	series := make(Series, 60)
	for i := 0; i < 60; i++ {
		val := 100.0
		if i == 15 {
			val = 300.0
		}
		if i == 45 {
			val = 500.0
		}
		series[i] = Point{T: base.AddDate(0, 0, i), V: val}
	}

	events := []Event{
		{Name: "Recurring Sale", Date: date(2025, 1, 16), Recurrence: "custom", LeadDays: 0, LagDays: 0},
		{Name: "Recurring Sale", Date: date(2025, 2, 15), Recurrence: "custom", LeadDays: 0, LagDays: 0},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	impact := impacts["Recurring Sale"]

	if impact.Occurrences != 2 {
		t.Errorf("occurrences = %d, want 2", impact.Occurrences)
	}
	// Average of ~3x and ~5x = ~4x.
	if impact.Core.Multiplier < 3.0 || impact.Core.Multiplier > 5.5 {
		t.Errorf("multi-occurrence core multiplier = %.2f, expected ~4.0", impact.Core.Multiplier)
	}
}

func TestLearnEventImpacts_LeadLagZones(t *testing.T) {
	// Flat baseline 100. Lead day (day 14) = 120, core (day 15) = 400, lag (day 16) = 150.
	base := date(2025, 1, 1)
	series := make(Series, 30)
	for i := 0; i < 30; i++ {
		val := 100.0
		switch i {
		case 14:
			val = 120.0
		case 15:
			val = 400.0
		case 16:
			val = 150.0
		}
		series[i] = Point{T: base.AddDate(0, 0, i), V: val}
	}

	events := []Event{
		{Name: "Zoned Event", Date: date(2025, 1, 16), LeadDays: 1, LagDays: 1},
	}

	impacts := LearnEventImpacts(series, events, Config{})
	impact := impacts["Zoned Event"]

	// Lead ~1.2, core ~4.0, lag ~1.5.
	if impact.Lead.Multiplier < 0.9 || impact.Lead.Multiplier > 1.5 {
		t.Errorf("lead multiplier = %.2f, expected ~1.2", impact.Lead.Multiplier)
	}
	if impact.Core.Multiplier < 3.0 || impact.Core.Multiplier > 5.0 {
		t.Errorf("core multiplier = %.2f, expected ~4.0", impact.Core.Multiplier)
	}
	if impact.Lag.Multiplier < 1.1 || impact.Lag.Multiplier > 2.0 {
		t.Errorf("lag multiplier = %.2f, expected ~1.5", impact.Lag.Multiplier)
	}
}

func TestApplyEventAdjustments(t *testing.T) {
	preds := []Prediction{
		{T: date(2025, 3, 1), Value: 100, LowerBound: 80, UpperBound: 120},
		{T: date(2025, 3, 2), Value: 100, LowerBound: 80, UpperBound: 120},
		{T: date(2025, 3, 3), Value: 100, LowerBound: 80, UpperBound: 120},
	}

	events := []Event{
		{Name: "Sale", Date: date(2025, 3, 2), LeadDays: 0, LagDays: 0},
	}

	impacts := map[string]LearnedImpact{
		"Sale": {
			EventName: "Sale",
			Core:      ZoneImpact{Zone: "core", Multiplier: 2.5},
			Lead:      ZoneImpact{Zone: "lead", Multiplier: 1.0},
			Lag:       ZoneImpact{Zone: "lag", Multiplier: 1.0},
		},
	}

	adjusted := ApplyEventAdjustments(preds, events, impacts)

	// Day 1 and 3 should be unchanged.
	if adjusted[0].Value != 100 {
		t.Errorf("day 1 value = %.2f, want 100", adjusted[0].Value)
	}
	if adjusted[2].Value != 100 {
		t.Errorf("day 3 value = %.2f, want 100", adjusted[2].Value)
	}

	// Day 2 should be 100 * 2.5 = 250.
	if math.Abs(adjusted[1].Value-250) > 0.01 {
		t.Errorf("day 2 value = %.2f, want 250", adjusted[1].Value)
	}
	if math.Abs(adjusted[1].LowerBound-200) > 0.01 {
		t.Errorf("day 2 lower = %.2f, want 200", adjusted[1].LowerBound)
	}
	if math.Abs(adjusted[1].UpperBound-300) > 0.01 {
		t.Errorf("day 2 upper = %.2f, want 300", adjusted[1].UpperBound)
	}
}

func TestApplyEventAdjustments_OverlappingMultiplicative(t *testing.T) {
	preds := []Prediction{
		{T: date(2025, 3, 1), Value: 100, LowerBound: 80, UpperBound: 120},
	}

	// Two events overlap on the same day.
	events := []Event{
		{Name: "Event A", Date: date(2025, 3, 1), LeadDays: 0, LagDays: 0},
		{Name: "Event B", Date: date(2025, 3, 1), LeadDays: 0, LagDays: 0},
	}

	impacts := map[string]LearnedImpact{
		"Event A": {Core: ZoneImpact{Zone: "core", Multiplier: 1.5}, Lead: ZoneImpact{Multiplier: 1.0}, Lag: ZoneImpact{Multiplier: 1.0}},
		"Event B": {Core: ZoneImpact{Zone: "core", Multiplier: 1.3}, Lead: ZoneImpact{Multiplier: 1.0}, Lag: ZoneImpact{Multiplier: 1.0}},
	}

	adjusted := ApplyEventAdjustments(preds, events, impacts)

	// Multiplicative: 100 * 1.5 * 1.3 = 195.
	expected := 100 * 1.5 * 1.3
	if math.Abs(adjusted[0].Value-expected) > 0.01 {
		t.Errorf("overlapping value = %.2f, want %.2f", adjusted[0].Value, expected)
	}
}

func TestFutureEvents(t *testing.T) {
	events := []Event{
		{Name: "Past", Date: date(2025, 1, 1)},
		{Name: "Future", Date: date(2025, 6, 1)},
		{Name: "Edge", Date: date(2025, 3, 15), LeadDays: 5, LagDays: 5},
	}

	future := FutureEvents(events, date(2025, 3, 10), date(2025, 3, 20))

	if len(future) != 1 {
		t.Errorf("expected 1 future event, got %d", len(future))
	}
	if len(future) > 0 && future[0].Name != "Edge" {
		t.Errorf("expected 'Edge', got %q", future[0].Name)
	}
}

func TestPastEvents(t *testing.T) {
	events := []Event{
		{Name: "Early", Date: date(2025, 1, 15)},
		{Name: "Mid", Date: date(2025, 2, 15)},
		{Name: "Late", Date: date(2025, 4, 1)},
	}

	past := PastEvents(events, date(2025, 1, 1), date(2025, 3, 1))

	if len(past) != 2 {
		t.Errorf("expected 2 past events, got %d", len(past))
	}
}

func TestTruncateToDay(t *testing.T) {
	input := time.Date(2025, 3, 15, 14, 30, 45, 123, time.UTC)
	expected := time.Date(2025, 3, 15, 0, 0, 0, 0, time.UTC)
	got := truncateToDay(input)
	if !got.Equal(expected) {
		t.Errorf("truncateToDay = %v, want %v", got, expected)
	}
}

func TestBlendZone_NoDataNoHint(t *testing.T) {
	zi := blendZone("core", nil, 1.0, false)
	if zi.Multiplier != 1.0 {
		t.Errorf("no-data no-hint multiplier = %.2f, want 1.0", zi.Multiplier)
	}
}

func TestBlendZone_DataOnly(t *testing.T) {
	ratios := []float64{2.0, 3.0, 4.0}
	zi := blendZone("core", ratios, 1.0, false)
	// Mean = 3.0.
	if math.Abs(zi.Multiplier-3.0) > 0.01 {
		t.Errorf("data-only multiplier = %.2f, want 3.0", zi.Multiplier)
	}
	if zi.Samples != 3 {
		t.Errorf("samples = %d, want 3", zi.Samples)
	}
}

func TestBlendZone_HintOnly(t *testing.T) {
	zi := blendZone("core", nil, 2.5, true)
	if math.Abs(zi.Multiplier-2.5) > 0.01 {
		t.Errorf("hint-only multiplier = %.2f, want 2.5", zi.Multiplier)
	}
}

func TestBlendZone_BayesianBlend(t *testing.T) {
	// Data mean = 2.0 (1 sample), hint = 4.0, priorWeight = 1.
	// Blended = (4.0 * 1 + 2.0 * 1) / (1 + 1) = 3.0.
	ratios := []float64{2.0}
	zi := blendZone("core", ratios, 4.0, true)
	if math.Abs(zi.Multiplier-3.0) > 0.01 {
		t.Errorf("blended multiplier = %.2f, want 3.0", zi.Multiplier)
	}
}

func TestBlendZone_DataDominatesWithManySamples(t *testing.T) {
	// 10 samples of data mean = 2.0, hint = 10.0, priorWeight = 1.
	// Blended = (10.0 * 1 + 2.0 * 10) / (1 + 10) = 30/11 ≈ 2.73.
	ratios := make([]float64, 10)
	for i := range ratios {
		ratios[i] = 2.0
	}
	zi := blendZone("core", ratios, 10.0, true)
	expected := (10.0*priorWeight + 2.0*10.0) / (priorWeight + 10.0)
	if math.Abs(zi.Multiplier-expected) > 0.01 {
		t.Errorf("data-dominant multiplier = %.2f, want %.2f", zi.Multiplier, expected)
	}
}

func TestApplyEventAdjustments_NoEvents(t *testing.T) {
	preds := []Prediction{
		{T: date(2025, 3, 1), Value: 100},
	}
	adjusted := ApplyEventAdjustments(preds, nil, nil)
	if adjusted[0].Value != 100 {
		t.Errorf("value changed with no events: %.2f", adjusted[0].Value)
	}
}

func TestApplyEventAdjustments_HintFallbackNoLearnedImpact(t *testing.T) {
	preds := []Prediction{
		{T: date(2025, 3, 1), Value: 100, LowerBound: 80, UpperBound: 120},
	}
	events := []Event{
		{Name: "New Event", Date: date(2025, 3, 1), ExpectedImpactPct: 50.0, LeadDays: 0, LagDays: 0},
	}
	// No learned impact — should fall back to hint (1.5x).
	adjusted := ApplyEventAdjustments(preds, events, map[string]LearnedImpact{})
	if math.Abs(adjusted[0].Value-150) > 0.01 {
		t.Errorf("hint fallback value = %.2f, want 150", adjusted[0].Value)
	}
}
