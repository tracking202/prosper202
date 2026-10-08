package cmd

import (
	"encoding/json"
	"reflect"
	"strings"
	"testing"
)

func searchJSON(t *testing.T, words ...string) searchAnswer {
	t.Helper()
	stdout, _, err := executeCommand(append(append([]string{"search"}, words...), "--json")...)
	if err != nil {
		t.Fatalf("search %v: %v", words, err)
	}
	var a searchAnswer
	if err := json.Unmarshal([]byte(stdout), &a); err != nil {
		t.Fatalf("search --json is not JSON: %v\n%s", err, stdout)
	}
	return a
}

func rankOf(a searchAnswer, command string) int {
	for i, r := range a.Results {
		if r.Command == command {
			return i + 1
		}
	}
	return 0
}

func TestSearchFindsTheCommandForEachTask(t *testing.T) {
	setTestHome(t, t.TempDir())

	a := searchJSON(t, "breakdown", "by", "browser")
	if !a.GoodMatch || rankOf(a, "p202 report breakdown") != 1 {
		t.Errorf("breakdown by browser: %+v", a.Results)
	}
	// report groups (the Group Overview) also groups by browser, and ranks
	// beside the single-level commands; the shorthand stays near the top.
	if r := rankOf(a, "p202 analytics"); r == 0 || r > 4 || a.Results[r-1].Try != "p202 analytics --group-by browser" {
		t.Errorf("breakdown by browser: analytics at %d in %+v", r, a.Results)
	}
	if r := rankOf(a, "p202 report groups"); r != 0 && a.Results[r-1].Try != "p202 report groups --by browser" {
		t.Errorf("breakdown by browser: report groups offered without a runnable try: %+v", a.Results[r-1])
	}
	if a.Results[0].Try != "p202 report breakdown --breakdown browser" {
		t.Errorf("breakdown by browser: try = %q", a.Results[0].Try)
	}

	a = searchJSON(t, "dead links")
	if !a.GoodMatch || rankOf(a, "p202 campaign check-urls") != 1 || !containsString(a.Results[0].Matched, `command name "check-urls" (for "link")`) {
		t.Errorf("dead links: %+v", a.Results)
	}

	a = searchJSON(t, "undo", "url", "change")
	if !a.GoodMatch || rankOf(a, "p202 campaign replace-url") != 1 || !containsString(a.Results[0].Matched, "flag --undo") {
		t.Errorf("undo url change: %+v", a.Results)
	}

	// --split-at's only description was its own flag help, so this found
	// forecast-event create (--lead-days "before", --lag-days "after").
	a = searchJSON(t, "compare", "before", "and", "after", "a", "date")
	if !a.GoodMatch || rankOf(a, "p202 analytics") != 1 {
		t.Errorf("compare before and after a date: %+v", a.Results)
	}

	a = searchJSON(t, "clicks per campaign")
	if !a.GoodMatch || rankOf(a, "p202 report breakdown") != 1 {
		t.Errorf("clicks per campaign: %+v", a.Results)
	}
	if r := rankOf(a, "p202 analytics"); r == 0 || r > 5 {
		t.Errorf("clicks per campaign: analytics at %d", r)
	}
	if !strings.Contains(a.Results[0].Try, "--breakdown campaign") {
		t.Errorf("clicks per campaign: try = %q", a.Results[0].Try)
	}
}

// searchFails runs a search that must find no good match: it fails with
// exit 1 and prints nothing on stdout, in every output mode, so a script
// that reads the first line or the exit status cannot take a guess for the
// answer.
func searchFails(t *testing.T, words ...string) (message, hint string) {
	t.Helper()
	for _, mode := range [][]string{nil, {"--json"}, {"--quiet"}} {
		args := append(append([]string{"search"}, words...), mode...)
		stdout, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != ExitValidation || stdout != "" {
			t.Fatalf("%v: want exit 1 with nothing on stdout, got err=%v stdout=%q", args, err, stdout)
		}
		message, hint = err.Error(), hintFor(err)
	}
	return message, hint
}

