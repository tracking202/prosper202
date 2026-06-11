package cmd

import "testing"

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
