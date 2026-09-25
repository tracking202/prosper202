package cmd

import (
	"strings"
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// An explicitly empty filter is refused by name, with the command that
// finds a right value — never read as "not given", which lists everything.
// Checked before the client is built (no configuration needed).
func TestAnEmptyConversionListFilterIsRefusedByName(t *testing.T) {
	t.Setenv("HOME", t.TempDir())
	for flag, hint := range map[string]string{
		"click_id":        "p202 click list",
		"source":          "universal_pixel",
		"goal":            "p202 goal list",
		"aff_campaign_id": "p202 campaign list",
	} {
		t.Run(flag, func(t *testing.T) {
			_, _, err := executeCommand("conversion", "list", "--"+flag, "")
			if err == nil {
				t.Fatalf("conversion list --%s \"\" was accepted", flag)
			}
			// Named as the flag set knows it (the CLI normalizes a few
			// spellings, e.g. aff_campaign_id).
			name := conversionListCmd.Flags().Lookup(flag).Name
			if !strings.Contains(err.Error(), "--"+name+" was given an empty value") {
				t.Errorf("message = %q, want it to name --%s", err.Error(), name)
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("exit code = %d, want %d (validation)", code, ExitValidation)
			}
			if h := hintFor(err); !strings.Contains(h, hint) {
				t.Errorf("hint = %q, want it to name %q", h, hint)
			}
		})
	}
	// Blank is empty too: `--source "$SOURCE"` with SOURCE=" ".
	if _, _, err := executeCommand("conversion", "list", "--source", "  "); err == nil || exitCodeForError(err) != ExitValidation {
		t.Errorf("a blank --source was accepted: %v", err)
	}
}

// The machine check behind the rule: every string flag of every command
// refuses an explicit empty value, unless the command declares that empty
// is a deliberate write (allowEmpty). A command added later is covered
// without anyone remembering to add it here.
func TestEveryStringFlagRefusesAnExplicitEmptyValue(t *testing.T) {
	resetAllFlags(rootCmd)
	defer resetAllFlags(rootCmd)
	checked, allowed := 0, 0
	var walk func(c *cobra.Command)
	walk = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			walk(sub)
		}
		if c == rootCmd {
			return
		}
		c.LocalFlags().VisitAll(func(f *pflag.Flag) {
			if f.Value.Type() != "string" {
				return
			}
			if _, ok := f.Annotations[flagAllowsEmpty]; ok {
				allowed++
				return
			}
			old, oldChanged := f.Value.String(), f.Changed
			if err := c.Flags().Set(f.Name, ""); err != nil {
				t.Fatalf("%s --%s: %v", c.CommandPath(), f.Name, err)
			}
			err := refuseEmptyStringFlags(c)
			_ = f.Value.Set(old)
			f.Changed = oldChanged
			checked++
			if err == nil {
				t.Errorf("%s --%s \"\" is not refused", c.CommandPath(), f.Name)
				return
			}
			if !strings.Contains(err.Error(), "--"+f.Name+" ") || exitCodeForError(err) != ExitValidation || hintFor(err) == "" {
				t.Errorf("%s --%s: %q (exit %d, hint %q)", c.CommandPath(), f.Name, err.Error(), exitCodeForError(err), hintFor(err))
			}
		})
	}
	walk(rootCmd)
	if checked < 100 {
		t.Fatalf("only %d string flags checked; the walk did not reach the command tree", checked)
	}
	if allowed == 0 {
		t.Fatalf("no flag allows an empty value; app update's deliberate clears were lost")
	}
	t.Logf("%d string flags refuse an empty value; %d allow it on purpose", checked, allowed)
}

// A flag whose empty value is a deliberate write still sends it.
func TestAnAllowedEmptyValueIsNotRefused(t *testing.T) {
	resetAllFlags(rootCmd)
	defer resetAllFlags(rootCmd)
	if err := appUpdateCmd.Flags().Set("notes", ""); err != nil {
		t.Fatal(err)
	}
	defer func() { appUpdateCmd.Flags().Lookup("notes").Changed = false }()
	if err := refuseEmptyStringFlags(appUpdateCmd); err != nil {
		t.Fatalf("app update --notes \"\" (clearing the notes) was refused: %v", err)
	}
	// Root's own flags are their documented "use the default" when empty.
	if err := rootCmd.PersistentFlags().Set("fields", ""); err != nil {
		t.Fatal(err)
	}
	defer func() { rootCmd.PersistentFlags().Lookup("fields").Changed = false }()
	if err := refuseEmptyStringFlags(conversionListCmd); err != nil {
		t.Fatalf("--fields \"\" was refused: %v", err)
	}
}
