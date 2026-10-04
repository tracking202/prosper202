package cmd

import (
	"encoding/json"
	"fmt"
	"math"
	"os"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// toFloat coerces an API value (number or numeric string) to float64.
func toFloat(v interface{}) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case int:
		return float64(x)
	case string:
		f, _ := strconv.ParseFloat(x, 64)
		return f
	default:
		return 0
	}
}

// fetchCampaignPayout returns the default payout for a campaign (internal id).
func fetchCampaignPayout(c *api.Client, campaignID string) (float64, error) {
	data, err := c.Get("campaigns/"+campaignID, nil)
	if err != nil {
		return 0, err
	}
	var resp struct {
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return 0, err
	}
	return toFloat(resp.Data["aff_campaign_payout"]), nil
}

// fetchBreakdownRows runs a breakdown report and returns its rows.
func fetchBreakdownRows(c *api.Client, params map[string]string) ([]map[string]interface{}, error) {
	data, err := c.Get("reports/breakdown", params)
	if err != nil {
		return nil, err
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return nil, err
	}
	return resp.Data, nil
}

// enrichBreakeven adds breakeven_cpc, margin, and a verdict to each row.
// breakeven_cpc = payout * (leads/clicks); margin = breakeven_cpc - avg_cpc.
// When targetCPC > 0 it overrides the payout-derived breakeven.
func enrichBreakeven(rows []map[string]interface{}, payout, targetCPC float64) {
	for _, r := range rows {
		clicks := toFloat(r["total_clicks"])
		leads := toFloat(r["total_leads"])
		cost := toFloat(r["total_cost"])
		cvr := 0.0
		if clicks > 0 {
			cvr = leads / clicks
		}
		avgCPC := 0.0
		if clicks > 0 {
			avgCPC = cost / clicks
		}
		be := payout * cvr
		if targetCPC > 0 {
			be = targetCPC
		}
		margin := be - avgCPC
		r["conv_rate"] = round(cvr*100, 2)
		r["avg_cpc"] = round(avgCPC, 4)
		r["breakeven_cpc"] = round(be, 4)
		r["margin"] = round(margin, 4)
		r["verdict"] = breakevenVerdict(leads, cost, margin)
	}
}

func breakevenVerdict(leads, cost, margin float64) string {
	if leads == 0 {
		if cost > 0 {
			return "NO-CONVERT"
		}
		return "NO-DATA"
	}
	if margin >= 0 {
		return "PROFITABLE"
	}
	return "OVER-BID"
}

// round rounds to the given number of decimal places, half away from zero.
// It delegates to math.Round rather than casting through int64: that cast is
// undefined in Go once f*10^places exceeds the int64 range, and it turned NaN or
// an infinity into an arbitrary finite number instead of preserving it.
func round(f float64, places int) float64 {
	if math.IsNaN(f) || math.IsInf(f, 0) {
		return f
	}
	p := math.Pow(10, float64(places))
	return math.Round(f*p) / p
}

func rowsToJSON(rows []map[string]interface{}) []byte {
	out, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		// Returning nil here would render as no output at all; render() reports
		// the empty payload, so add the cause.
		fmt.Fprintf(os.Stderr, "Error encoding rows for output: %v\n", err)
		return nil
	}
	return out
}

var reportBreakevenCmd = &cobra.Command{
	Use:   "breakeven",
	Short: "Break-even CPC per row: max biddable click price (payout × conversion rate)",
	Long: "Computes the maximum profitable cost-per-click for each breakdown row.\n" +
		"breakeven_cpc = campaign payout × (conversions / clicks); margin = breakeven_cpc − avg_cpc.\n" +
		"Requires --aff_campaign_id (the INTERNAL id from `campaign list`) to read the payout.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		campaignID, _ := cmd.Flags().GetString("aff_campaign_id")
		if campaignID == "" {
			return validationError("--aff_campaign_id is required (the internal id from `campaign list`)")
		}
		maxCPC, _ := cmd.Flags().GetFloat64("max-cpc")

		payout := maxCPC // if max-cpc set, payout isn't needed for the target
		if maxCPC <= 0 {
			payout, err = fetchCampaignPayout(c, campaignID)
			if err != nil {
				return err
			}
		}

		params := collectReportParams(cmd)
		breakdown, _ := cmd.Flags().GetString("breakdown")
		if breakdown == "" {
			breakdown = "keyword"
		}
		params["breakdown"] = resolveDimension(breakdown)

		rows, err := fetchBreakdownRows(c, params)
		if err != nil {
			return err
		}
		enrichBreakeven(rows, payout, maxCPC)
		sortRowsBy(rows, "margin", true) // worst margin first
		render(rowsToJSON(rows))
		return nil
	},
}

