package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var systemCmd = &cobra.Command{
	Use:   "system",
	Short: "System information and diagnostics",
	Long: "System information and diagnostics commands.\n\n" +
		"Subcommands: health, version, db-stats, cron, errors, dataengine.\n" +
		"The health command does not require authentication.",
}

var systemHealthCmd = &cobra.Command{
	Use:     "health",
	Short:   "Check system health (no auth required)",
	Long:    "Check system health. This endpoint does not require API authentication — use it to verify the instance is reachable.",
	Example: "  p202 system health --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewURLOnly()
		if err != nil {
			return err
		}
		data, err := c.Get("system/health", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var systemVersionCmd = &cobra.Command{
	Use:     "version",
	Short:   "Show Prosper202 and system version info",
	Long:    "Show the Prosper202 server version, PHP version, and system details.",
	Example: "  p202 system version --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/version", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var systemDBStatsCmd = &cobra.Command{
	Use:     "db-stats",
	Short:   "Show database table statistics",
	Long:    "Show database table row counts and sizes. Useful for monitoring data growth.",
	Example: "  p202 system db-stats --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/db-stats", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var systemCronCmd = &cobra.Command{
	Use:     "cron",
	Short:   "Show cron job status",
	Long:    "Show the status of scheduled cron jobs including last run time and next scheduled run.",
	Example: "  p202 system cron --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/cron", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var systemErrorsCmd = &cobra.Command{
	Use:   "errors",
	Short: "Show recent system errors",
	Long: "Show recent system errors. Use --limit to control how many errors are returned.\n" +
		"Useful for diagnosing tracking issues.",
	Example: "  p202 system errors --json\n  p202 system errors --limit 50 --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			params["limit"] = v
		}
		data, err := c.Get("system/errors", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var systemDataengineCmd = &cobra.Command{
	Use:     "dataengine",
	Short:   "Show data engine status",
	Long:    "Show the status of the data aggregation engine including queue size and processing state.",
	Example: "  p202 system dataengine --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/dataengine", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	systemErrorsCmd.Flags().StringP("limit", "l", "", "Max errors to show")

	systemCmd.AddCommand(systemHealthCmd, systemVersionCmd, systemDBStatsCmd,
		systemCronCmd, systemErrorsCmd, systemDataengineCmd)
	rootCmd.AddCommand(systemCmd)

	registerMeta("system health", commandMeta{
		Examples:     []string{"p202 system health --json"},
		OutputFields: []string{"status", "database", "api_version"},
		Related:      []string{"config test", "system version"},
	})
	registerMeta("system version", commandMeta{
		Examples:     []string{"p202 system version --json"},
		OutputFields: []string{"prosper_version", "php_version", "api_version"},
		Related:      []string{"system health"},
	})
	registerMeta("system db-stats", commandMeta{
		Examples:     []string{"p202 system db-stats --json"},
		OutputFields: []string{"table_name", "row_count", "data_size"},
	})
	registerMeta("system cron", commandMeta{
		Examples:     []string{"p202 system cron --json"},
		OutputFields: []string{"job_name", "last_run", "next_run", "status"},
	})
	registerMeta("system errors", commandMeta{
		Examples:     []string{"p202 system errors --json", "p202 system errors --limit 50 --json"},
		OutputFields: []string{"error_time", "error_message", "error_type"},
	})
	registerMeta("system dataengine", commandMeta{
		Examples:     []string{"p202 system dataengine --json"},
		OutputFields: []string{"status", "queue_size", "last_processed"},
	})
}
