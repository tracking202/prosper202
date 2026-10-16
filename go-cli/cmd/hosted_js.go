//go:build js

package cmd

// hostedArgsRefusal is the js/wasm build's entry check. That build is the
// hosted relay's engine, which runs one tool call per instance: anything
// but `p202 mcp call`, `p202 mcp tools` or --version is refused before a
// command runs, so a fault in the host cannot reach the rest of the CLI.
func hostedArgsRefusal(args []string) error {
	if len(args) == 1 && args[0] == "--version" {
		return nil
	}
	if c, _, err := rootCmd.Find(args); err == nil && (c == mcpCallCmd || c == mcpToolsCmd) {
		return nil
	}
	return validationError("a hosted run takes `p202 mcp call <tool> --input <json>` or `p202 mcp tools` only").
		WithHint("Run other commands with the p202 CLI on your computer.")
}
