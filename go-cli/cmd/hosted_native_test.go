//go:build !js

package cmd

import "testing"

// Outside the hosted engine, `p202 search` keeps its own output choice.
func TestNativeSearchChoosesItsOutput(t *testing.T) {
	t.Cleanup(func() {
		mcpToolRun = false
		jsonOutput, ndjsonOutput, csvOutput, quietOutput, tableOutput = false, false, false, false, false
		compactJSON, outputImplicit, outputSource = false, false, sourceDefault
		activeCommandPath = ""
	})
	if err := rootCmd.PersistentPreRunE(searchCmd, []string{"hour"}); err != nil {
		t.Fatalf("search refused: %v", err)
	}
	if mcpToolRun || compactJSON {
		t.Fatalf("native search was forced to compact JSON")
	}
}
