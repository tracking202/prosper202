package cmd

import (
	"encoding/json"
	"html"
	"os"
	"reflect"
	"regexp"
	"sort"
	"strings"
	"testing"

	"p202/internal/eval"
)

// topResult runs the search core for a query and returns the first result,
// or the reason there is none.
func topResult(tree commandTree, query string) (searchResult, searchAnswer, bool) {
	a := runSearch(tree, strings.Fields(query), 10)
	if !a.GoodMatch || len(a.Results) == 0 {
		return searchResult{}, a, false
	}
	return a.Results[0], a, true
}

// commandLine is what a result offers to run: its try line, or the command.
func (r searchResult) commandLine() string {
	if r.Try != "" {
		return r.Try
	}
	return r.Command
}

// Every task line is a command that exists, with flags it has and values
// they take: a table entry for a flag that was renamed would otherwise be
// offered to every searcher as the line to run, and fail.
func TestSearchTasksRunRealCommands(t *testing.T) {
	tree := buildCommandTree(rootCmd)
	commands := map[string]commandInfo{}
	for _, c := range tree.Commands {
		commands[c.Path] = c
	}
	pages := map[string]bool{}
	for _, task := range searchTasks {
		if len(task.Pages) == 0 && len(task.Phrases) == 0 {
			t.Errorf("%q: a task needs a page or a phrase to be found by", task.Run)
		}
		for _, p := range task.Pages {
			if pages[p] {
				t.Errorf("page %q is in two tasks", p)
			}
			pages[p] = true
		}
		if task.Run == "" {
			if task.Instead == "" || task.Hint == "" {
				t.Errorf("%v%v: a task no command does needs Instead and Hint", task.Pages, task.Phrases)
			}
			continue
		}
		path := taskCommandPath(task.Run)
		c, ok := commands[path]
		if !ok || !c.Runnable {
			t.Errorf("%q: %q is not a runnable command", task.Run, path)
			continue
		}
		flags := map[string]flagInfo{}
		for _, f := range append(append([]flagInfo{}, c.Flags...), tree.GlobalFlags...) {
			flags[f.Name] = f
		}
		words := strings.Fields(task.Run)[len(strings.Fields(path)):]
		for i := 0; i < len(words); i++ {
			name := strings.TrimPrefix(words[i], "--")
			f, ok := flags[name]
			if !strings.HasPrefix(words[i], "--") || !ok {
				t.Errorf("%q: %q is not a flag of %s", task.Run, words[i], path)
				continue
			}
			if f.Type == "bool" {
				continue
			}
			if i+1 >= len(words) {
				t.Errorf("%q: --%s needs a value", task.Run, name)
				continue
			}
			i++
			if len(f.AllowedValues) > 0 && !containsString(f.AllowedValues, words[i]) {
				t.Errorf("%q: --%s takes one of %v, not %q", task.Run, name, f.AllowedValues, words[i])
			}
		}
	}
}

// uiMenuLabels reads the pages of the web UI's menus as searchTasks names
// them: a section strip's label under its section ("Analyze › Text Ads"),
// a tab or an account-menu label as it reads.
func uiMenuLabels(t *testing.T) []string {
	t.Helper()
	read := func(path string) string {
		src, err := os.ReadFile(path)
		if err != nil {
			t.Fatalf("reading %s: %v", path, err)
		}
		return string(src)
	}
	var labels []string
	strip := regexp.MustCompile(`\['([^']+)',\s*'tracking202/(setup|overview|analyze|update)\b`).FindAllStringSubmatch(read(repoPath("tracking202", "_config", "sub-menu.php")), -1)
	tabs := regexp.MustCompile(`\$p202Tabs\[\] = \['\w+', '[^']*', '([^']+)'`).FindAllStringSubmatch(read(repoPath("tracking202", "_config", "top.php")), -1)
	account := regexp.MustCompile(`'label' => '([^']+)'`).FindAllStringSubmatch(read(repoPath("202-config", "template.php")), -1)
	// A floor on each, so a menu rewritten past these patterns fails here
	// rather than reading as a menu with nothing in it.
	if len(strip) < 30 || len(tabs) < 6 || len(account) < 10 {
		t.Fatalf("read %d strip items, %d tabs, %d account-menu labels: the patterns no longer read the menus", len(strip), len(tabs), len(account))
	}
	tags := regexp.MustCompile(`<[^>]+>`)
	for _, m := range strip {
		labels = append(labels, strings.ToUpper(m[2][:1])+m[2][1:]+" › "+m[1])
	}
	for _, m := range tabs {
		labels = append(labels, m[1])
	}
	for _, m := range account {
		labels = append(labels, strings.Join(strings.Fields(html.UnescapeString(tags.ReplaceAllString(m[1], ""))), " "))
	}
	return labels
}

