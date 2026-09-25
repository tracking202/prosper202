package cmd

import (
	"strings"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// An explicitly empty string flag (`--click_id ""`, `--source "$UNSET"`) is
// refused by name, for every command, before the command runs.
//
// Nearly every command reads its optional string flags as `if v != ""`,
// which cannot tell "not given" from "given empty": the empty value is
// dropped in silence. A list filter then widens to everything, an update
// leaves the field as it was, and a scoped request goes unscoped — the
// caller asked for something and got something else, with exit code 0
// (CLAUDE.md #4 and #12: validation is only as good as the layer that
// delivers the input). Checking here, from the parsed flag set, covers
// every command and every command added later, instead of one call site
// at a time.
//
// Two annotations opt a flag out or refine it:
//   - flagAllowsEmpty: an empty value is a deliberate write the command
//     sends as-is (clearing an app's notes on update);
//   - flagEmptyHint: the recovery step to print instead of the generic one.
//
// Root's persistent flags (--fields, --profile, --group) are not checked:
// empty is their documented "use the default".
const (
	flagAllowsEmpty = "p202_allows_empty"
	flagEmptyHint   = "p202_empty_hint"
)

// allowEmpty marks flags whose empty value the command deliberately sends.
func allowEmpty(cmd *cobra.Command, names ...string) {
	for _, name := range names {
		if err := cmd.Flags().SetAnnotation(name, flagAllowsEmpty, []string{"true"}); err != nil {
			panic("allowEmpty: " + cmd.CommandPath() + " has no flag --" + name)
		}
	}
}

// emptyHint sets the hint printed when the flag is given an empty value.
func emptyHint(cmd *cobra.Command, name, hint string) {
	if err := cmd.Flags().SetAnnotation(name, flagEmptyHint, []string{hint}); err != nil {
		panic("emptyHint: " + cmd.CommandPath() + " has no flag --" + name)
	}
}

// refuseEmptyStringFlags returns a validation error for the first string
// flag (in name order) the caller set to an empty or blank value.
func refuseEmptyStringFlags(cmd *cobra.Command) error {
	var bad *pflag.Flag
	cmd.Flags().Visit(func(f *pflag.Flag) {
		// Visit walks every flag ever Set on the set, and resetAllFlags
		// (the shell between commands) Sets each back to its default and
		// clears Changed: Changed, not Visit, is "the caller gave it".
		if bad != nil || !f.Changed || f.Value.Type() != "string" {
			return
		}
		if cmd.Root().PersistentFlags().Lookup(f.Name) == f {
			return
		}
		if _, ok := f.Annotations[flagAllowsEmpty]; ok {
			return
		}
		if strings.TrimSpace(f.Value.String()) == "" {
			bad = f
		}
	})
	if bad == nil {
		return nil
	}
	hint := "Omit --" + bad.Name + " to leave it unset, or give it a value (`" + cmd.CommandPath() + " --help` says what it takes)."
	if h, ok := bad.Annotations[flagEmptyHint]; ok && len(h) > 0 && h[0] != "" {
		hint = h[0]
	}
	return validationError("--%s was given an empty value", bad.Name).WithHint("%s", hint)
}
