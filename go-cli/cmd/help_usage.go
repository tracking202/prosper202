package cmd

import (
	"strings"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// flagValuePlaceholders are the backticked words a flag's usage names its
// value with on purpose — pflag's convention, rendered `--undo file`.
var flagValuePlaceholders = map[string]bool{"file": true}

// displayFlagUsage is a flag's help text as --help shows it. pflag reads the
// first backticked span of a usage as the name of the flag's value, but this
// CLI backticks commands in prose ("see `p202 change`"), so --help printed
// `--staged p202 change` and `--aff-campaign-id campaign list` as though the
// flag took that text. Every span except a deliberate placeholder is shown
// in single quotes instead, which leaves pflag to name the value by its type.
// The usage itself is untouched: `p202 commands --json` and search read it.
func displayFlagUsage(f *pflag.Flag) string {
	if !strings.Contains(f.Usage, "`") {
		return f.Usage
	}
	if name, _ := pflag.UnquoteUsage(f); flagValuePlaceholders[name] {
		return f.Usage
	}
	return strings.ReplaceAll(f.Usage, "`", "'")
}

// displayFlagUsages renders a flag set the way FlagUsages does, with each
// usage passed through displayFlagUsage.
func displayFlagUsages(fs *pflag.FlagSet) string {
	display := pflag.NewFlagSet("", pflag.ContinueOnError)
	display.SortFlags = fs.SortFlags
	fs.VisitAll(func(f *pflag.Flag) {
		shown := *f
		shown.Usage = displayFlagUsage(f)
		display.AddFlag(&shown)
	})
	return display.FlagUsages()
}

func init() {
	cobra.AddTemplateFunc("displayFlagUsages", displayFlagUsages)
	tmpl := rootCmd.UsageTemplate()
	for _, set := range []string{"LocalFlags", "InheritedFlags"} {
		tmpl = strings.Replace(tmpl, "{{."+set+".FlagUsages", "{{displayFlagUsages ."+set, 1)
	}
	rootCmd.SetUsageTemplate(tmpl)
}
