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
		t.Error("a hidden flag (conversion list --campaign_id) is listed")
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
