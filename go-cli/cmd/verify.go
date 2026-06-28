package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"p202/internal/api"
	"p202/internal/config"

	"github.com/spf13/cobra"
)

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

// countryCodeFromValue extracts the ISO code from a criteria value formatted as
// "United States(US)" -> "US".
func countryCodeFromValue(v string) string {
	open := strings.LastIndex(v, "(")
	closeP := strings.LastIndex(v, ")")
	if open >= 0 && closeP > open {
		return strings.ToUpper(strings.TrimSpace(v[open+1 : closeP]))
	}
	return strings.ToUpper(strings.TrimSpace(v))
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
		for _, ci := range crits {
			cr, _ := ci.(map[string]interface{})
			ctype, _ := cr["type"].(string)
			stmt, _ := cr["statement"].(string)
			val, _ := cr["value"].(string)
			ok := true
			if ctype == "country" {
				cc := countryCodeFromValue(val)
				if stmt == "is_not" {
					ok = geo != cc
				} else {
					ok = geo == cc
				}
				explain = append(explain, fmt.Sprintf("rule %q: country %s %s -> %v", r["rule_name"], stmt, cc, ok))
			}
			if !ok {
				matched = false
				break
			}
		}
		if matched && len(crits) > 0 {
			return fmt.Sprintf("%v", r["rule_name"]), redirectDest(r["redirects"]), explain
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

		client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
		rows := make([]map[string]interface{}, 0, len(geos))
		for _, g := range geos {
			req, _ := http.NewRequest("GET", link+"&t202kw=test", nil)
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

func init() {
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
