package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
)

// breakevenRows are landing-page rows, where click-throughs are fewer than clicks: the server's conv_rate is leads per
// click-through (1 of 2 is 50%), and break-even prices a click, so it needs leads per click (1 of 4 is 25%). The
// sub-cent CPC is one a rounding to 4 decimals changes (0.00125 is 0.0013).
const breakevenRows = `{"data":[
 {"id":5,"name":"lp shoes","total_clicks":4,"total_click_throughs":2,"total_leads":1,"total_income":12.5,"total_cost":0.005,
  "total_net":12.495,"epc":3.125,"avg_cpc":0.00125,"conv_rate":50,"roi":249900,"cpa":0.005},
 {"id":6,"name":"lp boots","total_clicks":3,"total_click_throughs":1,"total_leads":1,"total_income":12.5,"total_cost":0.00375,
  "total_net":12.49625,"epc":4.166666666,"avg_cpc":0.00125,"conv_rate":100,"roi":333233.333333333,"cpa":0.00375},
 {"id":7,"name":"lp no clicks","total_clicks":0,"total_click_throughs":0,"total_leads":1,"total_income":12.5,"total_cost":0,
  "total_net":12.5,"epc":0,"avg_cpc":0,"conv_rate":0,"roi":0,"cpa":0},
 {"id":8,"name":"lp no leads","total_clicks":7,"total_click_throughs":7,"total_leads":0,"total_income":0,"total_cost":1.23,
  "total_net":-1.23,"epc":0,"avg_cpc":0.175714285,"conv_rate":0,"roi":-100,"cpa":0}]}`

func breakevenServer(t *testing.T) *httptest.Server {
	return httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/1"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":1,"aff_campaign_payout":12.5}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			_, _ = w.Write([]byte(breakevenRows))
		default:
			t.Errorf("unexpected request %s", r.URL.Path)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
}

func rowsByID(t *testing.T, stdout string) map[string]map[string]interface{} {
	t.Helper()
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("not JSON rows: %v\n%s", err, stdout)
	}
	out := map[string]map[string]interface{}{}
	for _, r := range resp.Data {
		out[jsonString(r["id"])] = r
	}
	return out
}

func jsonString(v interface{}) string {
	b, _ := json.Marshal(v)
	return string(b)
}

// TestReportBreakevenKeepsTheBreakdownsFigures holds `report breakeven` to `report breakdown` for the same rows:
// every figure the breakdown shows is the breakeven row's too, unchanged. It wrote leads ÷ clicks over conv_rate,
// so a landing page's keyword at 50% in the breakdown read 25% there, and rounded avg_cpc to 4 decimals. Its own
// rate is click_conv_rate, the one breakeven_cpc is payout × (÷ 100).
func TestReportBreakevenKeepsTheBreakdownsFigures(t *testing.T) {
	srv := breakevenServer(t)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--aff-campaign-id", "1", "--json")
	if err != nil {
		t.Fatalf("report breakdown: %v", err)
	}
	breakdown := rowsByID(t, out)
	out, _, err = executeCommand("report", "breakeven", "--aff-campaign-id", "1", "--json")
	if err != nil {
		t.Fatalf("report breakeven: %v", err)
	}
	breakeven := rowsByID(t, out)
	if len(breakdown) != 4 || len(breakeven) != len(breakdown) {
		t.Fatalf("rows: breakdown %d, breakeven %d", len(breakdown), len(breakeven))
	}
	for id, b := range breakdown {
		e := breakeven[id]
		for field, want := range b {
			if !reflect.DeepEqual(e[field], want) {
				t.Errorf("row %s %s: breakeven %s, breakdown %s", id, field, jsonString(e[field]), jsonString(want))
			}
		}
	}

	// [click_conv_rate, breakeven_cpc, verdict]: the click rate and payout 12.5 × it.
	want := map[string][]interface{}{
		"5": {25.0, 3.125, "PROFITABLE"},
		"6": {33.3333, 4.1667, "PROFITABLE"},
		"7": {0.0, 0.0, "PROFITABLE"},
		"8": {0.0, 0.0, "NO-CONVERT"},
	}
	for id, w := range want {
		e := breakeven[id]
		got := []interface{}{e["click_conv_rate"], e["breakeven_cpc"], e["verdict"]}
		if !reflect.DeepEqual(got, w) {
			t.Errorf("row %s: click_conv_rate, breakeven_cpc, verdict = %v, want %v", id, got, w)
		}
	}
}
