package cmd

import (
	"strings"
	"testing"
)

const clickBreakdownJSON = `{"data":[
 {"conv_id":11,"click_id":7,"amount":"5.00000","payable":true,"deleted":false,"counted":false,"not_counted_reason":"superseded","superseded_reason":"replace","superseded_by":12,"explanation":"A later conversion replaced this value (the campaign pays the latest conversion).","source":"postback","source_label":"Postback","source_ref":null,"linked_to":null,"event_name":null,"transaction_id":"A-1","reverses_conv_id":null,"conv_time":1758800000},
 {"conv_id":12,"click_id":7,"amount":"10.00000","payable":true,"deleted":false,"counted":true,"not_counted_reason":null,"superseded_reason":null,"superseded_by":null,"explanation":null,"source":"goal","source_label":"Goal","source_ref":"goal:4:2","linked_to":{"type":"goal","goal_id":4,"goal_version":2,"name":"Sale","archived":false,"label":"Goal \"Sale\" v2"},"event_name":"purchase","transaction_id":"B-1","reverses_conv_id":null,"conv_time":1758800100}
],"click":{"click_id":7,"campaign_id":3,"campaign_name":"C","payout_mode":"replace","lead":true,"click_payout":"10.00000","ledger_state":"ledger","ledger_value":"10.00000","matches_click":true,"rows":2,"counted_rows":1}}`

func TestClickConversionsShowsEveryRowAndTheClicksValue(t *testing.T) {
	_, seen := goalServer(t, 200, clickBreakdownJSON)

	out, _, err := executeCommand("click", "conversions", "7")
	if err != nil {
		t.Fatalf("click conversions: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/clicks/7/conversions") {
		t.Fatalf("requests = %+v, want one GET /clicks/7/conversions", *seen)
	}
	for _, want := range []string{"superseded (replace)", "counted", `Goal "Sale" v2`, "A-1", "B-1", "purchase",
		"Click 7: 10.00000 (replace mode), 1 of 2 conversions counted."} {
		if !strings.Contains(out, want) {
			t.Errorf("output should contain %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "Warning") {
		t.Errorf("a breakdown that adds up carries no warning:\n%s", out)
	}
}

func TestClickConversionsJSONIsTheAPIsAnswerUnchanged(t *testing.T) {
	goalServer(t, 200, clickBreakdownJSON)
	out, _, err := executeCommand("--json", "click", "conversions", "7")
	if err != nil {
		t.Fatalf("click conversions --json: %v", err)
	}
	for _, want := range []string{`"matches_click": true`, `"explanation": "A later conversion replaced`, `"goal_version": 2`} {
		if !strings.Contains(out, want) {
			t.Errorf("--json should carry %s:\n%s", want, out)
		}
	}
}

func TestClickConversionsWarnsWhenTheRowsDoNotAddUpToTheClick(t *testing.T) {
	body := strings.Replace(strings.Replace(clickBreakdownJSON, `"matches_click":true`, `"matches_click":false`, 1),
		`"click_payout":"10.00000"`, `"click_payout":"7.00000"`, 1)
	goalServer(t, 200, body)
	out, _, err := executeCommand("click", "conversions", "7")
	if err != nil {
		t.Fatalf("click conversions: %v", err)
	}
	if !strings.Contains(out, "Warning: the counted conversions add up to 10.00000, which is not the click's 7.00000.") {
		t.Errorf("a breakdown that does not add up must say so:\n%s", out)
	}
}

func TestClickConversionsAndConversionFiltersRefuseBadValuesBeforeAnyRequest(t *testing.T) {
	setTestHome(t, t.TempDir())
	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"click", "conversions", "abc"}, "click id must be a positive integer", "p202 click list"},
		{[]string{"click", "conversions", "0"}, "positive integer", "p202 click list"},
		{[]string{"conversion", "list", "--click_id", "7x"}, "--click_id must be a positive integer", "p202 click list"},
		{[]string{"conversion", "list", "--source", "webhook"}, "--source must be one of pixel, postback", ""},
		{[]string{"conversion", "list", "--goal", "first"}, "--goal must be a positive integer", "p202 goal list"},
	}
	for _, tc := range cases {
		_, _, err := executeCommand(tc.args...)
		if err == nil {
			t.Errorf("%v: expected an error", tc.args)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code %d, want %d (validation): %v", tc.args, code, ExitValidation, err)
		}
		if !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: error %q should mention %q", tc.args, err, tc.want)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q should mention %q", tc.args, hintFor(err), tc.hint)
		}
	}
}

func TestConversionListSendsTheLedgerFilters(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`)
	if _, _, err := executeCommand("conversion", "list", "--click_id", "7", "--source", "goal", "--goal", "4"); err != nil {
		t.Fatalf("conversion list: %v", err)
	}
	if len(*seen) != 1 {
		t.Fatalf("requests = %+v", *seen)
	}
	q := (*seen)[0].Query
	for _, want := range []string{"click_id=7", "source=goal", "goal=4"} {
		if !strings.Contains(q, want) {
			t.Errorf("query %q should carry %s", q, want)
		}
	}
}

func TestClickConversionsNotFoundNamesTheNextStep(t *testing.T) {
	goalServer(t, 404, `{"error":true,"message":"Click not found","status":404}`)
	_, _, err := executeCommand("click", "conversions", "999")
	if err == nil {
		t.Fatal("expected an error")
	}
	if !strings.Contains(hintFor(err), "p202 click get 999") {
		t.Errorf("hint %q should name the command that checks the id", hintFor(err))
	}
}
