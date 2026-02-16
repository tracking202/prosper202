package cmd

import (
	"encoding/json"
	"fmt"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var attributionCmd = &cobra.Command{
	Use:   "attribution",
	Short: "Manage attribution models, snapshots, and exports",
}

// --- Model subcommands ---

var attrModelCmd = &cobra.Command{
	Use:   "model",
	Short: "Manage attribution models",
}

var attrModelListCmd = &cobra.Command{
	Use:   "list",
	Short: "List attribution models",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("type"); v != "" {
			params["type"] = v
		}
		data, err := c.Get("attribution/models", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get an attribution model",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/models/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create an attribution model",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		name, _ := cmd.Flags().GetString("model_name")
		mtype, _ := cmd.Flags().GetString("model_type")
		if name == "" {
			return fmt.Errorf("required flag --model_name is missing")
		}
		if mtype == "" {
			return fmt.Errorf("required flag --model_type is missing")
		}
		body := map[string]interface{}{
			"model_name": name,
			"model_type": mtype,
		}
		if v, _ := cmd.Flags().GetString("weighting_config"); v != "" {
			var parsed interface{}
			if err := json.Unmarshal([]byte(v), &parsed); err != nil {
				return fmt.Errorf("invalid --weighting_config JSON: %w", err)
			}
			body["weighting_config"] = parsed
		}
		if v, _ := cmd.Flags().GetString("is_active"); v != "" {
			body["is_active"] = v
		}
		if v, _ := cmd.Flags().GetString("is_default"); v != "" {
			body["is_default"] = v
		}
		data, err := c.Post("attribution/models", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update an attribution model",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		for _, f := range []string{"model_name", "model_type", "is_active", "is_default"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if v, _ := cmd.Flags().GetString("weighting_config"); v != "" {
			var parsed interface{}
			if err := json.Unmarshal([]byte(v), &parsed); err != nil {
				return fmt.Errorf("invalid --weighting_config JSON: %w", err)
			}
			body["weighting_config"] = parsed
		}
		if len(body) == 0 {
			return fmt.Errorf("no fields specified; pass at least one flag to update")
		}
		data, err := c.Put("attribution/models/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete an attribution model and all related data",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete attribution model %s and all related data? [y/N] ", args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("attribution/models/" + args[0]); err != nil {
			return err
		}
		output.Success("Attribution model %s deleted.", args[0])
		return nil
	},
}

// --- Snapshot subcommands ---

var attrSnapshotCmd = &cobra.Command{
	Use:   "snapshot",
	Short: "View attribution snapshots",
}

var attrSnapshotListCmd = &cobra.Command{
	Use:   "list <model_id>",
	Short: "List snapshots for a model",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		for _, f := range []string{"scope_type", "limit", "offset"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				params[f] = v
			}
		}
		data, err := c.Get("attribution/models/"+args[0]+"/snapshots", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// --- Export subcommands ---

var attrExportCmd = &cobra.Command{
	Use:   "export",
	Short: "Manage attribution exports",
}

var attrExportListCmd = &cobra.Command{
	Use:   "list <model_id>",
	Short: "List exports for a model",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/models/"+args[0]+"/exports", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrExportScheduleCmd = &cobra.Command{
	Use:   "schedule <model_id>",
	Short: "Schedule an attribution export",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		for _, f := range []string{"scope_type", "scope_id", "start_hour", "end_hour", "format", "webhook_url"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		data, err := c.Post("attribution/models/"+args[0]+"/exports", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	// Model flags
	attrModelListCmd.Flags().StringP("type", "t", "", "Filter by type: first_touch, last_touch, linear, time_decay, position_based, algorithmic")

	attrModelCreateCmd.Flags().String("model_name", "", "Model name (required)")
	attrModelCreateCmd.Flags().String("model_type", "", "Type: first_touch, last_touch, linear, time_decay, position_based, algorithmic (required)")
	attrModelCreateCmd.Flags().String("weighting_config", "", "Weighting config as JSON")
	attrModelCreateCmd.Flags().String("is_active", "", "1=active, 0=inactive")
	attrModelCreateCmd.Flags().String("is_default", "", "1=default, 0=not default")

	attrModelUpdateCmd.Flags().String("model_name", "", "Model name")
	attrModelUpdateCmd.Flags().String("model_type", "", "Model type")
	attrModelUpdateCmd.Flags().String("weighting_config", "", "Weighting config as JSON")
	attrModelUpdateCmd.Flags().String("is_active", "", "1=active, 0=inactive")
	attrModelUpdateCmd.Flags().String("is_default", "", "1=default, 0=not")

	attrModelDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	attrModelCmd.AddCommand(attrModelListCmd, attrModelGetCmd, attrModelCreateCmd, attrModelUpdateCmd, attrModelDeleteCmd)

	// Snapshot flags
	attrSnapshotListCmd.Flags().String("scope_type", "", "Filter: global, campaign, landing_page")
	attrSnapshotListCmd.Flags().StringP("limit", "l", "", "Max results")
	attrSnapshotListCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	attrSnapshotCmd.AddCommand(attrSnapshotListCmd)

	// Export flags
	attrExportScheduleCmd.Flags().String("scope_type", "", "Scope: global, campaign, landing_page")
	attrExportScheduleCmd.Flags().String("scope_id", "", "Scope ID")
	attrExportScheduleCmd.Flags().String("start_hour", "", "Start timestamp")
	attrExportScheduleCmd.Flags().String("end_hour", "", "End timestamp")
	attrExportScheduleCmd.Flags().String("format", "", "Export format: csv, json")
	attrExportScheduleCmd.Flags().String("webhook_url", "", "Webhook URL for delivery")

	attrExportCmd.AddCommand(attrExportListCmd, attrExportScheduleCmd)

	attributionCmd.AddCommand(attrModelCmd, attrSnapshotCmd, attrExportCmd)
	rootCmd.AddCommand(attributionCmd)
}
