package cmd

import (
	"bytes"
	"encoding/json"
	"math"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"testing"
)

// summaryServers serves one GET /reports/summary answer per profile and
// writes a config naming them, the first profile active.
func summaryServers(t *testing.T, answers map[string]string) {
	t.Helper()
	profiles := map[string]map[string]interface{}{}
	active := ""
	for name, body := range answers {
		body := body
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path != "/api/v3/reports/summary" {
				w.WriteHeader(404)
				_, _ = w.Write([]byte(`{"message":"not found"}`))
				return
			}
			_, _ = w.Write([]byte(body))
		}))
		t.Cleanup(srv.Close)
		profiles[name] = map[string]interface{}{"url": srv.URL, "api_key": name + "-key-123456"}
		if active == "" || name < active {
			active = name
		}
	}
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, active, profiles)
}

type multiSummary struct {
	Data       []map[string]interface{} `json:"data"`
	Aggregated map[string]interface{}   `json:"aggregated"`
	Errors     []string                 `json:"errors"`
	Meta       struct {
		Currencies  map[string]interface{} `json:"currencies"`
		NotTotalled map[string]string      `json:"not_totalled"`
		Warnings    []string               `json:"warnings"`
	} `json:"meta"`
}

func runMultiSummary(t *testing.T, args ...string) (multiSummary, string) {
	t.Helper()
	stdout, stderr, err := executeCommand(args...)
	if err != nil {
		t.Fatalf("%v: %v", args, err)
	}
	var got multiSummary
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &got); err != nil {
		t.Fatalf("invalid JSON: %v\n%s", err, stdout)
	}
	if len(got.Data) == 0 || got.Data[len(got.Data)-1]["profile"] != "TOTAL" {
		t.Fatalf("the last row is not the TOTAL: %v", got.Data)
	}
	for k, v := range got.Aggregated {
		if got.Data[len(got.Data)-1][k] != v {
			t.Fatalf("aggregated %s = %v, the TOTAL row has %v", k, v, got.Data[len(got.Data)-1][k])
		}
	}
	return got, stderr
}

// The defect: profiles at ROI 200% and 0% on equal cost made a TOTAL ROI of
// 200%. The ratios come from the sums: ROI 100%, and every other ratio as
// the server would answer it for both profiles' traffic.
func TestSummaryTotalRecomputesRatiosFromTheSums(t *testing.T) {
	summaryServers(t, map[string]string{
		"a": `{"data":{"total_clicks":10,"total_click_throughs":8,"total_leads":2,"total_income":30.0,"total_cost":10.0,"total_net":20.0,"epc":3.0,"avg_cpc":1.0,"conv_rate":25.0,"roi":200.0,"cpa":5.0,"currency":"USD"}}`,
		"b": `{"data":{"total_clicks":30,"total_click_throughs":12,"total_leads":1,"total_income":10.0,"total_cost":10.0,"total_net":0.0,"epc":0.333333333,"avg_cpc":0.333333333,"conv_rate":8.3333,"roi":0.0,"cpa":10.0,"currency":"USD"}}`,
	})
	for _, command := range [][]string{
		{"report", "summary", "--profiles", "a,b", "--json"},
		{"dashboard", "--all-profiles", "--json"},
	} {
		got, stderr := runMultiSummary(t, command...)
		want := map[string]interface{}{
			"total_clicks": 40.0, "total_click_throughs": 20.0, "total_leads": 3.0,
			"total_income": 40.0, "total_cost": 20.0, "total_net": 20.0,
			"epc": 1.0, "avg_cpc": 0.5, "conv_rate": 15.0, "roi": 100.0, "cpa": 6.666666666,
			"currency": "USD",
		}
		for field, v := range want {
			if got.Aggregated[field] != v {
				t.Errorf("%v: TOTAL %s = %v, want %v", command, field, got.Aggregated[field], v)
			}
		}
		if len(got.Aggregated) != len(want) {
			t.Errorf("%v: TOTAL has %v, want exactly %v", command, got.Aggregated, want)
		}
		if len(got.Meta.NotTotalled) != 0 || len(got.Meta.Warnings) != 0 {
			t.Errorf("%v: nothing is left out, got %v %v", command, got.Meta.NotTotalled, got.Meta.Warnings)
		}
		if stderr != "" {
			t.Errorf("%v: --json keeps stderr for errors, got %q", command, stderr)
		}
	}
}

