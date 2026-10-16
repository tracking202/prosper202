//go:build !js

package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	osexec "os/exec"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"p202/internal/api"
	configpkg "p202/internal/config"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/spf13/cobra"
)

// mcpCallTimeout bounds one tool call; Claude gives up on a call after 240 s.
const mcpCallTimeout = 200 * time.Second

// mcpCallResult is what running one tool call produced.
type mcpCallResult struct {
	ExitCode int
	Stdout   []byte
	Stderr   []byte
}

// mcpToolRunner runs one tool call. The server runs each call as a child
// process, `p202 mcp call <tool> --input -` with the arguments on its
// stdin: the CLI keeps output modes and the staged flag in process globals,
// so two calls cannot share a process, and stdout here is the protocol
// channel.
var mcpToolRunner = runMCPChild

func init() {
	mcpCmd.RunE = func(cmd *cobra.Command, args []string) error {
		policy, err := newMCPServerPolicy(mcpReadOnly, api.StagedMode(), profileName)
		if err != nil {
			return err
		}
		// Anything that writes to stdout from here on would corrupt the
		// protocol: the transport keeps the real stdout, and the rest of the
		// process writes to stderr.
		protocolOut := os.Stdout
		os.Stdout = os.Stderr
		defer func() { os.Stdout = protocolOut }()
		server, err := newMCPServer(policy)
		if err != nil {
			return err
		}
		return server.Run(cmd.Context(), &mcp.IOTransport{Reader: os.Stdin, Writer: nopWriteCloser{protocolOut}})
	}
}

type nopWriteCloser struct{ io.Writer }

func (nopWriteCloser) Close() error { return nil }

// mcpServerPolicy is what the server was started with; every call's child
// gets it again, because a child inherits no flags (CLAUDE.md #14).
type mcpServerPolicy struct {
	readOnly bool
	staged   bool
	profile  string
}

// newMCPServerPolicy pins the profile when the server starts. Left to each
// call, the config's active profile would decide, so `p202 config use
// other` in another terminal would move a running AI session to another
// install without a word.
func newMCPServerPolicy(readOnly, staged bool, profile string) (mcpServerPolicy, error) {
	_, name, err := configpkg.LoadProfileWithName(profile)
	if err != nil {
		return mcpServerPolicy{}, withHint(fmt.Errorf("the MCP server needs an install to talk to: %w", err),
			"Set one up with `p202 config set-url <install address>` and `p202 config set-key <API key>`, or pass --profile.")
	}
	if name == "" {
		return mcpServerPolicy{}, validationError("the MCP server needs a named profile, and none is active").
			WithHint("`p202 config list-profiles` lists them; pass --profile <name>.")
	}
	return mcpServerPolicy{readOnly: readOnly, staged: staged, profile: name}, nil
}

func (p mcpServerPolicy) childArgs(tool string) []string {
	args := []string{"--profile=" + p.profile}
	if p.staged {
		args = append(args, "--staged")
	}
	args = append(args, "mcp", "call", tool, "--input=-")
	if p.readOnly {
		args = append(args, "--read-only")
	}
	return args
}

func newMCPServer(policy mcpServerPolicy) (*mcp.Server, error) {
	cat, err := buildMCPCatalog(rootCmd, policy.readOnly, policy.staged)
	if err != nil {
		return nil, err
	}
	version := rootCmd.Version
	if version == "" {
		version = "dev"
	}
	server := mcp.NewServer(&mcp.Implementation{Name: "prosper202", Title: "Prosper202", Version: version},
		&mcp.ServerOptions{Instructions: cat.Instructions, Capabilities: &mcp.ServerCapabilities{}})
	for _, t := range cat.Tools {
		name, readOnly := t.Name, t.Annotations.ReadOnlyHint
		destructive, openWorld := t.Annotations.DestructiveHint, t.Annotations.OpenWorldHint
		server.AddTool(&mcp.Tool{
			Name:        t.Name,
			Title:       t.Title,
			Description: t.Description,
			InputSchema: t.InputSchema,
			Annotations: &mcp.ToolAnnotations{
				Title:           t.Annotations.Title,
				ReadOnlyHint:    t.Annotations.ReadOnlyHint,
				DestructiveHint: &destructive,
				IdempotentHint:  t.Annotations.IdempotentHint,
				OpenWorldHint:   &openWorld,
			},
		}, func(ctx context.Context, req *mcp.CallToolRequest) (*mcp.CallToolResult, error) {
			input := []byte(req.Params.Arguments)
			if len(bytes.TrimSpace(input)) == 0 {
				input = []byte("{}")
			}
			ctx, cancel := context.WithTimeout(ctx, mcpCallTimeout)
			defer cancel()
			res, err := mcpToolRunner(ctx, policy.childArgs(name), input)
			if err != nil {
				return mcpRunFailure(name, readOnly, err), nil
			}
			return mcpShapeResult(res, readOnly, policy.staged), nil
		})
	}
	return server, nil
}

var (
	errMCPTimedOut  = fmt.Errorf("it ran past %s and was stopped", mcpCallTimeout)
	errMCPCancelled = errors.New("the AI app cancelled it")
)

