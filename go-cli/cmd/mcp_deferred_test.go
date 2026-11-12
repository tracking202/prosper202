//go:build !js

package cmd

import (
	"context"
	"encoding/json"
	"os"
	osexec "os/exec"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

// The deferred tools are read into the model's context only when
// get_more_tools loads them, so they have a budget of their own; the
// always-loaded catalogue's (TestMCPCatalogStaysInBudget) does not count them.
func TestMCPDeferredToolsStayInBudget(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	if len(tools) != len(mcpDeferredToolSpecs) {
		t.Fatalf("built %d deferred tools of %d specs", len(tools), len(mcpDeferredToolSpecs))
	}
	body, err := json.Marshal(tools)
	if err != nil {
		t.Fatal(err)
	}
	// Characters. The three destination tools are about 2,100; a tool loaded
	// on request still costs its description once it is loaded.
	const budget = 40000
	if len(body) > budget {
		t.Fatalf("the deferred tools are %d characters, over their %d budget", len(body), budget)
	}
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for _, listed := range cat.Tools {
		if _, deferred := findMCPDeferredSpec(listed.Name); deferred {
			t.Errorf("%s is in the always-loaded catalogue and the deferred set", listed.Name)
		}
	}
}

// Each deferred tool is registered once and says what task it is for. One
// the hosted relay may load is held to the AI key's areas by
// TestHostedMCPToolsStayInsideTheAIKeyAreas.
func TestMCPDeferredToolsAreRegisteredOnce(t *testing.T) {
	seen := map[string]bool{mcpGetMoreTools: true}
	for _, s := range mcpAllToolSpecs() {
		if seen[s.Name] {
			t.Errorf("%s is registered twice (or is named get_more_tools)", s.Name)
		}
		seen[s.Name] = true
	}
	for _, s := range mcpDeferredToolSpecs {
		if len(s.Words) == 0 {
			t.Errorf("%s has no Words for get_more_tools to find it by", s.Name)
		}
	}
}

// A task in a deferred tool's own words finds it, and a task about nothing
// they do finds none.
func TestMCPDeferredToolsAreFoundByTheirTask(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range mcpDeferredToolSpecs {
		found := false
		for _, m := range mcpMatchDeferred(s.Words[0], mcpDeferredToolSpecs, tools) {
			found = found || m.Name == s.Name
		}
		if !found {
			t.Errorf("get_more_tools %q does not find %s", s.Words[0], s.Name)
		}
	}
	got := mcpMatchDeferred("why did a conversion not reach google ads", mcpDeferredToolSpecs, tools)
	if len(got) == 0 || got[0].Name != "destination_deliveries" {
		t.Errorf("a failed delivery's task ranks %v first", got)
	}
	if got := mcpMatchDeferred("the weather in Lisbon", mcpDeferredToolSpecs, tools); len(got) != 0 {
		t.Errorf("an unrelated task matched %d tools", len(got))
	}
	if got := mcpTaskWords("Show me the Destinations, please!"); strings.Join(got, ",") != "destination" {
		t.Errorf("task words %v", got)
	}
}

// A read-only server loads no deferred write; one that only proposes loads
// no tool that cannot be staged.
func TestMCPDeferredToolsFollowTheServersPolicy(t *testing.T) {
	for _, policy := range []struct{ readOnly, staged bool }{{true, false}, {false, true}} {
		tools, err := buildMCPDeferredTools(rootCmd, policy.readOnly, policy.staged)
		if err != nil {
			t.Fatal(err)
		}
		for _, tool := range tools {
			if tool.Name == "destination_test" {
				t.Errorf("read-only %v, staged %v offers destination_test", policy.readOnly, policy.staged)
			}
		}
	}
}

func TestMCPGetMoreToolsLoadsDeferredToolsOverStdio(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	t.Setenv(mcpTestChildEnv, "1")
	exe, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}

	var changed atomic.Int32
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	client := mcp.NewClient(&mcp.Implementation{Name: "p202-test", Version: "1"}, &mcp.ClientOptions{
		ToolListChangedHandler: func(context.Context, *mcp.ToolListChangedRequest) { changed.Add(1) },
	})
	session, err := client.Connect(ctx, &mcp.CommandTransport{Command: osexec.Command(exe, "mcp")}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer session.Close()

	if caps := session.InitializeResult().Capabilities; caps.Tools == nil || !caps.Tools.ListChanged {
		t.Fatalf("the server does not declare tools.listChanged: %+v", caps.Tools)
	}
	if !strings.Contains(session.InitializeResult().Instructions, "get_more_tools") {
		t.Errorf("the instructions do not say to call get_more_tools")
	}
	names := func() map[string]bool {
		list, err := session.ListTools(ctx, nil)
		if err != nil {
			t.Fatal(err)
		}
		out := map[string]bool{}
		for _, tool := range list.Tools {
			out[tool.Name] = true
		}
		return out
	}
	if before := names(); !before[mcpGetMoreTools] || before["destination_deliveries"] {
		t.Fatalf("before: get_more_tools %v, destination_deliveries %v", before[mcpGetMoreTools], before["destination_deliveries"])
	}

	// A client that ignores list_changed calls a deferred tool it was never given: it is loaded and runs.
	res, err := session.CallTool(ctx, &mcp.CallToolParams{Name: "destination_list", Arguments: map[string]any{}})
	if err != nil || res.IsError {
		t.Fatalf("destination_list before it was loaded: %v %s", err, mcpResultText(res))
	}
	if !mcpHit(fake.requests(), "GET /api/v3/destinations") {
		t.Fatalf("requests: %q", fake.requests())
	}

	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: map[string]any{"task": "why did a conversion not reach Google Ads"}})
	if err != nil || res.IsError {
		t.Fatalf("get_more_tools: %v %s", err, mcpResultText(res))
	}
	var answer struct {
		Tools []struct{ Name, Description string } `json:"tools"`
	}
	if err := json.Unmarshal([]byte(mcpResultText(res)), &answer); err != nil || len(answer.Tools) == 0 || answer.Tools[0].Name != "destination_deliveries" || answer.Tools[0].Description == "" {
		t.Fatalf("get_more_tools answered %s (%v)", mcpResultText(res), err)
	}
	for i := 0; i < 50 && changed.Load() == 0; i++ {
		time.Sleep(100 * time.Millisecond)
	}
	if changed.Load() == 0 {
		t.Error("no notifications/tools/list_changed after get_more_tools loaded a tool")
	}
	if after := names(); !after["destination_deliveries"] {
		t.Fatal("destination_deliveries is not listed after get_more_tools")
	}
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: "destination_deliveries", Arguments: map[string]any{"id": "4"}})
	if err != nil || res.IsError {
		t.Fatalf("destination_deliveries: %v %s", err, mcpResultText(res))
	}
	if !mcpHit(fake.requests(), "GET /api/v3/destinations/4/deliveries") {
		t.Fatalf("requests: %q", fake.requests())
	}

	// No task is refused in the CLI's error envelope, with what to send.
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: map[string]any{}})
	if err != nil {
		t.Fatal(err)
	}
	var refused struct {
		Error struct{ Category, Message, Hint string } `json:"error"`
	}
	if !res.IsError || json.Unmarshal([]byte(mcpResultText(res)), &refused) != nil || refused.Error.Category != "validation" || !strings.Contains(refused.Error.Hint, `"task"`) {
		t.Fatalf("get_more_tools without a task answered %s", mcpResultText(res))
	}
	// A task nothing matches names what can be added.
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: map[string]any{"task": "the weather in Lisbon"}})
	if err != nil || res.IsError || !strings.Contains(mcpResultText(res), "destination_test") {
		t.Fatalf("an unmatched task answered %v %s", err, mcpResultText(res))
	}
}

