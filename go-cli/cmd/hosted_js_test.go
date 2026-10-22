//go:build js

package cmd

import "testing"

// The relay parses the hosted engine's search answer: it is compact JSON
// whatever the environment says, with or without --json.
func TestHostedSearchAnswersCompactJSON(t *testing.T) {
	t.Setenv("P202_OUTPUT", "table")
	t.Cleanup(func() {
		mcpToolRun = false
		jsonOutput, compactJSON, tableOutput, csvOutput = false, false, false, false
	})
	if err := rootCmd.PersistentPreRunE(searchCmd, []string{"hour"}); err != nil {
		t.Fatalf("search refused before running: %v", err)
	}
	if !jsonOutput || !compactJSON || tableOutput || csvOutput || quietOutput {
		t.Fatalf("hosted search output: json=%v compact=%v table=%v csv=%v quiet=%v, want compact JSON", jsonOutput, compactJSON, tableOutput, csvOutput, quietOutput)
	}
}

func TestHostedRunTakesToolCallsOnly(t *testing.T) {
	for _, args := range [][]string{
		{"mcp", "call", "campaign_list"},
		{"--staged", "mcp", "call", "campaign_delete", "--input", `{"id":"1"}`},
		{"mcp", "tools", "--read-only"},
		{"search", "which", "hour", "earns", "most", "--json", "--limit", "8"},
		{"--version"},
	} {
		if err := hostedArgsRefusal(args); err != nil {
			t.Errorf("%q refused: %v", args, err)
		}
	}
	for _, args := range [][]string{
		{"campaign", "list"},
		{"campaign", "delete", "1", "--force"},
		{"mcp"},
		{"shell", "-c", "campaign list"},
		{"exec", "--all-profiles", "--", "campaign", "list"},
		{"config", "set-key", "x"},
		// search is the one offline command it takes; its neighbours are not.
		{"commands", "--brief"},
		{"help", "search"},
		{"completion", "bash"},
		{"__complete", "search", ""},
		{},
	} {
		if err := hostedArgsRefusal(args); err == nil {
			t.Errorf("%q was not refused", args)
		}
	}
}
