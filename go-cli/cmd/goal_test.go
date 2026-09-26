package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// goalServer records every request the command makes and answers with body.
type goalRequest struct {
	Method string
	Path   string
	Query  string
	Body   map[string]interface{}
}

func goalServer(t *testing.T, status int, body string) (*httptest.Server, *[]goalRequest) {
	t.Helper()
	var seen []goalRequest
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		req := goalRequest{Method: r.Method, Path: r.URL.Path, Query: r.URL.RawQuery}
		if data, _ := io.ReadAll(r.Body); len(data) > 0 {
			if err := json.Unmarshal(data, &req.Body); err != nil {
				t.Errorf("request body is not a JSON object: %s", data)
			}
		}
		seen = append(seen, req)
		w.WriteHeader(status)
		w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv, &seen
}

func TestGoalCreateBuildsTheDefinitionFromTheQuickFlags(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{"goal_id":12}}`)

	_, _, err := executeCommand("goal", "create", "--campaign-id", "7", "--name", "Level 3",
		"--event", "level_reached", "--where", "level gte 3; country in US,CA; coupon exists",
		"--within-days", "7", "--within-from", "install", "--repeat", "each", "--repeat-max", "2",
		"--value", "4.00", "--payout", "5", "--notify", "false")
	if err != nil {
		t.Fatalf("goal create: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/goals") {
		t.Fatalf("requests = %+v, want one POST /goals", *seen)
	}
	body := (*seen)[0].Body
	if body["scope"] != "campaign" || body["scope_id"] != float64(7) {
		t.Errorf("owner = %v/%v, want campaign/7 as a JSON number", body["scope"], body["scope_id"])
	}
	if body["payout"] != "5" || body["notify_traffic_source"] != false {
		t.Errorf("campaign terms = %v/%v", body["payout"], body["notify_traffic_source"])
	}
	def, _ := json.Marshal(body["definition"])
	want := `{"name":"Level 3","repeat":{"max":2,"mode":"each"},"trigger":{"event":"level_reached","where":[` +
		`{"op":"gte","prop":"level","value":3},{"op":"in","prop":"country","value":["US","CA"]},{"op":"exists","prop":"coupon"}]},` +
		`"value":{"amount":"4.00","type":"fixed"},"within":{"days":7,"from":"install"}}`
	if string(def) != want {
		t.Errorf("definition =\n %s\nwant\n %s", def, want)
	}
}

func TestGoalCreateRefusesBadInputBeforeAnyRequest(t *testing.T) {
	// No server configured at all: every one of these must fail on the
	// flags, as a validation error with a next step, not on the config.
	setTestHome(t, t.TempDir())
	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"goal", "create", "--name", "A", "--event", "a"}, "exactly one of --campaign-id", "p202 campaign list"},
		{[]string{"goal", "create", "--account", "--campaign-id", "7", "--name", "A", "--event", "a"}, "exactly one of", "--account"},
		{[]string{"goal", "create", "--account", "--name", "A"}, "trigger", "--event"},
		{[]string{"goal", "create", "--account", "--event", "a"}, "--name", "unique per owner"},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--where", "level like 3"}, "unknown operator", ""},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--where", "level"}, "needs a property and an operator", "level gte 3"},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--definition", "{}"}, "exclusive", ""},
		{[]string{"goal", "create", "--account", "--definition", "[1]"}, "not a JSON object", "22-goals.md"},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--payout", "3"}, "campaign goal", "goal campaign set"},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--count", "2", "--sum-prop", "x", "--sum-gte", "1"}, "exclusive", ""},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--within-days", "7"}, "go together", "--within-from install"},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--sum-prop", "x", "--sum-gte", "1", "--repeat", "each"}, "--repeat-max", "--repeat-max 12"},
		{[]string{"goal", "create", "--account", "--name", "A", "--install", "--event", "a"}, "takes no --event", ""},
		{[]string{"goal", "create", "--account", "--name", "A", "--event", "a", "--after", "x"}, "--after", "p202 goal list"},
		{[]string{"goal", "update", "5"}, "no fields specified", "--value 5"},
		{[]string{"goal", "evaluate"}, "--file", "README.md"},
		{[]string{"goal", "reevaluate", "5", "--limit", "zero"}, "--limit", ""},
		{[]string{"goal", "campaign", "set", "5", "7", "--payout", "lots"}, "--payout", ""},
		{[]string{"goal", "outcomes", "5", "--subject-type", "visitor"}, "--subject-type", ""},
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

func TestGoalCreateReadsTheDefinitionFromAFile(t *testing.T) {
	_, seen := goalServer(t, 201, `{"data":{"goal_id":12}}`)
	file := filepath.Join(t.TempDir(), "goal.json")
	if err := os.WriteFile(file, []byte(`{"name":"Sale","trigger":{"event":"sale"},"threshold":{"count":2}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("goal", "create", "--registration-id", "3", "--file", file); err != nil {
		t.Fatalf("goal create --file: %v", err)
	}
	body := (*seen)[0].Body
	if body["scope"] != "registration" || body["scope_id"] != float64(3) {
		t.Errorf("owner = %v/%v", body["scope"], body["scope_id"])
	}
	def := body["definition"].(map[string]interface{})
	if th := def["threshold"].(map[string]interface{}); th["count"] != float64(2) {
		t.Errorf("threshold = %v: the file's numbers must reach the server as numbers", th)
	}
}