// Unequal weights: a sum of ratios, and an average of them, are both far
// from the ratio of the sums.
func TestSummaryTotalWeighsEachProfileByItsTraffic(t *testing.T) {
	got, err := totalSummaries(map[string]map[string]interface{}{
		"big":   {"total_clicks": json.Number("900"), "total_click_throughs": json.Number("450"), "total_leads": json.Number("1"), "total_income": json.Number("0"), "total_cost": json.Number("90"), "total_net": json.Number("-90"), "epc": json.Number("0"), "conv_rate": json.Number("0.2222"), "roi": json.Number("-100"), "currency": "EUR"},
		"small": {"total_clicks": json.Number("100"), "total_click_throughs": json.Number("50"), "total_leads": json.Number("10"), "total_income": json.Number("50"), "total_cost": json.Number("10"), "total_net": json.Number("40"), "epc": json.Number("0.5"), "conv_rate": json.Number("20"), "roi": json.Number("400"), "currency": "EUR"},
	})
	if err != nil {
		t.Fatal(err)
	}
	for field, want := range map[string]float64{"epc": 0.05, "conv_rate": 2.2, "roi": -50} {
		if got.row[field] != want {
			t.Errorf("%s = %v, want %v", field, got.row[field], want)
		}
	}
	// Only the ratios the profiles carry: neither answer has avg_cpc or cpa.
	for _, field := range []string{"avg_cpc", "cpa"} {
		if _, ok := got.row[field]; ok {
			t.Errorf("%s is in the TOTAL though no profile has it", field)
		}
	}
	if got.row["currency"] != "EUR" {
		t.Errorf("currency = %v, want EUR", got.row["currency"])
	}
}

// A ratio whose denominator sums to zero (or below) is the server's answer
// for one: 0, as CASE WHEN SUM(...) > 0 ... ELSE 0 gives.
func TestSummaryTotalZeroDenominatorIsZero(t *testing.T) {
	zero := map[string]interface{}{"total_clicks": json.Number("0"), "total_click_throughs": json.Number("0"), "total_leads": json.Number("0"), "total_income": json.Number("0.0"), "total_cost": json.Number("0.0"), "total_net": json.Number("0.0"), "epc": json.Number("0.0"), "avg_cpc": json.Number("0.0"), "conv_rate": json.Number("0.0"), "roi": json.Number("0.0"), "cpa": json.Number("0.0"), "currency": "USD"}
	got, err := totalSummaries(map[string]map[string]interface{}{"a": zero, "b": cloneMap(zero)})
	if err != nil {
		t.Fatal(err)
	}
	for _, r := range summaryRatios {
		if got.row[r.field] != 0.0 {
			t.Errorf("%s = %v over no traffic, want 0", r.field, got.row[r.field])
		}
	}

	// Income without cost (a refund made the cost sum negative): ROI is 0,
	// the other ratios are computed.
	refund := cloneMap(zero)
	refund["total_clicks"], refund["total_income"], refund["total_cost"], refund["total_net"] = json.Number("4"), json.Number("8.0"), json.Number("-2.0"), json.Number("10.0")
	got, err = totalSummaries(map[string]map[string]interface{}{"a": zero, "b": refund})
	if err != nil {
		t.Fatal(err)
	}
	if got.row["roi"] != 0.0 || got.row["epc"] != 2.0 || got.row["avg_cpc"] != -0.5 || got.row["cpa"] != 0.0 {
		t.Errorf("TOTAL = %v, want roi 0, epc 2, avg_cpc -0.5, cpa 0", got.row)
	}

	// An older server's null (SUM over no rows) is that profile's 0.
	old := map[string]interface{}{"total_clicks": nil, "total_click_throughs": nil, "total_leads": nil, "total_income": nil, "total_cost": nil, "total_net": nil, "epc": "0.000000000", "avg_cpc": "0.000000000", "conv_rate": "0.0000", "roi": "0.000000000", "cpa": "0.000000000"}
	got, err = totalSummaries(map[string]map[string]interface{}{"old": old})
	if err != nil {
		t.Fatal(err)
	}
	if got.row["total_clicks"] != json.Number("0") || got.row["total_income"] != 0.0 || got.row["roi"] != 0.0 {
		t.Errorf("an empty older server's TOTAL = %v", got.row)
	}
}

