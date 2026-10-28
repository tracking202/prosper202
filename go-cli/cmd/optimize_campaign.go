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
		"so you can route losing traffic to the offer that monetizes it best.\n" +
		"The break-even CPC is the payout × conversions per click (\"% of clicks\"); conv_rate is the reports'\n" +
		"conversions ÷ click-throughs, which is higher on landing-page traffic.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		id := args[0]

		// 1. This campaign's totals + payout. Surface a bad campaign id here
		// rather than silently optimizing with payout 0.
		payout, err := fetchCampaignPayout(c, id)
		if err != nil {
			return err
		}
		params := collectReportParams(cmd)
		params["aff_campaign_id"] = id
		sumRaw, err := c.Get("reports/summary", params)
		if err != nil {
			return err
		}
		if err := refuseMaskedFigures(sumRaw, "p202 campaign optimize"); err != nil {
			return err
		}
		var sum struct {
			Data map[string]interface{} `json:"data"`
		}
		// A discarded parse error left sum.Data nil, and every metric below
		// coerced to 0 — the command then reported a campaign with real traffic
		// as having no clicks, leads or revenue.
		if err := json.Unmarshal(sumRaw, &sum); err != nil {
			return fmt.Errorf("parsing summary report for campaign %s: %w", id, err)
		}
		s := sum.Data
		clicks := toFloat(s["total_clicks"])
		leads := toFloat(s["total_leads"])
		cost := toFloat(s["total_cost"])
		income := toFloat(s["total_income"])
		net := income - cost
		// A click is what the traffic costs, so break-even prices one: payout × conversions per click. The reports'
		// conv_rate is conversions ÷ click-throughs, higher on landing-page traffic, and the diagnosis names both.
		leadsPerClick, epc := 0.0, 0.0
		if clicks > 0 {
			leadsPerClick = leads / clicks
			epc = income / clicks
		}
		be := payout * leadsPerClick

		// 2. Rank other offers by EPC over the same period (the salvage candidates).
		portParams := collectReportParams(cmd)
		portParams["breakdown"] = "campaign"
		// Sorted by revenue, so a bound on the rows read keeps the earners.
		portParams["sort"] = "total_income"
		portParams["sort_dir"] = "DESC"
		offers, offersCut, err := fetchBreakdownRows(c, portParams, breakdownReadRows)
		if err != nil {
			return fmt.Errorf("reading the other campaigns' EPC for campaign %s: %w", id, err)
		}
		type cand struct {
			name string
			epc  float64
			row  map[string]interface{} // the breakdown's row, whose epc is listed as the server sent it
		}
		var best []cand
		for _, o := range offers {
			if fmt.Sprintf("%v", normalizeID(o["id"])) == id {
				continue
			}
			if toFloat(o["total_clicks"]) <= 0 {
				continue
			}
			best = append(best, cand{fmt.Sprintf("%v", o["name"]), toFloat(o["epc"]), o})
		}
		for i := 0; i < len(best); i++ {
			for j := i + 1; j < len(best); j++ {
				if best[j].epc > best[i].epc {
					best[i], best[j] = best[j], best[i]
				}
			}
		}

		// Print a human report to stderr; emit the structured rows on stdout.
		rates := fmt.Sprintf("%.1f%% of clicks", leadsPerClick*100)
		if v, ok := s["conv_rate"]; ok {
			rates = fmt.Sprintf("conv_rate %.1f%% of click-throughs, %s", toFloat(v), rates)
		}
		fmt.Fprintf(os.Stderr, "\nCampaign %s — %.0f clicks, %.0f conv (%s), EPC $%.3f, break-even CPC $%.3f\n",
			id, clicks, leads, rates, epc, be)
		fmt.Fprintf(os.Stderr, "P&L: revenue $%.2f − cost $%.2f = $%.2f  (%s)\n", income, cost, net,
			map[bool]string{true: "PROFITABLE", false: "LOSING"}[net >= 0])
		if net < 0 && len(best) > 0 {
			top := best[0]
			fmt.Fprintf(os.Stderr, "Recommendation: this offer is losing money. Highest-EPC alternative for this traffic is %q (EPC $%.3f, vs $%.3f here).\n",
				top.name, top.epc, epc)
			fmt.Fprintf(os.Stderr, "Next: create a rotator defaulting to the salvage offer, keep converting geos on this one:\n")
			fmt.Fprintf(os.Stderr, "  p202 rotator create --name \"Salvage\" --default-campaign <salvage_id>\n")
			fmt.Fprintf(os.Stderr, "  p202 rotator rule-create <rid> --rule-name \"US\" --country US --redirect-campaign %s\n\n", id)
		} else if net >= 0 {
			fmt.Fprintln(os.Stderr, "Recommendation: profitable — scale the winning sources (`report winners`).")
		}

		rows := make([]map[string]interface{}, 0, len(best))
		for _, b := range best {
			// Rounded to 3 decimals here, a $0.0004 EPC read 0 where `report breakdown` shows it.
			rows = append(rows, map[string]interface{}{"name": b.name, "epc": b.row["epc"]})
		}
		render(pageJSON(rows, map[string]interface{}{"truncated": offersCut}))
		if offersCut {
			noteForPeople(cmd, coversFirstRowsNote("campaign", "most revenue first"))
		}
		return nil
	},
}

func init() {
	addReportFilters(campaignOptimizeCmd)
	if cc := findChildCommand("campaign"); cc != nil {
		cc.AddCommand(campaignOptimizeCmd)
	}
}
