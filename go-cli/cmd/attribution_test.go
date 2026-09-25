package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

// The multi-touch attribution commands: every flag check runs before a
// client is built (these tests run with no config at all, as CI does), and
// every error carries the validation category and, where the message alone
// leaves a choice, a hint.
func TestAttributionCommandsValidateBeforeTheyConnect(t *testing.T) {
	setTestHome(t, t.TempDir())

	for _, tc := range []struct {
		name    string
		args    []string
		message string
		hint    string
	}{
		{"create without a name", []string{"attribution", "model", "create", "--model-type", "linear"}, "required flag --model-name is missing", ""},
		{"create without a type", []string{"attribution", "model", "create", "--model-name", "Linear"}, "required flag --model-type is missing; valid: last_touch, first_touch, linear, time_decay, position_based", ""},
		{"create with a model the engine cannot compute", []string{"attribution", "model", "create", "--model-name", "A", "--model-type", "algorithmic"}, `invalid --model-type "algorithmic"; valid: last_touch`, ""},
		{"create with unparseable weighting config", []string{"attribution", "model", "create", "--model-name", "L", "--model-type", "linear", "--weighting-config", "{"}, "invalid --weighting-config: a JSON object is required", "half_life_hours"},
		{"a weighting config that is not an object", []string{"attribution", "model", "create", "--model-name", "L", "--model-type", "linear", "--weighting-config", "[1]"}, "invalid --weighting-config", "position_based"},
		{"snake_case flags still work", []string{"attribution", "model", "create", "--model_name", "L", "--model_type", "linear", "--lookback_days", "0"}, "invalid --lookback-days 0", ""},
		{"a lookback over a year", []string{"attribution", "model", "update", "3", "--lookback-days", "400"}, "invalid --lookback-days 400", ""},
		{"a status the engine sets", []string{"attribution", "model", "update", "3", "--status", "invalid"}, `invalid --status "invalid"; valid: active, inactive`, ""},
		{"update with no fields", []string{"attribution", "model", "update", "1"}, "no fields specified", ""},
		{"a model id that is not an id", []string{"attribution", "model", "get", "abc"}, `model id must be a positive whole number, got "abc"`, "p202 attribution model list"},
		{"list with an unknown type", []string{"attribution", "model", "list", "--type", "assisted"}, `invalid --type "assisted"`, ""},
		{"breakdown by an unknown dimension", []string{"attribution", "breakdown", "--group-by", "browser"}, `invalid --group-by "browser"; valid: campaign, traffic_source`, ""},
		{"breakdown with a model name instead of an id", []string{"attribution", "breakdown", "--model", "linear"}, `invalid --model "linear"`, "p202 attribution model list"},
		{"breakdown comparing a model with itself", []string{"attribution", "breakdown", "--model", "4", "--compare-model", "4"}, "--compare-model must differ from --model", ""},
		{"breakdown with a period and a range", []string{"attribution", "breakdown", "--period", "last7", "--time-from", "1"}, "--period and --time-from/--time-to are exclusive", "--period last30"},
		{"breakdown with an unknown period", []string{"attribution", "breakdown", "--period", "lastweek"}, `invalid --period "lastweek"; valid: today`, ""},
		{"breakdown with a date for a time", []string{"attribution", "breakdown", "--time-from", "2026-09-01"}, `invalid --time-from "2026-09-01": unix time in seconds`, ""},
		{"journeys with a bad time", []string{"attribution", "journeys", "--time-to", "soon"}, `invalid --time-to "soon"`, ""},
		{"journey of a non-id", []string{"attribution", "journey", "x1"}, `conversion id must be a positive whole number, got "x1"`, "p202 conversion list"},
		{"queue with a huge limit", []string{"attribution", "queue", "--limit", "5000"}, "invalid --limit 5000; 1 to 500", ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("expected a validation error for %v", tc.args)
			}
			if !strings.Contains(err.Error(), tc.message) {
				t.Errorf("message = %q, want it to contain %q", err.Error(), tc.message)
			}
			assertValidationError(t, err)
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d", code, ExitValidation)
			}
			if tc.hint != "" {
				if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
					t.Errorf("hint = %q, want it to contain %q", hint, tc.hint)
				}
			}
		})
	}
}

func TestAttributionBreakdownMapsFlagsToTheReportsApi(t *testing.T) {
	var gotPath string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"key":"1","name":"Campaign 1","attributed_revenue":"12.00000"}],"totals":{"attributed_revenue":"12.00000"},"meta":{}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("attribution", "breakdown", "--group-by", "traffic_source", "--model", "3", "--compare-model", "4", "--period", "last7", "--limit", "20"); err != nil {
		t.Fatalf("breakdown: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/attribution/reports/breakdown") {
		t.Errorf("path = %q", gotPath)
	}
	for param, want := range map[string]string{"group_by": "traffic_source", "model_id": "3", "compare_model_id": "4", "period": "last7", "limit": "20"} {
		if got := gotParams.Get(param); got != want {
			t.Errorf("param %s = %q, want %q", param, got, want)
		}
	}
}

func TestAttributionModelCreateSendsTheTypesTheApiTakes(t *testing.T) {
	var body map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		if err := json.Unmarshal(raw, &body); err != nil {
			t.Errorf("body is not JSON: %s", raw)
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"data":{"model_id":9}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("attribution", "model", "create", "--model-name", "Decay", "--model-type", "time_decay",
		"--weighting-config", `{"half_life_hours":24}`, "--lookback-days", "60", "--default"); err != nil {
		t.Fatalf("create: %v", err)
	}
	// A lookback sent as the string "60" is refused by the server (no
	// casting), so the CLI must send a number, and the config an object.
	if v, ok := body["lookback_days"].(float64); !ok || v != 60 {
		t.Errorf("lookback_days = %#v, want the number 60", body["lookback_days"])
	}
	if cfg, ok := body["weighting_config"].(map[string]interface{}); !ok || cfg["half_life_hours"] != float64(24) {
		t.Errorf("weighting_config = %#v, want an object", body["weighting_config"])
	}
	if body["is_default"] != true {
		t.Errorf("is_default = %#v, want true", body["is_default"])
	}
	if _, sent := body["status"]; sent {
		t.Errorf("status was sent though no --status was given")
	}
}

func TestDeletingTheDefaultModelSaysWhatToDoInstead(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(409)
		w.Write([]byte(`{"error":true,"message":"Model 1 is the default model and cannot be deleted; make another model the default first.","status":409}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("attribution", "model", "delete", "1", "--force")
	if err == nil {
		t.Fatal("expected the 409 to surface")
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 attribution model update <other-id> --default") {
		t.Errorf("hint = %q", hint)
	}
}