// The server's decimals: a quotient is truncated where MySQL stops dividing
// and rounded half away from zero to the decimals it is sent with.
func TestMysqlQuotientIsTheServersArithmetic(t *testing.T) {
	r := func(s string) *big.Rat {
		v, ok := new(big.Rat).SetString(s)
		if !ok {
			t.Fatalf("bad literal %s", s)
		}
		return v
	}
	for _, c := range []struct {
		a, b           string
		aScale, bScale int
		mul            int64
		want           string
	}{
		{"1", "3", 0, 0, 100, "33.3333"},          // conv_rate: 0.333333333 * 100, to 4
		{"1", "6", 0, 0, 100, "16.6667"},          // 0.166666666 * 100 = 16.6666666, rounded up
		{"1", "2000000", 0, 0, 100, "0.0001"},     // 0.000000500 * 100 = 0.00005, half away from zero
		{"2", "3", 5, 0, 1, "0.666666666"},        // epc: truncated at 9, not rounded
		{"2", "7", 5, 0, 100, "28.5714285"},       // 0.285714285 * 100: the 9 digits kept, then x100
		{"-1", "3", 5, 5, 100, "-33.333333333"},   // roi keeps 18 digits
		{"-2", "3", 5, 5, 100, "-66.666666667"},   // and rounds away from zero
		{"12345.6789", "3", 5, 0, 1, "4115.2263"}, // exact
		{"0.00001", "3", 5, 0, 1, "0.000003333"},  // cpa-sized
		{"5", "11", 0, 0, 100, "45.4545"},         // 0.454545454 * 100 = 45.4545454
		{"7", "8", 0, 0, 100, "87.5"},             // exact
		{"3", "2000000", 0, 0, 100, "0.0002"},     // 0.0000015 * 100 = 0.00015
		{"-0.00002", "0.00003", 5, 5, 100, "-66.666666667"},
	} {
		got := mysqlQuotient(r(c.a), r(c.b), c.aScale, c.bScale, c.mul)
		if got.Cmp(r(c.want)) != 0 {
			t.Errorf("%s / %s (scales %d, %d) x %d = %s, want %s", c.a, c.b, c.aScale, c.bScale, c.mul, got.FloatString(18), c.want)
		}
	}
}

