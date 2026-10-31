package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
)

// triageNumberRows are a current server's rows: every figure a JSON number. The costs and CPCs are below a cent, where
// a rounding to 2 decimals reads 0 and one to 4 changes the figure (0.00125 is 0.0013). 1 is CUT for spend without a
// sale, 2 is SCALE, 3 sells at a loss (WATCH, unless a break-even CPC puts it over).
const triageNumberRows = `{"data":[
 {"id":1,"name":"tiny spend","total_clicks":3,"total_click_throughs":3,"total_leads":0,"total_income":0,"total_cost":0.00375,
  "total_net":-0.00375,"epc":0,"avg_cpc":0.00125,"conv_rate":0,"roi":-100,"cpa":0},
 {"id":2,"name":"lp shoes","total_clicks":4,"total_click_throughs":2,"total_leads":1,"total_income":12.5,"total_cost":0.005,
  "total_net":12.495,"epc":3.125,"avg_cpc":0.00125,"conv_rate":50,"roi":249900,"cpa":0.005},
 {"id":3,"name":"over bid","total_clicks":8,"total_click_throughs":8,"total_leads":1,"total_income":0.01,"total_cost":0.0101,
  "total_net":-0.0001,"epc":0.00125,"avg_cpc":0.0012625,"conv_rate":12.5,"roi":-0.990099009,"cpa":0.0101}]}`

// triageStringRows are the same rows from a server that answers figures as numeric strings, as mysqli hands them over.
const triageStringRows = `{"data":[
 {"id":"1","name":"tiny spend","total_clicks":"3","total_click_throughs":"3","total_leads":"0","total_income":"0.00000",
  "total_cost":"0.00375","total_net":"-0.00375","epc":"0.000000","avg_cpc":"0.001250000","conv_rate":"0.0000","roi":"-100.0000","cpa":"0.00000"},
 {"id":"2","name":"lp shoes","total_clicks":"4","total_click_throughs":"2","total_leads":"1","total_income":"12.50000",
  "total_cost":"0.00500","total_net":"12.49500","epc":"3.125000","avg_cpc":"0.001250000","conv_rate":"50.0000","roi":"249900.0000","cpa":"0.00500"},
 {"id":"3","name":"over bid","total_clicks":"8","total_click_throughs":"8","total_leads":"1","total_income":"0.01000",
  "total_cost":"0.01010","total_net":"-0.00010","epc":"0.001250","avg_cpc":"0.001262500","conv_rate":"12.5000","roi":"-0.9901","cpa":"0.01010"}]}`

func triageFiguresServer(t *testing.T, rows string) *httptest.Server {
	return httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/reports/breakdown") && !strings.Contains(r.URL.Path, "attribution") {
			_, _ = w.Write([]byte(rows))
			return
		}
		t.Errorf("unexpected request %s", r.URL.Path)
		w.WriteHeader(http.StatusNotFound)
	}))
}

// triageRowsByID decodes a command's --json rows by id, numbers as json.Number, so a figure is compared digit for
// digit and a number is told from a numeric string.
func triageRowsByID(t *testing.T, stdout string) map[string]map[string]interface{} {
	t.Helper()
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	dec := json.NewDecoder(strings.NewReader(stdout))
	dec.UseNumber()
	if err := dec.Decode(&resp); err != nil {
		t.Fatalf("not JSON rows: %v\n%s", err, stdout)
	}
	out := map[string]map[string]interface{}{}
	for _, r := range resp.Data {
		out[fmt.Sprint(r["id"])] = r
	}
	return out
}