// attributionGroupBy maps a classic breakdown dimension to the attribution report's group_by whose row keys are the
// same ids (ReportsController's dimension id == AttributionReports' key). Device is left out: the classic report keys
// it by device model, the attribution report by device type.
var attributionGroupBy = map[string]string{
	"campaign":     "campaign",
	"ppc_account":  "traffic_source",
	"landing_page": "landing_page",
	"keyword":      "keyword",
	"country":      "country",
}

// starterCheck is attribution credit per row id, for keeping rows that start sales out of `report losers`' CUT list.
// The classic report counts a sale only for the click right before it, so a source that brings buyers in and rarely
// gets that final click looks like a loser there.
type starterCheck struct {
	model      string // the first-touch model's name; empty when the account has none (assists only)
	minAssists int64
	byKey      map[string]map[string]interface{}
}

// loadStarterCheck fetches the attribution breakdown for the same dimension and range, under a first-touch model when
// the account has one (assists don't depend on the model, so the check still runs without one). It pages through
// every row. It never fails the command: when the check can't run it returns nil and a note saying why.
func loadStarterCheck(c *api.Client, cmd *cobra.Command, dimension string, params map[string]string) (*starterCheck, []string) {
	if off, _ := cmd.Flags().GetBool("no-attribution-check"); off {
		return nil, nil
	}
	groupBy, ok := attributionGroupBy[dimension]
	if !ok {
		return nil, []string{fmt.Sprintf("attribution check skipped: the attribution report has no %s breakdown", dimension)}
	}
	minAssists, _ := cmd.Flags().GetInt64("min-assists")
	var notes []string
	modelID, _ := cmd.Flags().GetString("first-touch-model")
	modelName := "model " + modelID
	if modelID == "" {
		data, err := c.Get("attribution/models", map[string]string{"type": "first_touch"})
		if err != nil {
			return nil, []string{"attribution check skipped: " + err.Error()}
		}
		var resp struct {
			Data []map[string]interface{} `json:"data"`
		}
		if json.Unmarshal(data, &resp) == nil {
			for _, m := range resp.Data {
				if fmt.Sprint(m["status"]) == "active" {
					modelID, modelName = fmt.Sprint(m["model_id"]), fmt.Sprint(m["model_name"])
					break
				}
			}
		}
		if modelID == "" {
			modelName = ""
			notes = append(notes, "no active First touch model, so rows were checked on assists only. Add one with "+
				"`p202 attribution model create --model-name \"First touch\" --model-type first_touch` to check first-touch ROI too")
		}
	}
	q := map[string]string{"group_by": groupBy, "limit": strconv.Itoa(attributionPage)}
	if modelID != "" {
		q["model_id"] = modelID
	}
	switch {
	case params["period"] != "":
		q["period"] = params["period"]
	case params["time_from"] != "" || params["time_to"] != "":
		for _, k := range []string{"time_from", "time_to"} {
			if params[k] != "" {
				q[k] = params[k]
			}
		}
	default:
		notes = append(notes, "attribution checked over the last 30 days (no --period or --time_from/--time_to was given)")
	}
	for _, f := range []string{"aff_campaign_id", "ppc_account_id", "aff_network_id", "ppc_network_id", "landing_page_id", "country_id"} {
		if params[f] != "" {
			notes = append(notes, "attribution credit is account-wide: the attribution report has no --"+f+" filter")
			break
		}
	}
	rows, pageNotes, err := fetchAllAttributionRows(c, q)
	if err != nil {
		return nil, append(notes, "attribution check skipped: "+err.Error())
	}
	notes = append(notes, pageNotes...)
	check := &starterCheck{model: modelName, minAssists: minAssists, byKey: map[string]map[string]interface{}{}}
	for _, r := range rows {
		check.byKey[fmt.Sprint(r["key"])] = r
	}
	return check, notes
}

// attributionPage is the attribution report's largest page (AttributionReports::MAX_LIMIT).
const attributionPage = 1000