// No report has a currency dimension. Search must say so rather than dress
// up the nearest text match as the answer. (This case was "referrer" until
// reports gained a referer dimension; see the test below.)
func TestSearchDoesNotPretendACurrencyDimensionExists(t *testing.T) {
	setTestHome(t, t.TempDir())
	message, hint := searchFails(t, "currency")
	if message != `No command matches "currency" well.` || !strings.Contains(hint, "The closest") || !strings.Contains(hint, "p202 commands") {
		t.Errorf("currency: %q / %q", message, hint)
	}
	if strings.Count(hint, "`p202 ") > closestShown+1 { // the closest, and `p202 commands`
		t.Errorf("currency: more than %d commands named: %q", closestShown, hint)
	}
	// With "breakdown" in the query the breakdown command is a fair answer;
	// what it must not do is claim the dimension or offer it.
	stdout, _, err := executeCommand("search", "breakdown", "by", "currency", "--json")
	if err == nil {
		var a searchAnswer
		if jerr := json.Unmarshal([]byte(stdout), &a); jerr != nil {
			t.Fatalf("breakdown by currency: %v\n%s", jerr, stdout)
		}
		for _, r := range a.Results {
			for _, m := range r.Matched {
				if strings.Contains(m, "accepts currency") {
					t.Errorf("breakdown by currency: %s claims %q", r.Command, m)
				}
			}
			if strings.Contains(r.Try, "currency") {
				t.Errorf("breakdown by currency: %s suggests %q", r.Command, r.Try)
			}
		}
	} else if exitCodeForError(err) != ExitValidation || stdout != "" {
		t.Errorf("breakdown by currency: %v, stdout %q", err, stdout)
	}
}

// Reports break down by referer now (the Analyze › Referers page's dimension),
// and "referrer", the dictionary spelling, is an alias for it.
func TestSearchFindsTheRefererBreakdown(t *testing.T) {
	setTestHome(t, t.TempDir())
	a := searchJSON(t, "breakdown", "by", "referrer")
	if !a.GoodMatch || rankOf(a, "p202 report breakdown") != 1 {
		t.Fatalf("breakdown by referrer: %+v", a.Results)
	}
	if !strings.Contains(a.Results[0].Try, "--breakdown referrer") {
		t.Errorf("breakdown by referrer: try = %q", a.Results[0].Try)
	}
	if got := resolveDimension("referrer"); got != "referer" {
		t.Errorf("resolveDimension(referrer) = %q, want referer", got)
	}
}

func TestSearchWithNothingToSearchFor(t *testing.T) {
	setTestHome(t, t.TempDir())
	message, hint := searchFails(t, "xyzzy", "plugh")
	if message != `No command matches "xyzzy plugh".` || !strings.Contains(hint, "p202 commands") || strings.Contains(hint, "closest") {
		t.Errorf("no match: %q / %q", message, hint)
	}
	for _, args := range [][]string{{"search"}, {"search", "the", "by"}} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 search") {
			t.Errorf("%v: %v / %q", args, err, hintFor(err))
		}
	}
	_, _, err := executeCommand("search", "clicks", "--limit", "0")
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Errorf("--limit 0: %v", err)
	}
}

func TestSearchNormalisesPluralsAndSynonyms(t *testing.T) {
	if got := searchQueryTerms([]string{"Clicks", "per", "the", "CAMPAIGNS", "entries"}); !reflect.DeepEqual(got, []string{"click", "campaign", "entry"}) {
		t.Errorf("terms = %v", got)
	}
	if !containsString(searchSynonyms["referrer"], "referer") || !containsString(searchSynonyms["undo"], "revert") || !containsString(searchSynonyms["link"], "url") {
		t.Error("synonym groups are not symmetric")
	}
}

// The words people use for an update find the update, not the staged-change
// commands (named "change") or the create; and the commands named "change"
// still win when they are what was asked for.
func TestSearchReadsEditVerbsAsUpdate(t *testing.T) {
	setTestHome(t, t.TempDir())
	for query, want := range map[string]string{
		"change my timezone":         "p202 user update",
		"rename a user":              "p202 user update",
		"edit campaign payout":       "p202 campaign update",
		"modify a tracker":           "p202 tracker update",
		"set default url on rotator": "p202 rotator update",
		"set the api url":            "p202 config set-url",
		"set the server url":         "p202 config set-url",
		"list staged changes":        "p202 change list",
		"apply a change":             "p202 change apply",
	} {
		a := searchJSON(t, strings.Fields(query)...)
		if rankOf(a, want) != 1 {
			var got []string
			for _, r := range a.Results {
				got = append(got, r.Command)
			}
			t.Errorf("%q: want %s first, got %v", query, want, got)
		}
	}
}
