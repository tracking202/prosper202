package cmd

import (
	"strings"
	"testing"

	"github.com/spf13/cobra"
)

// Every delete command must carry the same safety flags. The set was
// hand-rolled at five of six sites, and a delete that quietly loses --dry-run
// does not fail: cobra rejects the unknown flag only at parse time, while
// bulkOrSingleDelete reads it with GetBool and cannot tell "absent" from
// "false". This walks the real command tree so a new delete cannot skip it.
func TestEveryDeleteCommandHasTheSafetyFlags(t *testing.T) {
	var walk func(c *cobra.Command)
	checked := 0

	walk = func(c *cobra.Command) {
		for _, sub := range c.Commands() {
			walk(sub)
		}
		name := c.Name()
		if name != "delete" && name != "remove" && !strings.HasSuffix(name, "-delete") {
			return
		}
		// rotate deletes the old key but is not itself a delete command.
		if strings.Contains(c.CommandPath(), "rotate") {
			return
		}
		checked++
		for _, flag := range []string{"force", "dry-run"} {
			if c.Flags().Lookup(flag) == nil {
				t.Errorf("%s is missing --%s", c.CommandPath(), flag)
			}
		}
	}
	walk(rootCmd)

	if checked == 0 {
		t.Fatal("walked the tree and found no delete commands - the matcher is wrong, not the tree")
	}
	t.Logf("checked %d delete commands", checked)
}
