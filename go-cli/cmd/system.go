package cmd

import (
	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var systemCmd = &cobra.Command{
	Use:   "system",
	Short: "System information and diagnostics",
}

var systemHealthCmd = &cobra.Command{
	Use:   "health",
	Short: "Check system health (no auth required)",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewURLOnly()
		if err != nil {
			return err
		}
		data, err := c.Get("system/health", nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var systemVersionCmd = &cobra.Command{
	Use:   "version",
	Short: "Show Prosper202 and system version info",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/version", nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var systemDBStatsCmd = &cobra.Command{
	Use:   "db-stats",
	Short: "Show database table statistics",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/db-stats", nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var systemCronCmd = &cobra.Command{
	Use:   "cron",
	Short: "Show cron job status",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/cron", nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

var systemErrorsCmd = &cobra.Command{
	Use:   "errors",
	Short: "Show recent system errors",
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
		output.Render(data, jsonOutput)
		return nil
	},
}

var systemDataengineCmd = &cobra.Command{
	Use:   "dataengine",
	Short: "Show data engine status",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/dataengine", nil)
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
		return nil
	},
}

func init() {
	systemErrorsCmd.Flags().StringP("limit", "l", "", "Max errors to show")

	systemCmd.AddCommand(systemHealthCmd, systemVersionCmd, systemDBStatsCmd,
		systemCronCmd, systemErrorsCmd, systemDataengineCmd)
	rootCmd.AddCommand(systemCmd)
}
