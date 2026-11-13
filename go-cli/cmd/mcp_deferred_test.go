//go:build !js

package cmd

import (
	"context"
	"encoding/json"
	"os"
	osexec "os/exec"
	"regexp"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

// The catalogue is read into the model's context on every connection. (Native
// only: get_more_tools is the stdio server's, which js/wasm does not build.)
func TestMCPCatalogStaysInBudget(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	// What the server lists on connecting: the catalogue, get_more_tools
	// and the sentence that tells the model to call it.
	more := mcpGetMoreToolsTool()
	cat.Tools = append(cat.Tools, mcpTool{Name: more.Name, Title: more.Title, Description: more.Description, InputSchema: more.InputSchema.(mcpSchema),
		Annotations: mcpToolAnnotations{Title: more.Annotations.Title, ReadOnlyHint: more.Annotations.ReadOnlyHint, IdempotentHint: more.Annotations.IdempotentHint}})
	cat.Instructions += mcpMoreToolsInstructions
	body, err := json.Marshal(cat)
	if err != nil {
		t.Fatal(err)
	}
	// Characters, about 23k tokens. Most of it is the report tools' filters;
	// a new tool or flag that crosses this should omit what few asks need.
	// The traffic source and category updates and deletes are about 4,600,
	// the text ad writes about 3,400 (Setup › Text Ads' own fields), the rotator
	// writes about 5,000 (rotator_set_rules' rules are a schema of their own);
	// with both the list was about 89,500; with every 1.9.79 addition (the scope
	// refusals, the optimizer's and the reports' notes) it is about 93,600; the
	// click filter's five tools (user_id and ids left out, no entry update) and
	// settings_get add about 5,100; the publisher dimension and its filter
	// (on report_summary, report_breakdown and click_list) about 800;
	// landing_page_check about 700; ppc_network_add_chatgpt_ads about 330,
	// paid for by a shorter help line on the link-token flags; get_more_tools
	// and its sentence in the instructions about 700 (every later tool is
	// listed on request, mcpDeferredToolSpecs), paid for by the click
	// filter tools' user_id, which their Omit meant to leave out.
	const budget = 100500
	if len(body) > budget {
		t.Fatalf("the tool list is %d characters, over its %d budget; trim notes or omit flags", len(body), budget)
	}
}

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
	getMore := res
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

	// A client that never reads the listing learns from the answer what each tool takes.
	var schemas struct {
		Tools []struct {
			Name        string    `json:"name"`
			InputSchema mcpSchema `json:"input_schema"`
		} `json:"tools"`
	}
	if err := json.Unmarshal([]byte(mcpResultText(getMore)), &schemas); err != nil {
		t.Fatal(err)
	}
	for _, found := range schemas.Tools {
		if found.Name == "destination_deliveries" && (strings.Join(found.InputSchema.Required, ",") != "id" || found.InputSchema.Properties["offset"].Type != "integer") {
			t.Errorf("get_more_tools names destination_deliveries' arguments as %+v", found.InputSchema)
		}
	}

	// No task, a blank one and an argument it does not take are each refused
	// in the CLI's error envelope, naming what was wrong and what to send.
	for _, tc := range []struct {
		args map[string]any
		want string
	}{
		{map[string]any{}, "needs task"},
		{map[string]any{"task": "   "}, "needs task"},
		{map[string]any{"task": "destinations", "extra": 1}, "extra is not an argument of get_more_tools"},
	} {
		res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: tc.args})
		if err != nil {
			t.Fatal(err)
		}
		var refused struct {
			Error struct{ Category, Message, Hint string } `json:"error"`
		}
		if !res.IsError || json.Unmarshal([]byte(mcpResultText(res)), &refused) != nil || refused.Error.Category != "validation" ||
			!strings.Contains(refused.Error.Message, tc.want) || !strings.Contains(refused.Error.Hint, `"task"`) {
			t.Errorf("get_more_tools %v answered %s, want %q", tc.args, mcpResultText(res), tc.want)
		}
	}
	// A task nothing matches names what can be added.
	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: mcpGetMoreTools, Arguments: map[string]any{"task": "the weather in Lisbon"}})
	if err != nil || res.IsError || !strings.Contains(mcpResultText(res), "destination_test") {
		t.Fatalf("an unmatched task answered %v %s", err, mcpResultText(res))
	}
}

// mcpAnswerKeyRef is a key a tool's Note names in its answer: a dotted path
// (pagination.total) or a key with its value (truncated: true).
var mcpAnswerKeyRef = regexp.MustCompile(`\b([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)\b|\b([a-z][a-z0-9_]*): (?:true|false)\b`)

// mcpDeferredAnswerSources: the controller each deferred tool's answer comes
// from as it is (the destination commands render the API's answer).
var mcpDeferredAnswerSources = map[string]string{
	"destination":   "DestinationsController.php",
	"ad-connection": "AdConnectionsController.php",
}