// TestTriageListsTheBreakdownsFigures holds `report losers` and `report winners` to `report breakdown` for the same
// rows: every figure a triage row lists under a breakdown field's name is the breakdown's, unchanged, as a number when
// the server sent one, and in the table the cell is the breakdown's cell. They rounded total_cost to 2 decimals and
// avg_cpc to 4, so a $0.00375 cost read 0 and a $0.00125 CPC 0.0013; with --payout, total_net was the command's
// sales × payout − cost, which is payout_net now.
func TestTriageListsTheBreakdownsFigures(t *testing.T) {
	runs := []struct {
		command string
		args    []string
		want    []string // the ids listed
	}{
		{"losers", nil, []string{"1"}},
		{"losers", []string{"--max-cpc", "0.00126"}, []string{"1", "3"}},
		{"losers", []string{"--payout", "0.01"}, []string{"1", "3"}},
		{"winners", nil, []string{"2"}},
		{"winners", []string{"--payout", "100"}, []string{"2", "3"}},
	}
	for _, server := range []struct {
		name, rows string
		numbers    bool
	}{{"numbers", triageNumberRows, true}, {"numeric strings", triageStringRows, false}} {
		t.Run(server.name, func(t *testing.T) {
			srv := triageFiguresServer(t, server.rows)
			defer srv.Close()
			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key")

			out, _, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--json")
			if err != nil {
				t.Fatalf("report breakdown: %v", err)
			}
			breakdown := triageRowsByID(t, out)
			table, _, err := executeCommand("report", "breakdown", "--breakdown", "keyword", "--table", "--fields", "id,"+strings.Join(triageFigures, ","))
			if err != nil {
				t.Fatalf("report breakdown --table: %v", err)
			}
			breakdownCells := tableCellsByID(table)

			for _, run := range runs {
				label := strings.TrimSpace(run.command + " " + strings.Join(run.args, " "))
				args := append([]string{"report", run.command, "--breakdown", "keyword", "--no-attribution-check"}, run.args...)
				out, _, err := executeCommand(append(args, "--json")...)
				if err != nil {
					t.Fatalf("%s: %v", label, err)
				}
				listed := triageRowsByID(t, out)
				if len(listed) != len(run.want) {
					t.Errorf("%s listed %d rows, want ids %v: %s", label, len(listed), run.want, out)
				}
				for _, id := range run.want {
					row, b := listed[id], breakdown[id]
					if row == nil {
						t.Errorf("%s: row %s isn't listed", label, id)
						continue
					}
					for _, f := range triageFigures {
						got, ok := row[f]
						if !ok {
							t.Errorf("%s row %s: %s is missing", label, id, f)
							continue
						}
						if _, isNumber := got.(json.Number); isNumber != server.numbers {
							t.Errorf("%s row %s %s: %s, want a JSON number only when the server sent one", label, id, f, typed(got))
						}
					}
					for f, v := range row {
						if want, shared := b[f]; shared && !reflect.DeepEqual(v, want) {
							t.Errorf("%s row %s %s: %s under the breakdown's name, breakdown %s", label, id, f, typed(v), typed(want))
						}
					}
				}

				table, _, err := executeCommand(append(args, "--table", "--fields", "id,"+strings.Join(triageFigures, ","))...)
				if err != nil {
					t.Fatalf("%s --table: %v", label, err)
				}
				for id, cells := range tableCellsByID(table) {
					if want := breakdownCells[id]; strings.Join(cells, " ") != strings.Join(want, " ") {
						t.Errorf("%s --table row %s: %v, breakdown's %v", label, id, cells, want)
					}
				}
			}
		})
	}
}

// typed shows a decoded value with its JSON type: a number and a numeric string print alike otherwise.
func typed(v interface{}) string {
	if n, ok := v.(json.Number); ok {
		return "number " + n.String()
	}
	return fmt.Sprintf("%T %q", v, fmt.Sprint(v))
}

// tableCellsByID reads a --table view whose cells hold no spaces into its rows' cells by id (the first column).
func tableCellsByID(table string) map[string][]string {
	rows := map[string][]string{}
	for i, line := range strings.Split(strings.TrimSpace(table), "\n") {
		if i < 2 { // the header and its underline
			continue
		}
		if cells := strings.Fields(line); len(cells) > 0 {
			rows[cells[0]] = cells[1:]
		}
	}
	return rows
}

