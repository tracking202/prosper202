package cmd

import (
	"p202/internal/api"

	"github.com/spf13/cobra"
)

var systemCmd = &cobra.Command{
	Use:   "system",
	Short: "System information and diagnostics",
}

var systemHealthCmd = &cobra.Command{
	Use:   "health",
	Short: "Check system health and the TLS certificate (no auth required)",
	Long: "Calls the unauthenticated system/health endpoint and, for an https base URL,\n" +
		"checks the TLS certificate of its host first: a verified handshake on its own\n" +
		"connection, with no HTTP request, so an expired certificate is reported as\n" +
		"one instead of as a network error from the API call.\n\n" +
		"tls_status: ok, expiring (expires within --cert-warn-days), expired,\n" +
		"hostname_mismatch, unknown_authority, invalid, unreachable; not_used for an\n" +
		"http:// URL. tls_not_after, tls_days_left and tls_issuer describe the\n" +
		"certificate the server sent (null when none arrived); tls_detail says why.\n\n" +
		"Like `p202 rotator check`, it exits 5 (partial_failure) when tls_status is\n" +
		"anything but ok or not_used, with the health object still printed on stdout.\n" +
		"When only the API call fails, it exits as that error does (3 network, 4 server).",
	Example: "  p202 system health\n" +
		"  p202 system health --cert-warn-days 30 --json",
	RunE: runSystemHealth,
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
		render(data)
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
		render(data)
		return nil
	},
}

var systemCronCmd = &cobra.Command{
	Use:   "cron",
	Short: "Show whether cron is ticking: rows and last run per job type",
	Long: "Summarizes 202_cronjobs: per cronjob_type, its row count and last run (time\n" +
		"and age), then the last execution recorded in 202_cronjob_logs with its age.\n" +
		"Rows record the slot a job ran for: the daily row is noon of its day (so\n" +
		"before noon it reads as later today), the hourly row the start of its hour.\n\n" +
		"hourl and secon are 'hourly' and 'second' cut to the column's char(5) and are\n" +
		"labelled so; when one holds more than 100 rows, a note explains the server bug\n" +
		"that adds a row every minute.\n\n" +
		"Like `p202 rotator check`, it exits 5 (partial_failure) when the last\n" +
		"execution is older than 5 minutes (stale) or none is recorded (never_ran),\n" +
		"with the summary still printed on stdout.\n\n" +
		"--raw prints every row as the server returns it; under --json it adds the\n" +
		"server's jobs and recent_logs arrays to the summary object.",
	Example: "  p202 system cron\n" +
		"  p202 system cron --json\n" +
		"  p202 system cron --raw --json",
	RunE: runSystemCron,
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
		render(data)
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
		render(data)
		return nil
	},
}

func init() {
	systemErrorsCmd.Flags().StringP("limit", "l", "", "Max errors to show")
	systemHealthCmd.Flags().Int("cert-warn-days", 21, "Report the certificate as expiring when it expires within this many days (0 turns the warning off)")
	systemCronCmd.Flags().Bool("raw", false, "Print every 202_cronjobs row as the server returns it (with --json: add jobs and recent_logs to the summary)")

	systemCmd.AddCommand(systemHealthCmd, systemVersionCmd, systemDBStatsCmd,
		systemCronCmd, systemErrorsCmd, systemDataengineCmd)
	rootCmd.AddCommand(systemCmd)
}
