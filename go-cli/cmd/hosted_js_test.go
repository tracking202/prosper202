//go:build js

package cmd

import "testing"

func TestHostedRunTakesToolCallsOnly(t *testing.T) {
	for _, args := range [][]string{
		{"mcp", "call", "campaign_list"},
		{"--staged", "mcp", "call", "campaign_delete", "--input", `{"id":"1"}`},
		{"mcp", "tools", "--read-only"},
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
		{},
	} {
		if err := hostedArgsRefusal(args); err == nil {
			t.Errorf("%q was not refused", args)
		}
	}
}
