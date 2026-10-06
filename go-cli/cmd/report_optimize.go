package cmd

import (
	"encoding/json"
	"errors"
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
	partial    bool    // only the first rows could be read (a server without offset paging)
	payout     float64 // --payout: values each attributed conversion at this instead of its recorded revenue
}

// firstTouchROI is a row's ROI under the first-touch model, in percent: the server's (recorded revenue against cost),
// or with --payout, attributed conversions × payout against cost, so it values a sale as the classic rows do. ok is
// false when the row had no cost in the range (the server sends roi null).
func (c *starterCheck) firstTouchROI(a map[string]interface{}) (roi float64, ok bool) {
	if c.payout > 0 {
		cost := toFloat(a["cost"])
		if cost <= 0 {
			return 0, false
		}
		return round((toFloat(a["attributed_conversions"])*c.payout-cost)/cost*100, 2), true
	}
	if a["roi"] == nil {
		return 0, false
	}
	return toFloat(a["roi"]), true
}

// filterDimension is the breakdown each entity filter narrows to. The attribution report takes no entity filters, so
// its rows match the classic ones only when the filter is the breakdown itself (the same keys, account-wide either way).
var filterDimension = map[string]string{
	"aff_campaign_id": "campaign", "ppc_account_id": "ppc_account", "landing_page_id": "landing_page",
	"country_id": "country", "aff_network_id": "", "ppc_network_id": "",
}