// TestTriagePayoutNetIsItsOwnFigure: with --payout, a row's profit at that payout is payout_net (sales × payout −
// cost), what the row is classified and sorted on, and total_net stays the breakdown's.
func TestTriagePayoutNetIsItsOwnFigure(t *testing.T) {
	// The recorded income ranks 2 first; at $100 a sale the two leads of 3 do.
	srv := triageFiguresServer(t, `{"data":[
	 {"id":2,"name":"lp shoes","total_clicks":4,"total_leads":1,"total_income":12.5,"total_cost":0.005,"total_net":12.495,"avg_cpc":0.00125},
	 {"id":3,"name":"lp boots","total_clicks":8,"total_leads":2,"total_income":0.01,"total_cost":0.0101,"total_net":-0.0001,"avg_cpc":0.0012625}]}`)
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	out, _, err := executeCommand("report", "winners", "--breakdown", "keyword", "--no-attribution-check", "--payout", "100", "--json")
	if err != nil {
		t.Fatalf("report winners: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	dec := json.NewDecoder(bytes.NewReader([]byte(out)))
	dec.UseNumber()
	if err := dec.Decode(&resp); err != nil {
		t.Fatalf("not JSON: %v\n%s", err, out)
	}
	var got []string
	for _, r := range resp.Data {
		got = append(got, fmt.Sprintf("%v %v %v", r["id"], r["payout_net"], r["total_net"]))
	}
	// 2 × 100 − 0.0101 and 1 × 100 − 0.005, best first.
	if want := []string{"3 199.9899 -0.0001", "2 99.995 12.495"}; strings.Join(got, "|") != strings.Join(want, "|") {
		t.Errorf("winners --payout 100 rows [id payout_net total_net] = %v, want %v", got, want)
	}
}

// TestCrosstabCellsAreTheBreakdownsFigures holds a `report crosstab` cell to the figure `report breakdown` answers for
// the same row and column. Each cell was rounded to 2 decimals, so an avg_cpc of $0.00125 read 0.
func TestCrosstabCellsAreTheBreakdownsFigures(t *testing.T) {
	const campaigns = `{"data":[{"id":4,"name":"shoes","total_clicks":12,"total_cost":0.0151,"avg_cpc":0.001258333}]}`
	const countries = `{"data":[{"id":1,"name":"US","total_clicks":4,"total_cost":0.005,"avg_cpc":0.00125},
	 {"id":2,"name":"DE","total_clicks":8,"total_cost":0.0101,"avg_cpc":0.0012625}]}`
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch q := r.URL.Query(); {
		case q.Get("breakdown") == "campaign":
			_, _ = w.Write([]byte(campaigns))
		case q.Get("breakdown") == "country" && q.Get("aff_campaign_id") == "4":
			_, _ = w.Write([]byte(countries))
		default:
			t.Errorf("unexpected request %s", r.URL)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("report", "breakdown", "--breakdown", "country", "--aff-campaign-id", "4", "--json")
	if err != nil {
		t.Fatalf("report breakdown: %v", err)
	}
	breakdown := triageRowsByID(t, out)
	for _, metric := range []string{"avg_cpc", "total_cost", "total_clicks"} {
		out, _, err := executeCommand("report", "crosstab", "--rows", "campaign", "--cols", "country", "--metric", metric, "--json")
		if err != nil {
			t.Fatalf("report crosstab --metric %s: %v", metric, err)
		}
		var resp struct {
			Data []map[string]interface{} `json:"data"`
		}
		dec := json.NewDecoder(strings.NewReader(out))
		dec.UseNumber()
		if err := dec.Decode(&resp); err != nil || len(resp.Data) != 1 {
			t.Fatalf("crosstab --metric %s: %v\n%s", metric, err, out)
		}
		for id, name := range map[string]string{"1": "US", "2": "DE"} {
			if got, want := resp.Data[0][name], breakdown[id][metric]; !reflect.DeepEqual(got, want) {
				t.Errorf("crosstab --metric %s shoes × %s: %s, breakdown %s", metric, name, typed(got), typed(want))
			}
		}
	}
}

// TestCampaignOptimizeListsTheBreakdownsEPC holds the offers `campaign optimize` ranks to `report breakdown
// --breakdown campaign`: each epc is the breakdown's, which it rounded to 3 decimals (a $0.0004 EPC read 0).
func TestCampaignOptimizeListsTheBreakdownsEPC(t *testing.T) {
	const offers = `{"data":[
	 {"id":9,"name":"this one","total_clicks":10,"total_income":20,"epc":2},
	 {"id":11,"name":"penny offer","total_clicks":2500,"total_income":1,"epc":0.0004},
	 {"id":12,"name":"fine offer","total_clicks":8,"total_income":0.01,"epc":0.00125},
	 {"id":13,"name":"string offer","total_clicks":"3","total_income":"0.00500","epc":"0.001666667"}]}`
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/campaigns/9"):
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_payout":10}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/summary"):
			_, _ = w.Write([]byte(`{"data":{"total_clicks":10,"total_leads":2,"total_income":20,"total_cost":5}}`))
		case strings.HasSuffix(r.URL.Path, "/reports/breakdown"):
			_, _ = w.Write([]byte(offers))
		default:
			t.Errorf("unexpected request %s", r.URL.Path)
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("report", "breakdown", "--breakdown", "campaign", "--json")
	if err != nil {
		t.Fatalf("report breakdown: %v", err)
	}
	byName := map[string]map[string]interface{}{}
	for _, r := range triageRowsByID(t, out) {
		byName[fmt.Sprint(r["name"])] = r
	}
	out, _, err = executeCommand("campaign", "optimize", "9", "--json")
	if err != nil {
		t.Fatalf("campaign optimize: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	dec := json.NewDecoder(strings.NewReader(out))
	dec.UseNumber()
	if err := dec.Decode(&resp); err != nil {
		t.Fatalf("not JSON: %v\n%s", err, out)
	}
	var names []string
	for _, r := range resp.Data {
		name := fmt.Sprint(r["name"])
		names = append(names, name)
		if want := byName[name]["epc"]; !reflect.DeepEqual(r["epc"], want) {
			t.Errorf("%s epc: %s, breakdown %s", name, typed(r["epc"]), typed(want))
		}
	}
	if got := strings.Join(names, ", "); got != "string offer, fine offer, penny offer" {
		t.Errorf("offers ranked %q, want the other offers by EPC, highest first", got)
	}
}

// A reason names a spend or a CPC below a cent as the table shows it, not as $0.00.
func TestTriageReasonsKeepAmountsBelowACent(t *testing.T) {
	for _, c := range []struct {
		clicks, leads, cost, net, avgCPC, payout, maxCPC float64
		want                                             string
	}{
		{3, 0, 0.00375, -0.00375, 0.00125, 0, 0, "spent $0.0037, 0 conversions"},
		{8, 1, 0.0101, -0.0001, 0.0012625, 0, 0.00126, "CPC $0.0013 > breakeven $0.0013"},
		{8, 1, 0.0101, -0.0001, 0.0012625, 0, 0, "loss $-0.0001"},
		{4, 1, 0.005, 0.005, 0.00125, 0, 0, "profit +$0.005, 1 conv"},
		{1124, 0, 3171.62, -3171.62, 2.8217, 0, 0, "spent $3171.62, 0 conversions"},
	} {
		if _, got := classify(c.clicks, c.leads, c.cost, c.net, c.avgCPC, c.payout, c.maxCPC); got != c.want {
			t.Errorf("classify(cost %v, net %v, CPC %v) reason = %q, want %q", c.cost, c.net, c.avgCPC, got, c.want)
		}
	}
}
