package cmd

import (
	"strings"
	"testing"
)

func TestRootHelpPointsAgentsAtSearchAndCommands(t *testing.T) {
	stdout, _, err := executeCommand("--help")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdout, "p202 search <what you want to do>") || !strings.Contains(stdout, "p202 commands --json") {
		t.Errorf("root help does not point at search and commands:\n%s", stdout)
	}
}

func TestUnknownCommandsAndFlagsPointAtSearch(t *testing.T) {
	setTestHome(t, t.TempDir())
	old := activeCommandPath
	defer func() { activeCommandPath = old }()

	for _, args := range [][]string{{"bogus"}, {"analytics", "--bogus"}, {"campaign", "list", "-Z"}} {
		activeCommandPath = ""
		_, _, err := executeCommand(args...)
		if err == nil {
			t.Fatalf("%v was accepted", args)
		}
		recoverCommandContext(args)
		if exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 search <what you want to do>") {
			t.Errorf("%v: exit %d, hint %q", args, exitCodeForError(err), hintFor(err))
		}
	}

	activeCommandPath = ""
	err := unknownSubcommandError(rootCmd, []string{"campaign", "lsit", "--json"})
	if err == nil || !strings.Contains(err.Error(), `unknown command "lsit" for "p202 campaign"; did you mean list?`) {
		t.Fatalf("campaign lsit: %v", err)
	}
	recoverCommandContext([]string{"campaign", "lsit"})
	if hint := hintFor(err); !strings.Contains(hint, "p202 campaign --help") || !strings.Contains(hint, "p202 search") {
		t.Errorf("campaign lsit hint = %q", hint)
	}
	for _, args := range [][]string{{}, {"campaign"}, {"campaign", "list"}, {"campaign", "--help"}, {"report", "--profile", "prod"}, {"report", "--profile=prod"}, {"bogus"}} {
		if err := unknownSubcommandError(rootCmd, args); err != nil {
			t.Errorf("%v: %v", args, err)
		}
	}
}
