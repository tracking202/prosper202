package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

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

func TestParseHaving(t *testing.T) {
	f, op, v, ok := parseHaving("roi<0")
	if !ok || f != "roi" || op != "<" || v != 0 {
		t.Errorf("parseHaving(roi<0) = %q %q %v %v", f, op, v, ok)
	}
	f, op, _, ok = parseHaving("profit>=5")
	if !ok || f != "total_net" || op != ">=" { // profit -> total_net alias
		t.Errorf("parseHaving(profit>=5) field/op = %q %q", f, op)
	}
	if _, _, _, ok := parseHaving(""); ok {
		t.Error("empty having should not parse")
	}
}

func TestCompareNum(t *testing.T) {
	if !compareNum(-5, "<", 0) || compareNum(5, "<", 0) {
		t.Error("< comparison wrong")
	}
	if !compareNum(0, "=", 0) || !compareNum(3, ">=", 3) {
		t.Error("=/>= comparison wrong")
	}
}

func TestApplyStarterCheckMovesStartersToTest(t *testing.T) {
	check := &starterCheck{model: "First touch", minAssists: 1, byKey: map[string]map[string]interface{}{
		"7":  {"key": "7", "roi": 46.58, "assisted_conversions": 15.0},
		"8":  {"key": "8", "roi": -61.2, "assisted_conversions": 0.0},
		"9":  {"key": "9", "roi": nil, "assisted_conversions": 0.0},
		"10": {"key": "10", "roi": -80.0, "assisted_conversions": 3.0},
	}}
	starter := map[string]interface{}{"id": "7", "bucket": "CUT", "reason": "spent $3171.62, 0 conversions"}
	applyStarterCheck(starter, check)
	if starter["bucket"] != "TEST" {
		t.Errorf("a row with positive first-touch ROI should be TEST, got %v", starter["bucket"])
	}
	if r, _ := starter["reason"].(string); !strings.Contains(r, "First touch ROI +46.6%") || !strings.Contains(r, "15 assists") {
		t.Errorf("reason should name the model, ROI and assists: %q", r)
	}
	loser := map[string]interface{}{"id": "8", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(loser, check)
	if loser["bucket"] != "CUT" || toFloat(loser["first_touch_roi"]) != -61.2 || loser["assisted_conversions"] != int64(0) {
		t.Errorf("a row that loses under first touch and assists nothing stays CUT with its numbers: %#v", loser)
	}
	noCost := map[string]interface{}{"id": "9", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(noCost, check)
	if noCost["bucket"] != "CUT" {
		t.Errorf("no first-touch ROI (no cost in range) and no assists must not rescue a row, got %v", noCost["bucket"])
	}
	assister := map[string]interface{}{"id": "10", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(assister, check)
	if assister["bucket"] != "TEST" || !strings.Contains(assister["reason"].(string), "3 assists") || strings.Contains(assister["reason"].(string), "ROI") {
		t.Errorf("a row with assists >= --min-assists is TEST on assists alone: %#v", assister)
	}
	unknown := map[string]interface{}{"id": "99", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(unknown, check)
	if unknown["bucket"] != "CUT" {
		t.Errorf("a row the attribution report doesn't have stays CUT")
	}

	// Without a first-touch model the ROI isn't first-touch, so only assists count; --min-assists 0 turns that off.
	noModel := &starterCheck{model: "", minAssists: 1, byKey: check.byKey}
	r := map[string]interface{}{"id": "7", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(r, noModel)
	if r["bucket"] != "TEST" || r["first_touch_roi"] != nil {
		t.Errorf("without a first-touch model: assists decide and no first_touch_roi is reported: %#v", r)
	}
	off := &starterCheck{model: "", minAssists: 0, byKey: check.byKey}
	r = map[string]interface{}{"id": "10", "bucket": "CUT", "reason": "x"}
	applyStarterCheck(r, off)
	if r["bucket"] != "CUT" {
		t.Errorf("--min-assists 0 turns the assists rule off: %#v", r)
	}
}

// losersServer answers the classic breakdown with two zero-conversion sources and the attribution API with `models`;
// attribution breakdown pages come from attr(offset), which returns the status and body for that request.
func losersServer(t *testing.T, models string, attr func(q url.Values) (int, string), calls *[]url.Values) *httptest.Server {
	return httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown") && !strings.Contains(r.URL.Path, "attribution"):
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[
				{"id":"7","name":"ChatGPT Ads","total_clicks":"1124","total_leads":"0","total_cost":"3171.62","total_net":"-3171.62"},
				{"id":"8","name":"Display","total_clicks":"900","total_leads":"0","total_cost":"800.00","total_net":"-800.00"}]}`))
		case strings.HasSuffix(r.URL.Path, "/attribution/models"):
			w.WriteHeader(200)
			w.Write([]byte(models))
		case strings.Contains(r.URL.Path, "/campaigns/"): // the payout `losers` reads when --aff_campaign_id is set
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_payout":"0"}}`))
		case strings.HasSuffix(r.URL.Path, "/attribution/models/4"):
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"model_id":4,"model_name":"First touch","model_type":"first_touch","status":"active"}}`))
		case strings.HasSuffix(r.URL.Path, "/attribution/models/1"):
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"model_id":1,"model_name":"Last touch","model_type":"last_touch","status":"active"}}`))
		case strings.HasSuffix(r.URL.Path, "/attribution/reports/breakdown"):
			*calls = append(*calls, r.URL.Query())
			code, body := attr(r.URL.Query())
			w.WriteHeader(code)
			w.Write([]byte(body))
		default:
			t.Errorf("unexpected request %s", r.URL.Path)
		}
	}))
}

