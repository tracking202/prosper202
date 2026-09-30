package cmd

import (
	"errors"
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"p202/internal/api"
)

// listStatsFields are the reports/breakdown metrics `list --with-stats` adds to each row.
var listStatsFields = []string{"total_clicks", "total_leads", "total_income", "total_cost", "total_net"}

// breakdownPageSize is ReportsController::breakdown's row cap per request.
const breakdownPageSize = 500

func registerListStatsFlags(cmd *cobra.Command, entity crudEntity) {
	cmd.Flags().Bool("with-stats", false, fmt.Sprintf("Add each %s's traffic in the window: %s (0 when none; needs the reports:read scope)", entity.Name, strings.Join(listStatsFields, ", ")))
	cmd.Flags().String("period", "", "Stats window for --with-stats: "+strings.Join(reportPeriods, ", ")+" (default last30)")
	cmd.Flags().Int("days", 0, "Stats window for --with-stats in days, ending now (ignored when --period is given)")
	cmd.Flags().Int("min-clicks", 0, fmt.Sprintf("With --with-stats: only %ss with at least N clicks in the window (searches every page)", entity.Name))
}

// listStatsParams validates the stats flags and returns the breakdown query,
// or nil without --with-stats.
func listStatsParams(cmd *cobra.Command, entity crudEntity) (map[string]string, int, error) {
	withStats, _ := cmd.Flags().GetBool("with-stats")
	if !withStats {
		for _, name := range []string{"period", "days", "min-clicks"} {
			if cmd.Flags().Changed(name) {
				return nil, 0, validationError("--%s only applies with --with-stats", name).
					WithHint("Add --with-stats to merge each %s's traffic for the window, or drop --%s.", entity.Name, name)
			}
		}
		return nil, 0, nil
	}
	period, _ := cmd.Flags().GetString("period")
	period = strings.TrimSpace(period)
	if period != "" && !containsString(reportPeriods, period) {
		return nil, 0, validationError("invalid --period %q; valid: %s", period, strings.Join(reportPeriods, ", ")).
			WithHint("For any other window use --days N (e.g. --days 60).")
	}
	days, _ := cmd.Flags().GetInt("days")
	if period == "" && days == 0 {
		period = "last30"
	}
	minClicks, _ := cmd.Flags().GetInt("min-clicks")
	if minClicks < 0 {
		return nil, 0, validationError("--min-clicks must be 0 or greater").WithHint("Pass the fewest clicks a %s needs in the window to be listed, e.g. --min-clicks 1.", entity.Name)
	}
	params := map[string]string{"breakdown": entity.StatsGroupBy}
	if err := applyReportWindow(params, period, days, "", ""); err != nil {
		return nil, 0, err
	}
	return params, minClicks, nil
}

// addListStats fetches the whole breakdown once and merges it onto rows by
// entity.IDField; rows without traffic get zeros. Drops rows under minClicks.
func addListStats(c *api.Client, entity crudEntity, rows []map[string]interface{}, params map[string]string, minClicks int) ([]map[string]interface{}, error) {
	if len(rows) == 0 {
		return rows, nil
	}
	stats, err := fetchAllRowsPaged(c, "reports/breakdown", params, breakdownPageSize)
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && apiErr.Status == 403 {
			return nil, withHint(fmt.Errorf("fetching %s stats: %w", entity.Name, err),
				"--with-stats reads GET /reports/breakdown, which needs an API key with the reports:read scope (or read). "+
					"Mint one with `p202 user apikey create <user_id> --scope %s:read,reports:read`, or drop --with-stats: `p202 %s list` without it still works.",
				entity.Endpoint, entity.Name)
		}
		return nil, fmt.Errorf("fetching %s stats: %w", entity.Name, err)
	}
	byID := make(map[string]map[string]interface{}, len(stats))
	for _, s := range stats {
		if id := scalarString(s["id"]); id != "" {
			byID[id] = s
		}
	}
	out := make([]map[string]interface{}, 0, len(rows))
	for _, row := range rows {
		var s map[string]interface{}
		if id := scalarString(row[entity.IDField]); id != "" {
			s = byID[id]
		}
		for _, f := range listStatsFields {
			row[f] = toFloat(s[f])
		}
		if minClicks > 0 && toFloat(s["total_clicks"]) < float64(minClicks) {
			continue
		}
		out = append(out, row)
	}
	return out, nil
}
