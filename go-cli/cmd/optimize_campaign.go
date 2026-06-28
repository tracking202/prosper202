package cmd

import (
	"encoding/json"
	"fmt"
	"os"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var campaignOptimizeCmd = &cobra.Command{
	Use:   "optimize <campaign_id>",
	Short: "Diagnose a campaign and recommend a salvage offer for its traffic",
	Long: "Summarizes the campaign's break-even/ROI status and ranks other offers by EPC,\n" +
		"so you can route losing traffic to the offer that monetizes it best.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		id := args[0]

		// 1. This campaign's totals + payout.
		payout, _ := fetchCampaignPayout(c, id)
		params := collectReportParams(cmd)
		params["aff_campaign_id"] = id
		sumRaw, err := c.Get("reports/summary", params)
		if err != nil {
			return err
		}
		var sum struct {
			Data map[string]interface{} `json:"data"`
		}
		_ = json.Unmarshal(sumRaw, &sum)
		s := sum.Data
		clicks := toFloat(s["total_clicks"])
		leads := toFloat(s["total_leads"])
		cost := toFloat(s["total_cost"])
		income := toFloat(s["total_income"])
		net := income - cost
		cvr, epc, be := 0.0, 0.0, 0.0
		if clicks > 0 {
			cvr = leads / clicks
			epc = income / clicks
		}
		be = payout * cvr

		// 2. Rank other offers by EPC over the same period (the salvage candidates).
		portParams := collectReportParams(cmd)
		portParams["breakdown"] = "campaign"
		// The backend defaults to limit=50 sorted by clicks; raise the limit and
		// sort by revenue so a high-EPC salvage offer isn't missed on page 1.
		portParams["limit"] = "500"
		portParams["sort"] = "total_income"
		portParams["sort_dir"] = "DESC"
		offers, _ := fetchBreakdownRows(c, portParams)
		type cand struct {
			name string
			epc  float64
		}
		var best []cand
		for _, o := range offers {
			if fmt.Sprintf("%v", normalizeID(o["id"])) == id {
				continue
			}
			oc := toFloat(o["total_clicks"])
			if oc <= 0 {
				continue
			}
			best = append(best, cand{fmt.Sprintf("%v", o["name"]), toFloat(o["total_income"]) / oc})
		}
		for i := 0; i < len(best); i++ {
			for j := i + 1; j < len(best); j++ {
				if best[j].epc > best[i].epc {
					best[i], best[j] = best[j], best[i]
				}
			}
		}

		// Print a human report to stderr; emit the structured rows on stdout.
		fmt.Fprintf(os.Stderr, "\nCampaign %s — %.0f clicks, %.0f conv (%.1f%% CVR), EPC $%.3f, break-even CPC $%.3f\n",
			id, clicks, leads, cvr*100, epc, be)
		fmt.Fprintf(os.Stderr, "P&L: revenue $%.2f − cost $%.2f = $%.2f  (%s)\n", income, cost, net,
			map[bool]string{true: "PROFITABLE", false: "LOSING"}[net >= 0])
		if net < 0 && len(best) > 0 {
			top := best[0]
			fmt.Fprintf(os.Stderr, "Recommendation: this offer is losing money. Highest-EPC alternative for this traffic is %q (EPC $%.3f, vs $%.3f here).\n",
				top.name, top.epc, epc)
			fmt.Fprintf(os.Stderr, "Next: create a rotator defaulting to the salvage offer, keep converting geos on this one:\n")
			fmt.Fprintf(os.Stderr, "  p202 rotator create --name \"Salvage\" --default_campaign <salvage_id>\n")
			fmt.Fprintf(os.Stderr, "  p202 rotator rule-create <rid> --rule_name \"US\" --country US --redirect-campaign %s\n\n", id)
		} else if net >= 0 {
			fmt.Fprintln(os.Stderr, "Recommendation: profitable — scale the winning sources (`report winners`).")
		}

		rows := make([]map[string]interface{}, 0, len(best))
		for _, b := range best {
			rows = append(rows, map[string]interface{}{"name": b.name, "epc": round(b.epc, 3)})
		}
		render(rowsToJSON(rows))
		return nil
	},
}

func init() {
	addReportFilters(campaignOptimizeCmd)
	if cc := findChildCommand("campaign"); cc != nil {
		cc.AddCommand(campaignOptimizeCmd)
	}
}
