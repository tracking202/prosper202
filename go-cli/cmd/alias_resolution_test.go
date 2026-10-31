package cmd

import (
	"testing"

	"github.com/spf13/cobra"
)

// Every alias a command declares reaches that command, and no two siblings
// answer to the same word. forecast-event declared the alias "event" while a
// sibling command was named "event"; Cobra resolved `p202 event` to the
// sibling, so `p202 event list` failed as an unknown command while
// `p202 --help` still advertised "event (forecast-event)".
func TestEveryAliasResolvesToItsOwnCommand(t *testing.T) {
	checked := 0
	var walk func(parent *cobra.Command, path []string)
	walk = func(parent *cobra.Command, path []string) {
		owner := map[string]string{}
		for _, c := range parent.Commands() {
			for _, word := range append([]string{c.Name()}, c.Aliases...) {
				if prev, ok := owner[word]; ok && prev != c.Name() {
					t.Errorf("%q under %v answers for both %s and %s", word, path, prev, c.Name())
				}
				owner[word] = c.Name()
			}
		}
		for _, c := range parent.Commands() {
			for _, alias := range c.Aliases {
				found, _, err := rootCmd.Find(append(append([]string{}, path...), alias))
				if err != nil || found != c {
					got := "<error>"
					if found != nil {
						got = found.CommandPath()
					}
					t.Errorf("alias %q of %s resolves to %s (%v)", alias, c.CommandPath(), got, err)
				}
				checked++
			}
			walk(c, append(append([]string{}, path...), c.Name()))
		}
	}
	walk(rootCmd, nil)
	if checked < 8 {
		t.Fatalf("checked %d aliases; the walk found too few to mean anything", checked)
	}
}
