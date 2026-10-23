//go:build js

package cmd

// hostedArgsRefusal is the js/wasm build's entry check, run by Execute before
// cobra. That build is the hosted relay's engine, which runs one command per
// instance: `p202 mcp call`, `p202 mcp tools`, --version, and `p202 search` in
// the one shape the relay sends (hostedSearchArgs). Anything else is refused
// before a command runs, so a fault in the host cannot reach the rest of the
// CLI.
func hostedArgsRefusal(args []string) error {
	if len(args) == 1 && args[0] == "--version" {
		return nil
	}
	if c, _, err := rootCmd.Find(args); err == nil && (c == mcpCallCmd || c == mcpToolsCmd) {
		return nil
	}
	if hostedSearchArgs(args) {
		return nil
	}
	return validationError("a hosted run takes `p202 mcp call <tool> --input <json>`, `p202 mcp tools` or `p202 search --json [--limit <n>] -- <words>` only").
		WithHint("Run other commands with the p202 CLI on your computer.")
}

// hostedPinOutput runs after hostedArgsRefusal has taken args, before cobra:
// the relay reads a search's answer, so it is compact JSON, as for `p202 mcp`,
// and pinned here because cobra's help, Args and flag errors come before root's
// PersistentPreRunE, where `p202 mcp` gets it.
func hostedPinOutput(args []string) error {
	if !hostedSearchArgs(args) {
		return nil
	}
	mcpToolRun = true
	return resolveOutputMode(outputFlags{})
}

// hostedSearchArgs reports whether args are `search [--json] [--limit <n>] --
// <words>`: the words after "--", so none is read as a flag, and no other flag
// (no help) before it.
func hostedSearchArgs(args []string) bool {
	if len(args) < 2 || args[0] != "search" {
		return false
	}
	for i := 1; i < len(args); i++ {
		switch {
		case args[i] == "--":
			return true
		case args[i] == "--json":
		case args[i] == "--limit" && i+1 < len(args) && hostedDigits(args[i+1]):
			i++
		default:
			return false
		}
	}
	return false
}

func hostedDigits(s string) bool {
	if s == "" || len(s) > 3 {
		return false
	}
	for _, r := range s {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}
