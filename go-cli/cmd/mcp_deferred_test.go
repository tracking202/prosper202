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

// Each deferred tool is registered once, is local only (the hosted relay
// lists the always-loaded catalogue and loads its own), and says what task
// it is for.
func TestMCPDeferredToolsAreRegisteredOnce(t *testing.T) {
	seen := map[string]bool{mcpGetMoreTools: true}
	for _, s := range mcpAllToolSpecs() {
		if seen[s.Name] {
			t.Errorf("%s is registered twice (or is named get_more_tools)", s.Name)
		}
		seen[s.Name] = true
	}
	for _, s := range mcpDeferredToolSpecs {
		if !s.NotHosted {
			t.Errorf("%s is deferred and not NotHosted: the hosted relay cannot load it", s.Name)
		}
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

// The spend tools are found by what people ask about ad spend, ahead of the
// destination tools that also name ChatGPT Ads; ad_spend takes the day or
// hour interval mcpOmittedFlags leaves out of other tools, and nothing else
// of that list.
func TestMCPSpendToolsAreFoundAndTakeTheInterval(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for task, want := range map[string]string{
		"What did ChatGPT Ads cost yesterday?":          "ad_spend",
		"openai ads spend per ad last week":             "ad_spend",
		"how much spend did no click carry":             "ad_spend",
		"is my spend sync working":                      "ad_spend_sync_status",
		"when did the ChatGPT Ads spend last sync":      "ad_spend_sync_status",
		"why did a conversion not reach google ads":     "destination_deliveries",
		"show me the destinations and their status now": "destination_list",
	} {
		got := mcpMatchDeferred(task, mcpDeferredToolSpecs, tools)
		if len(got) == 0 || got[0].Name != want {
			names := []string{}
			for _, g := range got {
				names = append(names, g.Name)
			}
			t.Errorf("get_more_tools %q ranks %v, want %s first", task, names, want)
		}
	}
	spec, ok := findMCPSpec("ad_spend")
	if !ok {
		t.Fatal("ad_spend is not registered")
	}
	argv, err := mcpToolArgv(rootCmd, spec, []byte(`{"period":"yesterday","interval":"hour"}`))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(argv, " "), "--interval=hour") {
		t.Errorf("ad_spend's argv %v does not carry the interval", argv)
	}
	if _, err := mcpToolArgv(rootCmd, spec, []byte(`{"force":true}`)); err == nil {
		t.Error("ad_spend took force")
	}
	forecast, _ := findMCPSpec("forecast")
	if _, err := mcpToolArgv(rootCmd, forecast, []byte(`{"metric":"clicks","interval":"day"}`)); err == nil {
		t.Error("forecast took interval, which Keep gives only ad_spend")
	}
	bad := spec
	bad.Keep = []string{"force"}
	if _, err := bad.tool(rootCmd); err == nil {
		t.Error("a Keep of force was accepted")
	}
}

// Through the real server, as an app asks: get_more_tools loads ad_spend for a
// spend question, and both spend tools read the spend ledger's routes.
func TestMCPGetMoreToolsLoadsTheSpendToolsOverStdio(t *testing.T) {
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
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	client := mcp.NewClient(&mcp.Implementation{Name: "p202-test", Version: "1"}, nil)
	session, err := client.Connect(ctx, &mcp.CommandTransport{Command: osexec.Command(exe, "mcp", "--read-only")}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer session.Close()

	res, err := session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: map[string]any{"task": "What did ChatGPT Ads cost yesterday?"}})
	if err != nil || res.IsError {
		t.Fatalf("get_more_tools: %v %s", err, mcpResultText(res))
	}
	var answer struct {
		Tools []struct{ Name string } `json:"tools"`
	}
	if err := json.Unmarshal([]byte(mcpResultText(res)), &answer); err != nil || len(answer.Tools) == 0 || answer.Tools[0].Name != "ad_spend" {
		t.Fatalf("get_more_tools answered %s (%v)", mcpResultText(res), err)
	}
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: "ad_spend", Arguments: map[string]any{"period": "yesterday", "interval": "hour"}})
	if err != nil || res.IsError {
		t.Fatalf("ad_spend: %v %s", err, mcpResultText(res))
	}
	read := false
	for _, h := range fake.requests() {
		read = read || (strings.HasPrefix(h, "GET /api/v3/spend-ledger?") && strings.Contains(h, "interval=hour") && strings.Contains(h, "period=yesterday"))
	}
	if !read {
		t.Fatalf("ad_spend did not read the ledger by the hour: %q", fake.requests())
	}
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: "ad_spend_sync_status", Arguments: map[string]any{}})
	if err != nil || res.IsError {
		t.Fatalf("ad_spend_sync_status: %v %s", err, mcpResultText(res))
	}
	if !mcpHit(fake.requests(), "GET /api/v3/spend-ledger/sync") {
		t.Fatalf("requests: %q", fake.requests())
	}
}