func TestGoalUpdateChangesOnlyThePartsTheFlagsName(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"goal_id":5,"definition":{"name":"Buy","trigger":{"event":"buy","where":[{"prop":"sku","op":"eq","value":"A"}]},`+
		`"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}}}`)

	if _, _, err := executeCommand("goal", "update", "5", "--count", "3", "--value", "2.50"); err != nil {
		t.Fatalf("goal update: %v", err)
	}
	if len(*seen) != 2 || (*seen)[0].Method != "GET" || (*seen)[1].Method != "PUT" || !strings.HasSuffix((*seen)[1].Path, "/goals/5") {
		t.Fatalf("requests = %+v, want GET then PUT /goals/5", *seen)
	}
	def, _ := json.Marshal((*seen)[1].Body["definition"])
	want := `{"after":[],"name":"Buy","repeat":{"mode":"once"},"threshold":{"count":3},"trigger":{"event":"buy","where":[{"op":"eq","prop":"sku","value":"A"}]},` +
		`"value":{"amount":"2.50","type":"fixed"},"within":null}`
	if string(def) != want {
		t.Errorf("definition =\n %s\nwant\n %s", def, want)
	}
}

func TestGoalUpdateRefusesARepeatingSumWithoutMaxBeforeThePut(t *testing.T) {
	// The current definition is a sum reached once; --repeat each alone
	// would make it a sum that repeats without a bound, which the server
	// refuses (definition.repeat.max). The CLI says so before writing.
	_, seen := goalServer(t, 200, `{"data":{"goal_id":5,"definition":{"name":"Spend","trigger":{"event":"buy","where":[]},`+
		`"threshold":{"sum":{"prop":"$revenue","gte":"10.00"}},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}}}`)

	_, _, err := executeCommand("goal", "update", "5", "--repeat", "each")
	if err == nil {
		t.Fatal("expected an error")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code %d, want %d (validation): %v", code, ExitValidation, err)
	}
	if !strings.Contains(err.Error(), "--repeat-max") || !strings.Contains(hintFor(err), "--repeat-max 12") {
		t.Errorf("error %q / hint %q should name --repeat-max", err, hintFor(err))
	}
	for _, r := range *seen {
		if r.Method != "GET" {
			t.Fatalf("requests = %+v: nothing may be written", *seen)
		}
	}

	if _, _, err := executeCommand("goal", "update", "5", "--repeat", "each", "--repeat-max", "3"); err != nil {
		t.Fatalf("with --repeat-max: %v", err)
	}
	last := (*seen)[len(*seen)-1]
	repeat, _ := json.Marshal(last.Body["definition"].(map[string]interface{})["repeat"])
	if last.Method != "PUT" || string(repeat) != `{"max":3,"mode":"each"}` {
		t.Errorf("request = %s %s, want a PUT with repeat {\"max\":3,\"mode\":\"each\"}", last.Method, repeat)
	}
}

func TestGoalReevaluatePreviewsUnlessApplied(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"goal_id":5,"version":2,"applied":false,"subjects":[],"totals":{},"next_after":null}}`)
	if _, _, err := executeCommand("goal", "reevaluate", "5", "--version", "2", "--limit", "10"); err != nil {
		t.Fatalf("preview: %v", err)
	}
	if _, _, err := executeCommand("goal", "reevaluate", "5", "--apply", "--after", "40"); err != nil {
		t.Fatalf("apply: %v", err)
	}
	if got := (*seen)[0]; got.Method != "GET" || !strings.HasSuffix(got.Path, "/goals/5/reevaluation") || !strings.Contains(got.Query, "version=2") {
		t.Errorf("preview request = %+v", got)
	}
	if got := (*seen)[1]; got.Method != "POST" || got.Body["after"] != float64(40) {
		t.Errorf("apply request = %+v, want a POST whose after is the number 40", got)
	}
}

func TestGoalComputationsAreNeverStaged(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"valid":true}}`)
	if _, _, err := executeCommand("goal", "validate", "--name", "A", "--event", "a", "--staged"); err != nil {
		t.Fatalf("validate --staged: %v", err)
	}
	if strings.Contains((*seen)[0].Query, "staged") {
		t.Errorf("validate was stamped staged (%s); it stores nothing, so there is no proposal", (*seen)[0].Query)
	}
}

