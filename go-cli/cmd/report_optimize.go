package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"

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

func round(f float64, places int) float64 {
	p := 1.0
	for i := 0; i < places; i++ {
		p *= 10
	}
	return float64(int64(f*p+sign(f)*0.5)) / p
}

func sign(f float64) float64 {
	if f < 0 {
		return -1
	}
	return 1
}

func rowsToJSON(rows []map[string]interface{}) []byte {
	out, _ := json.Marshal(map[string]interface{}{"data": rows})
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
				out = append(out, row)
			}
			sortRowsBy(out, "total_net", !wantWinners) // losers: worst first; winners: best first
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
	reportBreakevenCmd.Flags().StringP("breakdown", "b", "keyword", "Dimension: keyword, country, ppc_account, device, ... (alias: lp)")
	reportBreakevenCmd.Flags().Float64("max-cpc", 0, "Override the payout-derived break-even CPC target")
	reportCmd.AddCommand(reportBreakevenCmd)

	losers := triageCmd("losers", "Rows to CUT: over-bid keywords/geos and zero-conversion spend", false)
	winners := triageCmd("winners", "Rows to SCALE: profitable, converting keywords/geos", true)
	for _, c := range []*cobra.Command{losers, winners} {
		addReportFilters(c)
		c.Flags().StringP("breakdown", "b", "keyword", "Dimension to triage (keyword, country, ppc_account, device, ...)")
		c.Flags().Float64("min-clicks", 1, "Ignore rows with fewer than N clicks (significance floor)")
		c.Flags().Float64("max-cpc", 0, "Break-even CPC target (else derived from campaign payout × CVR)")
		reportCmd.AddCommand(c)
	}
}