// Every page of the web UI's menus is in the task table, and every page the
// table names is in a menu: a page added to the UI cannot go unfindable,
// and a page renamed cannot leave a label nobody sees.
func TestEveryUIMenuLabelHasASearchTask(t *testing.T) {
	inTable := map[string]bool{}
	for _, task := range searchTasks {
		for _, p := range task.Pages {
			inTable[p] = true
		}
	}
	inMenu := map[string]bool{}
	for _, label := range uiMenuLabels(t) {
		inMenu[label] = true
		if !inTable[label] && searchSections[label] == "" {
			t.Errorf("the UI's %q page has no entry in searchTasks: add the command line that does what it does (or, if none does, Instead and Hint)", label)
		}
	}
	for p := range inTable {
		if !inMenu[p] {
			t.Errorf("searchTasks names the page %q, which no menu has", p)
		}
	}
	for s := range searchSections {
		if !inMenu[s] {
			t.Errorf("searchSections names %q, which no menu has", s)
		}
	}
}

// Searching for a page by its name finds what it does: the task's line
// first, as a good match. A label two sections share ("Text Ads") finds
// either section's alone and its own section's when the section is named.
func TestSearchFindsEveryUIPage(t *testing.T) {
	setTestHome(t, t.TempDir())
	tree := buildCommandTree(rootCmd)
	byLabel := map[string][]searchTask{}
	for _, task := range searchTasks {
		for _, p := range task.Pages {
			_, label := splitPage(p)
			byLabel[label] = append(byLabel[label], task)
		}
	}
	for _, task := range searchTasks {
		for _, page := range task.Pages {
			section, label := splitPage(page)
			queries := []string{label}
			if section != "" && len(byLabel[label]) > 1 {
				queries = []string{section + " " + label}
			}
			for _, q := range queries {
				if task.Run == "" {
					a := runSearch(tree, strings.Fields(q), 10)
					if a.notInCLI == nil || !strings.Contains(searchError(a).Error(), page) {
						t.Errorf("%q: want the answer that %q has no command, got %+v", q, page, a)
					}
					continue
				}
				r, a, ok := topResult(tree, q)
				if !ok {
					t.Errorf("%q: no good match (results %+v)", q, a.Results)
				} else if r.commandLine() != task.Run {
					t.Errorf("%q: want %q first, got %q", q, task.Run, r.commandLine())
				}
			}
			if section != "" && len(byLabel[label]) > 1 {
				r, _, ok := topResult(tree, label)
				found := false
				for _, other := range byLabel[label] {
					found = found || (ok && r.commandLine() == other.Run)
				}
				if !found {
					t.Errorf("%q: want one of the %q pages' commands first, got %q", label, label, r.commandLine())
				}
			}
		}
	}
}

// What someone, or an agent, calls the Spy page without knowing its name.
// Each found nothing, or the wrong command, before the task table: "live
// clicks" found goal outcomes, "watch clicks" report losers, and
// "real-time traffic" found click list through --show real, the filter for
// human clicks, which is not live at all.
func TestSearchFindsSpyByWhatPeopleCallIt(t *testing.T) {
	setTestHome(t, t.TempDir())
	tree := buildCommandTree(rootCmd)
	for _, q := range []string{
		"spy", "realtime traffic", "real-time traffic", "real time clicks", "live traffic", "live clicks",
		"watch clicks", "watch traffic as it arrives", "tail clicks", "monitor traffic", "stream clicks",
		"new clicks as they come in",
	} {
		r, a, ok := topResult(tree, q)
		if !ok || r.Try != "p202 click list --follow" {
			t.Errorf("%q: want try p202 click list --follow first, got %q (results %v)", q, r.commandLine(), a.Results)
		}
	}
}