// Money in different currencies is never added: the TOTAL keeps the counts
// and the conversion rate, leaves out every amount and every ratio that
// needs one, and says so -- in meta under --json, on stderr otherwise.
func TestSummaryTotalNeverAddsDifferentCurrencies(t *testing.T) {
	usd := `{"data":{"total_clicks":10,"total_click_throughs":10,"total_leads":1,"total_income":30.0,"total_cost":10.0,"total_net":20.0,"epc":3.0,"avg_cpc":1.0,"conv_rate":10.0,"roi":200.0,"cpa":10.0,"currency":"USD"}}`
	eur := `{"data":{"total_clicks":30,"total_click_throughs":10,"total_leads":3,"total_income":10.0,"total_cost":10.0,"total_net":0.0,"epc":0.333333333,"avg_cpc":0.333333333,"conv_rate":30.0,"roi":0.0,"cpa":3.333333333,"currency":"EUR"}}`
	older := `{"data":{"total_clicks":30,"total_click_throughs":10,"total_leads":3,"total_income":10.0,"total_cost":10.0,"total_net":0.0,"epc":0.333333333,"avg_cpc":0.333333333,"conv_rate":30.0,"roi":0.0,"cpa":3.333333333}}`
	moneyFields := []string{"avg_cpc", "cpa", "epc", "roi", "total_cost", "total_income", "total_net"}

	for name, c := range map[string]struct {
		answers map[string]string
		says    []string
	}{
		"different":  {map[string]string{"prod": usd, "eu": eur}, []string{"eu: EUR, prod: USD", "not added"}},
		"not stated": {map[string]string{"prod": usd, "old": older}, []string{"old: not stated, prod: USD", "before 1.9.79"}},
	} {
		t.Run(name, func(t *testing.T) {
			summaryServers(t, c.answers)
			got, stderr := runMultiSummary(t, "report", "summary", "--all-profiles", "--json")
			if stderr != "" {
				t.Errorf("--json keeps stderr for errors, got %q", stderr)
			}
			for _, field := range moneyFields {
				if _, ok := got.Aggregated[field]; ok {
					t.Errorf("TOTAL has %s = %v across currencies", field, got.Aggregated[field])
				}
				if _, ok := got.Meta.NotTotalled[field]; !ok {
					t.Errorf("meta.not_totalled does not name %s: %v", field, got.Meta.NotTotalled)
				}
			}
			if len(got.Meta.NotTotalled) != len(moneyFields) {
				t.Errorf("not_totalled = %v, want exactly %v", got.Meta.NotTotalled, moneyFields)
			}
			if _, ok := got.Aggregated["currency"]; ok {
				t.Errorf("the TOTAL states a currency: %v", got.Aggregated["currency"])
			}
			if got.Aggregated["total_clicks"] != 40.0 || got.Aggregated["total_leads"] != 4.0 || got.Aggregated["conv_rate"] != 20.0 {
				t.Errorf("counts and the conversion rate are still totalled, got %v", got.Aggregated)
			}
			if len(got.Meta.Warnings) != 1 {
				t.Fatalf("want one warning, got %v", got.Meta.Warnings)
			}
			for _, want := range append([]string{strings.Join(moneyFields, ", ")}, c.says...) {
				if !strings.Contains(got.Meta.Warnings[0], want) {
					t.Errorf("warning %q does not say %q", got.Meta.Warnings[0], want)
				}
			}

			stdout, stderr, err := executeCommand("report", "summary", "--all-profiles")
			if err != nil {
				t.Fatal(err)
			}
			if !strings.Contains(stderr, "Warning: "+got.Meta.Warnings[0]) {
				t.Errorf("the table's stderr does not carry the warning:\n%s", stderr)
			}
			if strings.Contains(stdout, "Warning") || !strings.Contains(stdout, "TOTAL") {
				t.Errorf("stdout is the table and only the table:\n%s", stdout)
			}
		})
	}

	// One currency, or one profile whatever it states: the money is added.
	for name, answers := range map[string]map[string]string{
		"same":        {"a": usd, "b": strings.Replace(eur, `"EUR"`, `"USD"`, 1)},
		"one profile": {"old": older},
	} {
		t.Run(name, func(t *testing.T) {
			summaryServers(t, answers)
			got, _ := runMultiSummary(t, "report", "summary", "--all-profiles", "--json")
			for _, field := range moneyFields {
				if _, ok := got.Aggregated[field]; !ok {
					t.Errorf("TOTAL lacks %s: %v", field, got.Meta.NotTotalled)
				}
			}
		})
	}
}

// A field this p202 does not know is shown in each profile's row and left
// out of the TOTAL, named: it may be a ratio, and summing one is the defect.
func TestSummaryTotalLeavesOutAFieldItDoesNotKnow(t *testing.T) {
	summaryServers(t, map[string]string{
		"a": `{"data":{"total_clicks":10,"total_refunds":2,"refund_rate":20.0,"timezone":"UTC","currency":"USD"}}`,
		"b": `{"data":{"total_clicks":5,"total_refunds":1,"refund_rate":20.0,"timezone":"UTC","currency":"USD"}}`,
	})
	got, _ := runMultiSummary(t, "report", "summary", "--profiles", "a,b", "--json")
	for _, field := range []string{"total_refunds", "refund_rate", "timezone"} {
		if _, ok := got.Aggregated[field]; ok {
			t.Errorf("TOTAL has %s, which this p202 cannot know adds up", field)
		}
		if !strings.Contains(got.Meta.NotTotalled[field], "does not know") {
			t.Errorf("not_totalled[%s] = %q", field, got.Meta.NotTotalled[field])
		}
		if got.Data[0][field] == nil || got.Data[1][field] == nil {
			t.Errorf("a profile's row lost %s: %v", field, got.Data)
		}
	}
	if got.Aggregated["total_clicks"] != 15.0 {
		t.Errorf("total_clicks = %v, want 15", got.Aggregated["total_clicks"])
	}
}

