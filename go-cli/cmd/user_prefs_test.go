package cmd

import (
	"os"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// phpStringList reads the quoted strings of one PHP array literal.
func phpStringList(literal string) []string {
	var out []string
	for _, m := range regexp.MustCompile(`'([^']*)'`).FindAllStringSubmatch(literal, -1) {
		out = append(out, m[1])
	}
	return out
}

// The server checks every preference against PreferenceRules; this table
// is what the CLI offers. Read from the PHP so a column or a choice added
// on one side and not the other fails here, in either direction.
func TestPrefFlagsMatchThePreferenceRules(t *testing.T) {
	src, err := os.ReadFile(repoPath("202-config", "User", "PreferenceRules.php"))
	if err != nil {
		t.Fatal(err)
	}
	php := string(src)
	consts := map[string][]string{}
	for _, name := range []string{"REPORT_RANGES", "REPORT_ROW_LIMITS"} {
		m := regexp.MustCompile(`const ` + name + ` = \[([^\]]*)\];`).FindStringSubmatch(php)
		if m == nil {
			t.Fatalf("PreferenceRules has no const %s this test can read", name)
		}
		consts["self::"+name] = phpStringList(m[1])
	}
	choicesBlock := regexp.MustCompile(`(?s)const CHOICES = \[(.*?)\n    \];`).FindStringSubmatch(php)
	textBlock := regexp.MustCompile(`(?s)const TEXT = \[(.*?)\n    \];`).FindStringSubmatch(php)
	specialBlock := regexp.MustCompile(`(?s)const SPECIAL = \[(.*?)\];`).FindStringSubmatch(php)
	if choicesBlock == nil || textBlock == nil || specialBlock == nil {
		t.Fatal("PreferenceRules' CHOICES, TEXT or SPECIAL could not be read: the scan is blind, not the lists empty")
	}

	server := map[string][]string{} // column -> choices; nil: free text
	for _, line := range strings.Split(choicesBlock[1], "\n") {
		m := regexp.MustCompile(`^\s*'(\w+)' => (\[.*\]|self::\w+),$`).FindStringSubmatch(line)
		if m == nil {
			if strings.TrimSpace(line) != "" {
				t.Fatalf("cannot read this CHOICES line: %q", line)
			}
			continue
		}
		if strings.HasPrefix(m[2], "self::") {
			server[m[1]] = consts[m[2]]
		} else {
			server[m[1]] = phpStringList(m[2])
		}
	}
	for _, m := range regexp.MustCompile(`'(\w+)' => \d+`).FindAllStringSubmatch(textBlock[1], -1) {
		server[m[1]] = nil
	}
	for _, name := range phpStringList(specialBlock[1]) {
		server[name] = nil
	}
	if len(server) < 20 {
		t.Fatalf("read %d preference columns from PreferenceRules, expected the full set", len(server))
	}

	cli := map[string]prefFlag{}
	for _, f := range prefFlags {
		cli[f.name] = f
	}
	for name, choices := range server {
		f, ok := cli[name]
		if !ok {
			t.Errorf("PreferenceRules writes %s, but `user prefs update` has no --%s", name, name)
			continue
		}
		if choices != nil && strings.Join(f.values, ",") != strings.Join(choices, ",") {
			t.Errorf("--%s offers [%s], the server takes [%s]", name, strings.Join(f.values, " "), strings.Join(choices, " "))
		}
	}
	var extra []string
	for name := range cli {
		if _, ok := server[name]; !ok {
			extra = append(extra, name)
		}
	}
	sort.Strings(extra)
	if len(extra) > 0 {
		t.Errorf("`user prefs update` has flags the server refuses: %v", extra)
	}

	// The currency list is UsersController's.
	users, err := os.ReadFile(repoPath("api", "v3", "Controllers", "UsersController.php"))
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`(?s)SUPPORTED_CURRENCIES = \[(.*?)\];`).FindSubmatch(users)
	if m == nil {
		t.Fatal("UsersController::SUPPORTED_CURRENCIES could not be read")
	}
	if got, want := strings.Join(accountCurrencies, ","), strings.Join(phpStringList(string(m[1])), ","); got != want {
		t.Errorf("--user-account-currency offers %s, the server supports %s", got, want)
	}
}

