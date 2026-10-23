//go:build js

package cmd

import (
	"bytes"
	"encoding/json"
	"testing"

	"p202/internal/api"
	configpkg "p202/internal/config"
)

func resetHostedOutput() {
	mcpToolRun = false
	jsonOutput, ndjsonOutput, csvOutput, quietOutput, tableOutput = false, false, false, false, false
	compactJSON, outputImplicit, outputSource = false, false, sourceDefault
	activeCommandPath = ""
	configpkg.ResetActiveOverride()
	api.SetStagedMode(false)
	rootCmd.SetArgs(nil)
}

// The whole entry, as the relay's engine runs it: an error cobra raises before
// root's PersistentPreRunE (Args: a task with no words to search by) reaches
// stderr as the JSON envelope, whatever P202_OUTPUT says. Without --json, so
// the pin is what makes it JSON (with it, the error path reads the flag).
func TestHostedSearchErrorsReachTheRelayAsJSON(t *testing.T) {
	t.Setenv("P202_OUTPUT", "table")
	t.Cleanup(resetHostedOutput)
	var stderr bytes.Buffer
	if code := run([]string{"search", "--limit", "8", "--", "the"}, &stderr); code == 0 {
		t.Fatalf("a search with no words answered exit 0: %q", stderr.String())
	}
	var envelope struct {
		Error struct {
			Category string `json:"category"`
			Message  string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(stderr.Bytes(), &envelope); err != nil || envelope.Error.Category != "validation" {
		t.Fatalf("stderr is not the JSON envelope: %q (%v)", stderr.String(), err)
	}
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
// root's PersistentPreRunE (here Args: a task with no words to search by), and
// still after PersistentPreRunE resolves the output again.
func TestHostedSearchAnswersCompactJSON(t *testing.T) {
	t.Setenv("P202_OUTPUT", "table")
	t.Cleanup(resetHostedOutput)
	args := []string{"search", "--json", "--limit", "8", "--", "the"}
	if err := hostedArgsRefusal(args); err != nil {
		t.Fatalf("relay-shaped search refused: %v", err)
	}
	if mcpToolRun || jsonOutput {
		t.Fatal("hostedArgsRefusal changed the output; it only answers whether args are taken")
	}
	if err := hostedPinOutput(args); err != nil {
		t.Fatalf("pinning the output: %v", err)
	}
	compact := func(when string) {
		t.Helper()
		if !jsonOutput || !compactJSON || tableOutput || csvOutput || quietOutput || ndjsonOutput {
			t.Fatalf("%s: json=%v compact=%v table=%v csv=%v quiet=%v, want compact JSON", when, jsonOutput, compactJSON, tableOutput, csvOutput, quietOutput)
		}
	}
	compact("before cobra")
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
	if err := rootCmd.PersistentPreRunE(searchCmd, []string{"hour"}); err != nil {
		t.Fatalf("root's PersistentPreRunE: %v", err)
	}
	compact("after root's PersistentPreRunE")
}
