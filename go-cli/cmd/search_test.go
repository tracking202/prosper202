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
	if r := rankOf(a, "p202 analytics"); r == 0 || r > 3 || a.Results[r-1].Try != "p202 analytics --group-by browser" {
		t.Errorf("breakdown by browser: analytics at %d in %+v", r, a.Results)
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

// No report has a currency dimension. Search must say so rather than dress
// up the nearest text match as the answer. (This case was "referrer" until
// reports gained a referer dimension; see the test below.)
func TestSearchDoesNotPretendACurrencyDimensionExists(t *testing.T) {
	setTestHome(t, t.TempDir())
	for _, q := range []string{"currency", "breakdown by currency"} {
		a := searchJSON(t, q)
		if q == "currency" && (a.GoodMatch || !strings.HasPrefix(a.Note, `No command matches "currency" well`) || len(a.Results) > closestShown) {
			t.Errorf("%s: good=%v note=%q results=%d", q, a.GoodMatch, a.Note, len(a.Results))
		}
		for _, r := range a.Results {
			for _, m := range r.Matched {
				if strings.Contains(m, "accepts currency") {
					t.Errorf("%s: %s claims %q", q, r.Command, m)
				}
			}
			if strings.Contains(r.Try, "currency") {
				t.Errorf("%s: %s suggests %q", q, r.Command, r.Try)
			}
		}
	}

	stdout, _, err := executeCommand("search", "currency")
	if err != nil || !strings.HasPrefix(stdout, `No command matches "currency" well`) {
		t.Errorf("human form: %v\n%s", err, stdout)
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
	a := searchJSON(t, "xyzzy", "plugh")
	if a.GoodMatch || len(a.Results) != 0 || !strings.Contains(a.Note, "p202 commands") {
		t.Errorf("no match: %+v", a)
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
