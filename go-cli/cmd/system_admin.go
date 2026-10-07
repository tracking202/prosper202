package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// Account › Settings (202-account/administration.php) and the URLs of
// Account › API integrations, for this install, over the Administration API
// (GET /system/info, /system/login-log, /system/retention, /system/isp-lookup,
// /system/integrations; PUT /system/retention and /system/isp-lookup; POST
// /system/retention/delete-before):
//
//	p202 system info                       versions, PHP limits, clicks, database size, cron, DataEngine
//	p202 system login-log                  the sign-in attempts the page lists
//	p202 system retention show|set|delete-before
//	p202 system isp-lookup show|enable|disable
//	p202 system integrations               the IPN / postback URLs to paste into each network
//	p202 system metrics                    GET /system/metrics (sync counters, queue, alerts)
//
// The server asks for an Admin or Super user key whose role has the page's
// permission (access_to_settings; access_to_api_integrations for the
// integrations). None of the writes can be staged: the server refuses
// staged=1 on them, and the commands refuse --staged before any request
// (CLAUDE.md #14). AutoCron and "update available" are not here: the page
// reaches a remote Prosper202 service for both.

const (
	systemSettingsNeeds     = "Needs an Admin or Super user key whose role has access_to_settings (as Account › Settings asks)"
	systemIntegrationsNeeds = "Needs an Admin or Super user key whose role has access_to_api_integrations (as Account › API integrations asks)"
	// systemMaxAutoDeleteDays is AdministrationController::MAX_AUTO_DELETE_DAYS.
	systemMaxAutoDeleteDays = 36500
	// systemLoginLogMax is AdministrationController::LOGIN_LOG_MAX.
	systemLoginLogMax = 500
)

var systemDayPattern = regexp.MustCompile(`^[0-9]{4}-[0-9]{2}-[0-9]{2}$`)

// systemAdminError attaches the next step to a failed Administration request:
// a 404 means the server predates these endpoints (the generic 404 hint, "run
// the matching list", does not apply to a fixed path).
func systemAdminError(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 404 {
		return withHint(err, "This server has no Administration API (capabilities features.administration): upgrade Prosper202, or use Account › Settings in the UI.")
	}
	return err
}

// refuseStagedSystem stops a write before any request when --staged is on.
func refuseStagedSystem(cmd *cobra.Command) error {
	if !api.StagedMode() {
		return nil
	}
	hint := "Drop --staged to perform it directly."
	if cmd.Flags().Lookup("dry-run") != nil {
		hint = "Drop --staged. --dry-run shows what it would do without writing anything."
	}
	return validationError("--staged cannot apply to `%s`: the server writes this setting at once and records no proposal for it, so nothing was sent", cmd.CommandPath()).
		WithHint("%s", hint)
}

// systemGet is one plain Administration read: a GET, the answer rendered.
func systemGet(path string, params map[string]string) error {
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Get(path, params)
	if err != nil {
		return systemAdminError(err)
	}
	render(data)
	return nil
}

// systemMalformed is a 2xx answer that is not the Administration API's.
func systemMalformed(what string, err error) error {
	return &CLIError{
		Category: "server",
		ExitCode: ExitServer,
		Message:  fmt.Sprintf("%s: the server's answer is not the Administration API's (%v)", what, err),
		Hint:     "Check the server with `p202 system health`; a proxy in front of it may have answered instead.",
		Cause:    err,
	}
}

// ─── p202 system info / login-log / metrics / integrations ─────────────

var systemInfoCmd = &cobra.Command{
	Use:   "info",
	Short: "What Account › Settings shows: versions, PHP limits, memcache, clicks recorded, database size, cron, DataEngine",
	Long: "The Prosper202 code and database versions (database_upgrade_needed when they differ), PHP\n" +
		"and MySQL versions, memcache, the PHP limits uploads and long reports run under, clicks\n" +
		"recorded to date, database size, when the cron job last ran, and the DataEngine's progress.\n" +
		"Whether a newer release exists is not reported: the UI asks a remote service for that.\n\n" +
		systemSettingsNeeds + ", and system:read.",
	Example: "  p202 system info\n  p202 system info --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return systemGet("system/info", nil)
	},
}