func runMCPChild(ctx context.Context, args []string, input []byte) (mcpCallResult, error) {
	exe, err := os.Executable()
	if err != nil {
		return mcpCallResult{}, fmt.Errorf("finding the p202 binary: %w", err)
	}
	child := osexec.CommandContext(ctx, exe, args...)
	// The arguments are all the child reads: a command that would ask a
	// question then reads end-of-input and refuses with the flag that
	// answers it, instead of reading the protocol.
	child.Stdin = bytes.NewReader(input)
	child.WaitDelay = 5 * time.Second
	var stdout, stderr bytes.Buffer
	child.Stdout, child.Stderr = &stdout, &stderr
	err = child.Run()
	res := mcpCallResult{Stdout: stdout.Bytes(), Stderr: stderr.Bytes()}
	var exitErr *osexec.ExitError
	switch {
	case errors.Is(ctx.Err(), context.DeadlineExceeded):
		return res, errMCPTimedOut
	case errors.Is(ctx.Err(), context.Canceled):
		return res, errMCPCancelled
	case err == nil:
	case errors.As(err, &exitErr) && exitErr.ExitCode() >= 0:
		res.ExitCode = exitErr.ExitCode()
	default:
		return res, err
	}
	return res, nil
}

// mcpRunFailure is the result when the call did not finish. A write that
// was stopped may already have reached the install, so it is never
// reported as "did not run" (CLAUDE.md #13).
func mcpRunFailure(tool string, readOnly bool, err error) *mcp.CallToolResult {
	if readOnly {
		return mcpErrorResult(fmt.Sprintf("%s did not finish: %v", tool, err), "server", "Try again; a read changes nothing.")
	}
	return mcpErrorResult(fmt.Sprintf("%s did not finish: %v. The install may have made the change anyway.", tool, err), "server",
		"Look with the matching list or get tool before trying again, so the change is not made twice.")
}

// mcpShapeResult turns a call's output into the tool result: stdout on
// success (or what the command said on stderr when a write prints no data),
// the JSON error envelope from stderr on failure. An answer over
// mcpMaxResultChars is not cut short: a read is refused with how to ask for
// less; a write that succeeded is reported as succeeded, without the answer.
// mcpChangeIDInAnswer finds the proposal's id in a staged write's answer.
var mcpChangeIDInAnswer = regexp.MustCompile(`"change_id"\s*:\s*"(chg_[A-Za-z0-9]{1,64})"`)

// mcpChangeIDs lists every proposal id an answer names, once each, in order.
func mcpChangeIDs(out string) []string {
	var ids []string
	seen := map[string]bool{}
	for _, m := range mcpChangeIDInAnswer.FindAllStringSubmatch(out, -1) {
		if !seen[m[1]] {
			seen[m[1]] = true
			ids = append(ids, m[1])
		}
	}
	return ids
}

func mcpShapeResult(r mcpCallResult, readOnly, staged bool) *mcp.CallToolResult {
	out := strings.TrimSpace(string(r.Stdout))
	errText := strings.TrimSpace(string(r.Stderr))
	if r.ExitCode != 0 {
		text := errText
		if text == "" {
			text = out
		}
		if text == "" {
			return mcpErrorResult(fmt.Sprintf("the command exited %d without saying why", r.ExitCode), "server", "")
		}
		return &mcp.CallToolResult{IsError: true, Content: []mcp.Content{&mcp.TextContent{Text: text}}}
	}
	if out == "" {
		out = errText
		errText = ""
	}
	if out == "" {
		out = `{"ok":true}`
	}
	if size := utf8.RuneCountInString(out) + utf8.RuneCountInString(errText); size > mcpMaxResultChars {
		if ids := mcpChangeIDs(out); !readOnly && staged && len(ids) > 0 {
			// Under --staged a write records proposals; nothing changed yet.
			body, err := json.Marshal(map[string]interface{}{
				"ok": true, "staged": true, "change_ids": ids,
				"note": fmt.Sprintf("The change was proposed, not made: it waits for the user to apply it. Its answer is %d characters, too long to show; look at the proposals with change_show.", size),
			})
			if err != nil {
				return mcpErrorResult("the proposal was recorded, but its summary could not be encoded", "server", "List proposals with change_list.")
			}
			return &mcp.CallToolResult{Content: []mcp.Content{&mcp.TextContent{Text: string(body)}}}
		}
		if !readOnly && staged {
			// No proposal id in the answer: say neither that it was made nor
			// that it was only proposed.
			return &mcp.CallToolResult{Content: []mcp.Content{&mcp.TextContent{Text: fmt.Sprintf(
				`{"ok":true,"note":"The command succeeded. Its answer is %d characters, too long to show; change_list shows any proposal it recorded, and the matching get or list tool what it changed."}`, size)}}}
		}
		if !readOnly {
			return &mcp.CallToolResult{Content: []mcp.Content{&mcp.TextContent{Text: fmt.Sprintf(
				`{"ok":true,"note":"The change was made. Its answer is %d characters, too long to show; look at the result with the matching get or list tool."}`, size)}}}
		}
		return mcpErrorResult(fmt.Sprintf("the answer is %d characters, and this connector returns at most %d; nothing is shown rather than part of it", size, mcpMaxResultChars),
			"validation", "Ask for less: a smaller limit (with offset for the next page), a narrower filter or period, or fewer rows per report.")
	}
	content := []mcp.Content{&mcp.TextContent{Text: out}}
	if errText != "" {
		content = append(content, &mcp.TextContent{Text: "Notes from the command: " + errText})
	}
	return &mcp.CallToolResult{Content: content}
}

func mcpErrorResult(message, category, hint string) *mcp.CallToolResult {
	envelope := map[string]string{"category": category, "message": message}
	if hint != "" {
		envelope["hint"] = hint
	}
	body, err := json.Marshal(map[string]map[string]string{"error": envelope})
	if err != nil {
		body = []byte(`{"error":{"category":"server","message":"the tool failed, and its error could not be encoded"}}`)
	}
	return &mcp.CallToolResult{IsError: true, Content: []mcp.Content{&mcp.TextContent{Text: string(body)}}}
}
