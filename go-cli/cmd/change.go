package cmd

import (
	"fmt"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// The approval side of staged writes: any write run with --staged is
// recorded on the server as a change proposal instead of executing, and
// these commands are how a person (or an approval-gated harness) reviews and
// resolves the queue. Applying re-runs the write in full on the server —
// current state, current validation, the applier's key — so what was true at
// staging time is never trusted at apply time.

var changeCmd = &cobra.Command{
	Use:   "change",
	Short: "Review and resolve staged writes (proposals recorded by --staged)",
}

var changeListCmd = &cobra.Command{
	Use:   "list",
	Short: "List staged changes (yours; --all for every user's, admin only)",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		if status, _ := cmd.Flags().GetString("status"); status != "" {
			params["status"] = status
		}
		if all, _ := cmd.Flags().GetBool("all"); all {
			params["all"] = "1"
		}
		data, err := c.Get("staged-changes", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var changeShowCmd = &cobra.Command{
	Use:   "show <change_id>",
	Short: "Show one staged change, including its payload and preview",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("staged-changes/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var changeApplyCmd = &cobra.Command{
	Use:   "apply <change_id>",
	Short: "Apply a staged change (performs the recorded write)",
	Long: "Apply a staged change. This performs the recorded write, re-validated on the\n" +
		"server against current state and this key's authorization — a propose-only\n" +
		"(`stage`-scoped) key cannot apply; the applier needs the write scope for the\n" +
		"area the change touches. Running apply IS the approval, so it confirms\n" +
		"interactively unless --force is passed.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		force, _ := cmd.Flags().GetBool("force")
		if !force {
			// Show what is being approved before asking.
			data, err := c.Get("staged-changes/"+args[0], nil)
			if err != nil {
				return err
			}
			obj, perr := parseDataObject(data)
			if perr != nil {
				return fmt.Errorf("parsing staged change: %w", perr)
			}
			summary, _ := obj["summary"].(string)
			if !confirmPrompt("Apply staged change %s (%s)?", args[0], summary) {
				// stderr, not stdout: the go-cli contract keeps stdout empty
				// unless it carries data, so a --json caller never parses this
				// as a result. Every cancel path in crud.go does the same.
				output.Success("Cancelled; the change is still staged.")
				return nil
			}
		}

		data, err := c.Post("staged-changes/"+args[0]+"/apply", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var changeDiscardCmd = &cobra.Command{
	Use:   "discard <change_id>",
	Short: "Discard a staged change without performing it",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("staged-changes/"+args[0]+"/discard", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	changeListCmd.Flags().String("status", "", "Filter by status: staged, applying, applied, discarded, apply_interrupted")
	changeListCmd.Flags().Bool("all", false, "Every user's changes, not just yours (admin only)")
	changeApplyCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	changeCmd.AddCommand(changeListCmd, changeShowCmd, changeApplyCmd, changeDiscardCmd)
	rootCmd.AddCommand(changeCmd)
}
