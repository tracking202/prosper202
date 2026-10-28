//go:build js

package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
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

// The engine itself is the hosted run: without a test setting it, each list
// that reads every page with all lists no all and refuses it, as a
// validation error that says how to page; change_list keeps its all.
func TestHostedEngineOmitsEveryPage(t *testing.T) {
	if !mcpHostedRun {
		t.Fatal("the js/wasm build does not run as the hosted engine")
	}
	cat, err := buildMCPCatalog(rootCmd, true, false)
	if err != nil {
		t.Fatal(err)
	}
	listed := map[string]mcpTool{}
	for _, tool := range cat.Tools {
		listed[tool.Name] = tool
	}
	if _, ok := listed["change_list"].InputSchema.Properties["all"]; !ok {
		t.Error("the hosted engine lists change_list without all (every user's changes)")
	}
	for _, name := range mcpHostedOmitAll {
		tool, ok := listed[name]
		if !ok {
			t.Errorf("the hosted engine does not list %s", name)
			continue
		}
		if _, ok := tool.InputSchema.Properties["all"]; ok {
			t.Errorf("the hosted engine lists %s with all", name)
		}
		if _, ok := tool.InputSchema.Properties["limit"]; !ok {
			t.Errorf("the hosted engine lists %s without limit", name)
		}
		spec, _ := findMCPSpec(name)
		argv, err := mcpToolArgv(rootCmd, spec, []byte(`{"all":true}`))
		if err == nil {
			t.Errorf("the hosted engine ran %s with all: true as %q", name, argv)
			continue
		}
		if exitCodeForError(err) != 1 || !strings.Contains(err.Error(), name+" does not take all on the hosted server") || !strings.Contains(hintFor(err), "limit and offset") {
			t.Errorf("the hosted engine's %s all: true: %v (hint %q)", name, err, hintFor(err))
		}
	}
}

// fetchFunc answers the engine's requests in place of the host's fetch.
type fetchFunc func(*http.Request) (*http.Response, error)

