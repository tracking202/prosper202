package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var campaignOptimizeCmd = &cobra.Command{
	Use:   "optimize <campaign_id>",
	Short: "Diagnose a campaign and recommend a salvage offer for its traffic",
	Long: "Summarizes the campaign's break-even/ROI status and ranks other offers by EPC,\n" +
		"so you can route losing traffic to the offer that monetizes it best.\n" +
		"The break-even CPC is the payout × conversions per click (\"% of clicks\"); conv_rate is the reports'\n" +
		"conversions ÷ click-throughs, which is higher on landing-page traffic.\n" +
		"Each ranked row carries its aff_campaign_id, the id `rotator create --default-campaign` takes.\n" +
		"A ranking that cannot be read fails the command; it is never shown as no salvage offer.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id := args[0]
		selfID, err := strconv.ParseInt(id, 10, 64)
		if err != nil || selfID <= 0 || !isAllDigits(id) {
			return validationError("<campaign_id> must be a campaign's internal id (a whole number from 1), not %q", id).
				WithHint("`p202 campaign list` shows the internal ids.")
		}
		// The ranking covers every other campaign: narrowed to one, it would answer that there is no salvage offer.
		if cmd.Flags().Changed("aff_campaign_id") {
			return validationError("campaign optimize takes its campaign as the argument, not --aff-campaign-id").
				WithHint("Run `p202 campaign optimize %d` without --aff-campaign-id; the other filters narrow both the diagnosis and the ranking.", selfID)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

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
		// Without a data object every metric below coerces to 0, and a campaign
		// with real traffic reads as having no clicks, leads or revenue.
		if err := json.Unmarshal(sumRaw, &sum); err != nil || sum.Data == nil {
			why := "it has no data object"
			if err != nil {
				why = err.Error()
			}
			return &CLIError{Category: "server", ExitCode: ExitServer, Cause: err,
				Message: fmt.Sprintf("the server's answer to GET reports/summary for campaign %d is not a summary (%s)", selfID, why),
				Hint:    fmt.Sprintf("Check the server with `p202 system health`; `p202 report summary --aff-campaign-id %d --json` shows what it sent.", selfID)}
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
		// A configured report.aff_campaign_id default would narrow the ranking to one campaign.
		delete(portParams, "aff_campaign_id")
		portParams["breakdown"] = "campaign"
		// Sorted by revenue, so a bound on the rows read keeps the earners.
		portParams["sort"] = "total_income"
		portParams["sort_dir"] = "DESC"
		// The ranking is this command's answer: a read that fails is its error, never an empty ranking.
		offers, offersCut, err := fetchBreakdownRows(c, portParams, breakdownReadRows)
		if err != nil {
			return fmt.Errorf("ranking the other campaigns by EPC (report breakdown --breakdown campaign): %w", err)
		}
		type cand struct {
			id   int64
			name string
			epc  float64
			row  map[string]interface{} // the breakdown's row, whose epc is listed as the server sent it
		}
		var best []cand
		for _, o := range offers {
			// A row with no campaign id (clicks whose campaign is not one of the account's) is no offer to route to.
			oid, ok := campaignRowID(o["id"])
			if !ok || oid == selfID {
				continue
			}
			oc := toFloat(o["total_clicks"])
			if oc <= 0 {
				continue
			}
			// The breakdown's own epc where the row has one; a server that sends none is ranked on income ÷ clicks.
			rowEPC := toFloat(o["total_income"]) / oc
			if v, ok := o["epc"]; ok && v != nil {
				rowEPC = toFloat(v)
			}
			best = append(best, cand{oid, fmt.Sprintf("%v", o["name"]), rowEPC, o})
		}
		for i := 0; i < len(best); i++ {
			for j := i + 1; j < len(best); j++ {
				if best[j].epc > best[i].epc {
					best[i], best[j] = best[j], best[i]
				}
			}
		}

		rows := make([]map[string]interface{}, 0, len(best))
		for _, b := range best {
			// The breakdown's own epc: rounded here, a $0.0004 EPC read 0 where `report breakdown` shows it.
			rows = append(rows, map[string]interface{}{"aff_campaign_id": b.id, "name": b.name, "epc": b.row["epc"]})
		}
		// Encoded before anything is printed, so a failure leaves stdout empty and stderr the error alone.
		out, err := json.Marshal(map[string]interface{}{"data": rows, "truncated": offersCut})
		if err != nil {
			return &CLIError{Category: "server", ExitCode: ExitServer, Cause: err,
				Message: fmt.Sprintf("the campaigns' EPC could not be encoded (%v)", err),
				Hint:    "A figure in the server's breakdown is out of range; `p202 report breakdown --breakdown campaign --json` shows what it sent."}
		}

		// Print a human report to stderr; emit the structured rows on stdout.
		rates := fmt.Sprintf("%.1f%% of clicks", leadsPerClick*100)
		if v, ok := s["conv_rate"]; ok {
			rates = fmt.Sprintf("conv_rate %.1f%% of click-throughs, %s", toFloat(v), rates)
		}
		fmt.Fprintf(os.Stderr, "\nCampaign %d — %.0f clicks, %.0f conv (%s), EPC $%.3f, break-even CPC $%.3f\n",
			selfID, clicks, leads, rates, epc, be)
		fmt.Fprintf(os.Stderr, "P&L: revenue $%.2f − cost $%.2f = $%.2f  (%s)\n", income, cost, net,
			map[bool]string{true: "PROFITABLE", false: "LOSING"}[net >= 0])
		switch {
		case net >= 0:
			fmt.Fprintln(os.Stderr, "Recommendation: profitable — scale the winning sources (`report winners`).")
		case len(best) == 0 || best[0].epc <= epc:
			// Routing this traffic to an offer that earns less per click would lose more, so none is named.
			losers := fmt.Sprintf("p202 report losers --aff-campaign-id %d", selfID)
			for _, f := range append(append([]string{}, reportWindowFlags...), reportFilterFlags...) {
				if v := params[f]; v != "" && f != "aff_campaign_id" {
					losers += " --" + strings.ReplaceAll(f, "_", "-") + " " + shellArg(v)
				}
			}
			fmt.Fprintln(os.Stderr, "Recommendation: this offer is losing money, and no other campaign earned more per click in this window: there is no salvage offer.")
			fmt.Fprintf(os.Stderr, "Next: cut the sources that lose: %s\n\n", losers)
		default:
			top := best[0]
			fmt.Fprintf(os.Stderr, "Recommendation: this offer is losing money. Highest-EPC alternative for this traffic is %q (campaign %d, EPC $%.3f, vs $%.3f here).\n",
				top.name, top.id, top.epc, epc)
			fmt.Fprintf(os.Stderr, "Next: create a rotator defaulting to the salvage offer, keep converting geos on this one (<rid> is the id rotator create prints):\n")
			fmt.Fprintf(os.Stderr, "  p202 rotator create --name \"Salvage\" --default-campaign %d\n", top.id)
			fmt.Fprintf(os.Stderr, "  p202 rotator rule-create <rid> --rule-name \"US\" --country US --redirect-campaign %d\n\n", selfID)
		}

		render(out)
		if offersCut {
			noteForPeople(cmd, coversFirstRowsNote("campaign", "most revenue first"))
		}
		return nil
	},
}

// campaignRowID reads a campaign breakdown row's id: the internal campaign id, which rotator create's
// --default-campaign takes. A row with none (null, 0) has no campaign to route to.
func campaignRowID(v interface{}) (int64, bool) {
	n, err := strconv.ParseInt(fmt.Sprintf("%v", normalizeID(v)), 10, 64)
	return n, err == nil && n > 0
}

func init() {
	addReportFilters(campaignOptimizeCmd)
	if cc := findChildCommand("campaign"); cc != nil {
		cc.AddCommand(campaignOptimizeCmd)
	}
}