// A profile missing a field the others have: the field, and the ratios
// computed from it, are left out and named, never read as 0.
func TestSummaryTotalLeavesOutAFieldAProfileLacks(t *testing.T) {
	got, err := totalSummaries(map[string]map[string]interface{}{
		"a": {"total_clicks": json.Number("10"), "total_click_throughs": json.Number("5"), "total_leads": json.Number("1"), "conv_rate": json.Number("20")},
		"b": {"total_clicks": json.Number("10"), "total_leads": json.Number("1"), "conv_rate": json.Number("10")},
	})
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := got.row["total_click_throughs"]; ok {
		t.Error("total_click_throughs is totalled though b lacks it")
	}
	if !strings.Contains(got.notTotalled["total_click_throughs"], "b's answer") || !strings.Contains(got.notTotalled["conv_rate"], "total_click_throughs") {
		t.Errorf("not_totalled = %v", got.notTotalled)
	}
}

// A profile whose count or amount is not a number is a failed profile,
// named with the field and the value, and the TOTAL is the others'; when
// every profile fails so, the command fails naming each.
func TestAProfileWhoseAnswerIsNotANumberIsNamedNotSummed(t *testing.T) {
	for _, command := range [][]string{
		{"dashboard", "--all-profiles", "--json"},
		{"report", "summary", "--all-profiles", "--json"},
	} {
		t.Run(command[0], func(t *testing.T) {
			summaryServers(t, map[string]string{
				"prod":    `{"data":{"total_clicks":4,"total_income":"12.5","currency":"USD"}}`,
				"staging": `{"data":{"total_clicks":6,"total_income":"NaN","currency":"USD"}}`,
			})
			got, _ := runMultiSummary(t, command...)
			if len(got.Errors) != 1 {
				t.Fatalf("errors = %v, want staging's", got.Errors)
			}
			for _, want := range []string{"staging:", "total_income", "NaN", "p202 report summary --profile staging --json"} {
				if !strings.Contains(got.Errors[0], want) {
					t.Errorf("error %q does not name %q", got.Errors[0], want)
				}
			}
			if got.Aggregated["total_clicks"] != 4.0 || got.Aggregated["total_income"] != 12.5 {
				t.Errorf("TOTAL = %v, want prod's", got.Aggregated)
			}

			_, stderr, err := executeCommand(command[:len(command)-1]...)
			if err != nil {
				t.Fatal(err)
			}
			if !strings.Contains(stderr, "Warning: the TOTAL leaves out a profile that failed: staging:") {
				t.Errorf("the table does not say staging failed:\n%s", stderr)
			}
		})
	}

	summaryServers(t, map[string]string{
		"prod":    `{"data":{"total_clicks":1.5}}`,
		"staging": `{"data":{"total_cost":{"amount":1}}}`,
	})
	stdout, _, err := executeCommand("report", "summary", "--all-profiles", "--json")
	if err == nil || strings.TrimSpace(stdout) != "" {
		t.Fatalf("every profile failed: err %v, stdout %q", err, stdout)
	}
	for _, want := range []string{"prod: the server's answer is not a summary: its total_clicks is not a whole number", "staging: the server's answer is not a summary: its total_cost is not a number"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q does not say %q", err, want)
		}
	}
	if got := exitCodeForError(err); got != ExitServer {
		t.Errorf("exit code = %d, want %d: every server answered what is not a summary", got, ExitServer)
	}
	if h := hintFor(err); !strings.Contains(h, "p202 report summary --profile") {
		t.Errorf("hint %q does not point at a server's raw answer", h)
	}
}

