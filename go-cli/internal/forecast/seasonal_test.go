package forecast

import (
	"math"
	"testing"
	"time"
)

func TestBuildWeekdayWeights_ValidData(t *testing.T) {
	rows := []map[string]interface{}{
		{"day_of_week": "Monday", "total_income": 120.0},
		{"day_of_week": "Tuesday", "total_income": 80.0},
		{"day_of_week": "Wednesday", "total_income": 100.0},
		{"day_of_week": "Thursday", "total_income": 110.0},
		{"day_of_week": "Friday", "total_income": 90.0},
		{"day_of_week": "Saturday", "total_income": 60.0},
		{"day_of_week": "Sunday", "total_income": 140.0},
	}

	weights := BuildWeekdayWeights(rows, "total_income")
	if weights == nil {
		t.Fatal("expected non-nil weights")
	}

	// Mean = (120+80+100+110+90+60+140) / 7 = 700/7 = 100.
	expected := map[time.Weekday]float64{
		time.Monday:    1.2,
		time.Tuesday:   0.8,
		time.Wednesday: 1.0,
		time.Thursday:  1.1,
		time.Friday:    0.9,
		time.Saturday:  0.6,
		time.Sunday:    1.4,
	}

	for dow, want := range expected {
		got, ok := weights[dow]
		if !ok {
			t.Errorf("missing weight for %s", dow)
			continue
		}
		if math.Abs(got-want) > 0.001 {
			t.Errorf("%s weight = %.3f, want %.3f", dow, got, want)
		}
	}
}

func TestBuildWeekdayWeights_EmptyData(t *testing.T) {
	weights := BuildWeekdayWeights(nil, "total_income")
	if weights != nil {
		t.Error("expected nil weights for empty data")
	}
}

func TestBuildWeekdayWeights_NoMatchingMetric(t *testing.T) {
	rows := []map[string]interface{}{
		{"day_of_week": "Monday", "total_clicks": 100.0},
	}
	weights := BuildWeekdayWeights(rows, "total_income")
	if weights != nil {
		t.Error("expected nil weights when metric is missing")
	}
}

func TestBuildWeekdayWeights_StringValues(t *testing.T) {
	rows := []map[string]interface{}{
		{"day_of_week": "Monday", "total_income": "150.0"},
		{"day_of_week": "Tuesday", "total_income": "50.0"},
	}
	weights := BuildWeekdayWeights(rows, "total_income")
	if weights == nil {
		t.Fatal("expected non-nil weights with string values")
	}
	if len(weights) != 2 {
		t.Errorf("expected 2 weights, got %d", len(weights))
	}
}

func TestBuildWeekdayWeights_PartialDays(t *testing.T) {
	rows := []map[string]interface{}{
		{"day_of_week": "Monday", "total_income": 200.0},
		{"day_of_week": "Friday", "total_income": 100.0},
	}
	weights := BuildWeekdayWeights(rows, "total_income")
	if weights == nil {
		t.Fatal("expected non-nil weights")
	}

	// Mean = (200+100)/2 = 150.
	if math.Abs(weights[time.Monday]-200.0/150.0) > 0.001 {
		t.Errorf("Monday weight = %.3f, want ~%.3f", weights[time.Monday], 200.0/150.0)
	}
	if math.Abs(weights[time.Friday]-100.0/150.0) > 0.001 {
		t.Errorf("Friday weight = %.3f, want ~%.3f", weights[time.Friday], 100.0/150.0)
	}
}

func TestBuildWeekdayWeights_InvalidDayName(t *testing.T) {
	rows := []map[string]interface{}{
		{"day_of_week": "Notaday", "total_income": 100.0},
	}
	weights := BuildWeekdayWeights(rows, "total_income")
	if weights != nil {
		t.Error("expected nil weights for invalid day name")
	}
}

func TestExtractFloat(t *testing.T) {
	tests := []struct {
		input    interface{}
		expected float64
	}{
		{42.5, 42.5},
		{float32(3.14), 3.14},
		{100, 100.0},
		{int64(200), 200.0},
		{"  99.5 ", 99.5},
		{"", 0},
		{nil, 0},
		{true, 0},
		{"abc", 0},
	}

	for _, tc := range tests {
		got := extractFloat(tc.input)
		if math.Abs(got-tc.expected) > 0.01 {
			t.Errorf("extractFloat(%v) = %.2f, want %.2f", tc.input, got, tc.expected)
		}
	}
}
