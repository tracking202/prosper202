package cmd

import (
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// Pins the invariant that session-level persistent flags survive the
// per-command flag reset in the shell: --json/--csv/--profile/--group are
// bound with *Var, so the pflag Value reads through the package variable and
// restoring the variable restores cmd.Flags().GetString(...) lookups too
// (e.g. multi_report's group resolution).
func TestSessionGroupSurvivesFlagReset(t *testing.T) {
	groupName = "prodgroup"
	saved := groupName
	resetAllFlags(rootCmd)
	groupName = saved
	got, err := rootCmd.PersistentFlags().GetString("group")
	if err != nil {
		t.Fatal(err)
	}
	if got != "prodgroup" {
		t.Fatalf("GetString(group) = %q after var restore, want prodgroup", got)
	}
	groupName = ""
	resetAllFlags(rootCmd)
}

// Every repeatable flag in the tree starts each command empty. pflag's Set
// appends to a slice flag once it has been parsed, and its first Set stores
// the default's text "[]" as a value, so resetting through Set left the
// shell's next command reading values nobody gave it (--cf "[]", then the
// previous command's --cf as well). Walks the real tree so a repeatable flag
// added later is covered.
func TestResetLeavesEveryRepeatableFlagEmpty(t *testing.T) {
	defer resetAllFlags(rootCmd)
	checked := 0
	var walk func(c *cobra.Command)
	walk = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			walk(sub)
		}
		c.LocalFlags().VisitAll(func(f *pflag.Flag) {
			sv, ok := f.Value.(pflag.SliceValue)
			if !ok {
				return
			}
			checked++
			for _, round := range []string{"first", "second"} {
				if err := c.Flags().Set(f.Name, "x-"+round); err != nil {
					t.Fatalf("%s --%s: %v", c.CommandPath(), f.Name, err)
				}
				resetAllFlags(rootCmd)
				if got := sv.GetSlice(); len(got) != 0 || f.Changed {
					t.Errorf("%s --%s after the %s reset = %q (changed %v), want empty", c.CommandPath(), f.Name, round, got, f.Changed)
				}
			}
			if err := c.Flags().Set(f.Name, "only"); err != nil {
				t.Fatal(err)
			}
			if got := sv.GetSlice(); len(got) != 1 || got[0] != "only" {
				t.Errorf("%s --%s given once after resets = %q, want [only]", c.CommandPath(), f.Name, got)
			}
			resetAllFlags(rootCmd)
		})
	}
	walk(rootCmd)
	if checked < 5 {
		t.Fatalf("checked %d repeatable flags; the walk did not reach the ltv commands", checked)
	}
	t.Logf("%d repeatable flags reset clean", checked)
}