// fetchAllAttributionRows pages through the attribution breakdown (offset, while offset+rows < meta.groups). A server
// from before offset existed refuses it (422); then the rows already read are kept and a note says how many of how
// many were checked. A note also says when the pre-upgrade backfill is still running (meta.backfill not null).
func fetchAllAttributionRows(c *api.Client, q map[string]string) ([]map[string]interface{}, []string, error) {
	var all []map[string]interface{}
	var notes []string
	for offset := 0; ; {
		page := map[string]string{}
		for k, v := range q {
			page[k] = v
		}
		if offset > 0 {
			page["offset"] = strconv.Itoa(offset)
		}
		data, err := c.Get("attribution/reports/breakdown", page)
		if err != nil {
			if offset > 0 {
				notes = append(notes, fmt.Sprintf("checked the top %d attribution rows; this server can't page the attribution report (%v)", len(all), err))
				return all, notes, nil
			}
			return nil, nil, err
		}
		var resp struct {
			Data []map[string]interface{} `json:"data"`
			Meta struct {
				Groups   *int        `json:"groups"`
				Backfill interface{} `json:"backfill"`
			} `json:"meta"`
		}
		if err := json.Unmarshal(data, &resp); err != nil {
			return nil, nil, err
		}
		if offset == 0 && resp.Meta.Backfill != nil {
			notes = append(notes, "attribution is incomplete for this range while older conversions are backfilled")
		}
		all = append(all, resp.Data...)
		if resp.Meta.Groups == nil {
			if len(resp.Data) == attributionPage {
				notes = append(notes, fmt.Sprintf("checked the top %d attribution rows; this server doesn't say how many there are", len(all)))
			}
			return all, notes, nil
		}
		if len(resp.Data) == 0 || len(all) >= *resp.Meta.Groups {
			return all, notes, nil
		}
		offset = len(all)
	}
}

// applyStarterCheck adds first-touch ROI and assists to a CUT row and moves it to TEST when it starts sales: it pays
// for itself as a first click (first-touch ROI 0% or better), or it had a click in at least --min-assists sales that
// another row closed. Cutting it on last-click numbers would likely lose those sales.
func applyStarterCheck(row map[string]interface{}, check *starterCheck) {
	if check == nil {
		return
	}
	a, ok := check.byKey[fmt.Sprint(row["id"])]
	if !ok {
		return
	}
	assists := int64(toFloat(a["assisted_conversions"]))
	row["assisted_conversions"] = assists
	var why []string
	if check.model != "" && a["roi"] != nil { // no ROI when the row had no cost in the attribution range
		roi := toFloat(a["roi"])
		row["first_touch_roi"] = roi
		if roi >= 0 {
			why = append(why, fmt.Sprintf("%s ROI %+.1f%%", check.model, roi))
		}
	}
	if check.minAssists > 0 && assists >= check.minAssists {
		why = append(why, fmt.Sprintf("%d assists", assists))
	}
	if len(why) > 0 {
		row["bucket"] = "TEST"
		row["reason"] = fmt.Sprintf("%s, but starts sales (%s). Test a cut on part of the traffic before making it",
			row["reason"], strings.Join(why, ", "))
	}
}

// triageCmd builds `report losers` / `report winners`.
func triageCmd(use, short string, wantWinners bool) *cobra.Command {
	c := &cobra.Command{
		Use:   use,
		Short: short,
		RunE: func(cmd *cobra.Command, args []string) error {
			client, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			minClicks, _ := cmd.Flags().GetFloat64("min-clicks")
			maxCPC, _ := cmd.Flags().GetFloat64("max-cpc")
			campaignID, _ := cmd.Flags().GetString("aff_campaign_id")

			var payout float64
			if maxCPC <= 0 && campaignID != "" {
				payout, _ = fetchCampaignPayout(client, campaignID)
			}

			params := collectReportParams(cmd)
			breakdown, _ := cmd.Flags().GetString("breakdown")
			if breakdown == "" {
				breakdown = "keyword"
			}
			params["breakdown"] = resolveDimension(breakdown)

			rows, err := fetchBreakdownRows(client, params)
			if err != nil {
				return err
			}

			var check *starterCheck
			if !wantWinners {
				var notes []string
				check, notes = loadStarterCheck(client, cmd, params["breakdown"], params)
				for _, n := range notes {
					fmt.Fprintln(cmd.ErrOrStderr(), "Note: "+n)
				}
			}

			out := make([]map[string]interface{}, 0, len(rows))
			for _, r := range rows {
				clicks := toFloat(r["total_clicks"])
				leads := toFloat(r["total_leads"])
				cost := toFloat(r["total_cost"])
				net := toFloat(r["total_net"])
				if clicks < minClicks {
					continue
				}
				avgCPC := 0.0
				if clicks > 0 {
					avgCPC = cost / clicks
				}
				bucket, reason := classify(clicks, leads, cost, net, avgCPC, payout, maxCPC)
				keep := false
				if wantWinners && bucket == "SCALE" {
					keep = true
				}
				if !wantWinners && bucket == "CUT" {
					keep = true
				}
				if !keep {
					continue
				}
				row := map[string]interface{}{
					"name":         r["name"],
					"total_clicks": clicks,
					"total_leads":  leads,
					"total_cost":   round(cost, 2),
					"total_net":    round(net, 2),
					"avg_cpc":      round(avgCPC, 4),
					"bucket":       bucket,
					"reason":       reason,
				}
				if id, ok := r["id"]; ok {
					row["id"] = id
				}
				if !wantWinners {
					applyStarterCheck(row, check)
				}
				out = append(out, row)
			}
			sortRowsBy(out, "total_net", !wantWinners) // losers: worst first; winners: best first
			if !wantWinners {
				// CUT rows first, then the TEST rows held back by the attribution check.
				sort.SliceStable(out, func(i, j int) bool { return out[i]["bucket"] == "CUT" && out[j]["bucket"] != "CUT" })
			}
			render(rowsToJSON(out))
			return nil
		},
	}
	return c
}

