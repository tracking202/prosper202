package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// maskedReports answers every report as the server does to a role without
// access_to_campaign_data: absolute figures null, ratios kept, masked: true.
func maskedReports(t *testing.T) *httptest.Server {
	t.Helper()
	row := `{"id":3,"name":"A","total_clicks":null,"total_click_throughs":null,"total_leads":null,` +
		`"total_income":null,"total_cost":null,"total_net":null,"epc":2.5,"avg_cpc":0.5,"conv_rate":10,"roi":20,"cpa":5}`
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(`{"data":` + row + `,"masked":true}`))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			if r.URL.Query().Get("offset") != "" && r.URL.Query().Get("offset") != "0" {
				_, _ = w.Write([]byte(`{"data":[],"masked":true}`))
				return
			}
			_, _ = w.Write([]byte(`{"data":[` + row + `],"masked":true}`))
		case strings.HasSuffix(r.URL.Path, "/reports/timeseries"):
			_, _ = w.Write([]byte(`{"data":[{"period":"2026-10-01","total_clicks":null,"total_income":null,"epc":1}],"interval":"day","truncated":false,"limit":2000,"masked":true}`))
		case strings.Contains(r.URL.Path, "/campaigns/"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":3,"aff_campaign_name":"A"}}`))
		default:
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
}

// A command that sums, ranks or forecasts from absolute figures refuses a
// masked answer -- its nulls are hidden values, and read as zeros they
// would print confident wrong numbers -- with the auth exit code and a
// hint; a command that shows the answer shows it.
func TestCommandsThatComputeRefuseAMaskedAnswer(t *testing.T) {
	srv := maskedReports(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	for _, args := range [][]string{
		{"campaign", "optimize", "3"},
		{"report", "losers", "--breakdown", "ppc_account"},
		{"forecast", "--metric", "clicks"},
		{"analytics", "--group-by", "country", "--split-at", "2026-09-04"},
	} {
		stdout, _, err := executeCommand(append(args, "--json")...)
		if err == nil {
			t.Errorf("%v: computed from a masked answer: %s", args, stdout)
			continue
		}
		if code := exitCodeForError(err); code != ExitAuth {
			t.Errorf("%v: exit %d, want %d (%v)", args, code, ExitAuth, err)
		}
		if !strings.Contains(err.Error(), "access_to_campaign_data") || !strings.Contains(hintFor(err), "p202 whoami") {
			t.Errorf("%v: the refusal must name the permission and the next step: %v / %q", args, err, hintFor(err))
		}
	}

	stdout, _, err := executeCommand("report", "summary", "--json")
	if err != nil {
		t.Fatalf("report summary shows a masked answer: %v", err)
	}
	if !strings.Contains(stdout, `"masked": true`) || !strings.Contains(stdout, `"epc": 2.5`) {
		t.Errorf("report summary --json: the flag and the ratios, got %s", stdout)
	}
}

// Summing a summary across profiles names a masked profile as failed rather
// than leaving its hidden totals out of the sum without a word.
func TestAMaskedProfileIsNamedNotSummedAsZero(t *testing.T) {
	plain := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":{"total_clicks":7,"total_leads":2}}`))
	}))
	defer plain.Close()
	masked := maskedReports(t)
	defer masked.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":   {"url": plain.URL, "api_key": "prod-key-123456"},
		"viewer": {"url": masked.URL, "api_key": "viewer-key-123456"},
	})

	stdout, _, err := executeCommand("report", "summary", "--profiles", "prod,viewer", "--json")
	if err != nil {
		t.Fatalf("a partial answer still answers: %v", err)
	}
	var parsed struct {
		Errors     []string               `json:"errors"`
		Aggregated map[string]interface{} `json:"aggregated"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON: %v\n%s", err, stdout)
	}
	if len(parsed.Errors) != 1 || !strings.HasPrefix(parsed.Errors[0], "viewer:") || !strings.Contains(parsed.Errors[0], "access_to_campaign_data") {
		t.Errorf("the masked profile is named as failed, got %v", parsed.Errors)
	}
	if got, _ := parsed.Aggregated["total_clicks"].(float64); got != 7 {
		t.Errorf("aggregated total_clicks = %v, want prod's 7", parsed.Aggregated["total_clicks"])
	}
}
