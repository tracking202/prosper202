package cmd

import (
	"encoding/json"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var attributionCmd = &cobra.Command{
	Use:   "attribution",
	Short: "Attribution: models, snapshots and exports over your own clicks, plus platform-signed postbacks (SKAdNetwork, AdAttributionKit)",
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
			return validationError("required flag --model_name is missing")
		}
		if mtype == "" {
			return validationError("required flag --model_type is missing")
		}
		body := map[string]interface{}{
			"model_name": name,
			"model_type": mtype,
		}
		if v, _ := cmd.Flags().GetString("weighting_config"); v != "" {
			var parsed interface{}
			if err := json.Unmarshal([]byte(v), &parsed); err != nil {
				// A bad flag value is a validation error, not a bare
				// fmt.Errorf: the latter reports no category at all, so
				// the --json envelope's "validation" would come from the
				// default rather than from the code that knows.
				return validationError("invalid --weighting_config JSON: %v", err).
					WithHint("Pass a JSON object, e.g. --weighting_config '{\"first\":0.4,\"last\":0.6}'; quote it so the shell keeps it intact.")
			}
			body["weighting_config"] = parsed
		}
		if v, _ := cmd.Flags().GetString("is_active"); v != "" {
			body["is_active"] = v
		}
		if v, _ := cmd.Flags().GetString("is_default"); v != "" {
			body["is_default"] = v
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("attribution/models", body, idemKey)
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
				// A bad flag value is a validation error, not a bare
				// fmt.Errorf: the latter reports no category at all, so
				// the --json envelope's "validation" would come from the
				// default rather than from the code that knows.
				return validationError("invalid --weighting_config JSON: %v", err).
					WithHint("Pass a JSON object, e.g. --weighting_config '{\"first\":0.4,\"last\":0.6}'; quote it so the shell keeps it intact.")
			}
			body["weighting_config"] = parsed
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update")
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
	Short: "Delete an attribution model; supports --ids for bulk",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "attribution/models", "attribution model")
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
		// Shares runAttributionList with the postbacks/apps/conversion-values
		// lists so --limit/--offset are validated the same way (and before the
		// client is built) and --all traverses pages the same way. Hand-rolling
		// the body here is how this command drifted: it used to pass --limit
		// through to the server unchecked, which answered 422 where its three
		// siblings answered a validation error, and it had no --all at all.
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("scope_type"); v != "" {
			params["scope_type"] = v
		}
		return runAttributionList(cmd, "attribution/models/"+args[0]+"/snapshots", params)
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
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("attribution/models/"+args[0]+"/exports", body, idemKey)
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

	registerDeleteFlags(attrModelDeleteCmd, "model")
	registerIdempotencyKeyFlag(attrModelCreateCmd)
	registerIdempotencyKeyFlag(attrExportScheduleCmd)

	attrModelCmd.AddCommand(attrModelListCmd, attrModelGetCmd, attrModelCreateCmd, attrModelUpdateCmd, attrModelDeleteCmd)

	// Snapshot flags
	attrSnapshotListCmd.Flags().String("scope_type", "", "Filter: global, campaign, landing_page")
	registerAttributionListFlags(attrSnapshotListCmd)

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
