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
		t.Errorf("--user_account_currency offers %s, the server supports %s", got, want)
	}
}

func TestUserPrefsUpdateSendsWhatTheFlagsSay(t *testing.T) {
	_, seen := goalServer(t, 200, `{"data":{}}`)
	_, _, err := executeCommand("user", "prefs", "update", "1", "--user_daily_email", "never", "--user_tracking_domain", "",
		"--user_pref_limit", "100", "--chart_time_range", "hours")
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
	_, _, err := executeCommand("user", "prefs", "update", "1", "--user_daily_email", "off")
	if err == nil || !strings.Contains(err.Error(), "never, 00") {
		t.Fatalf("err = %v, want the hours listed", err)
	}
	if len(*seen) != 0 {
		t.Errorf("requests = %+v", *seen)
	}
}