var systemLoginLogCmd = &cobra.Command{
	Use:   "login-log",
	Short: "Sign-in attempts to this install, newest first: who, when, from which IP, passed or failed",
	Long: "The attempts Account › Settings lists (its last 50 by default; --limit up to 500). Each row\n" +
		"is the user name typed, the time (login_time, unix seconds), the IP address and whether it\n" +
		"passed (login_success). Throttled and token-refused attempts are not recorded.\n\n" +
		systemSettingsNeeds + ", and system:read.",
	Example: "  p202 system login-log\n  p202 system login-log --limit 200 --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if cmd.Flags().Changed("limit") {
			v, _ := cmd.Flags().GetString("limit")
			n, err := strconv.Atoi(v)
			if err != nil || n < 1 || n > systemLoginLogMax || strconv.Itoa(n) != v {
				return validationError("--limit must be a whole number from 1 to %d, got %q", systemLoginLogMax, v).
					WithHint("For example --limit 100; without it the last 50 attempts are listed, as on the page.")
			}
			params["limit"] = v
		}
		return systemGet("system/login-log", params)
	},
}

var systemMetricsCmd = &cobra.Command{
	Use:   "metrics",
	Short: "Server metrics: sync counters, the sync job queue (queued, running, lag), recent spans and active alerts",
	Long: "GET /system/metrics. alerts.active lists a failure spike or a queue lag past its threshold\n" +
		"(P202_ALERT_FAILURE_SPIKE, P202_ALERT_QUEUE_LAG_SECONDS on the server).\n\n" +
		"Needs an Admin or Super user key with system:read.",
	Example: "  p202 system metrics --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return systemGet("system/metrics", nil)
	},
}

var systemIntegrationsCmd = &cobra.Command{
	Use:   "integrations",
	Short: "The URLs to paste into ClickBank, JVZoo, Zaxaa, Slack and PayKickstart for this install",
	Long: "Each integration's notification URL (INS, IPN, ZPN, webhook) on this install's tracking base,\n" +
		"and whether its secret is stored (secret_stored; set it with `p202 user prefs update <user_id>`). The\n" +
		"secrets themselves are never shown. --json adds base_url; --wide shows the URLs in full.\n\n" +
		systemIntegrationsNeeds + ", and system:read.",
	Example: "  p202 system integrations\n  p202 system integrations --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/integrations", nil)
		if err != nil {
			return systemAdminError(err)
		}
		if jsonOutput || ndjsonOutput {
			render(data)
			return nil
		}
		var resp struct {
			Data struct {
				BaseURL      string                   `json:"base_url"`
				Integrations []map[string]interface{} `json:"integrations"`
			} `json:"data"`
		}
		if err := json.Unmarshal(data, &resp); err != nil || resp.Data.Integrations == nil {
			if err == nil {
				err = errors.New("integrations is missing")
			}
			return systemMalformed("reading the integrations", err)
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": resp.Data.Integrations})
		if err != nil {
			return fmt.Errorf("encoding the integrations: %w", err)
		}
		render(encoded)
		return nil
	},
}

// ─── p202 system retention ──────────────────────────────────────────────

var systemRetentionCmd = &cobra.Command{
	Use:   "retention",
	Short: "Click-data retention: automatic deletion after N days, and a one-off deletion before a date",
	Long: "Account › Settings › Click data, for the whole install (every account's clicks). The cron job\n" +
		"does the deleting, in batches: `show` says what is set, `set` changes the automatic deletion,\n" +
		"`delete-before` schedules the one-off deletion. Setup data (campaigns, trackers, landing\n" +
		"pages) is never deleted.\n\n" + systemSettingsNeeds + ".",
}

var systemRetentionShowCmd = &cobra.Command{
	Use:   "show",
	Short: "Show the automatic deletion (auto_delete_days; 0 keeps everything) and any scheduled one-off deletion",
	Long: "auto_delete_days: click data older than this many days is deleted each night (0 keeps it).\n" +
		"scheduled_deletion: a one-off deletion `delete-before` scheduled — the cron job deletes every\n" +
		"click below through_click_id (from before the day `before`); clicks_remaining counts those\n" +
		"still there. null when none was scheduled.",
	Example: "  p202 system retention show --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return systemGet("system/retention", nil)
	},
}

// retentionState is what GET /system/retention answers, read strictly.
type retentionState struct {
	Data struct {
		AutoDeleteDays *int `json:"auto_delete_days"`
	} `json:"data"`
}