// searchKnownMisses are the agent-eval asks whose command search does not
// put first, each with why and where it lands. The list only shrinks: a
// listed case that search now finds fails until it is removed, and a new case
// search misses fails until a task phrase finds it or it is listed here.
//
// The fused ranker (search_rank.go) found 9 cases the hand-weighted scorer
// had listed here and lost 2 it had found (attribution-003 and
// mobile-apps-ui-001), each now second: two tasks in one ask, where the
// other task's command comes first.
var searchKnownMisses = map[string]string{
	"attribution-003-development-postbacks-count-only-once-the-app-opts-in": "register an app and count its postbacks: two tasks; app report first, app create second",
	"breakdown-002-no-such-transaction-is-said-not-invented":                "the expected command is a lookup step (conversion list), not the task asked",
	"ios-sdk-001-encode-a-funnel-goal":                                      "describes the encoding's meaning, not the command; app encoding create third",
	"ltv-004-line-items-on-a-sale-with-no-known-customer":                   "the expected command is a lookup step (click list, fourth); the task asked, recording revenue, is first",
	"mobile-apps-ui-001-android-app-and-its-tracking-link":                  "set up an app and give its tracking link: two tasks; the tracker first, app link second",
	"setup-code-002-an-advanced-page-names-its-offers":                      "asks for the buttons' links; nothing says \"code\" (landing-page code fourth)",
	"setup-code-004-a-traffic-source-variable-reaches-the-link":             "\"pass it along as adid\" names no variable; the link ranks the tracker first",
	"setup-link-002-the-agent-knows-whose-key-it-holds":                     "\"which user does this key act as\" carries no word whoami's text uses; whoami second",
	"setup-link-003-a-rule-that-redirects-to-a-url-delivers-the-click":      "a redirector name, an address and a URL crowd out \"add a rule\"",
	"staged-001-apply-writes-the-reviewed-payload":                          "the task is a create with --staged; the expected command is the apply step (fourth)",
	"triage-002-no-sale-value-given-is-not-invented":                        "\"losing money\" is not read as losers (fifth)",
	"update-001-a-cost-set-on-one-campaign-only":                            "a campaign name and two days crowd out \"record that cost on those clicks\" (sixth)",
}

// The agent-eval asks are what an agent is actually asked, each with the
// command that answers it: a measure of search that its own author did not
// write the queries for. Search must put the case's command first as a good
// match for every case not in searchKnownMisses.
func TestSearchFindsTheEvalAsksCommand(t *testing.T) {
	setTestHome(t, t.TempDir())
	tree := buildCommandTree(rootCmd)
	cases, err := eval.LoadCases(repoPath("tests", "fixtures", "agent-eval", "cases"))
	if err != nil {
		t.Fatal(err)
	}
	checked, found := 0, 0
	seen := map[string]bool{}
	for _, c := range cases {
		// Only the patterns that name a command say what search should
		// find; a flag alone (--staged) does not.
		var commands []string
		for _, p := range c.Expected.RunsOneOf {
			if !strings.HasPrefix(strings.TrimSpace(p), "-") {
				commands = append(commands, p)
			}
		}
		if len(commands) == 0 {
			continue
		}
		checked++
		seen[c.ID] = true
		// The first candidate, confident or not: an agent reads the list.
		a := runSearch(tree, strings.Fields(c.Ask), 10)
		ok := len(a.Results) > 0
		var r searchResult
		if ok {
			r = a.Results[0]
		}
		hit := false
		for _, p := range commands {
			hit = hit || (ok && (strings.Contains(r.commandLine(), p) || strings.Contains(r.Command, p)))
		}
		if hit {
			found++
		}
		reason, listed := searchKnownMisses[c.ID]
		switch {
		case hit && listed:
			t.Errorf("%s: search now finds %v first; remove it from searchKnownMisses (%s)", c.ID, commands, reason)
		case !hit && !listed:
			got := "no good match"
			if ok {
				got = r.commandLine()
			} else if len(a.Results) > 0 {
				got += ", first " + a.Results[0].Command
			}
			t.Errorf("%s: want one of %v first, got %s. Add a phrase for the task to searchTasks, or list the case in searchKnownMisses with why.\nask: %s", c.ID, commands, got, c.Ask)
		}
	}
	for id := range searchKnownMisses {
		if !seen[id] {
			t.Errorf("searchKnownMisses lists %s, which is no case with a command to find", id)
		}
	}
	if checked < 30 {
		t.Fatalf("checked %d cases: the loader or the filter no longer reads the cases", checked)
	}
	t.Logf("%d of %d asks find their command first", found, checked)
}

