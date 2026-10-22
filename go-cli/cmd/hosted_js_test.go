//go:build js

package cmd

import (
	"bytes"
	"encoding/json"
	"testing"
)

func resetHostedOutput() {
	mcpToolRun = false
	jsonOutput, ndjsonOutput, csvOutput, quietOutput, tableOutput = false, false, false, false, false
	compactJSON, outputImplicit, outputSource = false, false, sourceDefault
}

func TestHostedRunTakesToolCallsOnly(t *testing.T) {
	t.Cleanup(resetHostedOutput)
	for _, args := range [][]string{
		{"mcp", "call", "campaign_list"},
		{"--staged", "mcp", "call", "campaign_delete", "--input", `{"id":"1"}`},
		{"mcp", "tools", "--read-only"},
		{"search", "--json", "--limit", "8", "--", "which", "hour", "earns", "most"},
		{"search", "--", "-h", "--profile", "x"},
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
		// search is the one offline command it takes, in the relay's shape only.
		{"search", "which", "hour"},
		{"search", "-h"},
		{"search", "--help", "--", "x"},
		{"search", "--quiet", "--", "x"},
		{"search", "--limit", "abc", "--", "x"},
		{"search", "--profile", "p", "--", "x"},
		{"search"},
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

// The relay parses the hosted engine's search answer, and its errors: compact
// JSON whatever the environment says, including the errors cobra raises before
// root's PersistentPreRunE (here Args: a task with no words to search by).
func TestHostedSearchAnswersCompactJSON(t *testing.T) {
	t.Setenv("P202_OUTPUT", "table")
	t.Cleanup(resetHostedOutput)
	if err := hostedArgsRefusal([]string{"search", "--json", "--limit", "8", "--", "the"}); err != nil {
		t.Fatalf("relay-shaped search refused: %v", err)
	}
	if !jsonOutput || !compactJSON || tableOutput || csvOutput || quietOutput || ndjsonOutput {
		t.Fatalf("hosted search output: json=%v compact=%v table=%v csv=%v quiet=%v, want compact JSON", jsonOutput, compactJSON, tableOutput, csvOutput, quietOutput)
	}
	argsErr := searchCmd.Args(searchCmd, []string{"the"})
	if argsErr == nil {
		t.Fatal("search took a task with no words to search by")
	}
	var buf bytes.Buffer
	printError(&buf, argsErr)
	var envelope struct {
		Error struct {
			Category string `json:"category"`
			Message  string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(buf.Bytes(), &envelope); err != nil || envelope.Error.Message == "" {
		t.Fatalf("the error is not the JSON envelope: %q (%v)", buf.String(), err)
	}
}