const firstTouchModels = `{"data":[{"model_id":4,"model_name":"First touch","model_type":"first_touch","status":"active","is_default":false}]}`

// onePage answers every attribution request with both rows: source 7 starts sales, source 8 doesn't.
func onePage(q url.Values) (int, string) {
	return 200, `{"data":[
		{"key":"7","name":"ChatGPT Ads","cost":"3171.62","attributed_revenue":"4649.00","roi":46.58,"assisted_conversions":15},
		{"key":"8","name":"Display","cost":"800.00","attributed_revenue":"120.00","roi":-85.0,"assisted_conversions":0}],
		"totals":{},"meta":{"groups":2,"backfill":null}}`
}

func runLosers(t *testing.T, models string, attr func(url.Values) (int, string), args ...string) (map[string]string, string, []url.Values) {
	t.Helper()
	var calls []url.Values
	srv := losersServer(t, models, attr, &calls)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	out, stderr, err := executeCommand(append([]string{"report", "losers", "--json"}, args...)...)
	if err != nil {
		t.Fatalf("losers: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(out), &resp); err != nil {
		t.Fatalf("output is not JSON: %v\n%s", err, out)
	}
	buckets := map[string]string{}
	for i, r := range resp.Data {
		buckets[fmt.Sprint(r["name"])] = fmt.Sprint(r["bucket"])
		if i > 0 && r["bucket"] == "CUT" && resp.Data[i-1]["bucket"] == "TEST" {
			t.Errorf("CUT rows should come before TEST rows: %v", resp.Data)
		}
	}
	return buckets, stderr, calls
}

func TestLosersHoldsBackSourcesThatStartSales(t *testing.T) {
	buckets, _, calls := runLosers(t, firstTouchModels, onePage, "--breakdown", "source", "--period", "last30")
	if len(calls) != 1 {
		t.Fatalf("attribution breakdown calls = %d, want 1", len(calls))
	}
	for k, want := range map[string]string{"group_by": "traffic_source", "model_id": "4", "period": "last30", "limit": "1000"} {
		if got := calls[0].Get(k); got != want {
			t.Errorf("attribution param %s = %q, want %q", k, got, want)
		}
	}
	if calls[0].Has("offset") {
		t.Errorf("the first page must not send offset (a server without it would refuse the whole check)")
	}
	if buckets["ChatGPT Ads"] != "TEST" || buckets["Display"] != "CUT" {
		t.Errorf("buckets = %v, want ChatGPT Ads TEST (starts sales) and Display CUT", buckets)
	}
}

func TestLosersPagesThroughTheAttributionReport(t *testing.T) {
	// Two pages: the starter is on the second, so a check that read only the first would leave it CUT.
	pages := func(q url.Values) (int, string) {
		if q.Get("offset") == "1" {
			return 200, `{"data":[{"key":"7","roi":46.58,"assisted_conversions":15}],"meta":{"groups":2,"backfill":null}}`
		}
		return 200, `{"data":[{"key":"8","roi":-85.0,"assisted_conversions":0}],"meta":{"groups":2,"backfill":null}}`
	}
	buckets, _, calls := runLosers(t, firstTouchModels, pages, "--breakdown", "source", "--period", "last30")
	if len(calls) != 2 || calls[1].Get("offset") != "1" {
		t.Fatalf("calls = %v, want a second page at offset 1", calls)
	}
	if buckets["ChatGPT Ads"] != "TEST" {
		t.Errorf("the starter on page 2 should be TEST: %v", buckets)
	}

	// A server from before offset refuses it: keep page 1 and say how much was checked.
	old := func(q url.Values) (int, string) {
		if q.Has("offset") {
			return 422, `{"error":true,"message":"Unknown parameter(s): offset","status":422}`
		}
		return 200, `{"data":[{"key":"8","roi":-85.0,"assisted_conversions":0}],"meta":{"groups":2,"backfill":null}}`
	}
	buckets, stderr, _ := runLosers(t, firstTouchModels, old, "--breakdown", "source", "--period", "last30")
	if !strings.Contains(stderr, "checked the top 1 attribution rows") {
		t.Errorf("stderr = %q, want a note on the partial check", stderr)
	}
	if buckets["Display"] != "CUT" || buckets["ChatGPT Ads"] != "CUT" {
		t.Errorf("rows the check never read stay CUT: %v", buckets)
	}

	// Any other failure on a later page (here a server error) discards the whole check: a starter on page 1 is
	// not rescued by a half-read report.
	broken := func(q url.Values) (int, string) {
		if q.Has("offset") {
			return 500, `{"error":true,"message":"Internal error","status":500}`
		}
		return 200, `{"data":[{"key":"7","roi":46.58,"assisted_conversions":15}],"meta":{"groups":2,"backfill":null}}`
	}
	buckets, stderr, _ = runLosers(t, firstTouchModels, broken, "--breakdown", "source", "--period", "last30")
	if !strings.Contains(stderr, "attribution check skipped") || buckets["ChatGPT Ads"] != "CUT" {
		t.Errorf("a failed page 2 must discard the check: buckets %v, stderr %q", buckets, stderr)
	}
}

func TestLosersMarksRowsPastAPartialCheckAsUnchecked(t *testing.T) {
	old := func(q url.Values) (int, string) {
		if q.Has("offset") {
			return 422, `{"error":true,"message":"Unknown parameter(s): offset","status":422}`
		}
		return 200, `{"data":[{"key":"8","roi":-85.0,"assisted_conversions":0}],"meta":{"groups":2,"backfill":null}}`
	}
	var calls []url.Values
	srv := losersServer(t, firstTouchModels, old, &calls)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	out, _, err := executeCommand("report", "losers", "--json", "--breakdown", "source", "--period", "last30")
	if err != nil {
		t.Fatalf("losers: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(out), &resp); err != nil {
		t.Fatalf("output is not JSON: %v", err)
	}
	for _, r := range resp.Data {
		checked, has := r["attribution_checked"]
		switch r["name"] {
		case "ChatGPT Ads":
			if !has || checked != false || !strings.Contains(fmt.Sprint(r["reason"]), "not checked") {
				t.Errorf("a row past the partial check is marked unchecked: %v", r)
			}
		case "Display":
			if has {
				t.Errorf("a row the check read carries no unchecked mark: %v", r)
			}
		}
	}
}

func TestLosersRefusesAFirstTouchOverrideThatIsNot(t *testing.T) {
	var calls []url.Values
	srv := losersServer(t, firstTouchModels, onePage, &calls)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("report", "losers", "--json", "--breakdown", "source", "--first-touch-model", "1")
	if err == nil || !strings.Contains(err.Error(), "last_touch model") {
		t.Fatalf("a last-touch model as --first-touch-model must be refused, got %v", err)
	}
	if len(calls) != 0 {
		t.Errorf("no attribution report should be read for a refused override")
	}
	if _, _, err := executeCommand("report", "losers", "--json", "--breakdown", "source", "--first-touch-model", "4"); err != nil {
		t.Errorf("a first_touch override is accepted: %v", err)
	}
}

func TestLosersNotesAndFallbacks(t *testing.T) {
	cases := []struct {
		name, models string
		attr         func(url.Values) (int, string)
		args         []string
		note         string
		wantCalls    int
		wantModel    bool
		chatgpt      string
	}{
		{"no first-touch model: assists only", `{"data":[]}`, onePage, []string{"--breakdown", "source"}, "no active First touch model", 1, false, "TEST"},
		{"dimension without an attribution equivalent", firstTouchModels, onePage, []string{"--breakdown", "browser"}, "no browser breakdown", 0, false, "CUT"},
		{"turned off", firstTouchModels, onePage, []string{"--breakdown", "source", "--no-attribution-check"}, "", 0, false, "CUT"},
		{"no range given", firstTouchModels, onePage, []string{"--breakdown", "source"}, "last 30 days", 1, true, "TEST"},
		{"a filter the attribution report can't mirror", firstTouchModels, onePage, []string{"--breakdown", "source", "--aff_campaign_id", "7"}, "can't be filtered by --aff_campaign_id", 0, false, "CUT"},
		{"a filter on the breakdown itself", firstTouchModels, onePage, []string{"--breakdown", "source", "--ppc_account_id", "7"}, "", 1, true, "TEST"},
		{"backfill running", firstTouchModels, func(url.Values) (int, string) {
			return 200, `{"data":[{"key":"7","roi":46.58,"assisted_conversions":15}],"meta":{"groups":1,"backfill":{"done":10,"total":100}}}`
		}, []string{"--breakdown", "source", "--period", "last7"}, "backfilled", 1, true, "TEST"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			buckets, stderr, calls := runLosers(t, tc.models, tc.attr, tc.args...)
			if tc.note != "" && !strings.Contains(stderr, tc.note) {
				t.Errorf("stderr = %q, want a note containing %q", stderr, tc.note)
			}
			if len(calls) != tc.wantCalls {
				t.Fatalf("attribution breakdown calls = %d, want %d", len(calls), tc.wantCalls)
			}
			if tc.wantCalls > 0 && calls[0].Has("model_id") != tc.wantModel {
				t.Errorf("model_id sent = %v, want %v", calls[0].Has("model_id"), tc.wantModel)
			}
			if buckets["Display"] != "CUT" {
				t.Errorf("the classic losers must still be listed, Display CUT: %v", buckets)
			}
			if buckets["ChatGPT Ads"] != tc.chatgpt {
				t.Errorf("ChatGPT Ads = %q, want %q", buckets["ChatGPT Ads"], tc.chatgpt)
			}
		})
	}
}
