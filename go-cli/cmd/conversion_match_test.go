package cmd

import (
	"strings"
	"testing"
)

const matchMethodsJSON = `{"data":[
 {"campaign_id":3,"campaign_name":"Offer","match_method":"click_id","match_method_label":"Click id","conversions":2,"counted":2,"income":15},
 {"campaign_id":3,"campaign_name":"Offer","match_method":"same_ip","match_method_label":"Same IP","conversions":1,"counted":1,"income":4.5}
],"totals":[
 {"match_method":"click_id","match_method_label":"Click id","conversions":2,"counted":2,"income":15},
 {"match_method":"cookie","match_method_label":"Cookie","conversions":0,"counted":0,"income":0},
 {"match_method":"visitor_id","match_method_label":"Visitor id","conversions":0,"counted":0,"income":0},
 {"match_method":"same_ip","match_method_label":"Same IP","conversions":1,"counted":1,"income":4.5},
 {"match_method":"unknown","match_method_label":"Unknown","conversions":0,"counted":0,"income":0}
],"match_methods":["click_id","cookie","visitor_id","same_ip","unknown"]}`

func TestConversionMatchMethodsSendsTheFiltersAndShowsEveryMethodsTotals(t *testing.T) {
	_, seen := goalServer(t, 200, matchMethodsJSON)
	out, _, err := executeCommand("conversion", "match-methods", "--aff-campaign-id", "3", "--period", "last30")
	if err != nil {
		t.Fatalf("conversion match-methods: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/conversions/match-methods") {
		t.Fatalf("requests = %+v, want one GET /conversions/match-methods", *seen)
	}
	for _, want := range []string{"campaign_id=3", "period=last30"} {
		if !strings.Contains((*seen)[0].Query, want) {
			t.Errorf("query %q should carry %s", (*seen)[0].Query, want)
		}
	}
	for _, want := range []string{"same_ip", "click_id", "Totals by match method:", "visitor_id", "unknown"} {
		if !strings.Contains(out, want) {
			t.Errorf("output should contain %q:\n%s", want, out)
		}
	}
}

func TestConversionMatchMethodsJSONIsTheAPIsAnswer(t *testing.T) {
	goalServer(t, 200, matchMethodsJSON)
	out, _, err := executeCommand("--json", "conversion", "match-methods")
	if err != nil {
		t.Fatalf("conversion match-methods --json: %v", err)
	}
	for _, want := range []string{`"totals"`, `"match_method_label": "Same IP"`, `"match_methods"`} {
		if !strings.Contains(out, want) {
			t.Errorf("--json should carry %s:\n%s", want, out)
		}
	}
}

func TestConversionListSendsTheMatchMethod(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`)
	if _, _, err := executeCommand("conversion", "list", "--match-method", "same_ip"); err != nil {
		t.Fatalf("conversion list: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains((*seen)[0].Query, "match_method=same_ip") {
		t.Fatalf("requests = %+v, want match_method=same_ip", *seen)
	}
}

func TestMatchMethodFlagsRefuseBadValuesBeforeAnyRequest(t *testing.T) {
	setTestHome(t, t.TempDir())
	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"conversion", "list", "--match-method", "ip"}, "--match-method must be one of: click_id, cookie, visitor_id, same_ip, unknown", ""},
		{[]string{"conversion", "list", "--match-method", ""}, "--match-method", "Omit --match-method"},
		{[]string{"conversion", "match-methods", "--aff-campaign-id", "3x"}, "--aff-campaign-id must be a positive integer", "p202 campaign list"},
		{[]string{"conversion", "match-methods", "--period", "last7d"}, "--period must be one of", ""},
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

func TestClickConversionsShowsHowEachClickWasFound(t *testing.T) {
	body := strings.Replace(clickBreakdownJSON, `"source":"postback",`, `"source":"postback","match_method":"same_ip","match_method_label":"Same IP",`, 1)
	goalServer(t, 200, body)
	out, _, err := executeCommand("click", "conversions", "7")
	if err != nil {
		t.Fatalf("click conversions: %v", err)
	}
	if !strings.Contains(out, "matched_by") || !strings.Contains(out, "same_ip") {
		t.Errorf("the table should show how the click was found:\n%s", out)
	}
}