func TestGoalWritesAreStagedUnderStaged(t *testing.T) {
	_, seen := goalServer(t, 202, `{"data":{"change_id":"chg_aabbccddeeff001122334455","status":"staged"}}`)
	if _, _, err := executeCommand("goal", "campaign", "set", "5", "7", "--payout", "3", "--staged"); err != nil {
		t.Fatalf("campaign set --staged: %v", err)
	}
	if !strings.Contains((*seen)[0].Query, "staged=1") {
		t.Errorf("campaign set --staged query = %q, want staged=1", (*seen)[0].Query)
	}
}

func TestGoalCampaignRemovePreviewsWithDryRun(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{"campaign_id":7,"goal_id":5}}`)
	if _, _, err := executeCommand("goal", "campaign", "remove", "5", "7", "--dry-run"); err != nil {
		t.Fatalf("remove --dry-run: %v", err)
	}
	got := (*seen)[0]
	if got.Method != "DELETE" || !strings.HasSuffix(got.Path, "/goals/5/campaigns/7") || !strings.Contains(got.Query, "dry_run=1") {
		t.Errorf("request = %+v, want DELETE …/goals/5/campaigns/7?dry_run=1", got)
	}
}

func TestGoalRefusalsNameTheCommandThatHasTheRightValue(t *testing.T) {
	goalServer(t, 422, `{"error":true,"message":"Invalid scope_id","status":422,"field_errors":{"scope_id":"No campaign 99 in this account."}}`)
	_, _, err := executeCommand("goal", "create", "--campaign-id", "99", "--name", "A", "--event", "a")
	if err == nil {
		t.Fatal("expected the 422")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 campaign list") {
		t.Errorf("hint = %q, want the command that lists campaigns", hint)
	}
}

func TestAppEncodingNamesTheGoalCommandsForARefusedGoal(t *testing.T) {
	goalServer(t, 422, `{"error":true,"message":"Invalid goal_id","status":422,"field_errors":{"goal_id":"Goal 4 counts from the click (within.from = \"click\")."}}`)
	_, _, err := executeCommand("app", "encoding", "create", "--registration-id", "3", "--fine-value", "1", "--goal-id", "4")
	if err == nil {
		t.Fatal("expected the 422")
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 goal list --registration-id") || !strings.Contains(hint, "p202 goal create") {
		t.Errorf("hint = %q, want the goal commands", hint)
	}
	if hint := hintFor(err); !strings.Contains(hint, `"from": "click"`) || strings.Contains(hint, "plain event") {
		t.Errorf("hint = %q, want the device-reachability rule, not the retired plain-goal one", hint)
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
}
