package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/output"
)

// reportGroupsMaxLevels is ReportsController::GROUPS_MAX_LEVELS, the Group
// Overview page's depth.
const reportGroupsMaxLevels = 4

// reportGroupsColumns are the human view's columns: the page's.
var reportGroupsColumns = []string{"group", "total_clicks", "total_click_throughs", "total_leads", "total_income", "total_cost", "total_net", "epc", "avg_cpc", "roi"}

// parseGroupLevels reads --by: one to four dimensions, outermost first,
// aliases resolved, each once.
func parseGroupLevels(by string) ([]string, error) {
	if strings.TrimSpace(by) == "" {
		return nil, validationError("--by is required: up to %d dimensions, outermost first, from: %s", reportGroupsMaxLevels, strings.Join(breakdownDimensions, ", ")).
			WithHint("For example --by ppc_network,campaign,keyword, as the Group Overview page groups.")
	}
	var levels []string
	seen := map[string]bool{}
	for _, part := range strings.Split(by, ",") {
		d := resolveDimension(part)
		known := false
		for _, v := range breakdownDimensions {
			if v == d {
				known = true
				break
			}
		}
		if !known {
			return nil, validationError("--by: %q is not a dimension; valid: %s", strings.TrimSpace(part), strings.Join(breakdownDimensions, ", "))
		}
		if seen[d] {
			return nil, validationError("--by names %s twice; each dimension once", d)
		}
		seen[d] = true
		levels = append(levels, d)
	}
	if len(levels) > reportGroupsMaxLevels {
		return nil, validationError("--by takes at most %d dimensions, got %d", reportGroupsMaxLevels, len(levels)).
			WithHint("Drop the innermost, or narrow with a filter and group the rest.")
	}
	return levels, nil
}

var reportGroupsCmd = &cobra.Command{
	Use:   "groups",
	Short: "Traffic grouped by up to four dimensions, nested, each group with its totals (Group Overview)",
	Long: "The Overview's Group Overview: traffic grouped by up to four dimensions, one inside\n" +
		"the other, every group with its clicks, leads, income, cost, net and ratios. A group\n" +
		"is the sum of its children: the clicks a level has no value for (no keyword, no\n" +
		"landing page) are its `[no keyword]` row. Takes every report filter and period.\n\n" +
		"--json answers the tree as the server serves it (each group's `children`); the table,\n" +
		"CSV and --ndjson give one row per group, marked by level (› per level), then a Total row.\n\n" +
		"  p202 report groups --by ppc_network,campaign,keyword --period last7\n" +
		"  p202 report groups --by campaign,device_type --show real --sort clicks --json",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		by, _ := cmd.Flags().GetString("by")
		levels, err := parseGroupLevels(by)
		if err != nil {
			return err
		}
		if err := rejectMultiProfile(cmd); err != nil {
			return err
		}
		params := collectReportParams(cmd)
		params["by"] = strings.Join(levels, ",")
		if v, _ := cmd.Flags().GetString("sort"); v != "" {
			if v != "name" {
				v = resolveMetric(v)
			}
			params["sort"] = v
		}
		if v, _ := cmd.Flags().GetString("sort_dir"); v != "" {
			params["sort_dir"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("reports/groups", params)
		if err != nil {
			// The only 404 here is a server that predates the endpoint.
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 404 {
				return withHint(err, "This server has no GET /reports/groups; upgrade it, or use `p202 report crosstab` for two dimensions.")
			}
			return err
		}
		opts := renderOpts()
		if opts.JSON && !opts.NDJSON {
			render(data)
			return nil
		}
		var resp struct {
			Data   []map[string]interface{} `json:"data"`
			Totals map[string]interface{}   `json:"totals"`
		}
		if err := json.Unmarshal(data, &resp); err != nil {
			return fmt.Errorf("parsing the group report: %w", err)
		}
		rows := flattenGroups(resp.Data, 0, nil)
		if resp.Totals != nil {
			total := map[string]interface{}{"group": "Total", "level": -1}
			for k, v := range resp.Totals {
				total[k] = v
			}
			rows = append(rows, total)
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": rows})
		if err != nil {
			return fmt.Errorf("encoding %d groups: %w", len(rows), err)
		}
		if len(opts.Fields) == 0 && !opts.NDJSON {
			opts.Fields = reportGroupsColumns
		}
		output.RenderWith(encoded, opts)
		return nil
	},
}

// flattenGroups turns the tree into rows, a parent before its children: the
// group's name marked by its level ("[no keyword]" for the clicks a level
// has no value for), its level, and the names above it as `path`.
func flattenGroups(groups []map[string]interface{}, level int, path []string) []map[string]interface{} {
	var rows []map[string]interface{}
	for _, g := range groups {
		name, _ := g["name"].(string)
		if g["id"] == nil {
			name = fmt.Sprintf("[no %v]", g["breakdown"])
		}
		row := map[string]interface{}{}
		for k, v := range g {
			if k != "children" {
				row[k] = v
			}
		}
		here := append(append([]string{}, path...), name)
		// A visible marker per level: the table view trims and collapses
		// spaces in a cell, so indenting with them would show no levels.
		row["group"] = strings.Repeat("› ", level) + name
		row["level"] = level
		row["path"] = here
		rows = append(rows, row)
		if children, ok := g["children"].([]interface{}); ok {
			var kids []map[string]interface{}
			for _, k := range children {
				if m, ok := k.(map[string]interface{}); ok {
					kids = append(kids, m)
				}
			}
			rows = append(rows, flattenGroups(kids, level+1, here)...)
		}
	}
	return rows
}

func init() {
	reportGroupsCmd.Flags().String("by", "", "Up to 4 dimensions, outermost first (e.g. ppc_network,campaign,keyword): {values}")
	enumFlag(reportGroupsCmd, "by", newEnum(breakdownDimensions, enumAliases(dimensionAliases), enumFoldCase(), enumList(),
		enumServerList("features", "report_breakdowns")))
	reportGroupsCmd.Flags().String("sort", "", "Order each group's rows by name (default) or a metric: {values}")
	enumFlag(reportGroupsCmd, "sort", metricEnum(append([]string{"name"}, breakdownSorts...)))
	reportGroupsCmd.Flags().String("sort_dir", "", "ASC or DESC (default ASC by name, DESC by a metric)")
	enumFlag(reportGroupsCmd, "sort_dir", sortDirEnum())
	addReportFilters(reportGroupsCmd)
	reportCmd.AddCommand(reportGroupsCmd)
}
