package cmd

import (
	"regexp"
	"strings"
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// truncatedList is help text that trails off instead of listing values:
// "(campaign, country, lp, etc.)", "device, ...", "bad_token, …".
var truncatedList = regexp.MustCompile(`(?i)\betc\b|,\s*(\.\.\.|…)`)

// walkCommands calls fn for root and every command under it.
func walkCommands(c *cobra.Command, fn func(*cobra.Command)) {
	fn(c)
	for _, sub := range c.Commands() {
		walkCommands(sub, fn)
	}
}

func TestNoFlagHelpTrailsOffInsteadOfListingValues(t *testing.T) {
	checked := 0
	walkCommands(rootCmd, func(c *cobra.Command) {
		c.LocalFlags().VisitAll(func(f *pflag.Flag) {
			checked++
			if truncatedList.MatchString(f.Usage) {
				t.Errorf("%s --%s help trails off instead of listing its values: %q", c.CommandPath(), f.Name, f.Usage)
			}
		})
	})
	if checked < 500 {
		t.Fatalf("walked only %d flags; the command tree was not walked", checked)
	}
	if !truncatedList.MatchString("Breakdown dimension (campaign, country, lp, etc.)") || !truncatedList.MatchString("in this state (attributed, organic, …)") {
		t.Fatal("the truncation pattern no longer recognises the phrasing it exists to catch")
	}
}

type enumFlagCase struct {
	cmd  *cobra.Command
	flag *pflag.Flag
	spec *enumSpec
}

func registeredEnumFlags(t *testing.T) []enumFlagCase {
	t.Helper()
	var cases []enumFlagCase
	walkCommands(rootCmd, func(c *cobra.Command) {
		c.LocalFlags().VisitAll(func(f *pflag.Flag) {
			if spec := enumSpecs[f]; spec != nil {
				cases = append(cases, enumFlagCase{c, f, spec})
			}
		})
	})
	// Every registered spec must be reachable from the tree: a flag
	// registered on a command that was never added would escape this test.
	if len(cases) != len(enumSpecs) {
		t.Fatalf("walked %d enum flags, %d registered", len(cases), len(enumSpecs))
	}
	if len(cases) < 80 {
		t.Fatalf("only %d enum flags registered; expected the CLI's fixed-set flags to be", len(cases))
	}
	return cases
}

func TestEveryEnumFlagHelpListsEveryValue(t *testing.T) {
	for _, tc := range registeredEnumFlags(t) {
		for _, v := range tc.spec.values {
			if !strings.Contains(tc.flag.Usage, v) {
				t.Errorf("%s --%s help %q does not list %q", tc.cmd.CommandPath(), tc.flag.Name, tc.flag.Usage, v)
			}
		}
		for alias, target := range tc.spec.aliases {
			if !strings.Contains(tc.flag.Usage, alias+"="+target) {
				t.Errorf("%s --%s help %q does not list alias %s=%s", tc.cmd.CommandPath(), tc.flag.Name, tc.flag.Usage, alias, target)
			}
		}
	}
}

// Every enum flag, run through the real command with a value outside its
// list and no configuration at all, must fail as a validation error (exit
// 1) whose message names every accepted value: so the check runs before
// any client is built, and the list is in the message, not only in --help.
func TestEveryEnumFlagRefusesAnInvalidValueWithTheWholeList(t *testing.T) {
	setTestHome(t, t.TempDir())
	const bad = "zz-not-a-value"
	for _, tc := range registeredEnumFlags(t) {
		path := strings.Fields(tc.cmd.CommandPath())[1:]
		var err error
		// Cobra checks the argument count before any flag is looked at, so
		// grow dummy positional arguments until the command accepts them.
		for n := 0; n <= 3; n++ {
			args := append([]string{}, path...)
			for i := 0; i < n; i++ {
				args = append(args, "1")
			}
			args = append(args, "--"+tc.flag.Name, bad)
			_, _, err = executeCommand(args...)
			if err != nil && strings.Contains(err.Error(), bad) {
				break
			}
		}
		where := tc.cmd.CommandPath() + " --" + tc.flag.Name
		if err == nil || !strings.Contains(err.Error(), bad) {
			t.Errorf("%s %s: want the enum error, got %v", where, bad, err)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%s: exit code %d, want %d (%v)", where, code, ExitValidation, err)
		}
		for _, v := range tc.spec.values {
			if !strings.Contains(err.Error(), v) {
				t.Errorf("%s: message %q does not list %q", where, err.Error(), v)
			}
		}
		for alias := range tc.spec.aliases {
			if !strings.Contains(err.Error(), alias+"=") {
				t.Errorf("%s: message %q does not list alias %q", where, err.Error(), alias)
			}
		}
		if hintFor(err) == "" {
			t.Errorf("%s: no hint", where)
		}
	}
}

func TestEnumAcceptsAliasesAndFoldsCaseOnlyWhenAsked(t *testing.T) {
	folded := newEnum([]string{"ASC", "DESC"}, enumFoldCase())
	if v, ok := folded.resolve(" asc "); !ok || v != "ASC" {
		t.Errorf("folded resolve(asc) = %q, %v", v, ok)
	}
	exact := newEnum([]string{"organic"})
	if _, ok := exact.resolve("Organic"); ok {
		t.Error("a case-sensitive list accepted a different case")
	}
	dims := dimensionEnum(breakdownDimensions)
	if v, ok := dims.resolve("LP"); !ok || v != "landing_page" {
		t.Errorf("dimension alias LP = %q, %v", v, ok)
	}
	list := newEnum([]string{"high", "low"}, enumList())
	if err := list.check("priority", "high, low"); err != nil {
		t.Errorf("a valid list was refused: %v", err)
	}
	if err := list.check("priority", "high,urgent"); err == nil || !strings.Contains(err.Error(), `got "urgent"`) {
		t.Errorf("list error = %v, want it to name the bad item", err)
	}
}

// The motivating case: an agent asked for a breakdown the server lacks (it was
// referer, before the server had one), was told only
// "unsupported" and pointed at --help, which did not list the values either.
func TestAnalyticsGroupByUnknownDimensionNamesEveryDimensionAndSearch(t *testing.T) {
	setTestHome(t, t.TempDir())
	_, _, err := executeCommand("analytics", "--group-by", "language", "--json")
	if err == nil {
		t.Fatal("language was accepted")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code %d, want %d", code, ExitValidation)
	}
	for _, d := range breakdownDimensions {
		if !strings.Contains(err.Error(), d) {
			t.Errorf("message %q does not list %q", err.Error(), d)
		}
	}
	if !strings.Contains(hintFor(err), "p202 search") {
		t.Errorf("hint = %q, want a pointer to p202 search", hintFor(err))
	}
}

// Without --group-by, analytics names the dimensions, and does so with no
// configuration: the check comes before the client is built.
func TestAnalyticsGroupByRequiredListsDimensionsWithoutAConfig(t *testing.T) {
	setTestHome(t, t.TempDir())
	_, _, err := executeCommand("analytics")
	if err == nil || exitCodeForError(err) != ExitValidation {
		t.Fatalf("analytics without --group-by: %v", err)
	}
	if !strings.Contains(err.Error(), "--group-by is required; one of: "+strings.Join(breakdownDimensions, ", ")) {
		t.Errorf("message %q does not list the dimensions", err.Error())
	}
}