func TestUserPrefsUpdateSendsWhatTheFlagsSay(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("user", "prefs", "update", "1", "--user-daily-email", "never", "--user-tracking-domain", "",
		"--user-pref-limit", "100", "--chart-time-range", "hours")
	if err != nil {
		t.Fatalf("prefs update: %v", err)
	}
	body := (*seen)[0].Body
	want := map[string]interface{}{"user_daily_email": "", "user_tracking_domain": "", "user_pref_limit": "100", "chart_time_range": "hours"}
	for k, v := range want {
		if body[k] != v {
			t.Errorf("%s = %#v, want %#v (body %v)", k, body[k], v, body)
		}
	}
	if len(body) != len(want) {
		t.Errorf("body = %v, want only the flags given", body)
	}
}

func TestUserPrefsUpdateRefusesAChoiceTheServerDoesNotOfferBeforeAnyRequest(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("user", "prefs", "update", "1", "--user-daily-email", "off")
	if err == nil || !strings.Contains(err.Error(), "never, 00") {
		t.Fatalf("err = %v, want the hours listed", err)
	}
	if len(*seen) != 0 {
		t.Errorf("requests = %+v", *seen)
	}
}

// The server answers a secret only as {"stored": <bool>}; a table names that
// state, and JSON keeps the API's own shape.
const prefsWithStoredSecrets = `{"data":{"user_id":1,"user_pref_limit":50,"user_tracking_domain":"t.example",` +
	`"cb_key":{"stored":true},"ipqs_api_key":{"stored":false},"lpo_bridge_config":{"stored":true}}}`

func TestUserPrefsShowAStoredSecretAsItsState(t *testing.T) {
	for _, args := range [][]string{
		{"user", "prefs", "get", "1", "--table"},
		{"user", "prefs", "update", "1", "--cb-key", "k", "--table"},
	} {
		goalServer(t, 200, prefsWithStoredSecrets)
		stdout, _, err := executeCommand(args...)
		if err != nil {
			t.Fatalf("%v: %v", args, err)
		}
		for _, line := range []*regexp.Regexp{
			regexp.MustCompile(`(?m)^cb_key:\s+\(stored\)$`),
			regexp.MustCompile(`(?m)^ipqs_api_key:\s+\(not stored\)$`),
			regexp.MustCompile(`(?m)^lpo_bridge_config:\s+\(stored\)$`),
			regexp.MustCompile(`(?m)^user_tracking_domain:\s+t\.example$`),
		} {
			if line.FindString(stdout) == "" {
				t.Errorf("%v: no line matches %s:\n%s", args, line, stdout)
			}
		}
		if strings.Contains(stdout, `"stored"`) {
			t.Errorf("%v: the table shows the raw state object:\n%s", args, stdout)
		}
	}
}

func TestUserPrefsJSONKeepsTheStoredStateTheAPIGave(t *testing.T) {
	goalServer(t, 200, prefsWithStoredSecrets)
	stdout, _, err := executeCommand("user", "prefs", "get", "1", "--json")
	if err != nil {
		t.Fatalf("prefs get --json: %v", err)
	}
	if !strings.Contains(stdout, `"stored": true`) || strings.Contains(stdout, "(stored)") {
		t.Errorf("--json output = %s, want the API's {\"stored\": true}", stdout)
	}
}

func TestWithStoredStatesLeavesEverythingElseAlone(t *testing.T) {
	for _, in := range []string{
		`{"data":[{"cb_key":{"stored":true}}]}`,
		`{"data":{"x":{"stored":"yes"},"y":{"stored":true,"n":1},"z":{}}}`,
		`not json`,
	} {
		out := string(withStoredStates([]byte(in)))
		if strings.Contains(out, "(stored)") || strings.Contains(out, "(not stored)") {
			t.Errorf("withStoredStates(%s) = %s, want nothing relabelled", in, out)
		}
	}
}
