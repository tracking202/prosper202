package cmd

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"testing"

	"p202/internal/api"
)

const testRuleSet = `[{"id":5,"rule_name":"Office","splittest":1,"criteria":[{"type":"ip","value":"203.0.113.7"}],` +
	`"redirects":[{"redirect_url":"https://a.example/","weight":70},{"redirect_url":"https://b.example/","weight":30}]}]`

// set-rules sends the set as PUT /rotators/{id}/rules {"rules": [...]},
// numbers as written; an object holding "rules" is sent as it is.
func TestRotatorSetRulesSendsTheWholeSet(t *testing.T) {
	for _, in := range []string{testRuleSet, `{"rules":` + testRuleSet + `}`} {
		_, seen := goalServer(t, 200, `{"data":{"dry_run":false,"rules":[]}}`)
		if _, _, err := executeCommand("rotator", "set-rules", "7", "--rules", in); err != nil {
			t.Fatalf("set-rules %s: %v", in, err)
		}
		if len(*seen) != 1 {
			t.Fatalf("requests = %+v, want one", *seen)
		}
		req := (*seen)[0]
		if req.Method != "PUT" || !strings.HasSuffix(req.Path, "/api/v3/rotators/7/rules") || req.Query != "" {
			t.Fatalf("request = %s %s?%s, want PUT /rotators/7/rules", req.Method, req.Path, req.Query)
		}
		var want interface{}
		if err := json.Unmarshal([]byte(`{"rules":`+testRuleSet+`}`), &want); err != nil {
			t.Fatal(err)
		}
		sent, _ := json.Marshal(req.Body)
		if wanted, _ := json.Marshal(want); string(sent) != string(wanted) {
			t.Errorf("body = %s, want %s", sent, wanted)
		}
	}
}

// --dry-run is the route's own preview, and a dry run is a read: --staged
// does not stamp it. Without --dry-run, --staged proposes the set.
func TestRotatorSetRulesDryRunAndStaged(t *testing.T) {
	for _, tc := range []struct {
		args  []string
		query string
	}{
		{[]string{"--dry-run"}, "dry_run=1"},
		{[]string{"--dry-run", "--staged"}, "dry_run=1"},
		{[]string{"--staged"}, "staged=1"},
	} {
		_, seen := goalServer(t, 200, `{"data":{"dry_run":true}}`)
		args := append([]string{"rotator", "set-rules", "7", "--rules", testRuleSet}, tc.args...)
		if _, _, err := executeCommand(args...); err != nil {
			t.Fatalf("%v: %v", tc.args, err)
		}
		if len(*seen) != 1 || (*seen)[0].Method != "PUT" || (*seen)[0].Query != tc.query {
			t.Errorf("%v: requests = %+v, want one PUT ?%s", tc.args, *seen, tc.query)
		}
	}
}

