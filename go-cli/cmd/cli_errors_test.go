package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"
	"testing"

	"p202/internal/api"
)

func TestExitCodeForError_SeesThroughWrapping(t *testing.T) {
	cases := []struct {
		name string
		err  error
		want int
	}{
		{"bare 401", &api.APIError{Status: 401, Message: "bad key"}, ExitAuth},
		{"wrapped 401", fmt.Errorf("fetching historical data: %w", &api.APIError{Status: 401}), ExitAuth},
		{"wrapped 500", fmt.Errorf("fetching: %w", &api.APIError{Status: 500}), ExitServer},
		{"wrapped network", fmt.Errorf("fetching: %w", &api.RequestError{Kind: "network", Op: "GET", Err: fmt.Errorf("refused")}), ExitNetwork},
		{"hinted wrapped 403", withHint(fmt.Errorf("x: %w", &api.APIError{Status: 403}), "check key"), ExitAuth},
		{"validation", validationError("bad flag"), ExitValidation},
		{"wrapped validation", fmt.Errorf("outer: %w", validationError("bad flag")), ExitValidation},
		{"partial", partialFailureError("2 of 5 failed"), ExitPartialFailure},
		{"plain", fmt.Errorf("something"), ExitValidation},
		{"nil", nil, ExitOK},
	}
	for _, tc := range cases {
		if got := exitCodeForError(tc.err); got != tc.want {
			t.Errorf("%s: exit code = %d, want %d", tc.name, got, tc.want)
		}
	}
}

func TestHintFor_ExplicitHintWins(t *testing.T) {
	err := validationError("--tracker is required").WithHint("run `p202 tracker list`")
	if got := hintFor(err); got != "run `p202 tracker list`" {
		t.Errorf("hint = %q", got)
	}
	wrapped := withHint(fmt.Errorf("fetch: %w", &api.APIError{Status: 401}), "custom")
	if got := hintFor(wrapped); got != "custom" {
		t.Errorf("explicit hint should win over the 401 default, got %q", got)
	}
	// Generic API hints still apply to bare API errors.
	if got := hintFor(&api.APIError{Status: 500}); !strings.Contains(got, "system health") {
		t.Errorf("5xx hint = %q", got)
	}
	if got := hintFor(&api.APIError{Status: 429}); !strings.Contains(got, "Rate limited") {
		t.Errorf("429 hint = %q", got)
	}
}

func TestHintFor_GenericHelpFallback(t *testing.T) {
	old := activeCommandPath
	defer func() { activeCommandPath = old }()
	activeCommandPath = "p202 rotator create"
	if got := hintFor(validationError("required flag --name is missing")); !strings.Contains(got, "p202 rotator create --help") {
		t.Errorf("expected --help fallback naming the command, got %q", got)
	}
	activeCommandPath = ""
	if got := hintFor(validationError("required flag --name is missing")); got != "" {
		t.Errorf("no command path: expected no fallback hint, got %q", got)
	}
}

func TestErrorEnvelope(t *testing.T) {
	old := activeCommandPath
	defer func() { activeCommandPath = old }()
	activeCommandPath = "p202 campaign create"

	err := fmt.Errorf("creating campaign: %w", &api.APIError{
		Status: 422, Message: "validation failed",
		FieldErrors: map[string]string{"campaign_name": "required"},
	})
	env := errorEnvelope(err)["error"].(map[string]interface{})
	if env["category"] != "validation" || env["exit_code"] != ExitValidation {
		t.Errorf("category/exit = %v/%v", env["category"], env["exit_code"])
	}
	if env["http_status"] != 422 {
		t.Errorf("http_status = %v", env["http_status"])
	}
	if env["command"] != "p202 campaign create" {
		t.Errorf("command = %v", env["command"])
	}
	fe, _ := env["field_errors"].(map[string]string)
	if fe["campaign_name"] != "required" {
		t.Errorf("field_errors = %v", env["field_errors"])
	}
	if env["hint"] == nil || env["hint"] == "" {
		t.Error("expected a hint for a 422 with field errors")
	}
}

func TestPrintError_JSONVsText(t *testing.T) {
	oldJSON, oldND := jsonOutput, ndjsonOutput
	defer func() { jsonOutput, ndjsonOutput = oldJSON, oldND }()
	err := withHint(fmt.Errorf("fetching: %w", &api.APIError{Status: 401, Message: "bad key"}), "set a key")

	jsonOutput, ndjsonOutput = true, false
	var buf bytes.Buffer
	printError(&buf, err)
	var parsed map[string]map[string]interface{}
	if jErr := json.Unmarshal(buf.Bytes(), &parsed); jErr != nil {
		t.Fatalf("--json error output is not JSON: %v\n%s", jErr, buf.String())
	}
	if parsed["error"]["category"] != "auth" || parsed["error"]["hint"] != "set a key" || parsed["error"]["exit_code"] != float64(ExitAuth) {
		t.Errorf("envelope = %v", parsed["error"])
	}

	jsonOutput, ndjsonOutput = false, false
	buf.Reset()
	printError(&buf, err)
	out := buf.String()
	if !strings.HasPrefix(out, "Error [auth]: ") || !strings.Contains(out, "\nHint: set a key\n") {
		t.Errorf("text output = %q", out)
	}
}