// loadAttributionCheck fetches the attribution breakdown rows for keys (the ids of the classic rows being checked) over
// the same dimension and range, under a first-touch model when there is a usable one. It is shared by `losers`
// (needFirstTouch false: assists don't depend on the model, so the check still runs without one) and `winners`
// (needFirstTouch true: a closer only shows as a first-touch loss, so without a usable First touch model the check is
// skipped). override is the validated --first-touch-model (firstTouchOverride runs before any report is read). When
// the check can't run it returns nil and a note saying why.
func loadAttributionCheck(c *api.Client, cmd *cobra.Command, dimension string, params map[string]string, needFirstTouch bool, override overrideModel, keys []string) (*starterCheck, []string) {
	groupBy, ok := attributionGroupBy[dimension]
	if !ok {
		return nil, []string{fmt.Sprintf("attribution check skipped: the attribution report has no %s breakdown", dimension)}
	}
	// The classic report treats a bound of "0" as unset (PHP empty()), so the check does too.
	bound := func(k string) string {
		if params[k] == "0" {
			return ""
		}
		return params[k]
	}
	// The classic report applies both a period and a time range (their overlap); the attribution report takes one,
	// so a mixed range (often a configured default period plus explicit times) can't be mirrored.
	if params["period"] != "" && (bound("time_from") != "" || bound("time_to") != "") {
		return nil, []string{"attribution check skipped: --period and --time_from/--time_to are both set (perhaps a configured " +
			"default period), and the attribution report takes one range. Pass only one to check attribution"}
	}
	// A filter other than the breakdown itself would compare campaign-scoped rows with account-wide credit, so a row
	// could be rescued by sales elsewhere (or kept CUT by losses elsewhere): don't reclassify at all.
	for _, f := range []string{"aff_campaign_id", "ppc_account_id", "aff_network_id", "ppc_network_id", "landing_page_id", "country_id"} {
		if params[f] != "" && filterDimension[f] != dimension {
			hint := "Drop the filter to check attribution"
			if filterDimension[f] != "" {
				hint += ", or break down by " + filterDimension[f] + " instead"
			}
			return nil, []string{"attribution check skipped: the attribution report can't be filtered by --" + f +
				", so its numbers wouldn't match these rows. " + hint}
		}
	}
	var minAssists int64
	if cmd.Flags().Lookup("min-assists") != nil {
		minAssists, _ = cmd.Flags().GetInt64("min-assists")
	}
	var notes []string
	modelID, modelName := override.id, override.name
	if modelID != "" {
		if override.pending {
			notes = append(notes, fmt.Sprintf("First touch model %s (%s) is still being recomputed, so its ROI wasn't used", modelID, modelName))
			modelID, modelName = "", ""
		}
	} else {
		data, err := c.Get("attribution/models", map[string]string{"type": "first_touch"})
		if err != nil {
			return nil, []string{"attribution check skipped: " + err.Error()}
		}
		var resp struct {
			Data []map[string]interface{} `json:"data"`
		}
		if json.Unmarshal(data, &resp) == nil {
			pending := ""
			for _, m := range resp.Data {
				if fmt.Sprint(m["status"]) != "active" {
					continue
				}
				// Credits are absent or stale until the worker finishes recomputing a new or edited model.
				if m["recompute_pending"] == true {
					pending = fmt.Sprint(m["model_name"])
					continue
				}
				modelID, modelName = fmt.Sprint(m["model_id"]), fmt.Sprint(m["model_name"])
				break
			}
			if modelID == "" && pending != "" {
				notes = append(notes, "First touch model "+pending+" is still being recomputed, so its ROI wasn't used")
			}
		}
	}
	if modelID == "" && needFirstTouch {
		if len(notes) > 0 { // a First touch model exists but is still being recomputed
			return nil, append(notes, "closer check skipped until it finishes")
		}
		return nil, []string{"closer check skipped: no active First touch model. Add one with " +
			"`p202 attribution model create --model-name \"First touch\" --model-type first_touch` so winners can tell new-buyer sources from closers"}
	}
	if modelID == "" && len(notes) == 0 {
		notes = append(notes, "no active First touch model, so rows were checked on assists only. Add one with "+
			"`p202 attribution model create --model-name \"First touch\" --model-type first_touch` to check first-touch ROI too")
	}
	q := map[string]string{"group_by": groupBy, "limit": strconv.Itoa(attributionPage)}
	if modelID != "" {
		q["model_id"] = modelID
	}
	// The same window as the classic report. Without a lower bound the classic report runs from the first click, while
	// the attribution report would default to the last 30 days, so a missing time_from is sent as 0 (all time); a
	// missing time_to defaults to now on both.
	// The classic report counts the clicks made in the range, so a bounded range asks for the click cohort: credits
	// and assists landing on clicks made in the range, whenever they converted. All time is the same in both cohorts,
	// so it reads the default (conversion) cohort, which the rollup serves.
	if params["period"] != "" || bound("time_from") != "" || bound("time_to") != "" {
		q["cohort"] = "click"
	}
	if params["period"] != "" {
		q["period"] = params["period"]
	} else {
		q["time_from"] = "0"
		if bound("time_from") != "" {
			q["time_from"] = bound("time_from")
		}
		if bound("time_to") != "" {
			q["time_to"] = bound("time_to")
		}
	}
	rows, partial, pageNotes, err := fetchCheckRows(c, q, keys)
	if err != nil {
		// A server from before keys and the click cohort (one change brought both) names what it refused; drop it and
		// read again once. Without keys every row is paged through; without the click cohort attribution counts sales
		// converted in the range (credited to clicks of any age), so the populations differ at the range's edges.
		// TEST and CLOSER only hold a row for a closer look, so the check still runs, and says so.
		retry := false
		if len(keys) > 0 && refusesParam(err, "keys") {
			keys, retry = nil, true
		}
		if q["cohort"] != "" && refusesParam(err, "cohort") {
			delete(q, "cohort")
			notes = append(notes, cohortEdgeNote)
			retry = true
		}
		if retry {
			rows, partial, pageNotes, err = fetchCheckRows(c, q, keys)
		}
	}
	if err != nil {
		return nil, append(notes, "attribution check skipped: "+err.Error())
	}
	notes = append(notes, pageNotes...)
	check := &starterCheck{model: modelName, minAssists: minAssists, byKey: map[string]map[string]interface{}{}, partial: partial}
	for _, r := range rows {
		check.byKey[fmt.Sprint(r["key"])] = r
	}
	return check, notes
}

// maxPayout bounds --payout: a billion per conversion is far past any real order value, and keeps
// conversions × payout finite for any count a report can hold.
const maxPayout = 1e9

