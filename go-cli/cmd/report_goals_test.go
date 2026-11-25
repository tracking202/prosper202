package cmd

import (
	"encoding/json"
	"net/url"
	"strings"
	"testing"
)

// funnelBreakdown answers reports/breakdown?goals= as the server does: one
// row per traffic source with its steps, the steps' goals and the totals.
func funnelBreakdown(path string, q url.Values) (int, string) {
	if !strings.HasSuffix(path, "/reports/breakdown") {
		return 200, `{"data":[]}`
	}
	return 200, `{"data":[` +
		`{"id":7,"name":"search","total_clicks":5,"goals":[` +
		`{"goal_id":12,"reached":3,"continued":3,"rate":60,"step_rate":60,"drop_off":2},` +
		`{"goal_id":13,"reached":2,"continued":2,"rate":40,"step_rate":66.6667,"drop_off":1}]}],` +
		`"breakdown":"ppc_network","truncated":false,"limit":50,"offset":0,` +
		`"goals":[{"goal_id":12,"name":"Sign-up","scope":"campaign","scope_id":316,"archived":false},` +
		`{"goal_id":13,"name":"Trial","scope":"campaign","scope_id":316,"archived":false}],` +
		`"goal_totals":{"total_clicks":8,"goals":[` +
		`{"goal_id":12,"reached":5,"continued":5,"rate":62.5,"step_rate":62.5,"drop_off":3},` +
		`{"goal_id":13,"reached":2,"continued":2,"rate":25,"step_rate":40,"drop_off":3}]}}`
}

// --goals goes to the server as the funnel's ids; a table shows each step
// as columns and says on stderr which goal each step is and the totals,
// and --json is the server's answer, steps nested, stderr empty.
func TestReportBreakdownGoalsShowsTheFunnel(t *testing.T) {
	_, requests := depthServer(t, funnelBreakdown)

	stdout, stderr, err := executeCommand("report", "breakdown", "--breakdown", "ppc_network", "--goals", "12, 13", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	var sent string
	for _, r := range requests() {
		if r.path == "reports/breakdown" {
			sent = r.query.Get("goals")
		}
	}
	if sent != "12,13" {
		t.Errorf("want goals=12,13 sent, got %q", sent)
	}
	header := strings.SplitN(stdout, "\n", 2)[0]
	for _, col := range []string{"goal_01_reached", "goal_01_rate", "goal_01_step_rate", "goal_01_drop_off", "goal_02_reached"} {
		if !strings.Contains(header, col) {
			t.Errorf("CSV header lacks %s: %s", col, header)
		}
	}
	if strings.Contains(header, ",goals") || strings.Contains(stdout, "goal_id") {
		t.Errorf("the nested steps are columns now, not a cell: %s", stdout)
	}
	if !strings.Contains(stdout, "66.6667") {
		t.Errorf("the second step's step_rate is a cell: %s", stdout)
	}
	for _, want := range []string{"goal_01 is Sign-up (goal 12)", "goal_02 is Trial (goal 13)", "Over all 8 clicks", "Sign-up 5 (62.5%)"} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr lacks %q: %s", want, stderr)
		}
	}

	stdout, stderr, err = executeCommand("report", "breakdown", "--breakdown", "ppc_network", "--goals", "12,13", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var answer struct {
		Data []struct {
			Goals []map[string]interface{} `json:"goals"`
		} `json:"data"`
		GoalTotals map[string]interface{} `json:"goal_totals"`
	}
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
		t.Fatalf("--json is not one object: %v\n%s", err, stdout)
	}
	if len(answer.Data) != 1 || len(answer.Data[0].Goals) != 2 || answer.GoalTotals == nil {
		t.Errorf("--json keeps the server's nested steps and totals: %s", stdout)
	}
	if stderr != "" {
		t.Errorf("--json keeps stderr for errors, got %q", stderr)
	}
}

// A breakdown without --goals is printed as before: no columns, no note.
func TestReportBreakdownWithoutGoalsHasNoFunnelNote(t *testing.T) {
	depthServer(t, func(string, url.Values) (int, string) {
		return 200, `{"data":[{"id":1,"name":"a","total_clicks":1}],"truncated":false,"limit":50,"offset":0}`
	})
	stdout, stderr, err := executeCommand("report", "breakdown", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(stdout, "goal_") || strings.Contains(stderr, "Funnel") {
		t.Errorf("no funnel asked, none shown: %s / %s", stdout, stderr)
	}
}

// A --goals the server would refuse is refused before any request, as a
// validation error naming the value and how to find the ids.
func TestReportBreakdownGoalsRefusesMalformedIDs(t *testing.T) {
	cases := map[string]string{
		"abc":                     `"abc" is not a goal id`,
		"12abc":                   `"12abc" is not a goal id`,
		"0":                       `"0" is not a goal id`,
		"012":                     `"012" is not a goal id`,
		"1,,2":                    `"" is not a goal id`,
		"-3":                      `"-3" is not a goal id`,
		"4294967296":              `"4294967296" is not a goal id`,
		"5,5":                     "names goal 5 twice",
		"1,2,3,4,5,6,7,8,9,10,11": "at most 10 goals, got 11",
	}
	for value, says := range cases {
		_, requests := depthServer(t, funnelBreakdown)
		_, _, err := executeCommand("report", "breakdown", "--goals", value)
		if err == nil {
			t.Errorf("--goals %q was accepted", value)
			continue
		}
		if !strings.Contains(err.Error(), says) {
			t.Errorf("--goals %q: want %q in %q", value, says, err.Error())
		}
		if got := exitCodeForError(err); got != ExitValidation {
			t.Errorf("--goals %q: exit %d, want %d", value, got, ExitValidation)
		}
		if !strings.Contains(hintFor(err), "p202 goal list") {
			t.Errorf("--goals %q: hint %q names no way to the ids", value, hintFor(err))
		}
		for _, r := range requests() {
			if r.path == "reports/breakdown" {
				t.Errorf("--goals %q reached the server", value)
			}
		}
	}
}

// A masked answer (a role without campaign figures) holds the counts as
// null: the columns are empty cells and the note says the counts are
// hidden, never a 0 and never a failure.
func TestReportBreakdownGoalsShowsAMaskedFunnel(t *testing.T) {
	depthServer(t, func(path string, q url.Values) (int, string) {
		return 200, `{"data":[{"id":7,"name":"search","total_clicks":null,"goals":[` +
			`{"goal_id":12,"reached":null,"continued":null,"rate":60,"step_rate":60,"drop_off":null}]}],` +
			`"truncated":false,"limit":50,"offset":0,"masked":true,` +
			`"goals":[{"goal_id":12,"name":"Sign-up","scope":"campaign","scope_id":316,"archived":false}],` +
			`"goal_totals":{"total_clicks":null,"goals":[{"goal_id":12,"reached":null,"continued":null,"rate":62.5,"step_rate":62.5,"drop_off":null}]}}`
	})
	stdout, stderr, err := executeCommand("report", "breakdown", "--goals", "12", "--csv")
	if err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	if len(lines) != 2 || !strings.Contains(lines[0], "goal_01_rate") || !strings.Contains(lines[1], "60") {
		t.Errorf("want the header and one row with the rate: %s", stdout)
	}
	if !strings.Contains(stderr, "Sign-up hidden (62.5%)") {
		t.Errorf("the note says the count is hidden: %s", stderr)
	}
}
