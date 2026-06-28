package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/config"

	"github.com/spf13/cobra"
)

// probeClient is the HTTP client used to resolve tracking links: it follows no
// redirects (so we read the Location header) and has a timeout so a stalled
// host can't hang the command.
func probeClient() *http.Client {
	return &http.Client{
		Timeout:       15 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

// appendTrackingKW appends the test keyword param using the correct separator
// whether or not the link already has a query string.
func appendTrackingKW(link string) string {
	if strings.Contains(link, "?") {
		return link + "&t202kw=test"
	}
	return link + "?t202kw=test"
}

// geoIPs maps an ISO country code to a representative public IP, used to spoof
// the visitor's geo (via X-Forwarded-For) for tracker test.
var geoIPs = map[string]string{
	"US": "8.8.8.8",
	"CA": "24.48.0.1",
	"GB": "81.2.69.142",
	"UK": "81.2.69.142",
	"AU": "1.128.0.1",
	"DE": "85.214.132.117",
	"NL": "94.142.241.111",
	"FR": "90.84.144.1",
	"BR": "200.160.2.3",
	"IN": "103.48.196.1",
	"MX": "201.144.0.1",
}

// deviceUAs maps a friendly device name to a representative User-Agent.
var deviceUAs = map[string]string{
	"mobile":  "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1",
	"desktop": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36",
	"tablet":  "Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1",
}

// countryCodeFromValue extracts the ISO code from a single criteria token
// formatted as "United States(US)" -> "US".
func countryCodeFromValue(v string) string {
	open := strings.LastIndex(v, "(")
	closeP := strings.LastIndex(v, ")")
	if open >= 0 && closeP > open {
		return strings.ToUpper(strings.TrimSpace(v[open+1 : closeP]))
	}
	return strings.ToUpper(strings.TrimSpace(v))
}

// countryCodesFromValue parses a criteria value into the set of country codes,
// mirroring rtr.php which splits the value on commas (a rule criterion may list
// several countries, e.g. "United States(US),Canada(CA)").
func countryCodesFromValue(v string) []string {
	out := []string{}
	for _, tok := range strings.Split(v, ",") {
		if tok = strings.TrimSpace(tok); tok != "" {
			out = append(out, countryCodeFromValue(tok))
		}
	}
	return out
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

// rotatorDestination resolves which destination a visitor from geo hits, by
// evaluating the rotator's active rules locally (mirroring rtr.php precedence:
// first matching rule wins, else the default). Returns the matched rule name,
// a destination description, and per-criteria explain lines.
func rotatorDestination(data map[string]interface{}, geo string) (rule, dest string, explain []string) {
	geo = strings.ToUpper(strings.TrimSpace(geo))
	rules, _ := data["rules"].([]interface{})
	for _, ri := range rules {
		r, ok := ri.(map[string]interface{})
		if !ok {
			continue
		}
		if toFloat(r["status"]) != 1 {
			continue
		}
		crits, _ := r["criteria"].([]interface{})
		matched := true
		unsupported := ""
		for _, ci := range crits {
			cr, _ := ci.(map[string]interface{})
			ctype, _ := cr["type"].(string)
			stmt, _ := cr["statement"].(string)
			val, _ := cr["value"].(string)
			ok := true
			switch {
			case strings.EqualFold(strings.TrimSpace(val), "ALL"):
				ok = true // catch-all criterion matches any visitor
				explain = append(explain, fmt.Sprintf("rule %q: %s ALL -> true", r["rule_name"], stmt))
			case ctype == "country":
				codes := countryCodesFromValue(val)
				inList := contains(codes, geo)
				if stmt == "is_not" {
					ok = !inList
				} else {
					ok = inList
				}
				explain = append(explain, fmt.Sprintf("rule %q: country %s %s -> %v", r["rule_name"], stmt, strings.Join(codes, ","), ok))
			default:
				// device/browser/platform/ip/region/city criteria can't be
				// evaluated locally. Don't silently treat them as a match — flag
				// the rule as unverified so the result is never over-claimed.
				unsupported = ctype
				explain = append(explain, fmt.Sprintf("rule %q: %s criteria can't be evaluated locally — use `tracker test`", r["rule_name"], ctype))
			}
			if !ok {
				matched = false
				break
			}
		}
		// rtr.php treats a rule with zero criteria as a match (the criteria loop
		// runs zero times and count==count holds), so do the same.
		if matched {
			dest := redirectDest(r["redirects"])
			if unsupported != "" {
				dest += fmt.Sprintf(" (unverified: %s criteria)", unsupported)
			}
			return fmt.Sprintf("%v", r["rule_name"]), dest, explain
		}
	}
	return "(default)", defaultDest(data), explain
}

func redirectDest(redirects interface{}) string {
	arr, _ := redirects.([]interface{})
	if len(arr) == 0 {
		return "(no redirect)"
	}
	parts := make([]string, 0, len(arr))
	for _, ri := range arr {
		r, _ := ri.(map[string]interface{})
		parts = append(parts, destOf(r["redirect_campaign"], r["redirect_url"], r["redirect_lp"], r["weight"]))
	}
	return strings.Join(parts, " | ")
}

func defaultDest(data map[string]interface{}) string {
	return destOf(data["default_campaign"], data["default_url"], data["default_lp"], nil)
}

func destOf(campaign, url, lp, weight interface{}) string {
	w := ""
	if ws := fmt.Sprintf("%v", weight); weight != nil && ws != "" && ws != "0" && ws != "100" {
		w = " (w" + ws + ")"
	}
	if c := toFloat(campaign); c > 0 {
		return fmt.Sprintf("campaign %d%s", int64(c), w)
	}
	if s, _ := url.(string); s != "" {
		return s + w
	}
	if l := toFloat(lp); l > 0 {
		return fmt.Sprintf("landing_page %d%s", int64(l), w)
	}
	return "(none)"
}

var rotatorTestCmd = &cobra.Command{
	Use:   "test <rotator_id>",
	Short: "Show where a rotator routes traffic per geo (local rule evaluation)",
	Long: "Evaluates the rotator's active rules locally and prints the destination a\n" +
		"visitor from each --geo would reach. --explain shows per-criteria pass/fail,\n" +
		"which surfaces the Name(CC) country-format mismatches that silently break rules.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		raw, err := c.Get("rotators/"+args[0], nil)
		if err != nil {
			return err
		}
		var resp struct {
			Data map[string]interface{} `json:"data"`
		}
		if err := json.Unmarshal(raw, &resp); err != nil {
			return err
		}

		geos := splitCSV(mustString(cmd, "geo"))
		if len(geos) == 0 {
			geos = []string{"US", "GB", "CA", "AU", "BR", "IN"}
		}
		explain, _ := cmd.Flags().GetBool("explain")

		rows := make([]map[string]interface{}, 0, len(geos))
		for _, g := range geos {
			rule, dest, ex := rotatorDestination(resp.Data, g)
			row := map[string]interface{}{"geo": strings.ToUpper(g), "matched_rule": rule, "destination": dest}
			if explain {
				row["criteria"] = strings.Join(ex, "; ")
			}
			rows = append(rows, row)
		}
		render(rowsToJSON(rows))
		return nil
	},
}

var trackerTestCmd = &cobra.Command{
	Use:   "test <tracker_id>",
	Short: "Resolve a tracking link's live destination (follows the redirect)",
	Long: "Fetches the tracker's link and issues a real request per --geo/--device,\n" +
		"reporting the HTTP status and final destination. Flags a blank/dropped link.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		urlData, err := c.Get("trackers/"+args[0]+"/url", nil)
		if err != nil {
			return err
		}
		var resp struct {
			Data struct {
				DirectURL string `json:"direct_url"`
			} `json:"data"`
		}
		if err := json.Unmarshal(urlData, &resp); err != nil {
			return err
		}
		link := resp.Data.DirectURL
		if link == "" {
			return validationError("tracker has no resolvable link")
		}
		if !strings.HasPrefix(link, "http") {
			scheme := "http"
			if prof, _, perr := config.LoadProfileWithName(profileName); perr == nil && strings.HasPrefix(prof.URL, "https") {
				scheme = "https"
			}
			link = scheme + "://" + link
		}

		geos := splitCSV(mustString(cmd, "geo"))
		if len(geos) == 0 {
			geos = []string{""} // single request with the local geo
		}
		device, _ := cmd.Flags().GetString("device")

		client := probeClient()
		rows := make([]map[string]interface{}, 0, len(geos))
		for _, g := range geos {
			req, reqErr := http.NewRequest("GET", appendTrackingKW(link), nil)
			if reqErr != nil {
				return fmt.Errorf("building request: %w", reqErr)
			}
			if g != "" {
				if ip, ok := geoIPs[strings.ToUpper(g)]; ok {
					req.Header.Set("X-Forwarded-For", ip)
				}
			}
			if ua, ok := deviceUAs[strings.ToLower(device)]; ok {
				req.Header.Set("User-Agent", ua)
			}
			row := map[string]interface{}{"geo": strings.ToUpper(g)}
			if g == "" {
				row["geo"] = "(local)"
			}
			res, err := client.Do(req)
			if err != nil {
				row["status"] = "ERR"
				row["destination"] = err.Error()
			} else {
				loc := res.Header.Get("Location")
				row["status"] = fmt.Sprintf("%d", res.StatusCode)
				if loc == "" {
					row["destination"] = "(blank — click dropped!)"
				} else {
					row["destination"] = loc
				}
				res.Body.Close()
			}
			rows = append(rows, row)
		}
		render(rowsToJSON(rows))
		return nil
	},
}

func splitCSV(s string) []string {
	out := []string{}
	for _, p := range strings.Split(s, ",") {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

func mustString(cmd *cobra.Command, name string) string {
	v, _ := cmd.Flags().GetString(name)
	return v
}

// rotatorIssues returns config problems for a single rotator's data.
func rotatorIssues(d map[string]interface{}) []string {
	var issues []string
	rules, _ := d["rules"].([]interface{})
	activeRules := 0
	for _, ri := range rules {
		r, _ := ri.(map[string]interface{})
		if toFloat(r["status"]) == 1 {
			activeRules++
		}
		if reds, _ := r["redirects"].([]interface{}); len(reds) == 0 {
			issues = append(issues, fmt.Sprintf("rule %q has no redirect", r["rule_name"]))
		}
	}
	hasDefault := defaultDest(d) != "(none)"
	if activeRules == 0 && !hasDefault {
		issues = append(issues, "no active rules and no default — every click is dropped")
	}
	return issues
}

var rotatorCheckCmd = &cobra.Command{
	Use:   "check [rotator_id]",
	Short: "Health-check rotators: flag missing defaults, ruleless rotators, empty rules",
	Long:  "Exits non-zero if any checked rotator has a configuration problem, so it can gate a deploy.",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		var datas []map[string]interface{}
		if len(args) == 1 {
			raw, err := c.Get("rotators/"+args[0], nil)
			if err != nil {
				return err
			}
			var resp struct {
				Data map[string]interface{} `json:"data"`
			}
			if err := json.Unmarshal(raw, &resp); err != nil {
				return err
			}
			datas = append(datas, resp.Data)
		} else {
			list, err := c.Get("rotators", map[string]string{"limit": "500"})
			if err != nil {
				return err
			}
			var resp struct {
				Data []map[string]interface{} `json:"data"`
			}
			if err := json.Unmarshal(list, &resp); err != nil {
				return err
			}
			for _, r := range resp.Data {
				full, err := c.Get(fmt.Sprintf("rotators/%v", normalizeID(r["id"])), nil)
				if err != nil {
					continue
				}
				var fr struct {
					Data map[string]interface{} `json:"data"`
				}
				if json.Unmarshal(full, &fr) == nil {
					datas = append(datas, fr.Data)
				}
			}
		}

		rows := make([]map[string]interface{}, 0, len(datas))
		failed := 0
		for _, d := range datas {
			issues := rotatorIssues(d)
			status := "OK"
			if len(issues) > 0 {
				status = "ISSUES"
				failed++
			}
			rows = append(rows, map[string]interface{}{
				"id":     normalizeID(d["id"]),
				"name":   d["name"],
				"status": status,
				"reason": strings.Join(issues, "; "),
			})
		}
		render(rowsToJSON(rows))
		if failed > 0 {
			return partialFailureError("%d rotator(s) have configuration issues", failed)
		}
		return nil
	},
}

var rotatorTraceCmd = &cobra.Command{
	Use:   "trace <rotator_id>",
	Short: "Show the trackers that feed a rotator and where it routes",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		raw, err := c.Get("rotators/"+args[0], nil)
		if err != nil {
			return err
		}
		var rot struct {
			Data map[string]interface{} `json:"data"`
		}
		if err := json.Unmarshal(raw, &rot); err != nil {
			return err
		}
		trk, err := c.Get("trackers", map[string]string{"filter[rotator_id]": args[0]})
		if err != nil {
			return err
		}
		var tr struct {
			Data []map[string]interface{} `json:"data"`
		}
		_ = json.Unmarshal(trk, &tr)

		fmt.Fprintf(os.Stderr, "Rotator %s %q — default %s, %d rule(s), fed by %d tracker(s)\n",
			args[0], fmt.Sprintf("%v", rot.Data["name"]), defaultDest(rot.Data),
			len(asSlice(rot.Data["rules"])), len(tr.Data))
		rows := make([]map[string]interface{}, 0, len(tr.Data))
		for _, t := range tr.Data {
			rows = append(rows, map[string]interface{}{
				"tracker_id":        normalizeID(t["tracker_id"]),
				"tracker_id_public": normalizeID(t["tracker_id_public"]),
				"ppc_account_id":    normalizeID(t["ppc_account_id"]),
				"click_cpc":         t["click_cpc"],
			})
		}
		render(rowsToJSON(rows))
		return nil
	},
}

func asSlice(v interface{}) []interface{} {
	s, _ := v.([]interface{})
	return s
}

// resolveTrackerLink fetches and follows a tracker's link, returning status and
// destination (used by tracker test and tracker check).
func resolveTrackerLink(c *api.Client, id, geo, device string) (status, dest string) {
	urlData, err := c.Get("trackers/"+id+"/url", nil)
	if err != nil {
		return "ERR", err.Error()
	}
	var resp struct {
		Data struct {
			DirectURL string `json:"direct_url"`
		} `json:"data"`
	}
	if json.Unmarshal(urlData, &resp) != nil || resp.Data.DirectURL == "" {
		return "ERR", "no link"
	}
	link := resp.Data.DirectURL
	if !strings.HasPrefix(link, "http") {
		scheme := "http"
		if prof, _, perr := config.LoadProfileWithName(profileName); perr == nil && strings.HasPrefix(prof.URL, "https") {
			scheme = "https"
		}
		link = scheme + "://" + link
	}
	client := probeClient()
	req, reqErr := http.NewRequest("GET", appendTrackingKW(link), nil)
	if reqErr != nil {
		return "ERR", reqErr.Error()
	}
	if ip, ok := geoIPs[strings.ToUpper(geo)]; ok {
		req.Header.Set("X-Forwarded-For", ip)
	}
	if ua, ok := deviceUAs[strings.ToLower(device)]; ok {
		req.Header.Set("User-Agent", ua)
	}
	res, err := client.Do(req)
	if err != nil {
		return "ERR", err.Error()
	}
	defer res.Body.Close()
	loc := res.Header.Get("Location")
	if loc == "" {
		return fmt.Sprintf("%d", res.StatusCode), "(blank — click dropped!)"
	}
	return fmt.Sprintf("%d", res.StatusCode), loc
}

var trackerCheckCmd = &cobra.Command{
	Use:   "check [tracker_id]",
	Short: "Resolve tracker links and flag blank/dropped destinations",
	Long:  "Exits non-zero if any checked tracker resolves to a blank Location or a non-3xx status.",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		var ids []string
		if len(args) == 1 {
			ids = append(ids, args[0])
		} else {
			list, err := c.Get("trackers", map[string]string{"limit": "500"})
			if err != nil {
				return err
			}
			var resp struct {
				Data []map[string]interface{} `json:"data"`
			}
			_ = json.Unmarshal(list, &resp)
			for _, t := range resp.Data {
				ids = append(ids, fmt.Sprintf("%v", normalizeID(t["tracker_id"])))
			}
		}
		rows := make([]map[string]interface{}, 0, len(ids))
		failed := 0
		for _, id := range ids {
			status, dest := resolveTrackerLink(c, id, "", "")
			ok := strings.HasPrefix(status, "3")
			if !ok {
				failed++
			}
			rows = append(rows, map[string]interface{}{
				"tracker_id":  id,
				"status":      status,
				"destination": dest,
			})
		}
		render(rowsToJSON(rows))
		if failed > 0 {
			return partialFailureError("%d tracker(s) did not resolve to a redirect", failed)
		}
		return nil
	},
}

func init() {
	rotatorCmd.AddCommand(rotatorCheckCmd, rotatorTraceCmd)
	if tc := findChildCommand("tracker"); tc != nil {
		tc.AddCommand(trackerCheckCmd)
	}
	rotatorTestCmd.Flags().String("geo", "", "Comma-separated country codes to evaluate (default: a tier-1+rest sample)")
	rotatorTestCmd.Flags().Bool("explain", false, "Show per-criteria pass/fail for each geo")
	rotatorCmd.AddCommand(rotatorTestCmd)

	trackerTestCmd.Flags().String("geo", "", "Comma-separated country codes to simulate (spoofs X-Forwarded-For)")
	trackerTestCmd.Flags().String("device", "", "Device to simulate: mobile, desktop, tablet")
	// The tracker command is built dynamically in crud.go's init() (which runs
	// before this file's init), so look it up by name to attach the subcommand.
	if tc := findChildCommand("tracker"); tc != nil {
		tc.AddCommand(trackerTestCmd)
	}
}

// findChildCommand returns the direct subcommand of rootCmd with the given name.
func findChildCommand(name string) *cobra.Command {
	for _, c := range rootCmd.Commands() {
		if c.Name() == name {
			return c
		}
	}
	return nil
}
