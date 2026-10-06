package cmd

import (
	"math"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// A "NaN" from one server used to reach json.Marshal and fail as a generic
// "unsupported value" error that named neither the profile nor the field.
func TestAllProfilesRejectsNonFiniteMetric(t *testing.T) {
	for _, command := range [][]string{
		{"dashboard", "--all-profiles", "--json"},
		{"report", "summary", "--all-profiles", "--json"},
	} {
		t.Run(command[0], func(t *testing.T) {
			prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(200)
				w.Write([]byte(`{"data":{"total_clicks":4,"total_income":"12.5"}}`))
			}))
			defer prodSrv.Close()
			stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(200)
				w.Write([]byte(`{"data":{"total_clicks":6,"total_income":"NaN"}}`))
			}))
			defer stageSrv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
				"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456"},
				"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456"},
			})

			stdout, _, err := executeCommand(command...)
			if err == nil {
				t.Fatalf("expected an error, got output:\n%s", stdout)
			}
			if strings.TrimSpace(stdout) != "" {
				t.Fatalf("stdout must stay empty on failure, got:\n%s", stdout)
			}
			for _, want := range []string{"staging", "total_income", "NaN"} {
				if !strings.Contains(err.Error(), want) {
					t.Fatalf("error %q does not name %q", err, want)
				}
			}
			if got := exitCodeForError(err); got != ExitValidation {
				t.Fatalf("exit code = %d, want %d", got, ExitValidation)
			}
			if h := hintFor(err); !strings.Contains(h, "p202 report summary --profile staging --json") {
				t.Fatalf("hint %q does not point at the staging server's raw response", h)
			}
		})
	}
}

func TestAggregateNumericFields(t *testing.T) {
	got, err := aggregateNumericFields(map[string]map[string]interface{}{
		"a": {"clicks": 4.0, "income": "1.5", "name": "x"},
		"b": {"clicks": 6.0, "income": ""},
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got["clicks"] != 10.0 || got["income"] != 1.5 {
		t.Fatalf("aggregate = %v", got)
	}
	if _, ok := got["name"]; ok {
		t.Fatalf("non-numeric field was aggregated: %v", got)
	}

	for name, rows := range map[string]map[string]map[string]interface{}{
		"inf value": {"a": {"cost": "Inf"}},
		"overflow":  {"a": {"cost": math.MaxFloat64}, "b": {"cost": math.MaxFloat64}},
	} {
		if _, err := aggregateNumericFields(rows); err == nil || !strings.Contains(err.Error(), "cost") {
			t.Fatalf("%s: err = %v, want an error naming the field", name, err)
		}
	}
}
