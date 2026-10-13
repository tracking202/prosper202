package cmd

import (
	"regexp"
	"strings"
	"testing"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

// typePlaceholder is the value name pflag shows for a flag with no
// backticked placeholder.
func typePlaceholder(f *pflag.Flag) string {
	plain := *f
	plain.Usage = ""
	name, _ := pflag.UnquoteUsage(&plain)
	return name
}

// Every flag's --help line names its value by type, or by a placeholder
// listed in flagValuePlaceholders — never by a command quoted in its prose.
func TestHelpNeverShowsQuotedProseAsAFlagValue(t *testing.T) {
	var walk func(c *cobra.Command)
	checked := 0
	walk = func(c *cobra.Command) {
		for _, fs := range []*pflag.FlagSet{c.LocalFlags(), c.InheritedFlags()} {
			fs.VisitAll(func(f *pflag.Flag) {
				shown := *f
				shown.Usage = displayFlagUsage(f)
				name, _ := pflag.UnquoteUsage(&shown)
				if name != typePlaceholder(f) && !flagValuePlaceholders[name] {
					t.Errorf("%s --%s: help shows value %q (from %q)", c.CommandPath(), f.Name, name, f.Usage)
				}
				if strings.Contains(f.Usage, "`") {
					checked++
				}
			})
		}
		for _, sub := range c.Commands() {
			walk(sub)
		}
	}
	walk(rootCmd)
	if checked == 0 {
		t.Fatal("no flag usage carries a backtick; the check above checked nothing")
	}
}

// The rendered help, not only the helper: the global --staged flag quotes
// `p202 change`, and pflag showed that as the flag's value on every command.
func TestRenderedHelpShowsStagedAsABareSwitch(t *testing.T) {
	cmd, _, err := rootCmd.Find([]string{"campaign", "update"})
	if err != nil {
		t.Fatal(err)
	}
	usage := cmd.UsageString()
	if !regexp.MustCompile(`--staged\s{2,}Stage writes`).MatchString(usage) {
		t.Errorf("--staged line should take no value:\n%s", grepLines(usage, "--staged"))
	}
	if !strings.Contains(usage, "see 'p202 change'") {
		t.Errorf("--staged help should still name the command:\n%s", grepLines(usage, "--staged"))
	}
	if !regexp.MustCompile(`--app-registration-id string\s`).MatchString(usage) {
		t.Errorf("--app-registration-id should take a string:\n%s", grepLines(usage, "--app-registration-id"))
	}

	replace, _, err := rootCmd.Find([]string{"campaign", "replace-url"})
	if err != nil {
		t.Fatal(err)
	}
	if !regexp.MustCompile(`--undo file\s`).MatchString(replace.UsageString()) {
		t.Errorf("--undo keeps its deliberate placeholder:\n%s", grepLines(replace.UsageString(), "--undo"))
	}
}

func grepLines(text, needle string) string {
	var out []string
	for _, line := range strings.Split(text, "\n") {
		if strings.Contains(line, needle) {
			out = append(out, line)
		}
	}
	return strings.Join(out, "\n")
}