// When every profile fails the same way, the error keeps that failure's
// category, exit code and hint; failures of different kinds keep none.
func TestAllProfilesFailingKeepsTheirCategory(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	refused := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(401)
		_, _ = w.Write([]byte(`{"message":"Invalid API key"}`))
	}))
	defer refused.Close()
	dead := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	deadURL := dead.URL
	dead.Close()
	writeTestConfigWithProfiles(t, tmp, "a", map[string]map[string]interface{}{
		"a":       {"url": deadURL, "api_key": "a-key-123456"},
		"b":       {"url": deadURL, "api_key": "b-key-123456"},
		"refused": {"url": refused.URL, "api_key": "r-key-123456"},
	})
	for profiles, want := range map[string]int{"a,b": ExitNetwork, "a,refused": ExitValidation} {
		stdout, _, err := executeCommand("report", "summary", "--profiles", profiles, "--json")
		if err == nil || strings.TrimSpace(stdout) != "" {
			t.Fatalf("%s: err %v, stdout %q", profiles, err, stdout)
		}
		for _, name := range strings.Split(profiles, ",") {
			if !strings.Contains(err.Error(), name+": ") {
				t.Errorf("%s: error %q does not name %s", profiles, err, name)
			}
		}
		if got := exitCodeForError(err); got != want {
			t.Errorf("%s: exit code = %d, want %d", profiles, got, want)
		}
	}
}

func TestSummaryTotalRefusesASumNoFloatHolds(t *testing.T) {
	huge := json.Number("1e308")
	_, err := totalSummaries(map[string]map[string]interface{}{
		"a": {"total_cost": huge, "currency": "USD"}, "b": {"total_cost": huge, "currency": "USD"},
	})
	if err == nil || !strings.Contains(err.Error(), "total_cost") {
		t.Fatalf("err = %v, want one naming total_cost", err)
	}
	if _, err := summaryValue(json.Number("1e999")); err == nil {
		t.Fatal("1e999 read as a number")
	}
	if _, err := summaryValue(math.Inf(1)); err == nil {
		t.Fatal("+Inf read as a number")
	}
}

// summaryVectors is tests/fixtures/summary-totals/vectors.json: profiles'
// 202_dataengine rows and what GET /reports/summary answered for each
// profile and for all of their rows at once, measured on MySQL 8.0 and
// MariaDB 10.11 and held to the server by SummaryTotalVectorsIntegrationTest.
type summaryVectors struct {
	Cases []struct {
		Name     string `json:"name"`
		Profiles []struct {
			Rows   [][]json.Number        `json:"rows"`
			Answer map[string]interface{} `json:"answer"`
		} `json:"profiles"`
		Union map[string]interface{} `json:"union"`
	} `json:"cases"`
}

