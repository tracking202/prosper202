package cmd

import (
	"errors"
	"strings"
	"testing"

	configpkg "p202/internal/config"
)

// An unconfigured CLI fails before any flag is read, so its hint has to name
// the setup commands; it used to say "Run `p202 campaign list --help`",
// which an agent follows to a page that cannot help.
func TestUnconfiguredCLINamesTheSetupCommands(t *testing.T) {
	old := activeCommandPath
	defer func() { activeCommandPath = old }()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	for _, args := range [][]string{
		{"campaign", "list"},
		{"system", "health"},
		{"conversion", "postback-url"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil {
			t.Fatalf("%v: expected an error with nothing configured", args)
		}
		if !errors.Is(err, configpkg.ErrNoURL) {
			t.Errorf("%v: error %q does not wrap ErrNoURL", args, err)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code = %d, want %d", args, code, ExitValidation)
		}
		activeCommandPath = "p202 " + strings.Join(args[:2], " ")
		hint := hintFor(err)
		for _, want := range []string{"`p202 config set-url", "`p202 config set-key", "`p202 config test`"} {
			if !strings.Contains(hint, want) {
				t.Errorf("%v: hint %q does not name %s", args, hint, want)
			}
		}
	}

	writeTestConfig(t, tmp, "https://tracker.example.com", "")
	_, _, err := executeCommand("campaign", "list")
	if !errors.Is(err, configpkg.ErrNoAPIKey) {
		t.Fatalf("with a URL and no key: error %v does not wrap ErrNoAPIKey", err)
	}
	activeCommandPath = "p202 campaign list"
	hint := hintFor(err)
	if !strings.Contains(hint, "`p202 config set-key") || strings.Contains(hint, "set-url") {
		t.Errorf("with the URL set the hint should name set-key only, got %q", hint)
	}
}

// A create names every required flag it is missing, and where each id comes
// from, in one answer: before, it named the first and an agent needed one
// failed run per flag.
func TestCreateNamesEveryMissingRequiredFlag(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "test-key")

	_, _, err := executeCommand("campaign", "create", "--aff-campaign-name=x")
	if err == nil {
		t.Fatal("expected a missing-flags error")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	want := "required flags --aff-campaign-url, --aff-campaign-payout, --aff-network-id are missing"
	if !strings.Contains(err.Error(), want) {
		t.Errorf("error = %q, want it to contain %q", err.Error(), want)
	}
	hint := hintFor(err)
	for _, part := range []string{"--aff-network-id takes an id from `p202 aff-network list`", "`p202 campaign create --help`"} {
		if !strings.Contains(hint, part) {
			t.Errorf("hint %q does not contain %q", hint, part)
		}
	}
}