var systemRetentionSetCmd = &cobra.Command{
	Use:   "set --days <n>",
	Short: "Set the automatic click-data deletion to N days (0 keeps every click); asks when it would keep less",
	Long: "Sets how many days of click data the cron job's automatic deletion keeps (0 to 36500; 0 keeps\n" +
		"everything), for the whole install. When the new value keeps less than the current one (turning\n" +
		"deletion on, or a shorter period) it asks first, because what the cron job deletes cannot be\n" +
		"recovered; --force skips the question. Cannot be staged.\n\n" +
		"Known issue: the cron job's automatic deletion (AutoOptimizeDatabase in 202-cronjobs/index.php)\n" +
		"currently deletes no clicks; the setting is stored as the page stores it. `delete-before` works.\n\n" +
		systemSettingsNeeds + ", and system:write.",
	Example: "  p202 system retention set --days 180\n  p202 system retention set --days 0",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := refuseStagedSystem(cmd); err != nil {
			return err
		}
		if !cmd.Flags().Changed("days") {
			return validationError("--days is required: how many days of click data to keep (0 keeps everything)").
				WithHint("E.g. --days 180; `p202 system retention show` says what is set now.")
		}
		v, _ := cmd.Flags().GetString("days")
		days, err := strconv.Atoi(v)
		if err != nil || days < 0 || days > systemMaxAutoDeleteDays || strconv.Itoa(days) != v {
			return validationError("--days must be a whole number of days from 0 to %d, got %q", systemMaxAutoDeleteDays, v).
				WithHint("0 keeps every click; e.g. --days 365 keeps a year.")
		}
		force, _ := cmd.Flags().GetBool("force")
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/retention", nil)
		if err != nil {
			return systemAdminError(err)
		}
		var current retentionState
		if err := json.Unmarshal(data, &current); err != nil || current.Data.AutoDeleteDays == nil {
			if err == nil {
				err = errors.New("auto_delete_days is missing")
			}
			return systemMalformed("reading the retention setting", err)
		}
		was := *current.Data.AutoDeleteDays
		deletesMore := days > 0 && (was == 0 || days < was)
		if deletesMore && !force {
			keep := "every click"
			if was > 0 {
				keep = fmt.Sprintf("%d days of click data", was)
			}
			fmt.Fprintf(os.Stderr, "Now kept: %s. This sets the cron job's automatic deletion to remove click data older than %d days, for every account on this install. Deleted clicks cannot be recovered.\n", keep, days)
			ok, err := confirmAction(cmd, "Keep only %d days of click data?", days)
			if err != nil {
				return err
			}
			if !ok {
				fmt.Fprintln(os.Stderr, "Cancelled.")
				return nil
			}
		}
		data, err = c.Put("system/retention", map[string]interface{}{"auto_delete_days": days})
		if err != nil {
			return systemAdminError(err)
		}
		render(data)
		if days == 0 {
			output.Success("Automatic deletion is off; click data is kept.")
		} else {
			output.Success("Automatic deletion is set to click data older than %d days (was: %s).", days, retentionWords(was))
		}
		return nil
	},
}

func retentionWords(days int) string {
	if days == 0 {
		return "kept"
	}
	return fmt.Sprintf("%d days", days)
}

// deleteBeforeAnswer is what POST /system/retention/delete-before answers,
// read strictly: a count that is missing is a malformed answer, never 0.
type deleteBeforeAnswer struct {
	Data struct {
		DryRun         bool             `json:"dry_run"`
		Scheduled      bool             `json:"scheduled"`
		Before         string           `json:"before"`
		Timezone       string           `json:"timezone"`
		ThroughClickID *int64           `json:"through_click_id"`
		Clicks         *int64           `json:"clicks"`
		Rows           map[string]int64 `json:"rows"`
	} `json:"data"`
}

func readDeleteBeforeAnswer(data []byte, what string) (deleteBeforeAnswer, error) {
	var a deleteBeforeAnswer
	if err := json.Unmarshal(data, &a); err != nil {
		return a, systemMalformed(what, err)
	}
	if a.Data.Clicks == nil || a.Data.Rows == nil || a.Data.Before == "" {
		return a, systemMalformed(what, errors.New("before, clicks or rows is missing"))
	}
	return a, nil
}