// A page with no command is answered with where it is done, not with the
// nearest command.
func TestSearchNamesPagesWithNoCommand(t *testing.T) {
	setTestHome(t, t.TempDir())
	for query, want := range map[string]string{
		"watch tv202": `No p202 command does what the web UI's "Watch TV202" page does`,
		"hot deals":   `the web UI's "Hot Deals & Discounts" page`,
		"upgrade":     `No p202 command for "upgrade": upgrades run from the web UI's upgrade page.`,
	} {
		message, hint := searchFails(t, strings.Fields(query)...)
		if !strings.Contains(message, want) || hint == "" {
			t.Errorf("%q: %q / %q", query, message, hint)
		}
	}
	if _, hint := searchFails(t, "upgrade"); !strings.Contains(hint, "p202 system info") {
		t.Errorf("upgrade: hint %q should name p202 system info", hint)
	}
	// Reaching a page with no command through a synonym and a prefix is not
	// naming it: "server" → install upgrade, "up" → upgrade. This answered
	// that the CLI cannot do what `system health` does.
	// (Where `system health` ranks for it is the ranker's question; "up"
	// begins upload, update and upgrade. The refusal was the bug.)
	a := searchJSON(t, "is", "the", "server", "up")
	if len(a.Results) == 0 {
		t.Errorf("is the server up: refused, or nothing: %+v", a)
	}
}

func TestSearchFoldsShortPluralsCompoundsAndStemmedStopwords(t *testing.T) {
	for query, want := range map[string][]string{
		"IPs and ads":               {"ip", "ad"},
		"real-time traffic":         {"realtime", "traffic"},
		"real time clicks":          {"realtime", "click"},
		"sign-in attempts":          {"signin", "attempt"},
		"sub ids":                   {"subid"},
		"what's this":               nil, // "this" was kept as "thi"; the "s" of what's is no word
		"does the key have a scope": {"key", "scope"},
	} {
		if got := searchQueryTerms(strings.Fields(query)); !reflect.DeepEqual(got, want) {
			t.Errorf("%q: terms %v, want %v", query, got, want)
		}
	}
	// "IPs" found nothing at all: three-letter plurals were not folded.
	setTestHome(t, t.TempDir())
	if r, a, ok := topResult(buildCommandTree(rootCmd), "IPs"); !ok || r.commandLine() != "p202 report breakdown --breakdown ip" {
		t.Errorf("IPs: %q (results %v)", r.commandLine(), a.Results)
	}
}

// `p202 commands --json` carries the tasks, so an agent that loads the tree
// instead of searching finds the same command lines and pages.
func TestCommandsJSONListsTasks(t *testing.T) {
	setTestHome(t, t.TempDir())
	stdout, _, err := executeCommand("commands", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var tree commandTree
	if err := json.Unmarshal([]byte(stdout), &tree); err != nil {
		t.Fatal(err)
	}
	var spy *taskInfo
	for _, c := range tree.Commands {
		if c.Path == "p202 click list" {
			for i := range c.Tasks {
				if c.Tasks[i].Run == "p202 click list --follow" {
					spy = &c.Tasks[i]
				}
			}
		}
	}
	if spy == nil || !reflect.DeepEqual(spy.UIPages, []string{"Spy"}) || len(spy.Phrases) == 0 {
		t.Errorf("click list's Spy task: %+v", spy)
	}
	var notInCLI []string
	for _, t := range tree.NotInCLI {
		notInCLI = append(notInCLI, t.UIPages...)
	}
	sort.Strings(notInCLI)
	if !containsString(notInCLI, "Watch TV202") || !containsString(notInCLI, "Help") {
		t.Errorf("not_in_cli pages: %v", notInCLI)
	}
	stdout, _, err = executeCommand("commands", "click", "--json")
	if err != nil || strings.Contains(stdout, "not_in_cli") {
		t.Errorf("a subtree lists no pages without commands: %v\n%s", err, stdout)
	}
	// --ndjson ends with the pages no command does, one line.
	stdout, _, err = executeCommand("commands", "--ndjson")
	lines := strings.Split(strings.TrimSpace(stdout), "\n")
	var last map[string][]taskInfo
	if err != nil || json.Unmarshal([]byte(lines[len(lines)-1]), &last) != nil || len(last["not_in_cli"]) == 0 {
		t.Errorf("--ndjson: last line %q (%v)", lines[len(lines)-1], err)
	}
}
