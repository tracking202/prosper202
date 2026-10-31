package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
)

// optimizeCampaignRows are campaign 9's figures, from a landing page: 10 clicks, 4 click-throughs and 2 conversions.
// The reports' conv_rate is per click-through (2 of 4 is 50%); break-even prices a click, at 2 of 10 (20%).
const optimizeCampaignRows = `{"data":[
 {"id":9,"name":"lp offer","total_clicks":10,"total_click_throughs":4,"total_leads":2,"total_income":20,"total_cost":5,
  "total_net":15,"epc":2,"avg_cpc":0.5,"conv_rate":50,"roi":300,"cpa":2.5},
 {"id":11,"name":"direct offer","total_clicks":10,"total_click_throughs":10,"total_leads":1,"total_income":30,"total_cost":5,
  "total_net":25,"epc":3,"avg_cpc":0.5,"conv_rate":10,"roi":500,"cpa":5}]}`

const optimizeCampaignSummary = `{"data":{"total_clicks":10,"total_click_throughs":4,"total_leads":2,"total_income":20,
 "total_cost":5,"total_net":15,"epc":2,"avg_cpc":0.5,"conv_rate":50,"roi":300,"cpa":2.5}}`

func optimizeCampaignServer(t *testing.T) *httptest.Server {
	return httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/9"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_payout":10}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/summary") && r.URL.Query().Get("aff_campaign_id") == "9":
			_, _ = w.Write([]byte(optimizeCampaignSummary))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown") && r.URL.Query().Get("breakdown") == "campaign":
			_, _ = w.Write([]byte(optimizeCampaignRows))
		default:
			t.Errorf("unexpected request %s", r.URL)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
}

// TestCampaignOptimizeNamesItsConversionRates holds `campaign optimize`'s diagnosis to `report breakdown` for the same
// campaign: its conv_rate is the breakdown's (conversions ÷ click-throughs), and the rate break-even uses is named as
// per click. It printed conversions ÷ clicks as "CVR", so on landing-page traffic a campaign at 50% in every report
// read 20% there. The MCP tool campaign_optimize runs the same command, locally and in the hosted relay.
func TestCampaignOptimizeNamesItsConversionRates(t *testing.T) {
	srv := optimizeCampaignServer(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("report", "breakdown", "--breakdown", "campaign", "--json")
	if err != nil {
		t.Fatalf("report breakdown: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(out), &resp); err != nil {
		t.Fatalf("not JSON: %v\n%s", err, out)
	}
	var row map[string]interface{}
	for _, r := range resp.Data {
		if fmt.Sprint(r["id"]) == "9" {
			row = r
		}
	}
	if row == nil {
		t.Fatalf("no campaign 9 in the breakdown: %s", out)
	}
	leadsPerClick := toFloat(row["total_leads"]) / toFloat(row["total_clicks"]) * 100
	if toFloat(row["conv_rate"]) == leadsPerClick {
		t.Fatalf("the rows must be landing-page traffic, where the two rates differ: %v", row)
	}
	convRate := fmt.Sprintf("conv_rate %.1f%% of click-throughs", toFloat(row["conv_rate"]))
	perClick := fmt.Sprintf("%.1f%% of clicks", leadsPerClick)

	for i, run := range [][]string{
		{"campaign", "optimize", "9", "--json"},
		{"mcp", "call", "campaign_optimize", "--input", `{"campaign_id":"9"}`},
		{"mcp", "call", "campaign_optimize", "--input", `{"campaign_id":"9"}`}, // as the hosted relay runs it
	} {
		withHostedRun(t, i == 2)
		_, stderr, err := mcpExecute(t, run...)
		if err != nil {
			t.Fatalf("%v: %v\n%s", run, err, stderr)
		}
		line := regexp.MustCompile(`(?m)^Campaign 9 .*$`).FindString(stderr)
		if !strings.Contains(line, convRate) || !strings.Contains(line, perClick) {
			t.Errorf("%v: the diagnosis %q should give the breakdown's %q and name its own rate %q", run, line, convRate, perClick)
		}
		if strings.Contains(line, "CVR") {
			t.Errorf("%v: %q still calls a rate CVR, which reads as the reports' conv_rate", run, line)
		}
		// Break-even is the payout (10) × conversions per click (20%).
		if !strings.Contains(line, "break-even CPC $2.000") {
			t.Errorf("%v: %q: break-even should be $10 × 20%% = $2.000", run, line)
		}
	}
}

// A summary without conv_rate (a server that does not send it) gives the rate per click alone, not a conv_rate of 0.
func TestCampaignOptimizeWithoutAConvRate(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/9"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_payout":10}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(`{"data":{"total_clicks":10,"total_leads":2,"total_income":20,"total_cost":5}}`))
		default:
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, stderr, err := executeCommand("campaign", "optimize", "9", "--json")
	if err != nil {
		t.Fatalf("campaign optimize: %v\n%s", err, stderr)
	}
	if line := regexp.MustCompile(`(?m)^Campaign 9 .*$`).FindString(stderr); !strings.Contains(line, "(20.0% of clicks)") {
		t.Errorf("diagnosis %q: want the rate per click alone", line)
	}
}