func TestRotatorSetRulesReadsAFile(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{}}`)
	path := filepath.Join(t.TempDir(), "rules.json")
	if err := os.WriteFile(path, []byte(testRuleSet+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := executeCommand("rotator", "set-rules", "7", "-F", path); err != nil {
		t.Fatalf("set-rules --file: %v", err)
	}
	if len(*seen) != 1 {
		t.Fatalf("requests = %+v", *seen)
	}
	if rules, _ := (*seen)[0].Body["rules"].([]interface{}); len(rules) != 1 {
		t.Errorf("body = %v", (*seen)[0].Body)
	}
}

// A path naming something larger than the limit is read only up to it:
// /dev/zero never ends, so reading it whole never returns.
func TestRotatorSetRulesReadsAFileOnlyUpToTheLimit(t *testing.T) {
	if _, err := os.Stat("/dev/zero"); err != nil {
		t.Skip("no /dev/zero here")
	}
	_, seen := goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("rotator", "set-rules", "7", "--file", "/dev/zero")
	if err == nil || !strings.Contains(err.Error(), "over the server's 1 MB limit") {
		t.Fatalf("err = %v, want the 1 MB refusal", err)
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit %d, want %d", code, ExitValidation)
	}
	if len(*seen) != 0 {
		t.Errorf("requests = %+v, want none", *seen)
	}
}

// What the command can judge itself is refused before any request, with a
// next step; a misshapen set never reaches the server.
func TestRotatorSetRulesRefusesWhatIsNotARuleSet(t *testing.T) {
	for _, tc := range []struct {
		args []string
		want string
	}{
		{[]string{"x", "--rules", testRuleSet}, "invalid ID"},
		{[]string{"7"}, "a rule set is required"},
		{[]string{"7", "--rules", testRuleSet, "--file", "x.json"}, "mutually exclusive"},
		{[]string{"7", "--rules", "[{"}, "--rules is not JSON"},
		{[]string{"7", "--rules", testRuleSet + testRuleSet}, "more than one JSON value"},
		{[]string{"7", "--rules", `{"data":{"rules":[]}}`}, "an API answer"},
		{[]string{"7", "--rules", `{"rule_name":"x"}`}, `without "rules"`},
		{[]string{"7", "--rules", `"rules"`}, "must be a JSON array"},
		{[]string{"7", "--rules", `[{"rule_name":"a","rule_name":"b"}]`}, "gives rules.0.rule_name twice"},
		{[]string{"7", "--rules", `{"rules":[{"criteria":[{"type":"ip","type":"country"}]}]}`}, "gives rules.0.criteria.0.type twice"},
		{[]string{"7", "--file", filepath.Join(t.TempDir(), "missing.json")}, "reading"},
	} {
		srv := newRecordingServer(t)
		home := t.TempDir()
		setTestHome(t, home)
		writeTestConfig(t, home, srv.URL, "test-key-1234")
		_, _, err := executeCommand(append([]string{"rotator", "set-rules"}, tc.args...)...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.args, err, tc.want)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit %d, want %d", tc.args, code, ExitValidation)
		}
		if hintFor(err) == "" {
			t.Errorf("%v: no hint", tc.args)
		}
		for _, req := range srv.seen() {
			if strings.HasPrefix(req, "PUT") {
				t.Errorf("%v sent %s", tc.args, req)
			}
		}
	}
}

// The server's refusals get the next step: a missing redirector names the
// list, a server without the route the upgrade, a refused set what to fix.
func TestRotatorSetRulesErrorsSayWhatToDoNext(t *testing.T) {
	for _, tc := range []struct {
		status int
		body   string
		exit   int
		hint   string
	}{
		{404, `{"error":true,"message":"Rotator not found","status":404}`, ExitValidation, "`p202 rotator list`"},
		{404, `{"error":true,"message":"Not found","status":404}`, ExitValidation, "upgrade it to 1.9.79"},
		{422, `{"error":true,"message":"Invalid rules.0.criteria","status":422,"field_errors":{"rules.0.criteria":"A rule needs at least one criterion"}}`, ExitValidation, "Nothing was written"},
		{403, `{"error":true,"message":"This account's role does not have the 'remove_rotator_rule' permission.","status":403}`, ExitAuth, "permission"},
	} {
		goalServer(t, tc.status, tc.body)
		_, _, err := executeCommand("rotator", "set-rules", "7", "--rules", testRuleSet)
		var apiErr *api.APIError
		if !errors.As(err, &apiErr) || apiErr.Status != tc.status {
			t.Fatalf("%d: err = %v, want the API's %d", tc.status, err, tc.status)
		}
		if code := exitCodeForError(err); code != tc.exit {
			t.Errorf("%d: exit %d, want %d", tc.status, code, tc.exit)
		}
		if h := hintFor(err); !strings.Contains(h, tc.hint) {
			t.Errorf("%d: hint %q, want it to name %q", tc.status, h, tc.hint)
		}
	}
}

// rotator_set_rules takes the set as JSON, not as a string of it, and the
// call writes it back compact as --rules; anything else is refused naming
// the argument, before a word is built.
func TestMCPRuleSetArgumentIsJSON(t *testing.T) {
	spec, ok := findMCPSpec("rotator_set_rules")
	if !ok {
		t.Fatal("no rotator_set_rules tool")
	}
	argv, err := mcpToolArgv(rootCmd, spec, []byte(`{"id":"7","dry_run":true,"rules":[ {"rule_name": "<a & b>", "weight": 1.50} ]}`))
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"rotator", "set-rules", "--dry-run=true", `--rules=[{"rule_name":"<a & b>","weight":1.50}]`, "--", "7"}
	if strings.Join(argv, "\x00") != strings.Join(want, "\x00") {
		t.Errorf("argv = %q, want %q", argv, want)
	}
	for in, why := range map[string]string{
		`{"id":"7","rules":"[{}]"}`:                              "must be a JSON array",
		`{"id":"7","rules":{"rules":[]}}`:                        "must be a JSON array",
		`{"id":"7","rules":null}`:                                "is null",
		`{"id":"7"}`:                                             "needs rules",
		`{"id":"7","rules":[{"rule_name":"a","rule_name":"b"}]}`: "rules.0.rule_name is given twice",
	} {
		if _, err := mcpToolArgv(rootCmd, spec, []byte(in)); err == nil || !strings.Contains(err.Error(), why) {
			t.Errorf("%s: err = %v, want %q", in, err, why)
		}
	}
}

// rotatorControllerList reads a `const NAME = ['a', 'b'];` list of the controller.
func rotatorControllerList(t *testing.T, src []byte, name string) []string {
	t.Helper()
	m := regexp.MustCompile(`(?s)const ` + name + ` = \[(.*?)\];`).FindSubmatch(src)
	if m == nil {
		t.Fatalf("RotatorsController.php has no %s", name)
	}
	out := phpStringList(string(m[1]))
	if len(out) == 0 {
		t.Fatalf("%s is empty", name)
	}
	return out
}

// The rules schema a model reads names every key the controller writes and
// no key it refuses; the read-only keys GET answers are left open, so a GET
// body goes back as it came.
func TestRotatorRulesSchemaIsTheControllers(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Controllers", "RotatorsController.php"))
	if err != nil {
		t.Fatal(err)
	}
	rules := rotatorRulesSchema()
	if rules.Type != "array" || rules.Items == nil {
		t.Fatalf("rules is %+v, want an array of objects", rules)
	}
	criterion := rules.Items.Properties["criteria"].Items
	redirect := rules.Items.Properties["redirects"].Items
	if criterion == nil || redirect == nil {
		t.Fatal("criteria and redirects are lists of objects")
	}
	for _, part := range []struct {
		name     string
		schema   *mcpProperty
		fields   string
		readOnly string
	}{
		{"rule", rules.Items, "RULE_FIELDS", "RULE_READ_ONLY"},
		{"criterion", criterion, "CRITERION_FIELDS", "CRITERION_READ_ONLY"},
		{"redirect", redirect, "REDIRECT_FIELDS", "REDIRECT_READ_ONLY"},
	} {
		fields := rotatorControllerList(t, src, part.fields)
		accepted := map[string]bool{}
		for _, f := range append(fields, rotatorControllerList(t, src, part.readOnly)...) {
			accepted[f] = true
		}
		var named []string
		for k := range part.schema.Properties {
			named = append(named, k)
			if !accepted[k] {
				t.Errorf("the %s schema names %s, which the controller refuses", part.name, k)
			}
		}
		sort.Strings(named)
		for _, f := range append(fields, "id") {
			if _, ok := part.schema.Properties[f]; !ok {
				t.Errorf("the %s schema leaves out %s (it names %v)", part.name, f, named)
			}
		}
		if part.schema.AdditionalProperties != nil && !*part.schema.AdditionalProperties {
			t.Errorf("the %s schema is closed: a GET body's read-only keys would be refused by a client", part.name)
		}
	}
	types := rotatorControllerList(t, src, "CRITERIA_TYPES")
	if strings.Join(criterion.Properties["type"].Enum, ",") != strings.Join(types, ",") {
		t.Errorf("criterion types %v, the controller's %v", criterion.Properties["type"].Enum, types)
	}
}