// classify buckets a row into SCALE / WATCH / CUT with a human reason.
func classify(clicks, leads, cost, net, avgCPC, payout, maxCPC float64) (string, string) {
	target := maxCPC
	if target <= 0 && payout > 0 && clicks > 0 {
		target = payout * (leads / clicks)
	}
	if leads == 0 && cost > 0 {
		return "CUT", fmt.Sprintf("spent $%.2f, 0 conversions", cost)
	}
	if target > 0 && avgCPC > target {
		return "CUT", fmt.Sprintf("CPC $%.2f > breakeven $%.2f", avgCPC, target)
	}
	if net > 0 && leads > 0 {
		return "SCALE", fmt.Sprintf("profit +$%.2f, %d conv", net, int64(leads))
	}
	if net < 0 {
		return "WATCH", fmt.Sprintf("loss $%.2f", net)
	}
	return "WATCH", "break-even"
}

// sortRowsBy sorts rows by a numeric field; asc=true sorts ascending.
func sortRowsBy(rows []map[string]interface{}, field string, asc bool) {
	sort.SliceStable(rows, func(i, j int) bool {
		a, b := toFloat(rows[i][field]), toFloat(rows[j][field])
		if asc {
			return a < b
		}
		return a > b
	})
}

func init() {
	addReportFilters(reportBreakevenCmd)
	reportBreakevenCmd.Flags().StringP("breakdown", "b", "keyword", "Dimension")
	enumFlag(reportBreakevenCmd, "breakdown", dimensionEnum(breakdownDimensions))
	reportBreakevenCmd.Flags().Float64("max-cpc", 0, "Override the payout-derived break-even CPC target")
	reportCmd.AddCommand(reportBreakevenCmd)

	losers := triageCmd("losers", "Rows to CUT: over-bid keywords/geos and zero-conversion spend; rows that start sales come back as TEST", false)
	losers.Long = "Rows to CUT from the classic (last-click) report: zero conversions with spend, or CPC above break-even.\n\n" +
		"Each CUT row is then checked against the attribution report for the same dimension and range. A row that\n" +
		"starts sales comes back as TEST, with its first-touch ROI and assists: it pays for itself as a first click\n" +
		"(first-touch ROI 0% or better), or it had a click in at least --min-assists sales (default 1) that another row\n" +
		"closed. Cutting it on last-click numbers would likely lose those sales, so test a cut on part of its traffic first.\n\n" +
		"The check runs for campaign, ppc_account (traffic source), landing_page, keyword and country. ROI comes from the\n" +
		"first active First touch model (or --first-touch-model); without one, rows are checked on assists only. It needs\n" +
		"an attribution:read key; when it can't run, the command still lists the classic losers and says why on stderr.\n" +
		"--no-attribution-check turns it off."
	winners := triageCmd("winners", "Rows to SCALE: profitable, converting keywords/geos", true)
	for _, c := range []*cobra.Command{losers, winners} {
		addReportFilters(c)
		c.Flags().StringP("breakdown", "b", "keyword", "Dimension to triage")
		enumFlag(c, "breakdown", dimensionEnum(breakdownDimensions))
		c.Flags().Float64("min-clicks", 1, "Ignore rows with fewer than N clicks (significance floor)")
		c.Flags().Float64("max-cpc", 0, "Break-even CPC target (else derived from campaign payout × CVR)")
		reportCmd.AddCommand(c)
	}
	losers.Flags().String("first-touch-model", "", "Attribution model id for the starter check (default: the first active First touch model)")
	losers.Flags().Bool("no-attribution-check", false, "List classic last-click losers only, without the first-touch starter check")
	losers.Flags().Int64("min-assists", 1, "A CUT row with at least this many assisted sales comes back as TEST (0 turns the assists rule off)")
}
