//go:build js

package cmd

// hostedArgsRefusal is the js/wasm build's entry check. That build is the
// hosted relay's engine, which runs one command per instance: anything but
// `p202 mcp call`, `p202 mcp tools`, `p202 search` (offline: the relay's
// get_more_tools ranks tools with it) or --version is refused before a
// command runs, so a fault in the host cannot reach the rest of the CLI.
func hostedArgsRefusal(args []string) error {
	if len(args) == 1 && args[0] == "--version" {
		return nil
	}
	if c, _, err := rootCmd.Find(args); err == nil && (c == mcpCallCmd || c == mcpToolsCmd || c == searchCmd) {
		return nil
	}
	return validationError("a hosted run takes `p202 mcp call <tool> --input <json>`, `p202 mcp tools` or `p202 search <words>` only").
		WithHint("Run other commands with the p202 CLI on your computer.")
}
