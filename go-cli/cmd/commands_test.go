package cmd

import (
	"encoding/json"
	"reflect"
	"strings"
	"testing"
)

func commandsJSON(t *testing.T, args ...string) commandTree {
	t.Helper()
	stdout, _, err := executeCommand(append([]string{"commands", "--json"}, args...)...)
	if err != nil {
		t.Fatalf("commands --json: %v", err)
	}
	var tree commandTree
	if err := json.Unmarshal([]byte(stdout), &tree); err != nil {
		t.Fatalf("commands --json is not JSON: %v\n%.300s", err, stdout)
	}
	return tree
}

func findCommand(tree commandTree, path string) *commandInfo {
	for i := range tree.Commands {
		if tree.Commands[i].Path == path {
			return &tree.Commands[i]
		}
	}
	return nil
}

func findFlag(c *commandInfo, name string) *flagInfo {
	for i := range c.Flags {
		if c.Flags[i].Name == name {
			return &c.Flags[i]
		}
	}
	return nil
}

func TestCommandsJSONDescribesTheWholeTreeOnce(t *testing.T) {
	setTestHome(t, t.TempDir())
	tree := commandsJSON(t)
	if tree.Schema != commandTreeSchema || len(tree.Commands) < 150 {
		t.Fatalf("schema %d, %d commands", tree.Schema, len(tree.Commands))
	}

	globals := map[string]bool{}
	for _, f := range tree.GlobalFlags {
		globals[f.Name] = true
	}
	for _, want := range []string{"json", "ndjson", "profile", "staged"} {
		if !globals[want] {
			t.Errorf("global_flags lacks --%s", want)
		}
	}

	seen := map[string]bool{}
	for _, c := range tree.Commands {
		if seen[c.Path] {
			t.Errorf("%s listed twice", c.Path)
		}
		seen[c.Path] = true
		for _, f := range c.Flags {
			if globals[f.Name] || f.Name == "help" {
				t.Errorf("%s repeats --%s", c.Path, f.Name)
			}
		}
	}
	for _, hidden := range []string{"p202 help"} {
		if seen[hidden] {
			t.Errorf("%s is listed", hidden)
		}
	}

	analytics := findCommand(tree, "p202 analytics")
	if analytics == nil {
		t.Fatal("p202 analytics missing")
	}
	groupBy := findFlag(analytics, "group-by")
	if groupBy == nil || !reflect.DeepEqual(groupBy.AllowedValues, breakdownDimensions) || groupBy.ValueAliases["lp"] != "landing_page" {
		t.Errorf("analytics --group-by = %+v", groupBy)
	}
	if limit := findFlag(analytics, "limit"); limit == nil || limit.Shorthand != "l" || limit.AllowedValues != nil {
		t.Errorf("analytics --limit = %+v", limit)
	}

	list := findCommand(tree, "p202 conversion list")
	if list == nil || findFlag(list, "campaign_id") != nil || findFlag(list, "campaign-id") != nil {
		t.Error("a hidden flag (conversion list --campaign-id) is listed")
	}
	if create := findCommand(tree, "p202 conversion create"); create == nil || !findFlag(create, "click-id").Required {
		t.Error("conversion create --click-id is not marked required")
	}
	if create := findCommand(tree, "p202 campaign create"); create == nil || !findFlag(create, "aff-campaign-name").Required {
		t.Error("campaign create --aff-campaign-name is not marked required")
	}
	if eval := findCommand(tree, "p202 eval run"); eval == nil || !findFlag(eval, "priority").ValueList {
		t.Error("eval run --priority is not marked as a value list")
	}

	// Stable: a second dump is byte-identical.
	again := commandsJSON(t)
	if !reflect.DeepEqual(tree, again) {
		t.Error("two dumps differ")
	}
}

func TestCommandsSubtreeAndPlainForms(t *testing.T) {
	setTestHome(t, t.TempDir())
	sub := commandsJSON(t, "report")
	if len(sub.Commands) < 5 {
		t.Fatalf("report subtree has %d commands", len(sub.Commands))
	}
	for _, c := range sub.Commands {
		if c.Path != "p202 report" && !strings.HasPrefix(c.Path, "p202 report ") {
			t.Errorf("%s is outside the report subtree", c.Path)
		}
	}

	out, _, err := executeCommand("commands")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(out, "Global flags: ") || !strings.Contains(out, "\n  breakdown — ") || !strings.Contains(out, "--group-by") {
		t.Errorf("human form:\n%.600s", out)
	}

	quiet, _, err := executeCommand("commands", "--quiet")
	if err != nil || !strings.Contains(quiet, "p202 report breakdown\n") || strings.Contains(quiet, "—") {
		t.Errorf("--quiet = %.200q, %v", quiet, err)
	}

	_, _, err = executeCommand("commands", "reprot")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 search reprot") {
		t.Errorf("unknown subtree: %v / %q", err, hintFor(err))
	}
}

// briefCatalogBudget bounds `p202 commands --brief`: its point is that an
// agent can afford to read all of it (24,961 bytes, about 6,000 tokens, when
// it was written). A catalog that outgrows this has stopped being cheap, and
// the summaries are where to cut.
const briefCatalogBudget = 32000