// `p202 mcp tools --deferred` is what the hosted relay builds its own
// get_more_tools from: every deferred tool, hosted_excludes and
// staged_excludes naming exactly the NotHosted and NotStaged ones, and words
// carrying each tool's Words, so the relay matches a task by the words this
// server does.
func TestMCPDeferredListingCarriesWhatTheRelayMatches(t *testing.T) {
	listed, err := mcpDeferredListing(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	body, err := json.Marshal(listed)
	if err != nil {
		t.Fatal(err)
	}
	var got struct {
		Tools          []struct{ Name string } `json:"tools"`
		HostedExcludes []string                `json:"hosted_excludes"`
		StagedExcludes []string                `json:"staged_excludes"`
		Words          map[string][]string     `json:"words"`
	}
	if err := json.Unmarshal(body, &got); err != nil {
		t.Fatal(err)
	}
	if len(got.Tools) != len(mcpDeferredToolSpecs) {
		t.Fatalf("listed %d tools of %d specs", len(got.Tools), len(mcpDeferredToolSpecs))
	}
	excluded, notStaged := map[string]bool{}, map[string]bool{}
	for _, name := range got.HostedExcludes {
		excluded[name] = true
	}
	for _, name := range got.StagedExcludes {
		notStaged[name] = true
	}
	for _, s := range mcpDeferredToolSpecs {
		if excluded[s.Name] != s.NotHosted {
			t.Errorf("%s: in hosted_excludes %v, NotHosted %v", s.Name, excluded[s.Name], s.NotHosted)
		}
		if notStaged[s.Name] != s.NotStaged {
			t.Errorf("%s: in staged_excludes %v, NotStaged %v", s.Name, notStaged[s.Name], s.NotStaged)
		}
		if strings.Join(got.Words[s.Name], "|") != strings.Join(s.Words, "|") || len(s.Words) == 0 {
			t.Errorf("%s: words %q, want its Words %q", s.Name, got.Words[s.Name], s.Words)
		}
	}
	if len(got.Words) != len(mcpDeferredToolSpecs) {
		t.Errorf("words names %d tools, want %d", len(got.Words), len(mcpDeferredToolSpecs))
	}
}
