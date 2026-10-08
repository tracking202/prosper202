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
	if a.Results[0].Try != "p202 report breakdown --breakdown browser" {
		t.Errorf("breakdown by browser: try = %q", a.Results[0].Try)
	}
	// report groups (the Group Overview) also groups by browser, and offers
	// its own flag for it.
	if r := rankOf(a, "p202 report groups"); r != 0 && a.Results[r-1].Try != "p202 report groups --by browser" {
		t.Errorf("breakdown by browser: report groups offered without a runnable try: %+v", a.Results[r-1])
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
	// forecast-event create (--lead-days "before", --lag-days "after"); and
	// "before" is a word of `system retention delete-before`'s name.
	a = searchJSON(t, "compare", "before", "and", "after", "a", "date")
	if rankOf(a, "p202 analytics") != 1 {
		t.Errorf("compare before and after a date: %+v", a.Results)
	}

	a = searchJSON(t, "clicks per campaign")
	if !a.GoodMatch || rankOf(a, "p202 report breakdown") != 1 {
		t.Errorf("clicks per campaign: %+v", a.Results)
	}
	if !strings.Contains(a.Results[0].Try, "--breakdown campaign") {
		t.Errorf("clicks per campaign: try = %q", a.Results[0].Try)
	}

	// --group-by is report breakdown's alias for --breakdown: a flag taking
	// the same values as one already set is not offered as well.
	a = searchJSON(t, "Which IP addresses clicked on my keyword 'cheap flights'?")
	if rankOf(a, "p202 report breakdown") != 1 || !strings.HasPrefix(a.Results[0].Try, "p202 report breakdown --breakdown ip") || strings.Contains(a.Results[0].Try, "--group-by") {
		t.Errorf("IPs on a keyword: %+v", a.Results)
	}
}

// searchFails runs a search that must have no answer: it fails with exit 1
// and prints nothing on stdout, in every output mode.
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

// No report has a currency dimension. Search hands back what matches —
// `user prefs update` sets the account's currency — and never a breakdown by
// currency, which would be a guess dressed as an answer. (This case was
// "referrer" until reports gained a referer dimension; see the test below.)
func TestSearchDoesNotPretendACurrencyDimensionExists(t *testing.T) {
	setTestHome(t, t.TempDir())
	for _, q := range []string{"currency", "breakdown by currency"} {
		a := searchJSON(t, strings.Fields(q)...)
		for _, r := range a.Results {
			for _, m := range r.Matched {
				if strings.Contains(m, "accepts currency") {
					t.Errorf("%s: %s claims %q", q, r.Command, m)
				}
			}
			for _, flag := range []string{"--breakdown currency", "--group-by currency", "--by currency", "--cols currency", "--rows currency"} {
				if strings.Contains(r.Try, flag) {
					t.Errorf("%s: %s suggests %q", q, r.Command, r.Try)
				}
			}
		}
		if q == "breakdown by currency" && a.GoodMatch {
			t.Errorf("%s: a confident answer for half the question: %+v", q, a.Results)
		}
	}
	stdout, _, err := executeCommand("search", "breakdown", "by", "currency")
	if err != nil || !strings.HasPrefix(stdout, `No confident match for "breakdown by currency"`) || !strings.Contains(stdout, "`p202 commands --brief`") {
		t.Errorf("human form: %v\n%s", err, stdout)
	}
}