// The TOTAL is what the server answers for the union of the profiles'
// rows, field for field and to the last bit of each float.
func TestSummaryTotalIsWhatTheServerAnswersForTheUnion(t *testing.T) {
	raw, err := os.ReadFile(repoPath("tests", "fixtures", "summary-totals", "vectors.json"))
	if err != nil {
		t.Fatal(err)
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var vectors summaryVectors
	if err := dec.Decode(&vectors); err != nil {
		t.Fatal(err)
	}
	if len(vectors.Cases) < 50 {
		t.Fatalf("%d cases: the file is not the measured set", len(vectors.Cases))
	}
	for _, c := range vectors.Cases {
		rows := map[string]map[string]interface{}{}
		for i, p := range c.Profiles {
			rows[string(rune('a'+i))] = p.Answer
		}
		got, err := totalSummaries(rows)
		if err != nil {
			t.Fatalf("%s: %v", c.Name, err)
		}
		if len(got.notTotalled) != 0 {
			t.Errorf("%s: left out %v", c.Name, got.notTotalled)
		}
		for field, want := range c.Union {
			have := got.row[field]
			if field == summaryCurrencyField {
				if have != want {
					t.Errorf("%s: currency %v, want %v", c.Name, have, want)
				}
				continue
			}
			w, err := want.(json.Number).Float64()
			if err != nil {
				t.Fatalf("%s: %s: %v", c.Name, field, err)
			}
			var h float64
			switch v := have.(type) {
			case json.Number:
				h, _ = v.Float64()
			case float64:
				h = v
			default:
				t.Errorf("%s: TOTAL has no %s (want %v)", c.Name, field, want)
				continue
			}
			if h != w {
				t.Errorf("%s: TOTAL %s = %v, the server answers %v for the union", c.Name, field, h, w)
			}
		}
		if len(got.row) != len(c.Union) {
			t.Errorf("%s: TOTAL has %d fields, the server's answer %d", c.Name, len(got.row), len(c.Union))
		}
	}
}

// The TOTAL's lists are the server's summary: every field it answers is a
// count, an amount or a ratio here, each ratio has the server's formula, and
// the decimals assumed are 202_dataengine's. A field the server adds fails
// this until it is classified above.
func TestSummaryTotalFollowsTheServersSQL(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Controllers", "ReportsController.php"))
	if err != nil {
		t.Fatal(err)
	}
	body := string(src)
	start := strings.Index(body, "public function summary(")
	if start < 0 {
		t.Fatal("ReportsController has no summary()")
	}
	body = body[start:]
	sqlStart, sqlEnd := strings.Index(body, `$sql = "SELECT`), strings.Index(body, "FROM 202_dataengine de")
	if sqlStart < 0 || sqlEnd < sqlStart {
		t.Fatal("summary()'s SELECT is not where this test reads it")
	}
	sql := strings.Join(strings.Fields(body[sqlStart+len(`$sql = "SELECT`):sqlEnd]), " ")

	column := map[string]string{
		"total_clicks": "SUM(de.clicks)", "total_click_throughs": "SUM(de.click_out)", "total_leads": "SUM(de.leads)",
		"total_income": "SUM(de.income)", "total_cost": "SUM(de.cost)", "total_net": "SUM(de.income) - SUM(de.cost)",
	}
	want := map[string]string{}
	for _, f := range append(append([]string{}, summaryCounts...), summaryMoney...) {
		want[f] = column[f]
	}
	for _, r := range summaryRatios {
		num := column[r.num]
		if strings.Contains(num, " - ") {
			num = "(" + num + ")"
		}
		expr := num + " / " + column[r.den]
		if r.percent {
			expr += " * 100"
		}
		want[r.field] = "CASE WHEN " + column[r.den] + " > 0 THEN " + expr + " ELSE 0 END"
	}
	got := map[string]string{}
	for _, m := range regexp.MustCompile(`(.+?) as ([a-z_]+)(?:,|$)`).FindAllStringSubmatch(sql, -1) {
		got[m[2]] = strings.TrimSpace(strings.TrimPrefix(m[1], ","))
	}
	if len(got) == 0 {
		t.Fatalf("read no field from the summary's SQL: %s", sql)
	}
	for field, expr := range got {
		if want[field] == "" {
			t.Errorf("the summary answers %s (%s), which the TOTAL does not classify: add it to summaryCounts, summaryMoney or summaryRatios", field, expr)
		} else if want[field] != expr {
			t.Errorf("%s is %q on the server, the TOTAL computes %q", field, expr, want[field])
		}
	}
	for field := range want {
		if _, ok := got[field]; !ok {
			t.Errorf("the TOTAL computes %s, which the summary no longer answers", field)
		}
	}
	if !strings.Contains(body[:strings.Index(body, "\n    }\n")], `'currency' => $this->accountCurrency()`) {
		t.Error("summary() no longer states its currency as the TOTAL reads it")
	}

	tables, err := os.ReadFile(repoPath("202-config", "Database", "Tables", "MiscTables.php"))
	if err != nil {
		t.Fatal(err)
	}
	de := string(tables)
	de = de[strings.Index(de, "function dataengine()"):]
	de = de[:strings.Index(de, "PRIMARY KEY")]
	scales := map[string]int{}
	for _, m := range regexp.MustCompile("`(clicks|click_out|leads|income|cost)` (bigint|decimal)\\((\\d+)(?:,(\\d+))?\\)").FindAllStringSubmatch(de, -1) {
		s, _ := strconv.Atoi(m[4]) // bigint has none: 0
		scales[m[1]] = s
	}
	wantScales := map[string]int{"clicks": 0, "click_out": 0, "leads": 0, "income": summaryMoneyScale, "cost": summaryMoneyScale}
	keys := make([]string, 0, len(wantScales))
	for k := range wantScales {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if s, ok := scales[k]; !ok || s != wantScales[k] {
			t.Errorf("202_dataengine.%s has %d decimals (found: %v), the TOTAL assumes %d", k, s, ok, wantScales[k])
		}
	}
}