// rowsInWords lists the non-zero per-table counts, largest first.
func rowsInWords(rows map[string]int64) string {
	tables := make([]string, 0, len(rows))
	for t, n := range rows {
		if n > 0 {
			tables = append(tables, t)
		}
	}
	sort.Slice(tables, func(i, j int) bool {
		if rows[tables[i]] != rows[tables[j]] {
			return rows[tables[i]] > rows[tables[j]]
		}
		return tables[i] < tables[j]
	})
	parts := make([]string, 0, len(tables))
	for _, t := range tables {
		parts = append(parts, fmt.Sprintf("%s %d", t, rows[t]))
	}
	return strings.Join(parts, ", ")
}

func runSystemRetentionDeleteBefore(cmd *cobra.Command, _ []string) error {
	if err := refuseStagedSystem(cmd); err != nil {
		return err
	}
	date, _ := cmd.Flags().GetString("date")
	date = strings.TrimSpace(date)
	if date == "" {
		return validationError("--date is required: click data from before this day is deleted (YYYY-MM-DD, in the account's time zone)").
			WithHint("E.g. --date 2026-01-01; run it with --dry-run first to see what it would delete.")
	}
	if _, err := time.Parse("2006-01-02", date); err != nil || !systemDayPattern.MatchString(date) {
		return validationError("--date must be a day written YYYY-MM-DD, got %q", date).
			WithHint("E.g. --date 2026-01-01.")
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	force, _ := cmd.Flags().GetBool("force")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}

	// The preview always runs first: what the deletion would remove, and the
	// click id the write must carry back.
	data, err := c.PostUpdate("system/retention/delete-before", map[string]string{"dry_run": "1"}, map[string]interface{}{"before": date})
	if err != nil {
		return systemAdminError(err)
	}
	check, err := readDeleteBeforeAnswer(data, "previewing the deletion")
	if err != nil {
		return err
	}
	where := fmt.Sprintf("from before %s (%s)", check.Data.Before, check.Data.Timezone)
	if dryRun {
		render(data)
		if check.Data.ThroughClickID == nil {
			output.Success("Dry run: no click is %s, so there is nothing to delete. Nothing was scheduled.", where)
		} else {
			output.Success("Dry run: %d click(s) %s would be deleted by the cron job, for every account on this install (rows: %s). Nothing was scheduled; drop --dry-run to schedule it (it asks first).",
				*check.Data.Clicks, where, rowsInWords(check.Data.Rows))
		}
		return nil
	}
	if check.Data.ThroughClickID == nil {
		render(data)
		output.Success("No click is %s, so there is nothing to delete. Nothing was scheduled.", where)
		return nil
	}
	through := *check.Data.ThroughClickID
	if !force {
		fmt.Fprintf(os.Stderr, "%d click(s) %s, of every account on this install, will be deleted by the cron job in batches: every click below id %d (rows: %s). Setup data is kept. This cannot be undone.\n",
			*check.Data.Clicks, where, through, rowsInWords(check.Data.Rows))
		ok, err := confirmAction(cmd, "Delete the click data from before %s?", check.Data.Before)
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}

	data, err = c.PostUpdate("system/retention/delete-before", nil, map[string]interface{}{"before": date, "through_click_id": through})
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && apiErr.Status == 409 {
			return withHint(err, "The clicks before that day changed between the preview and the write, so nothing was scheduled. Run the same command again: it previews again and asks again.")
		}
		return systemAdminError(err)
	}
	done, err := readDeleteBeforeAnswer(data, "scheduling the deletion")
	if err != nil {
		return err
	}
	render(data)
	if !done.Data.Scheduled {
		output.Success("No click is %s any more, so nothing was scheduled.", where)
		return nil
	}
	output.Success("Scheduled: the cron job deletes the %d click(s) %s in batches (5,000 rows a table each run). `p202 system retention show` counts what remains.", *done.Data.Clicks, where)
	return nil
}

var systemRetentionDeleteBeforeCmd = &cobra.Command{
	Use:   "delete-before --date YYYY-MM-DD",
	Short: "Schedule the deletion of all click data from before a day (irreversible; previews and asks first)",
	Long: "Schedules what Account › Settings › Advanced › \"Delete click data from before\" does: the\n" +
		"cron job deletes, in batches, every click of every account on this install recorded before\n" +
		"the newest click at or before midnight that begins --date (the account's time zone), from\n" +
		"the ten click tables. Setup data is kept. It cannot be undone.\n\n" +
		"It always previews first (POST …/delete-before?dry_run=1) and says how many clicks and rows\n" +
		"would go, then asks (--force skips the question, never the preview; --dry-run ends with\n" +
		"the preview). The write carries the click id the preview named and the server schedules only\n" +
		"that, so it never schedules more than was shown. Cannot be staged.\n\n" + systemSettingsNeeds + ", and system:write.",
	Example: "  p202 system retention delete-before --date 2026-01-01 --dry-run\n" +
		"  p202 system retention delete-before --date 2026-01-01",
	Args: cobra.NoArgs,
	RunE: runSystemRetentionDeleteBefore,
}

