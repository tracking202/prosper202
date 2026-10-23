//go:build !js

package cmd

import (
	"context"
	"encoding/json"
	"os"
	osexec "os/exec"
	"strings"
	"testing"
	"time"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

func connectMCPServer(t *testing.T, serverArgs ...string) *mcp.ClientSession {
	t.Helper()
	exe, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv(mcpTestChildEnv, "1")
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	t.Cleanup(cancel)
	client := mcp.NewClient(&mcp.Implementation{Name: "p202-test", Version: "1"}, nil)
	session, err := client.Connect(ctx, &mcp.CommandTransport{Command: osexec.Command(exe, serverArgs...)}, nil)
	if err != nil {
		t.Fatalf("connecting to `p202 %s`: %v", strings.Join(serverArgs, " "), err)
	}
	t.Cleanup(func() { _ = session.Close() })
	return session
}

func TestMCPServerOverStdio(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	ctx := context.Background()

	session := connectMCPServer(t, "mcp")
	if got := session.InitializeResult().Instructions; !strings.Contains(got, "Prosper202") {
		t.Fatalf("instructions = %q", got)
	}
	tools, err := session.ListTools(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(tools.Tools) != len(mcpToolSpecs) {
		t.Fatalf("listed %d tools, want %d", len(tools.Tools), len(mcpToolSpecs))
	}
	for _, tool := range tools.Tools {
		if tool.Annotations == nil || tool.Annotations.DestructiveHint == nil || tool.Title == "" {
			t.Fatalf("tool %s lacks its title or hints: %+v", tool.Name, tool.Annotations)
		}
	}

	res, err := session.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_list", Arguments: map[string]any{"limit": "5"}})
	if err != nil {
		t.Fatal(err)
	}
	if res.IsError {
		t.Fatalf("campaign_list failed: %s", mcpResultText(res))
	}
	if got := mcpResultText(res); got != `{"data":[],"pagination":{"limit":50,"offset":0,"total":0}}` {
		t.Fatalf("campaign_list answered %q", got)
	}
	if hits := fake.requests(); !mcpHit(hits, "GET /api/v3/campaigns?limit=5") {
		t.Fatalf("requests: %q", hits)
	}

	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_list", Arguments: map[string]any{"limt": "5"}})
	if err != nil {
		t.Fatal(err)
	}
	var envelope struct {
		Error struct {
			Category string `json:"category"`
			Message  string `json:"message"`
			ExitCode int    `json:"exit_code"`
		} `json:"error"`
	}
	if !res.IsError || json.Unmarshal([]byte(mcpResultText(res)), &envelope) != nil ||
		envelope.Error.Category != "validation" || !strings.Contains(envelope.Error.Message, "does not take limt") {
		t.Fatalf("a bad argument answered %v %q", res.IsError, mcpResultText(res))
	}

	res, err = session.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_delete", Arguments: map[string]any{"id": "7"}})
	if err != nil {
		t.Fatal(err)
	}
	if res.IsError || !mcpHit(fake.requests(), "DELETE /api/v3/campaigns/7") {
		t.Fatalf("campaign_delete answered %v %q; requests %q", res.IsError, mcpResultText(res), fake.requests())
	}
}

func TestMCPServerReadOnlyAndStagedReachEveryCall(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	ctx := context.Background()

	readOnly := connectMCPServer(t, "mcp", "--read-only")
	tools, err := readOnly.ListTools(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range tools.Tools {
		if !tool.Annotations.ReadOnlyHint {
			t.Fatalf("a read-only server lists %s", tool.Name)
		}
	}
	res, err := readOnly.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_delete", Arguments: map[string]any{"id": "7"}})
	if err == nil && !res.IsError {
		t.Fatalf("a read-only server ran campaign_delete: %q", mcpResultText(res))
	}

	staged := connectMCPServer(t, "--staged", "mcp")
	stagedTools, err := staged.ListTools(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range stagedTools.Tools {
		if tool.Name == "change_apply" {
			t.Fatalf("a staged server lists change_apply")
		}
	}
	res, err = staged.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_delete", Arguments: map[string]any{"id": "8"}})
	if err != nil {
		t.Fatal(err)
	}
	if res.IsError {
		t.Fatalf("staged campaign_delete failed: %s", mcpResultText(res))
	}
	if !mcpHit(fake.requests(), "DELETE /api/v3/campaigns/8?staged=1") {
		t.Fatalf("the delete was not staged: %q", fake.requests())
	}
	for _, hit := range fake.requests() {
		if hit == "DELETE /api/v3/campaigns/7" {
			t.Fatalf("the read-only server's delete reached the install")
		}
	}
}

func TestMCPShapeResult(t *testing.T) {
	big := strings.Repeat("é", mcpMaxResultChars+1)
	fits := strings.Repeat("é", mcpMaxResultChars) // more bytes than the cap, as many characters
	for _, tc := range []struct {
		name     string
		in       mcpCallResult
		readOnly bool
		isError  bool
		want     string
	}{
		{"data", mcpCallResult{Stdout: []byte(`{"data":[1]}` + "\n")}, true, false, `{"data":[1]}`},
		{"void write says so", mcpCallResult{Stderr: []byte("Deleted campaign 7\n")}, false, false, "Deleted campaign 7"},
		{"nothing at all", mcpCallResult{}, false, false, `{"ok":true}`},
		{"error envelope", mcpCallResult{ExitCode: 2, Stderr: []byte(`{"error":{"category":"auth"}}`)}, true, true, `{"error":{"category":"auth"}}`},
		{"silent failure", mcpCallResult{ExitCode: 3}, true, true, "exited 3 without saying why"},
		{"a read too big", mcpCallResult{Stdout: []byte(big)}, true, true, "at most 60000"},
		{"characters, not bytes", mcpCallResult{Stdout: []byte(fits)}, true, false, "éé"},
		{"a write too big still succeeded", mcpCallResult{Stdout: []byte(big)}, false, false, "The change was made"},
	} {
		res := mcpShapeResult(tc.in, tc.readOnly, false)
		if res.IsError != tc.isError || !strings.Contains(mcpResultText(res), tc.want) {
			t.Errorf("%s: got %v %q", tc.name, res.IsError, mcpResultText(res))
		}
	}
}

// Under --staged a write only proposes: an answer too big to show must not
// say the change was made, and names the proposal.
func TestMCPStagedWriteTooBigSaysItWasProposed(t *testing.T) {
	big := `{"data":[{"change_id":"chg_1a2b3c","status":"staged"},{"change_id":"chg_4d5e6f","status":"staged"},` +
		`{"change_id":"chg_1a2b3c"}],"preview":"` + strings.Repeat("x", mcpMaxResultChars) + `"}`
	res := mcpShapeResult(mcpCallResult{Stdout: []byte(big)}, false, true)
	text := mcpResultText(res)
	if res.IsError || strings.Contains(text, "The change was made") || !strings.Contains(text, "proposed, not made") ||
		!strings.Contains(text, `"change_ids":["chg_1a2b3c","chg_4d5e6f"]`) {
		t.Fatalf("staged oversize answer: %v %q", res.IsError, text)
	}
	if !json.Valid([]byte(text)) {
		t.Fatalf("not JSON: %q", text)
	}
	// With no proposal id in the answer, it claims neither outcome.
	noID := mcpShapeResult(mcpCallResult{Stdout: []byte(`{"data":"` + strings.Repeat("x", mcpMaxResultChars) + `"}`)}, false, true)
	text = mcpResultText(noID)
	if noID.IsError || strings.Contains(text, "was made") || strings.Contains(text, "not made") || !json.Valid([]byte(text)) {
		t.Fatalf("staged oversize answer without an id: %v %q", noID.IsError, text)
	}
}

func TestMCPUnfinishedWriteIsNotCalledUnrun(t *testing.T) {
	text := mcpResultText(mcpRunFailure("campaign_delete", false, errMCPTimedOut))
	if !strings.Contains(text, "may have made the change anyway") || !strings.Contains(text, "before trying again") {
		t.Fatalf("a stopped write answered %s", text)
	}
	if text := mcpResultText(mcpRunFailure("campaign_list", true, errMCPCancelled)); !strings.Contains(text, "cancelled") {
		t.Fatalf("a cancelled read answered %s", text)
	}
}

func TestMCPChildArgsCarryThePolicy(t *testing.T) {
	got := mcpServerPolicy{readOnly: true, staged: true, profile: "prod"}.childArgs("campaign_list")
	want := []string{"--profile=prod", "--staged", "mcp", "call", "campaign_list", "--input=-", "--read-only"}
	if strings.Join(got, " ") != strings.Join(want, " ") {
		t.Fatalf("childArgs = %q, want %q", got, want)
	}
}

// The server's install is the one active when it started: switching the
// config's active profile later does not move a running AI session.
func TestMCPServerKeepsTheProfileItStartedWith(t *testing.T) {
	first, second := &mcpFakeInstall{}, &mcpFakeInstall{}
	firstSrv, secondSrv := first.server(t), second.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	profiles := map[string]map[string]interface{}{
		"first":  {"url": firstSrv.URL, "api_key": "first-key-123456"},
		"second": {"url": secondSrv.URL, "api_key": "second-key-123456"},
	}
	writeTestConfigWithProfiles(t, tmp, "first", profiles)
	session := connectMCPServer(t, "mcp")
	ctx := context.Background()
	if _, err := session.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_list", Arguments: map[string]any{}}); err != nil {
		t.Fatal(err)
	}
	writeTestConfigWithProfiles(t, tmp, "second", profiles)
	if _, err := session.CallTool(ctx, &mcp.CallToolParams{Name: "campaign_list", Arguments: map[string]any{}}); err != nil {
		t.Fatal(err)
	}
	if len(second.requests()) != 0 {
		t.Fatalf("after the active profile changed, the running server called the other install: %q", second.requests())
	}
	if !mcpHit(first.requests(), "GET /api/v3/campaigns") {
		t.Fatalf("first install's requests: %q", first.requests())
	}
}

func mcpResultText(res *mcp.CallToolResult) string {
	var parts []string
	for _, c := range res.Content {
		if text, ok := c.(*mcp.TextContent); ok {
			parts = append(parts, text.Text)
		}
	}
	return strings.Join(parts, "\n")
}
