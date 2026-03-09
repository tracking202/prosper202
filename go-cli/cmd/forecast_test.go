package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

func makeTimeseriesResponse(n int, metric string) string {
	buckets := make([]map[string]interface{}, n)
	for i := 0; i < n; i++ {
		buckets[i] = map[string]interface{}{
			"bucket_start": 1704067200 + i*86400, // 2024-01-01 + i days
			metric:         100.0 + float64(i)*5,
		}
	}
	data, _ := json.Marshal(map[string]interface{}{"data": buckets})
	return string(data)
}

func makeWeekpartResponse(metric string) string {
	days := []string{"Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"}
	rows := make([]map[string]interface{}, len(days))
	for i, day := range days {
		rows[i] = map[string]interface{}{
			"day_of_week": day,
			metric:        100.0 + float64(i)*10,
		}
	}
	data, _ := json.Marshal(map[string]interface{}{"data": rows})
	return string(data)
}

func TestForecastRequiresMetric(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast")
	if err == nil {
		t.Fatal("expected error when --metric is missing")
	}
	if !strings.Contains(err.Error(), "--metric is required") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestForecastRejectsInvalidMetric(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=bogus")
	if err == nil {
		t.Fatal("expected error for invalid metric")
	}
	if !strings.Contains(err.Error(), "unsupported metric") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestForecastRejectsInvalidMethod(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue", "--method=magic")
	if err == nil {
		t.Fatal("expected error for invalid method")
	}
	if !strings.Contains(err.Error(), "unsupported method") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestForecastRejectsExcessiveHorizon(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue", "--horizon=500")
	if err == nil {
		t.Fatal("expected error for horizon > 365")
	}
	if !strings.Contains(err.Error(), "cannot exceed 365") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestForecastLinearProducesOutput(t *testing.T) {
	var gotPaths []string
	var gotParams []url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPaths = append(gotPaths, r.URL.Path)
		gotParams = append(gotParams, r.URL.Query())

		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("forecast", "--metric=revenue", "--method=linear", "--horizon=5", "--json")
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}

	// Verify timeseries endpoint was called.
	foundTimeseries := false
	for _, p := range gotPaths {
		if strings.HasSuffix(p, "/reports/timeseries") {
			foundTimeseries = true
		}
	}
	if !foundTimeseries {
		t.Errorf("expected call to reports/timeseries, got paths: %v", gotPaths)
	}

	// Verify JSON output has predictions.
	var output map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &output); err != nil {
		t.Fatalf("output is not valid JSON: %v\nGot: %s", err, stdout)
	}
	data, ok := output["data"].([]interface{})
	if !ok {
		t.Fatalf("output missing data array, got: %s", stdout)
	}
	if len(data) != 5 {
		t.Errorf("expected 5 predictions, got %d", len(data))
	}

	// Verify meta.
	meta, ok := output["meta"].(map[string]interface{})
	if !ok {
		t.Fatal("output missing meta object")
	}
	if meta["method"] != "linear" {
		t.Errorf("meta.method = %v, want linear", meta["method"])
	}
	if meta["metric"] != "total_income" {
		t.Errorf("meta.metric = %v, want total_income", meta["metric"])
	}
}

func TestForecastMetricAliases(t *testing.T) {
	aliases := map[string]string{
		"clicks":      "total_clicks",
		"conversions": "total_leads",
		"leads":       "total_leads",
		"revenue":     "total_income",
		"income":      "total_income",
		"cost":        "total_cost",
		"profit":      "total_net",
		"net":         "total_net",
	}

	for alias, canonical := range aliases {
		t.Run(alias, func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(200)
				w.Write([]byte(makeTimeseriesResponse(30, canonical)))
			}))
			defer srv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key")

			stdout, _, err := executeCommand("forecast", fmt.Sprintf("--metric=%s", alias), "--method=sma", "--json")
			if err != nil {
				t.Fatalf("forecast with alias %q error: %v", alias, err)
			}

			var output map[string]interface{}
			if err := json.Unmarshal([]byte(stdout), &output); err != nil {
				t.Fatalf("invalid JSON: %v", err)
			}
			meta := output["meta"].(map[string]interface{})
			if meta["metric"] != canonical {
				t.Errorf("metric = %v, want %v", meta["metric"], canonical)
			}
		})
	}
}