func (f fetchFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

// The engine itself bounds what a call decodes, through the whole entry the
// relay runs: an answer past hostedAnswerValues, and arguments past
// hostedArgumentValues, are refused as validation errors that say how to ask
// for less, and refused arguments reach no install.
func TestHostedEngineBoundsWhatACallDecodes(t *testing.T) {
	t.Setenv("P202_HOSTED_URL", "https://install.example")
	t.Setenv("P202_HOSTED_API_KEY", "probe-key-0123456789abcdef")
	t.Cleanup(resetHostedOutput)
	over := tinyRows(hostedAnswerValues/2 + 100)
	requests := 0
	saved := http.DefaultTransport
	t.Cleanup(func() { http.DefaultTransport = saved })
	http.DefaultTransport = fetchFunc(func(r *http.Request) (*http.Response, error) {
		requests++
		body := over
		switch r.URL.Path {
		case "/api/versions":
			body = []byte(`{"data":{"preferred":"v3","supported":["v3"]}}`)
		case "/api/v3/capabilities":
			body = []byte(`{"data":{"features":{}}}`)
		}
		return &http.Response{StatusCode: 200, Header: http.Header{"Content-Type": {"application/json"}}, Body: io.NopCloser(bytes.NewReader(body)), Request: r}, nil
	})
	envelope := func(stderr []byte) (category, message, hint string) {
		t.Helper()
		var e struct {
			Error struct{ Category, Message, Hint string } `json:"error"`
		}
		if err := json.Unmarshal(stderr, &e); err != nil {
			t.Fatalf("stderr is not the JSON envelope: %q (%v)", stderr, err)
		}
		return e.Error.Category, e.Error.Message, e.Error.Hint
	}

	var stderr bytes.Buffer
	if code := run([]string{"mcp", "call", "campaign_list", "--input", "{}", "--read-only"}, &stderr); code != ExitValidation {
		t.Fatalf("an answer past the bound: exit %d, %s", code, stderr.String())
	}
	category, message, hint := envelope(stderr.Bytes())
	if category != "validation" || !strings.Contains(message, fmt.Sprintf("more than %d JSON values", hostedAnswerValues)) || !strings.Contains(hint, "campaign_list takes: ") {
		t.Fatalf("an answer past the bound: %s", stderr.String())
	}
	if requests == 0 {
		t.Fatal("the call reached no install")
	}

	requests = 0
	stderr.Reset()
	offers := `{"id":"1","offer":[` + strings.TrimSuffix(strings.Repeat(`"campaign:1",`, hostedArgumentValues), ",") + `]}`
	if code := run([]string{"mcp", "call", "landing_page_code", "--input", offers, "--read-only"}, &stderr); code != ExitValidation {
		t.Fatalf("arguments past the bound: exit %d, %s", code, stderr.String())
	}
	if _, message, _ := envelope(stderr.Bytes()); !strings.Contains(message, fmt.Sprintf("more than %d JSON values", hostedArgumentValues)) || requests != 0 {
		t.Fatalf("arguments past the bound: %s after %d requests", stderr.String(), requests)
	}
}

// The whole entry the relay runs, on a connection's key that an install
// refuses for a scope it was not approved with: exit 2, and a hint that says
// to approve the connection again, whatever the install says of the key
// (an install older than 1.9.79 says nothing); never one that makes a key.
func TestHostedScopeRefusalSaysConnectAgain(t *testing.T) {
	t.Setenv("P202_HOSTED_URL", "https://install.example")
	t.Setenv("P202_HOSTED_API_KEY", "probe-key-0123456789abcdef")
	t.Cleanup(resetHostedOutput)
	saved := http.DefaultTransport
	t.Cleanup(func() { http.DefaultTransport = saved })
	for _, tc := range []struct{ refusal, want string }{
		{`{"error":true,"message":"Insufficient API key scope for this operation: requires 'ppc-networks:write' (key has: ppc-networks:read). This key is an AI app's connection through Tracking202's MCP server.","status":403,"code":"insufficient_scope","required_scope":"ppc-networks:write","key_scope":"ppc-networks:read","key_source":"ai_connection","remedy":"(remedy)","connect_access":"write"}`,
			`choose "Read and change"`},
		{`{"error":true,"message":"Insufficient API key scope for this operation: requires 'ppc-networks:write' (key has: ppc-networks:read).","status":403}`,
			"approve it with the access that includes 'ppc-networks:write'"},
	} {
		http.DefaultTransport = fetchFunc(func(r *http.Request) (*http.Response, error) {
			status, body := 200, `{"data":{"ppc_network_id":1,"ppc_network_name":"Google Ads - Main"}}`
			switch {
			case r.URL.Path == "/api/versions":
				body = `{"data":{"preferred":"v3","supported":["v3"]}}`
			case r.URL.Path == "/api/v3/capabilities":
				body = `{"data":{"features":{}}}`
			case r.Method != http.MethodGet:
				status, body = 403, tc.refusal
			}
			return &http.Response{StatusCode: status, Header: http.Header{"Content-Type": {"application/json"}}, Body: io.NopCloser(strings.NewReader(body)), Request: r}, nil
		})
		// A write connection's call: a test before this one may have left
		// the flag set.
		mcpReadOnly = false
		var stderr bytes.Buffer
		if code := run([]string{"mcp", "call", "ppc_network_update", "--input", `{"id":"1","ppc_network_name":"Main"}`}, &stderr); code != ExitAuth {
			t.Fatalf("exit %d, want %d: %s", code, ExitAuth, stderr.String())
		}
		var e struct {
			Error struct{ Category, Message, Hint string } `json:"error"`
		}
		if err := json.Unmarshal(stderr.Bytes(), &e); err != nil {
			t.Fatalf("stderr is not the JSON envelope: %q (%v)", stderr.String(), err)
		}
		if e.Error.Category != "auth" || !strings.Contains(e.Error.Message, "requires 'ppc-networks:write'") ||
			!strings.Contains(e.Error.Hint, "Connect the AI app to Prosper202 again") || !strings.Contains(e.Error.Hint, tc.want) ||
			strings.Contains(e.Error.Hint, "apikey create") {
			t.Fatalf("envelope %+v", e.Error)
		}
		resetHostedOutput()
	}
}
