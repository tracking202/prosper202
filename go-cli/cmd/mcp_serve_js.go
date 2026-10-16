//go:build js

package cmd

import "github.com/spf13/cobra"

func init() {
	mcpCmd.RunE = func(cmd *cobra.Command, args []string) error {
		return validationError("the MCP server runs on your computer, not in a hosted run").
			WithHint("Hosted runs take `p202 mcp call <tool> --input <json>` only.")
	}
}