func TestForecastPassesFilters(t *testing.T) {
	var capturedQuery url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/reports/timeseries") {
			capturedQuery = r.URL.Query()
		}
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast",
		"--metric=revenue",
		"--aff_campaign_id=42",
		"--ppc_account_id=7",
		"--country_id=3",
	)
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}

	if capturedQuery.Get("aff_campaign_id") != "42" {
		t.Errorf("aff_campaign_id = %q, want 42", capturedQuery.Get("aff_campaign_id"))
	}
	if capturedQuery.Get("ppc_account_id") != "7" {
		t.Errorf("ppc_account_id = %q, want 7", capturedQuery.Get("ppc_account_id"))
	}
	if capturedQuery.Get("country_id") != "3" {
		t.Errorf("country_id = %q, want 3", capturedQuery.Get("country_id"))
	}
}

func TestForecastWithSeasonalFetchesWeekpart(t *testing.T) {
	requestPaths := []string{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestPaths = append(requestPaths, r.URL.Path)
		w.WriteHeader(200)

		if strings.HasSuffix(r.URL.Path, "/reports/weekpart") {
			w.Write([]byte(makeWeekpartResponse("total_income")))
		} else {
			w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("forecast", "--metric=revenue", "--seasonal", "--json")
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}

	// Should have called both timeseries and weekpart.
	foundTimeseries := false
	foundWeekpart := false
	for _, p := range requestPaths {
		if strings.HasSuffix(p, "/reports/timeseries") {
			foundTimeseries = true
		}
		if strings.HasSuffix(p, "/reports/weekpart") {
			foundWeekpart = true
		}
	}
	if !foundTimeseries {
		t.Error("expected call to reports/timeseries")
	}
	if !foundWeekpart {
		t.Error("expected call to reports/weekpart when --seasonal is set")
	}

	// Meta should reflect seasonal=true.
	var output map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &output); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	meta := output["meta"].(map[string]interface{})
	if meta["seasonal"] != true {
		t.Errorf("meta.seasonal = %v, want true", meta["seasonal"])
	}
}

func TestForecastTooFewDataPoints(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(2, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue")
	if err == nil {
		t.Fatal("expected error for insufficient data points")
	}
	if !strings.Contains(err.Error(), "not enough data points") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestForecastPredictAlias(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("predict", "--metric=revenue")
	if err != nil {
		t.Fatalf("predict alias error: %v", err)
	}
}

func TestForecastDefaultHistory(t *testing.T) {
	var capturedQuery url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/reports/timeseries") {
			capturedQuery = r.URL.Query()
		}
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue")
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}

	if capturedQuery.Get("period") != "last90" {
		t.Errorf("default period = %q, want last90", capturedQuery.Get("period"))
	}
	if capturedQuery.Get("interval") != "day" {
		t.Errorf("default interval = %q, want day", capturedQuery.Get("interval"))
	}
}

func TestForecastCSVOutput(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("forecast", "--metric=revenue", "--csv")
	if err != nil {
		t.Fatalf("forecast csv error: %v", err)
	}

	// CSV output should have header and data rows.
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) < 2 {
		t.Errorf("expected at least header + 1 data row, got %d lines", len(lines))
	}
}

func TestForecastAllMethods(t *testing.T) {
	methods := []string{"linear", "sma", "wma", "holtwinters", "auto"}

	for _, method := range methods {
		t.Run(method, func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(200)
				w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
			}))
			defer srv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key")

			_, _, err := executeCommand("forecast", "--metric=revenue", fmt.Sprintf("--method=%s", method), "--json")
			if err != nil {
				t.Fatalf("forecast with method %q error: %v", method, err)
			}
		})
	}
}