// attributionPage is the attribution report's largest page (AttributionReports::MAX_LIMIT), and the most keys one
// request takes.
const attributionPage = 1000

// fetchCheckRows reads the attribution rows for keys, 1000 keys a request: the server computes the whole report for
// every request, so asking for the rows by key reads it once instead of once per page. Without keys it pages through
// every row.
func fetchCheckRows(c *api.Client, q map[string]string, keys []string) ([]map[string]interface{}, bool, []string, error) {
	if len(keys) == 0 {
		return fetchAllAttributionRows(c, q)
	}
	var all []map[string]interface{}
	var notes []string
	partial := false
	for start := 0; start < len(keys); start += attributionPage {
		chunk := keys[start:min(start+attributionPage, len(keys))]
		page := map[string]string{"keys": strings.Join(chunk, ",")}
		for k, v := range q {
			page[k] = v
		}
		rows, p, n, err := fetchAllAttributionRows(c, page)
		if err != nil {
			return nil, false, nil, err
		}
		if start == 0 {
			notes = n // the backfill and cohort notes describe the report, not the chunk
		}
		all = append(all, rows...)
		partial = partial || p
	}
	return all, partial, notes, nil
}

// cohortEdgeNote is printed when a bounded range was read in the conversion cohort (a server without cohort=click).
const cohortEdgeNote = "this server counts attribution by sale date (it predates cohort=click), while the classic rows count " +
	"clicks made in the range, so clicks near the range's edges can differ"

// fetchAllAttributionRows pages through the attribution breakdown (offset, while offset+rows < meta.groups). A server
// from before offset existed refuses it (422); then the rows already read are kept and a note says how many of how
// many were checked. A note also says when the pre-upgrade backfill is still running (meta.backfill not null).
func fetchAllAttributionRows(c *api.Client, q map[string]string) ([]map[string]interface{}, bool, []string, error) {
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
			// Only the server's refusal of offset itself means "no paging here"; any other failure (an expired key, a
			// network error, a server error) leaves the check incomplete, so it is discarded.
			if offset > 0 && refusesOffset(err) {
				notes = append(notes, fmt.Sprintf("checked the top %d attribution rows; this server can't page the attribution report, so rows past them are marked unchecked", len(all)))
				return all, true, notes, nil //nolint:nilerr // a server without offset paging: keep the first page, marked partial
			}
			return nil, false, nil, err
		}
		var resp struct {
			Data []map[string]interface{} `json:"data"`
			Meta struct {
				Groups   *int        `json:"groups"`
				Backfill interface{} `json:"backfill"`
				Cohort   string      `json:"cohort"`
			} `json:"meta"`
		}
		if err := json.Unmarshal(data, &resp); err != nil {
			return nil, false, nil, err
		}
		if offset == 0 && resp.Meta.Backfill != nil {
			notes = append(notes, "attribution is incomplete for this range while older conversions are backfilled")
		}
		// A server that took cohort=click without applying it (none should: unknown parameters are refused).
		if offset == 0 && q["cohort"] != "" && resp.Meta.Cohort != q["cohort"] {
			notes = append(notes, cohortEdgeNote)
		}
		all = append(all, resp.Data...)
		if resp.Meta.Groups == nil {
			if len(resp.Data) == attributionPage {
				notes = append(notes, fmt.Sprintf("checked the top %d attribution rows; this server doesn't say how many there are, so rows past them are marked unchecked", len(all)))
				return all, true, notes, nil
			}
			return all, false, notes, nil
		}
		if len(resp.Data) == 0 || len(all) >= *resp.Meta.Groups {
			return all, false, notes, nil
		}
		offset = len(all)
	}
}

// refusesOffset says whether err is a server refusing the offset parameter (a server from before attribution paging:
// 422 "Unknown parameter(s): offset").
func refusesOffset(err error) bool {
	return refusesParam(err, "offset")
}

