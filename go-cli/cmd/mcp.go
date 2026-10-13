package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var mcpReadOnly bool
var mcpCallInput string

// mcpToolRun is set for the `p202 mcp` commands (root's PersistentPreRunE):
// their output is compact JSON, or the protocol, whatever P202_OUTPUT, the
// config default or the environment would choose, because the reader is a
// program.
var mcpToolRun bool

var mcpCmd = &cobra.Command{
	Use:   "mcp",
	Short: "Serve Prosper202 to AI assistants over MCP (stdio), for Claude Desktop, Claude Code, Cursor and others",
	Long: "Runs an MCP (Model Context Protocol) server on stdin/stdout. An AI app starts it\n" +
		"and calls its tools, each of which runs one p202 command against your install with\n" +
		"the profile that is active when the server starts (or --profile).\n\n" +
		"  claude mcp add prosper202 -- p202 mcp --read-only    (recommended to start)\n" +
		"  claude mcp add prosper202 -- p202 --staged mcp       (writes become proposals)\n" +
		"  claude mcp add prosper202 -- p202 mcp\n\n" +
		"Without --read-only or --staged, a write tool (deletes included) runs as soon as\n" +
		"the AI app's approval prompt is answered: the app's prompt replaces the CLI's own\n" +
		"question. Do not set the app to always allow the delete tools. With --staged, you\n" +
		"apply a proposal yourself with `p202 change apply`; the assistant cannot.\n\n" +
		"Claude Desktop and Cursor take the same command in their MCP settings:\n" +
		"  {\"mcpServers\": {\"prosper202\": {\"command\": \"/path/to/p202\", \"args\": [\"mcp\", \"--read-only\"]}}}\n\n" +
		"`p202 mcp tools` lists the tools; `p202 mcp call <tool> --input <json>` runs one.",
	Args: cobra.NoArgs,
}

var mcpToolsCmd = &cobra.Command{
	Use:   "tools",
	Short: "List the MCP tools as JSON (names, input schemas, read/write hints)",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		cat, err := buildMCPCatalog(rootCmd, mcpReadOnly, api.StagedMode())
		if err != nil {
			return err
		}
		var buf bytes.Buffer
		enc := json.NewEncoder(&buf)
		enc.SetEscapeHTML(false)
		enc.SetIndent("", "  ")
		if err := enc.Encode(cat); err != nil {
			return fmt.Errorf("encoding the tool list: %w", err)
		}
		_, err = os.Stdout.Write(buf.Bytes())
		return err
	},
}

var mcpCallCmd = &cobra.Command{
	Use:   "call <tool>",
	Short: "Run one MCP tool with JSON arguments, as an AI app's call would",
	Example: "  p202 mcp call campaign_list --input '{\"limit\": \"5\"}'\n" +
		"  echo '{\"period\": \"last7\"}' | p202 mcp call report_summary --input -",
	Args: cobra.ExactArgs(1),
	RunE: runMCPCall,
}

func runMCPCall(cmd *cobra.Command, args []string) error {
	spec, ok := findMCPSpec(args[0])
	if !ok {
		return validationError("there is no MCP tool %q", args[0]).
			WithHint("`p202 mcp tools` lists the tools.")
	}
	if mcpReadOnly && !spec.readOnly() {
		return validationError("%s changes data, and this server is read-only", spec.Name).
			WithHint("Use a read tool, or run the server without --read-only.")
	}
	if api.StagedMode() && spec.NotStaged {
		return validationError("%s acts on a proposed change itself, and this server only proposes changes", spec.Name).
			WithHint("Applying or discarding a proposal is the user's to do: `p202 change apply <id>` or `p202 change discard <id>`.")
	}
	input := []byte(mcpCallInput)
	if mcpCallInput == "-" {
		// The arguments come on stdin when a program runs the call: a command
		// line has a length limit (128 KiB per argument on Linux, about 12 KB
		// in all under js/wasm) and shows in a process list.
		read, err := io.ReadAll(io.LimitReader(os.Stdin, mcpMaxInputBytes+1))
		if err != nil {
			return fmt.Errorf("reading the tool's arguments from stdin: %w", err)
		}
		input = read
	}
	if len(input) > mcpMaxInputBytes {
		return validationError("the arguments for %s are over %d bytes", spec.Name, mcpMaxInputBytes).
			WithHint("Send less: a tool's arguments are ids, filters and short values.")
	}
	argv, err := mcpToolArgv(rootCmd, spec, input)
	if err != nil {
		return err
	}
	// The tool's command runs in this process, on the same command tree.
	// Root's persistent flags keep what this invocation parsed (nothing
	// resets them between the two runs), so --profile and --staged carry
	// over; TestMCPCallCarriesProfileAndStaged holds that.
	rootCmd.SetArgs(argv)
	return rootCmd.Execute()
}

func init() {
	mcpCmd.Flags().BoolVar(&mcpReadOnly, "read-only", false, "Serve only the tools that read; writes are refused")
	mcpToolsCmd.Flags().BoolVar(&mcpReadOnly, "read-only", false, "List only the tools that read")
	mcpCallCmd.Flags().BoolVar(&mcpReadOnly, "read-only", false, "Refuse a tool that changes data")
	mcpCallCmd.Flags().StringVar(&mcpCallInput, "input", "{}", "The tool's arguments as a JSON object, or - to read them from stdin")
	mcpCmd.AddCommand(mcpToolsCmd, mcpCallCmd)
	rootCmd.AddCommand(mcpCmd)
}