// A search hands back candidates whether or not the first is a confident
// answer, as `cf cli search` does: of the 25 benchmark queries the previous
// scorer refused outright, 20 have the right command in the top five now (5
// of the 7 among the phrasings sealed before tuning). Only --quiet, which
// prints command paths alone, withholds a guess.
func TestSearchQuietPrintsOnlyAConfidentAnswer(t *testing.T) {
	setTestHome(t, t.TempDir())
	stdout, _, err := executeCommand("search", "realtime", "traffic", "--quiet")
	if err != nil || !strings.HasPrefix(stdout, "p202 click list\n") {
		t.Errorf("realtime traffic --quiet: %v\n%s", err, stdout)
	}
	stdout, _, err = executeCommand("search", "breakdown", "by", "currency", "--quiet")
	if err == nil || exitCodeForError(err) != ExitValidation || stdout != "" || !strings.Contains(hintFor(err), "The candidates") || !strings.Contains(hintFor(err), "p202 commands --brief") {
		t.Errorf("weak --quiet: want exit 1, nothing on stdout and the candidates in the hint; got %v %q / %q", err, stdout, hintFor(err))
	}
	a := searchJSON(t, "breakdown", "by", "currency")
	if a.GoodMatch || len(a.Results) == 0 {
		t.Errorf("weak --json: want candidates and good_match false, got %+v", a)
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
	if message != `No command matches "xyzzy plugh".` || !strings.Contains(hint, "p202 commands --brief") {
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
	a := searchJSON(t, "clicks")
	if len(a.Results) > defaultSearchLimit {
		t.Errorf("default limit: %d results", len(a.Results))
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
// still win when they are what was asked for. Two of these are ambiguous in
// the words themselves and are held to the top three: a campaign's payout is
// also changed per goal (`goal campaign set` "changes its payout"), and "the
// server url" is also the server-to-server postback URL.
func TestSearchReadsEditVerbsAsUpdate(t *testing.T) {
	setTestHome(t, t.TempDir())
	for _, c := range []struct {
		query  string
		want   string
		within int
	}{
		{"change my timezone", "p202 user update", 1},
		{"rename a user", "p202 user update", 1},
		{"edit campaign payout", "p202 campaign update", 3},
		{"modify a tracker", "p202 tracker update", 1},
		{"set default url on rotator", "p202 rotator update", 1},
		{"set the api url", "p202 config set-url", 1},
		{"set the server url", "p202 config set-url", 3},
		{"list staged changes", "p202 change list", 1},
		{"apply a change", "p202 change apply", 1},
	} {
		a := searchJSON(t, strings.Fields(c.query)...)
		if r := rankOf(a, c.want); r == 0 || r > c.within {
			var got []string
			for _, r := range a.Results {
				got = append(got, r.Command)
			}
			t.Errorf("%q: want %s within the first %d, got %v", c.query, c.want, c.within, got)
		}
	}
}

// tryLine sets one flag per query word. A flag sharing a value list with one
// already set is the same choice (report breakdown's --group-by is
// --breakdown), and a word the task's line already spent is not spent again
// on another flag that happens to take it. No command in the tree has that
// second shape today, so it is pinned here on a made-up one.
func TestSearchTryLineSetsOneFlagPerWord(t *testing.T) {
	values := []flagValue{{"breakdown", "campaign"}, {"group-by", "campaign"}, {"sort", "campaign"}, {"sort", "clicks"}}
	slots := map[string]string{"breakdown": "campaign,country", "group-by": "campaign,country", "sort": "campaign,clicks"}
	terms := []string{"campaign"} // as parseSearchQuery hands them over: stemmed
	got := tryLine("p202 r", "p202 r --breakdown campaign", values, slots, terms, termSetOf(terms))
	if got != "p202 r --breakdown campaign" {
		t.Errorf("task line: %q", got)
	}
	terms = []string{"campaign", "click"}
	got = tryLine("p202 r", "", values, slots, terms, termSetOf(terms))
	if got != "p202 r --breakdown campaign --sort clicks" {
		t.Errorf("no task line: %q", got)
	}
	values = append(values, flagValue{"breakdown", "country"}, flagValue{"group-by", "country"})
	terms = []string{"campaign", "country"}
	got = tryLine("p202 r", "p202 r --breakdown campaign", values, slots, terms, termSetOf(terms))
	if got != "p202 r --breakdown campaign" {
		t.Errorf("a second value for the slot the task line set: %q", got)
	}
}

// Two commands each ranked first by one ranker and second by the other tie
// in the fusion. The one that matched more of the query goes first; at equal
// coverage, the BM25F ranker's first (it weighs a page or phrase named in
// full); never the one whose name sorts first.
func TestSearchFusionSettlesATieByCoverageThenBM25F(t *testing.T) {
	terms := []string{"live", "click"}
	both := map[string]bool{"live": true, "click": true}
	one := map[string]bool{"click": true}
	order := func(summed, bm25f []rankedCommand) []string {
		var keys []string
		for _, f := range fuseRankings(summed, bm25f, terms) {
			keys = append(keys, f.key)
		}
		return keys
	}
	a := rankedCommand{key: "p202 a", terms: one}
	b := rankedCommand{key: "p202 b", terms: both}
	if got := order([]rankedCommand{a, b}, []rankedCommand{b, a}); !reflect.DeepEqual(got, []string{"p202 b", "p202 a"}) {
		t.Errorf("more coverage should win the tie: %v", got)
	}
	if got := order([]rankedCommand{b, a}, []rankedCommand{a, b}); !reflect.DeepEqual(got, []string{"p202 b", "p202 a"}) {
		t.Errorf("more coverage should win the tie whichever ranker put it first: %v", got)
	}
	a.terms = both
	if got := order([]rankedCommand{a, b}, []rankedCommand{b, a}); !reflect.DeepEqual(got, []string{"p202 b", "p202 a"}) {
		t.Errorf("at equal coverage BM25F's first should win: %v", got)
	}
	if got := order([]rankedCommand{b, a}, []rankedCommand{a, b}); !reflect.DeepEqual(got, []string{"p202 a", "p202 b"}) {
		t.Errorf("at equal coverage BM25F's first should win, not the name sorting first: %v", got)
	}
}