// refusesParam reports whether err is the server's 422 refusing the named parameter as unknown (a server that predates
// it), not one refusing its value.
func refusesParam(err error, name string) bool {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 422 || !strings.Contains(apiErr.Message, "Unknown parameter") {
		return false
	}
	if _, ok := apiErr.FieldErrors[name]; ok {
		return true
	}
	return strings.Contains(apiErr.Message, name)
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
		if check.partial {
			// Past the rows the server could return: it may start sales, but that couldn't be checked.
			row["attribution_checked"] = false
			row["reason"] = fmt.Sprintf("%s (attribution not checked: past the rows this server can return)", row["reason"])
		}
		return
	}
	assists := int64(toFloat(a["assisted_conversions"]))
	row["assisted_conversions"] = assists
	var why []string
	if roi, ok := check.firstTouchROI(a); check.model != "" && ok { // no ROI when the row had no cost in the attribution range
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

// overrideModel is a validated --first-touch-model: an active first_touch model, possibly still being recomputed.
type overrideModel struct {
	id, name string
	pending  bool
}

// firstTouchOverride reads --first-touch-model and checks it names an active first_touch model. The ROI is reported as
// first-touch ROI, and an inactive model has no credits, so either mistake would make the check wrong or silent.
func firstTouchOverride(c *api.Client, cmd *cobra.Command) (overrideModel, error) {
	id, _ := cmd.Flags().GetString("first-touch-model")
	if id == "" {
		return overrideModel{}, nil
	}
	data, err := c.Get("attribution/models/"+id, nil)
	if err != nil {
		return overrideModel{}, err
	}
	var resp struct {
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return overrideModel{}, err
	}
	if t := fmt.Sprint(resp.Data["model_type"]); t != "first_touch" {
		return overrideModel{}, validationError("--first-touch-model %s is a %s model; the check needs a first_touch model", id, t).
			WithHint("`p202 attribution model list` shows each model's type; omit the flag to use the first active First touch model.")
	}
	if st := fmt.Sprint(resp.Data["status"]); st != "active" {
		return overrideModel{}, validationError("--first-touch-model %s is %s; the check needs an active first_touch model", id, st).
			WithHint("Activate it with `p202 attribution model update " + id + " --status active`, or omit the flag to use the first active First touch model.")
	}
	return overrideModel{id: id, name: fmt.Sprint(resp.Data["model_name"]), pending: resp.Data["recompute_pending"] == true}, nil
}

// applyCloserCheck adds first-touch ROI and assists to a SCALE row and moves it to CLOSER when it loses money under
// first touch: last-click credits it with sales other rows started, so more budget won't bring more new buyers.
func applyCloserCheck(row map[string]interface{}, check *starterCheck) {
	if check == nil || check.model == "" {
		return
	}
	a, ok := check.byKey[fmt.Sprint(row["id"])]
	if !ok {
		if check.partial {
			row["attribution_checked"] = false
			row["reason"] = fmt.Sprintf("%s (attribution not checked: past the rows this server can return)", row["reason"])
		}
		return
	}
	row["assisted_conversions"] = int64(toFloat(a["assisted_conversions"]))
	roi, ok := check.firstTouchROI(a)
	if !ok {
		return
	}
	row["first_touch_roi"] = roi
	if roi < 0 {
		row["bucket"] = "CLOSER"
		row["reason"] = fmt.Sprintf("%s, but loses money under %s (ROI %+.1f%%): it closes sales other rows start, so more budget "+
			"won't bring more new buyers. Check what feeds it before scaling", row["reason"], check.model, roi)
	}
}

// triageCmd builds `report losers` / `report winners`.
func triageCmd(use, short string, wantWinners bool) *cobra.Command {
	c := &cobra.Command{
		Use:   use,
		Short: short,
		RunE: func(cmd *cobra.Command, args []string) error {
			if v, _ := cmd.Flags().GetString("first-touch-model"); v != "" && !positiveIntPattern.MatchString(v) {
				return validationError("invalid --first-touch-model %q: a positive whole number", v).
					WithHint("Run `p202 attribution model list` to find model ids.")
			}
			if cmd.Flags().Lookup("min-assists") != nil {
				if n, _ := cmd.Flags().GetInt64("min-assists"); n < 0 {
					return validationError("--min-assists must be 0 or more; got %d", n).
						WithHint("0 turns the assists rule off; 1 (the default) holds back any row that had a click in a sale another row closed.")
				}
			}
			payoutFlag, _ := cmd.Flags().GetFloat64("payout")
			// An explicit 0 is refused too: every payout branch below needs a positive value, so 0 would silently
			// read as no --payout at all (recorded revenue) rather than as zero revenue per conversion.
			if (cmd.Flags().Changed("payout") && payoutFlag <= 0) || payoutFlag < 0 || math.IsNaN(payoutFlag) || math.IsInf(payoutFlag, 0) {
				return validationError("--payout must be more than 0; got %g", payoutFlag).
					WithHint("Pass your revenue per conversion, like 160 for a $160 average order.")
			}
			// A finite but huge payout times a row's conversions overflows to +Inf, which JSON can't encode.
			if payoutFlag > maxPayout {
				return validationError("--payout must be at most %.0f; got %g", maxPayout, payoutFlag).
					WithHint("Pass your revenue per conversion, like 160 for a $160 average order.")
			}
			client, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			minClicks, _ := cmd.Flags().GetFloat64("min-clicks")
			maxCPC, _ := cmd.Flags().GetFloat64("max-cpc")
			campaignID, _ := cmd.Flags().GetString("aff_campaign_id")

			params := collectReportParams(cmd)
			breakdown, _ := cmd.Flags().GetString("breakdown")
			if breakdown == "" {
				breakdown = "keyword"
			}
			params["breakdown"] = resolveDimension(breakdown)

			// An explicit --first-touch-model is validated before any report is read, so a bad one is refused even when
			// the check would then be skipped for another reason (a filter, a dimension, a mixed range).
			noCheck, _ := cmd.Flags().GetBool("no-attribution-check")
			var override overrideModel
			if !noCheck {
				if override, err = firstTouchOverride(client, cmd); err != nil {
					return err
				}
			}

			// The break-even CPC target: --max-cpc as given, else payout × each row's conversion rate (in classify).
			// --payout sets the payout without filtering the report; --aff_campaign_id reads its campaign's payout but
			// also filters the report to that campaign, which turns the attribution check off.
			var payout float64
			switch {
			case maxCPC > 0:
			case payoutFlag > 0:
				payout = payoutFlag
			case campaignID != "":
				payout, _ = fetchCampaignPayout(client, campaignID)
			}

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
				if payoutFlag > 0 {
					// The stated revenue per conversion values every row's sales, so a row profitable at that payout is
					// a winner (and one that isn't, a loser) whatever the campaign's recorded income says.
					net = leads*payoutFlag - cost
				}
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
				if payoutFlag > 0 {
					row["payout"] = payoutFlag // total_net is total_leads × payout − total_cost
				}
				out = append(out, row)
			}
			// The attribution check reads only the rows kept, by id.
			if !noCheck && len(out) > 0 {
				var keys []string
				for _, row := range out {
					if id, ok := row["id"]; ok && id != nil {
						keys = append(keys, fmt.Sprint(id))
					}
				}
				check, notes := loadAttributionCheck(client, cmd, params["breakdown"], params, wantWinners, override, keys)
				if check != nil {
					check.payout = payoutFlag
				}
				for _, row := range out {
					if wantWinners {
						applyCloserCheck(row, check)
					} else {
						applyStarterCheck(row, check)
					}
				}
				for _, n := range notes {
					fmt.Fprintln(cmd.ErrOrStderr(), "Note: "+n)
				}
			}
			sortRowsBy(out, "total_net", !wantWinners) // losers: worst first; winners: best first
			// The classic bucket first (CUT / SCALE), then the rows the attribution check held back (TEST / CLOSER).
			first := "CUT"
			if wantWinners {
				first = "SCALE"
			}
			sort.SliceStable(out, func(i, j int) bool { return out[i]["bucket"] == first && out[j]["bucket"] != first })
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
	losers.Long = "Rows to CUT from the classic (last-click) report: zero conversions with spend, or CPC above break-even.\n" +
		"Break-even is --max-cpc, or a payout × the row's own conversion rate: --payout (your revenue per conversion) or\n" +
		"the payout of --aff_campaign_id. Without one, only zero-conversion spend is CUT: a row that sells at a loss is\n" +
		"WATCH and isn't listed. --payout doesn't filter the report, so the attribution check below still runs, and it\n" +
		"values every sale the command reports at that payout: total_net, and the first-touch ROI below.\n\n" +
		"Each CUT row is then checked against the attribution report for the same dimension and range. A row that\n" +
		"starts sales comes back as TEST, with its first-touch ROI and assists: it pays for itself as a first click\n" +
		"(first-touch ROI 0% or better), or it had a click in at least --min-assists sales (default 1) that another row\n" +
		"closed. Cutting it on last-click numbers would likely lose those sales, so test a cut on part of its traffic first.\n\n" +
		"The check runs for campaign, ppc_account (traffic source), landing_page, keyword and country. ROI comes from the\n" +
		"first active First touch model (or --first-touch-model); without one, rows are checked on assists only. It needs\n" +
		"an attribution:read key; when it can't run, the command still lists the classic losers and says why on stderr.\n" +
		"An entity filter other than the breakdown itself turns the check off (the attribution report is account-wide).\n" +
		"On a server that can't page the attribution report, rows past the first page are marked attribution_checked:\n" +
		"false. --no-attribution-check turns it off."
	winners := triageCmd("winners", "Rows to SCALE: profitable, converting keywords/geos; closers come back as CLOSER", true)
	winners.Long = "Rows to SCALE from the classic (last-click) report: profitable and converting. Profit is the campaign's\n" +
		"recorded income less cost, or with --payout (your revenue per conversion) conversions × payout less cost, the\n" +
		"value the first-touch ROI below then uses too.\n\n" +
		"Each SCALE row is then checked against the attribution report under a first-touch model, for the same dimension\n" +
		"and range. A row that loses money under first touch comes back as CLOSER, with its first-touch ROI and assists:\n" +
		"last-click credits it with sales other rows started (retargeting, brand search and email often look like this),\n" +
		"so more budget won't bring more new buyers. Check what feeds it before scaling.\n\n" +
		"The check runs for campaign, ppc_account (traffic source), landing_page, keyword and country, and needs a First\n" +
		"touch model (the first active one, or --first-touch-model) and an attribution:read key. When it can't run, the\n" +
		"classic winners are still listed with the reason on stderr. An entity filter other than the breakdown itself\n" +
		"turns it off; --no-attribution-check does too."
	for _, c := range []*cobra.Command{losers, winners} {
		addReportFilters(c)
		c.Flags().StringP("breakdown", "b", "keyword", "Dimension to triage")
		enumFlag(c, "breakdown", dimensionEnum(breakdownDimensions))
		c.Flags().Float64("min-clicks", 1, "Ignore rows with fewer than N clicks (significance floor)")
		c.Flags().Float64("max-cpc", 0, "Break-even CPC target (else payout × each row's CVR, from --payout or the campaign)")
		c.Flags().Float64("payout", 0, "Revenue per conversion, e.g. your average order value: each row's break-even CPC is this × its conversion rate, and its profit (total_net) and first-touch ROI value each sale at this. Unlike --aff_campaign_id it doesn't filter the report, so the attribution check still runs")
		reportCmd.AddCommand(c)
	}
	losers.Flags().String("first-touch-model", "", "Attribution model id for the starter check (default: the first active First touch model)")
	losers.Flags().Bool("no-attribution-check", false, "List classic last-click losers only, without the first-touch starter check")
	winners.Flags().String("first-touch-model", "", "Attribution model id for the closer check (default: the first active First touch model)")
	winners.Flags().Bool("no-attribution-check", false, "List classic last-click winners only, without the first-touch closer check")
	losers.Flags().Int64("min-assists", 1, "A CUT row with at least this many assisted sales comes back as TEST (0 turns the assists rule off)")
}