func TestForecastEmptyTimeseriesResponse(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue")
	if err == nil {
		t.Fatal("expected error for empty timeseries")
	}
}

func TestForecastRejectsInvalidInterval(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue", "--interval=minute")
	if err == nil {
		t.Fatal("expected error for invalid interval")
	}
	if !strings.Contains(err.Error(), "unsupported interval") {
		t.Errorf("unexpected error: %v", err)
	}
}

func TestParseTimeseries(t *testing.T) {
	response := makeTimeseriesResponse(10, "total_income")
	series, err := parseTimeseries([]byte(response), "total_income")
	if err != nil {
		t.Fatalf("parseTimeseries error: %v", err)
	}
	if len(series) != 10 {
		t.Errorf("expected 10 points, got %d", len(series))
	}
	// Should be sorted chronologically.
	for i := 1; i < len(series); i++ {
		if series[i].T.Before(series[i-1].T) {
			t.Error("series not sorted chronologically")
		}
	}
}

func TestParseTimeseriesMissingMetric(t *testing.T) {
	response := makeTimeseriesResponse(10, "total_clicks")
	_, err := parseTimeseries([]byte(response), "total_income")
	if err == nil {
		t.Fatal("expected error when metric is missing from buckets")
	}
}

func makeForecastEventsResponse() string {
	events := []map[string]interface{}{
		{
			"event_id":            1,
			"event_name":          "Black Friday",
			"event_date":          "2024-01-15",
			"end_date":            "2024-01-15",
			"recurrence":          "yearly",
			"impact_type":         "boost",
			"expected_impact_pct": 50.0,
			"lead_days":           2,
			"lag_days":            1,
			"tags":                "shopping,us-holidays",
		},
		{
			"event_id":            2,
			"event_name":          "Maintenance Window",
			"event_date":          "2024-01-20",
			"end_date":            "2024-01-20",
			"recurrence":          "none",
			"impact_type":         "suppress",
			"expected_impact_pct": -30.0,
			"lead_days":           0,
			"lag_days":            0,
			"tags":                "internal",
		},
	}
	data, _ := json.Marshal(map[string]interface{}{"data": events})
	return string(data)
}