// A deferred tool's Note names only keys its answer carries: the answer is
// the API's own, so each key a Note names is one the controller writes, with
// a dotted path's parts nested in that order.
func TestMCPDeferredNotesNameKeysTheAnswerCarries(t *testing.T) {
	named := 0
	for _, s := range mcpDeferredToolSpecs {
		file, ok := mcpDeferredAnswerSources[s.Command[0]]
		if !ok {
			t.Errorf("%s: no controller listed for `p202 %s` in mcpDeferredAnswerSources", s.Name, s.Command[0])
			continue
		}
		src, err := os.ReadFile(repoPath("api", "v3", "Controllers", file))
		if err != nil {
			t.Fatal(err)
		}
		for _, m := range mcpAnswerKeyRef.FindAllStringSubmatch(s.Note, -1) {
			path := m[1] + m[2]
			parts := strings.Split(path, ".")
			quoted := make([]string, len(parts))
			for i, p := range parts {
				quoted[i] = `'` + regexp.QuoteMeta(p) + `'\s*=>`
			}
			nested := regexp.MustCompile(strings.Join(quoted, `\s*\[[^;]*?`))
			if !nested.Match(src) {
				t.Errorf("%s's description names %s, which %s does not write", s.Name, path, file)
			}
			named++
		}
	}
	if named < 2 {
		t.Fatalf("read %d key names in the deferred Notes; destination_deliveries names two", named)
	}
}

// `p202 mcp tools --deferred` lists the tools get_more_tools loads, under
// the same policies as the server, and the plain listing leaves them out.
func TestMCPToolsListsTheDeferredOnes(t *testing.T) {
	t.Cleanup(func() { mcpToolsDeferred, mcpReadOnly = false, false })
	names := func(args ...string) []string {
		stdout, stderr, err := mcpExecute(t, args...)
		if err != nil {
			t.Fatalf("%q: %v %s", args, err, stderr)
		}
		var listed struct {
			Tools []mcpTool `json:"tools"`
		}
		if err := json.Unmarshal([]byte(stdout), &listed); err != nil {
			t.Fatalf("%q: %v", args, err)
		}
		out := []string{}
		for _, tool := range listed.Tools {
			out = append(out, tool.Name)
		}
		mcpToolsDeferred, mcpReadOnly = false, false
		return out
	}
	if got := strings.Join(names("mcp", "tools", "--deferred"), ","); got != "destination_list,destination_deliveries,destination_test,ad_connection_list,ad_connection_check" {
		t.Errorf("--deferred lists %s", got)
	}
	if got := strings.Join(names("mcp", "tools", "--deferred", "--read-only"), ","); got != "destination_list,destination_deliveries,ad_connection_list" {
		t.Errorf("--deferred --read-only lists %s", got)
	}
	if got := strings.Join(names("--staged", "mcp", "tools", "--deferred"), ","); got != "destination_list,destination_deliveries,ad_connection_list" {
		t.Errorf("--staged --deferred lists %s", got)
	}
	for _, name := range names("mcp", "tools") {
		if _, deferred := findMCPDeferredSpec(name); deferred {
			t.Errorf("the plain listing has %s", name)
		}
	}
}

// A test fire makes the install call the ad platform: it says so.
func TestMCPDeferredToolsSayWhenTheyReachOut(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range tools {
		// The test fire and the connection check make the install call OpenAI.
		if want := tool.Name == "destination_test" || tool.Name == "ad_connection_check"; tool.Annotations.OpenWorldHint != want {
			t.Errorf("%s openWorldHint %v, want %v", tool.Name, tool.Annotations.OpenWorldHint, want)
		}
	}
}

// The connection check writes (so --read-only leaves it out, above) but only
// what it read from OpenAI: it is not annotated destructive. The test fire
// takes no `real`: that sends an event the platform saves and counts, which
// the page asks about first and a tool call cannot.
func TestMCPDeferredToolsDoNothingAPersonWasNotAsked(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	seen := map[string]bool{}
	for _, tool := range tools {
		seen[tool.Name] = true
		if tool.Annotations.DestructiveHint {
			t.Errorf("%s is annotated destructive", tool.Name)
		}
		if tool.Name == "ad_connection_check" && tool.Annotations.ReadOnlyHint {
			t.Errorf("%s writes the check: it is not read-only", tool.Name)
		}
		if strings.Count(tool.Description, "Reads the ad account from OpenAI again")+strings.Count(tool.Description, "Read the ad account from OpenAI again") > 1 {
			t.Errorf("%s says one sentence twice: %s", tool.Name, tool.Description)
		}
		if tool.Name != "destination_test" {
			continue
		}
		raw, err := json.Marshal(tool.InputSchema)
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(string(raw), `"real"`) {
			t.Errorf("destination_test takes real: %s", raw)
		}
		if !strings.Contains(string(raw), `"id"`) {
			t.Errorf("destination_test lost its id: %s", raw)
		}
	}
	if !seen["destination_test"] || !seen["ad_connection_check"] {
		t.Errorf("the listing lacks a tool this test reads: %v", seen)
	}
}
