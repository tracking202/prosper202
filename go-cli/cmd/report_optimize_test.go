package cmd

import "testing"

func TestEnrichBreakevenComputesMarginAndVerdict(t *testing.T) {
	rows := []map[string]interface{}{
		{"total_clicks": 100.0, "total_leads": 20.0, "total_cost": 50.0}, // CVR 20%, CPC 0.50
	}
	enrichBreakeven(rows, 2.20, 0) // payout 2.20 -> breakeven 0.44
	r := rows[0]
	if be := toFloat(r["breakeven_cpc"]); be < 0.43 || be > 0.45 {
		t.Errorf("breakeven_cpc = %v, want ~0.44", be)
	}
	if m := toFloat(r["margin"]); m > -0.05 || m < -0.07 { // 0.44 - 0.50 = -0.06
		t.Errorf("margin = %v, want ~-0.06", m)
	}
	if r["verdict"] != "OVER-BID" {
		t.Errorf("verdict = %v, want OVER-BID", r["verdict"])
	}
}

func TestEnrichBreakevenProfitable(t *testing.T) {
	rows := []map[string]interface{}{
		{"total_clicks": 100.0, "total_leads": 30.0, "total_cost": 10.0}, // CVR 30%, CPC 0.10
	}
	enrichBreakeven(rows, 2.20, 0) // breakeven 0.66 > 0.10
	if rows[0]["verdict"] != "PROFITABLE" {
		t.Errorf("verdict = %v, want PROFITABLE", rows[0]["verdict"])
	}
}

func TestBreakevenVerdictNoConvert(t *testing.T) {
	if v := breakevenVerdict(0, 50, -1); v != "NO-CONVERT" {
		t.Errorf("zero leads with spend should be NO-CONVERT, got %v", v)
	}
	if v := breakevenVerdict(0, 0, 0); v != "NO-DATA" {
		t.Errorf("zero leads no spend should be NO-DATA, got %v", v)
	}
}

func TestClassifyBuckets(t *testing.T) {
	// zero conversions with spend -> CUT
	if b, _ := classify(100, 0, 50, -50, 0.5, 2.2, 0); b != "CUT" {
		t.Errorf("zero-conv spend should be CUT, got %s", b)
	}
	// over-bid (CPC above breakeven) -> CUT
	if b, _ := classify(100, 10, 60, -38, 0.6, 2.2, 0); b != "CUT" { // breakeven 0.22 < 0.60
		t.Errorf("over-bid should be CUT, got %s", b)
	}
	// profitable -> SCALE
	if b, _ := classify(100, 20, 10, 34, 0.1, 2.2, 0); b != "SCALE" {
		t.Errorf("profitable should be SCALE, got %s", b)
	}
}

func TestResolveDimensionAndMetric(t *testing.T) {
	if resolveDimension("lp") != "landing_page" {
		t.Error("lp should map to landing_page")
	}
	if resolveDimension("source") != "ppc_account" {
		t.Error("source should map to ppc_account")
	}
	if resolveMetric("profit") != "total_net" {
		t.Error("profit should map to total_net")
	}
	if resolveMetric("clicks") != "total_clicks" {
		t.Error("clicks should map to total_clicks")
	}
}