func TestForecastWithEventsFetchesEventsEndpoint(t *testing.T) {
	requestPaths := []string{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestPaths = append(requestPaths, r.URL.Path)
		w.WriteHeader(200)

		if strings.HasSuffix(r.URL.Path, "/forecast-events") {
			w.Write([]byte(makeForecastEventsResponse()))
		} else {
			w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("forecast", "--metric=revenue", "--events", "--json")
	if err != nil {
		t.Fatalf("forecast with --events error: %v", err)
	}

	// Should have called forecast-events endpoint.
	foundEvents := false
	for _, p := range requestPaths {
		if strings.HasSuffix(p, "/forecast-events") {
			foundEvents = true
		}
	}
	if !foundEvents {
		t.Error("expected call to forecast-events when --events is set")
	}

	// Meta should show events_active=true.
	var output map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &output); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	meta := output["meta"].(map[string]interface{})
	if meta["events_active"] != true {
		t.Errorf("meta.events_active = %v, want true", meta["events_active"])
	}
}

func TestForecastEventTagFiltersFetchesEvents(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		if strings.HasSuffix(r.URL.Path, "/forecast-events") {
			w.Write([]byte(makeForecastEventsResponse()))
		} else {
			w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue", "--event-tag=shopping", "--json")
	if err != nil {
		t.Fatalf("forecast with --event-tag error: %v", err)
	}
}

func TestForecastWithoutEventsFlagDoesNotFetchEvents(t *testing.T) {
	requestPaths := []string{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestPaths = append(requestPaths, r.URL.Path)
		w.WriteHeader(200)
		w.Write([]byte(makeTimeseriesResponse(30, "total_income")))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("forecast", "--metric=revenue", "--json")
	if err != nil {
		t.Fatalf("forecast error: %v", err)
	}

	for _, p := range requestPaths {
		if strings.HasSuffix(p, "/forecast-events") {
			t.Error("should not call forecast-events when --events is not set")
		}
	}
}

func TestParseForecastEvents(t *testing.T) {
	data := []byte(makeForecastEventsResponse())
	events, err := parseForecastEvents(data)
	if err != nil {
		t.Fatalf("parseForecastEvents error: %v", err)
	}
	if len(events) != 2 {
		t.Fatalf("expected 2 events, got %d", len(events))
	}
	if events[0].Name != "Black Friday" {
		t.Errorf("event[0].Name = %q, want Black Friday", events[0].Name)
	}
	if events[0].LeadDays != 2 {
		t.Errorf("event[0].LeadDays = %d, want 2", events[0].LeadDays)
	}
	if events[0].ExpectedImpactPct != 50.0 {
		t.Errorf("event[0].ExpectedImpactPct = %f, want 50.0", events[0].ExpectedImpactPct)
	}
	if events[0].Tags != "shopping,us-holidays" {
		t.Errorf("event[0].Tags = %q, want shopping,us-holidays", events[0].Tags)
	}
}

func TestParseForecastEventsInvalidJSON(t *testing.T) {
	_, err := parseForecastEvents([]byte(`not json`))
	if err == nil {
		t.Fatal("expected error for invalid JSON")
	}
}

func TestFilterEventsByTag(t *testing.T) {
	data := []byte(makeForecastEventsResponse())
	events, _ := parseForecastEvents(data)

	// Filter for "shopping" — should get Black Friday only.
	filtered := filterEventsByTag(events, "shopping")
	if len(filtered) != 1 {
		t.Fatalf("expected 1 event for tag 'shopping', got %d", len(filtered))
	}
	if filtered[0].Name != "Black Friday" {
		t.Errorf("filtered[0].Name = %q, want Black Friday", filtered[0].Name)
	}

	// Filter for "internal" — should get Maintenance Window only.
	filtered = filterEventsByTag(events, "internal")
	if len(filtered) != 1 {
		t.Fatalf("expected 1 event for tag 'internal', got %d", len(filtered))
	}
	if filtered[0].Name != "Maintenance Window" {
		t.Errorf("filtered[0].Name = %q, want Maintenance Window", filtered[0].Name)
	}

	// Filter for "nonexistent" — should get 0.
	filtered = filterEventsByTag(events, "nonexistent")
	if len(filtered) != 0 {
		t.Errorf("expected 0 events for tag 'nonexistent', got %d", len(filtered))
	}

	// Multi-tag filter — should get both.
	filtered = filterEventsByTag(events, "shopping,internal")
	if len(filtered) != 2 {
		t.Errorf("expected 2 events for tags 'shopping,internal', got %d", len(filtered))
	}
}

func TestForecastEndDate(t *testing.T) {
	base := time.Date(2024, 1, 1, 0, 0, 0, 0, time.UTC)

	dayEnd := forecastEndDate(base, "day", 7)
	if dayEnd != base.AddDate(0, 0, 7) {
		t.Errorf("day: got %v, want %v", dayEnd, base.AddDate(0, 0, 7))
	}

	weekEnd := forecastEndDate(base, "week", 4)
	if weekEnd != base.AddDate(0, 0, 28) {
		t.Errorf("week: got %v, want %v", weekEnd, base.AddDate(0, 0, 28))
	}

	monthEnd := forecastEndDate(base, "month", 3)
	if monthEnd != base.AddDate(0, 3, 0) {
		t.Errorf("month: got %v, want %v", monthEnd, base.AddDate(0, 3, 0))
	}
}

func TestRoundTo(t *testing.T) {
	tests := []struct {
		v    float64
		n    int
		want float64
	}{
		{3.14159, 2, 3.14},
		{3.145, 2, 3.15},
		{100.0, 0, 100.0},
		{1.006, 2, 1.01},
	}
	for _, tc := range tests {
		got := roundTo(tc.v, tc.n)
		if got != tc.want {
			t.Errorf("roundTo(%.5f, %d) = %.5f, want %.5f", tc.v, tc.n, got, tc.want)
		}
	}
}
