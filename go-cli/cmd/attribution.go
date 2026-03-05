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
	Long: "Manage attribution models for multi-touch conversion tracking.\n\n" +
		"Subgroups: model, snapshot, export.\n" +
		"Model types: first_touch, last_touch, linear, time_decay, position_based, algorithmic.",
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
	Long: "Create an attribution model. Required: --model_name and --model_type.\n\n" +
		"Types: first_touch, last_touch, linear, time_decay, position_based, algorithmic.\n" +
		"Optional: --weighting_config (JSON), --is_active, --is_default.",
	Example: "  p202 attribution model create --model_name 'Last Touch' --model_type last_touch --json\n" +
		"  p202 attribution model create --model_name 'Custom' --model_type position_based --weighting_config '{\"first\":0.4,\"last\":0.4,\"middle\":0.2}' --json",
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
	Long: "Schedule an export of attribution data for a model.\n\n" +
		"Specify scope (global, campaign, landing_page), time window, format (csv, json),\n" +
		"and optional webhook URL for delivery.",
	Example: "  p202 attribution export schedule 1 --scope_type global --format csv --json\n" +
		"  p202 attribution export schedule 1 --scope_type campaign --scope_id 5 --format json --webhook_url https://webhook.example.com --json",
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

	modelFields := []string{"model_id", "model_name", "model_type", "weighting_config", "is_active", "is_default"}
	registerMeta("attribution model list", commandMeta{Examples: []string{"p202 attribution model list --json", "p202 attribution model list --type last_touch --json"}, OutputFields: modelFields, Related: []string{"attribution model get", "attribution model create"}})
	registerMeta("attribution model get", commandMeta{Examples: []string{"p202 attribution model get 1 --json"}, OutputFields: modelFields, Related: []string{"attribution model list", "attribution snapshot list"}})
	registerMeta("attribution model create", commandMeta{
		Examples: []string{"p202 attribution model create --model_name 'Last Touch' --model_type last_touch --json"}, OutputFields: modelFields, Related: []string{"attribution model list"}, Mutating: true,
		AllowedValues: map[string][]string{"model_type": {"first_touch", "last_touch", "linear", "time_decay", "position_based", "algorithmic"}},
	})
	registerMeta("attribution model update", commandMeta{Examples: []string{"p202 attribution model update 1 --model_name 'Renamed' --json"}, OutputFields: modelFields, Related: []string{"attribution model get"}, Mutating: true})
	registerMeta("attribution model delete", commandMeta{Examples: []string{"p202 attribution model delete 1 --force"}, Related: []string{"attribution model list"}, Mutating: true})
	registerMeta("attribution snapshot list", commandMeta{Examples: []string{"p202 attribution snapshot list 1 --json"}, OutputFields: []string{"snapshot_id", "scope_type", "created_at"}, Related: []string{"attribution model get", "attribution export list"}})
	registerMeta("attribution export list", commandMeta{Examples: []string{"p202 attribution export list 1 --json"}, OutputFields: []string{"export_id", "status", "format", "created_at"}, Related: []string{"attribution export schedule"}})
	registerMeta("attribution export schedule", commandMeta{
		Examples: []string{"p202 attribution export schedule 1 --scope_type global --format csv --json"}, OutputFields: []string{"export_id", "status"}, Related: []string{"attribution export list"}, Mutating: true,
		AllowedValues: map[string][]string{"scope_type": {"global", "campaign", "network"}, "format": {"csv", "json"}},
	})
}