// ─── p202 system isp-lookup ─────────────────────────────────────────────

var systemIspLookupCmd = &cobra.Command{
	Use:   "isp-lookup",
	Short: "MaxMind ISP and carrier lookup on your trackers' clicks: show, enable, disable",
	Long: "Account › Settings › ISP and carrier lookup. It needs the MaxMind ISP database\n" +
		"(GeoIP2-ISP.mmdb, or legacy GeoIPISP.dat) in the directory `show` names. The switch is the\n" +
		"key's own user's, and applies to that user's trackers; live traffic picks a change up within\n" +
		"five minutes.\n\n" + systemSettingsNeeds + ".",
}

var systemIspLookupShowCmd = &cobra.Command{
	Use:     "show",
	Short:   "Whether ISP lookup is on, and whether the ISP database it needs is in place",
	Example: "  p202 system isp-lookup show --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return systemGet("system/isp-lookup", nil)
	},
}

func setIspLookup(cmd *cobra.Command, enabled bool) error {
	if err := refuseStagedSystem(cmd); err != nil {
		return err
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Put("system/isp-lookup", map[string]interface{}{"enabled": enabled})
	if err != nil {
		var apiErr *api.APIError
		if enabled && errors.As(err, &apiErr) && apiErr.Status == 422 && strings.Contains(apiErr.Message, "ISP database") {
			return withHint(err, "Upload GeoIP2-ISP.mmdb (or legacy GeoIPISP.dat) to the directory the message names (`p202 system isp-lookup show` names it too), then run this again.")
		}
		return systemAdminError(err)
	}
	render(data)
	if enabled {
		output.Success("ISP and carrier lookup is on. Live traffic picks it up within five minutes.")
	} else {
		output.Success("ISP and carrier lookup is off.")
	}
	return nil
}

var systemIspLookupEnableCmd = &cobra.Command{
	Use:     "enable",
	Short:   "Turn ISP and carrier lookup on (refused while the ISP database is missing)",
	Long:    "Cannot be staged. " + systemSettingsNeeds + ", and system:write.",
	Example: "  p202 system isp-lookup enable",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return setIspLookup(cmd, true)
	},
}

var systemIspLookupDisableCmd = &cobra.Command{
	Use:     "disable",
	Short:   "Turn ISP and carrier lookup off",
	Long:    "Cannot be staged. " + systemSettingsNeeds + ", and system:write.",
	Example: "  p202 system isp-lookup disable",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		return setIspLookup(cmd, false)
	},
}

func init() {
	systemLoginLogCmd.Flags().String("limit", "", "How many attempts to list, 1-500 (default 50, as on the page)")

	systemRetentionSetCmd.Flags().String("days", "", "Days of click data to keep, 0-36500 (0 keeps every click) (required)")
	systemRetentionSetCmd.Flags().BoolP("force", "f", false, "Set it without asking, even when it deletes more")

	systemRetentionDeleteBeforeCmd.Flags().String("date", "", "Delete click data from before this day, YYYY-MM-DD in the account's time zone (required)")
	systemRetentionDeleteBeforeCmd.Flags().Bool("dry-run", false, "Preview what would be deleted and schedule nothing")
	systemRetentionDeleteBeforeCmd.Flags().BoolP("force", "f", false, "Schedule it without asking (the preview still runs)")
	systemRetentionCmd.AddCommand(systemRetentionShowCmd, systemRetentionSetCmd, systemRetentionDeleteBeforeCmd)

	systemIspLookupCmd.AddCommand(systemIspLookupShowCmd, systemIspLookupEnableCmd, systemIspLookupDisableCmd)

	systemCmd.AddCommand(systemInfoCmd, systemLoginLogCmd, systemMetricsCmd, systemIntegrationsCmd,
		systemRetentionCmd, systemIspLookupCmd)
}