func TestCommandsBriefIsTheCatalogAnAgentReads(t *testing.T) {
	setTestHome(t, t.TempDir())
	tree := commandsJSON(t)

	text, _, err := executeCommand("commands", "--brief")
	if err != nil {
		t.Fatal(err)
	}
	if len(text) > briefCatalogBudget {
		t.Errorf("the catalog is %d bytes, over its %d-byte budget", len(text), briefCatalogBudget)
	}
	lines := strings.Split(strings.TrimSuffix(text, "\n"), "\n")
	runnable := 0
	for _, c := range tree.Commands {
		if !c.Runnable {
			continue
		}
		runnable++
		n := 0
		for _, l := range lines {
			if strings.HasPrefix(l, c.Path+" — "+c.Short) {
				n++
			}
		}
		if n != 1 {
			t.Errorf("%s is on %d lines of the catalog, want 1", c.Path, n)
		}
	}
	for _, want := range []string{
		"p202 click list --follow", // the Spy page's command line
	} {
		if !strings.Contains(text, "Spy = "+want) {
			t.Errorf("the catalog does not say Spy is `%s`", want)
		}
	}
	elsewhere := 0
	for _, l := range lines {
		if strings.HasPrefix(l, "(no command) ") {
			elsewhere++
		}
	}
	if elsewhere != len(tree.NotInCLI) || len(lines) != runnable+elsewhere {
		t.Errorf("%d lines: %d commands and %d pages with no command expected, %d of those found", len(lines), runnable, len(tree.NotInCLI), elsewhere)
	}
	if !strings.Contains(text, "(no command) Watch TV202 — it plays") {
		t.Error("a page with no command is not listed with where it is done")
	}
	if !strings.Contains(text, "(no command) upgrade — upgrades run from the web UI's upgrade page. `p202 system info` says") {
		t.Error("a page with no command is not listed with the command that helps")
	}

	// An agent gets the text: JSON was chosen for it only because it is an
	// agent, and the catalog is for reading.
	t.Setenv("CLAUDECODE", "1")
	agent, _, err := executeCommand("commands", "--brief")
	if err != nil || agent != text {
		t.Errorf("an agent should get the text catalog (err %v):\n%.300s", err, agent)
	}

	// Asking for JSON gets JSON, from the flag or P202_OUTPUT.
	var doc struct {
		Commands []briefCommand   `json:"commands"`
		NotInCLI []briefElsewhere `json:"not_in_cli"`
	}
	for name, args := range map[string][]string{"--json": {"commands", "--brief", "--json"}, "P202_OUTPUT": {"commands", "--brief"}} {
		if name == "P202_OUTPUT" {
			t.Setenv(outputEnvVar, "json")
		}
		out, _, err := executeCommand(args...)
		if err != nil {
			t.Fatal(err)
		}
		doc.Commands, doc.NotInCLI = nil, nil
		if err := json.Unmarshal([]byte(out), &doc); err != nil {
			t.Fatalf("%s: not JSON: %v\n%.300s", name, err, out)
		}
		if len(doc.Commands) != runnable || len(doc.NotInCLI) != len(tree.NotInCLI) {
			t.Errorf("%s: %d commands and %d pages, want %d and %d", name, len(doc.Commands), len(doc.NotInCLI), runnable, len(tree.NotInCLI))
		}
		var spy, visitors bool
		for _, c := range doc.Commands {
			for _, p := range c.UI {
				spy = spy || c.Command == "p202 click list" && p == briefPage{Page: "Spy", Run: "p202 click list --follow"}
				visitors = visitors || c.Command == "p202 click list" && p == briefPage{Page: "Visitors"}
			}
		}
		if !spy || !visitors {
			t.Errorf("%s: click list's pages are wrong (Spy with its line %v, Visitors bare %v)", name, spy, visitors)
		}
	}
	t.Setenv(outputEnvVar, "")

	nd, _, err := executeCommand("commands", "--brief", "--ndjson")
	if err != nil {
		t.Fatal(err)
	}
	ndLines := strings.Split(strings.TrimSuffix(nd, "\n"), "\n")
	for _, l := range ndLines {
		if !json.Valid([]byte(l)) {
			t.Errorf("--ndjson line is not JSON: %.200s", l)
		}
	}
	if len(ndLines) != runnable+len(tree.NotInCLI) {
		t.Errorf("--ndjson has %d lines, want %d", len(ndLines), runnable+len(tree.NotInCLI))
	}

	sub, _, err := executeCommand("commands", "report", "--brief")
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range strings.Split(strings.TrimSuffix(sub, "\n"), "\n") {
		if !strings.HasPrefix(l, "p202 report ") {
			t.Errorf("the report subtree's catalog has %q", l)
		}
	}

	quiet, _, err := executeCommand("commands", "--brief", "--quiet")
	if err != nil || strings.Contains(quiet, "—") || !strings.Contains(quiet, "p202 click list\n") {
		t.Errorf("--brief --quiet should print paths only: %.200q, %v", quiet, err)
	}
}

// An agent that gets JSON by default gets it on one line from search and
// commands, as from every other command (render.go); they wrote it indented,
// a third more to read for nothing. --json still asks for it indented.
func TestSearchAndCommandsPrintCompactJSONForAnAgent(t *testing.T) {
	setTestHome(t, t.TempDir())
	t.Setenv("CLAUDECODE", "1")
	for _, args := range [][]string{{"search", "spy"}, {"commands", "report"}} {
		out, _, err := executeCommand(args...)
		if err != nil {
			t.Fatal(err)
		}
		if strings.Count(out, "\n") != 1 || !json.Valid([]byte(out)) {
			t.Errorf("%v under an agent: want one line of JSON, got %d lines", args, strings.Count(out, "\n"))
		}
		pretty, _, err := executeCommand(append(args, "--json")...)
		if err != nil || !strings.Contains(pretty, "\n  \"") {
			t.Errorf("%v --json: want indented JSON (%v)", args, err)
		}
	}
}
