package cmd

import (
	"fmt"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// prefFlag is one `user prefs update` flag; the flag is the preference
// column it writes. TestPrefFlagsMatchThePreferenceRules holds this table to
// Prosper202\User\PreferenceRules, which the server checks every value
// against: a flag the server does not know would be refused there, and a
// choice it does not offer would be refused here first.
type prefFlag struct {
	name   string
	desc   string
	values []string // fixed choices; nil: free text
	clear  bool     // `--<name> ""` is sent, and clears the value
}

// accountCurrencies are UsersController::SUPPORTED_CURRENCIES.
var accountCurrencies = []string{
	"AUD", "BRL", "CAD", "CHF", "CNY", "CZK", "DKK", "EUR", "GBP", "HKD",
	"HUF", "ILS", "INR", "JPY", "MXN", "MYR", "NOK", "NZD", "PHP", "PLN",
	"RUB", "SEK", "SGD", "THB", "TRY", "TWD", "USD",
}

// dailyEmailHours are the hours Personal settings offers; "never" is sent as "".
var dailyEmailHours = func() []string {
	out := []string{"never"}
	for h := 0; h < 24; h++ {
		out = append(out, fmt.Sprintf("%02d", h))
	}
	return out
}()

var prefFlags = []prefFlag{
	// Personal settings
	{name: "user_tracking_domain", desc: "Domain tracking links are built on, e.g. track.example.com (\"\" uses this install's own)", clear: true},
	{name: "user_daily_email", desc: "Hour the daily stats email is sent, in your time zone", values: dailyEmailHours},
	{name: "user_keyword_searched_or_bidded", desc: "Keyword to record: the one searched or the one bid on", values: []string{"searched", "bidded"}},
	{name: "user_pref_referer_data", desc: "Referer to record: the browser's, or the t202ref link parameter", values: []string{"browser", "t202ref"}},
	{name: "user_pref_dynamic_bid", desc: "Click cost: 0 from the tracker's setup, 1 from the t202b link parameter", values: binaryValues},
	{name: "user_pref_privacy", desc: "Privacy mode: disabled, eu (European traffic only) or all traffic", values: []string{"disabled", "eu", "all"}},
	{name: "user_pref_cloak_referer", desc: "What a cloaked link shows as referer: origin (this domain) or never (blank)", values: []string{"origin", "never"}},
	{name: "user_pref_ad_settings", desc: "Where Prosper202 shows its ads", values: []string{"show_all", "hide_login", "hide_all"}},
	{name: "user_account_currency", desc: "Account currency; re-prices every campaign's payout into it", values: accountCurrencies},
	// Report defaults (the report pages' saved choices)
	{name: "user_pref_time_predefined", desc: "Default report date range", values: []string{"today", "yesterday", "last7", "last14", "last30", "thismonth", "lastmonth", "thisyear", "lastyear", "alltime"}},
	{name: "user_pref_limit", desc: "Default rows per report page", values: []string{"10", "25", "50", "75", "100", "150", "200"}},
	{name: "user_cpc_or_cpv", desc: "Show costs per click (cpc) or per view (cpv, 5 decimals)", values: []string{"cpc", "cpv"}},
	{name: "chart_time_range", desc: "Overview chart resolution", values: []string{"hours", "days"}},
	// Integrations (secrets: a value here stays in shell history)
	{name: "user_slack_incoming_webhook", desc: "Slack incoming webhook URL, https:// (\"\" stops Slack notifications)", clear: true},
	{name: "ipqs_api_key", desc: "IPQualityScore API key (\"\" removes it)", clear: true},
	{name: "cb_key", desc: "ClickBank secret key; changing it resets its verification (\"\" removes it)", clear: true},
	{name: "zaxaa_api_signature", desc: "Zaxaa API signature (\"\" removes it)", clear: true},
	{name: "jvzoo_ipn_secret_key", desc: "JVZoo IPN secret key (\"\" removes it)", clear: true},
	// LTV › Settings
	{name: "user_ltv_customer_cparam", desc: "Tracking variable that carries a customer id: 0 off, 1-4 for c1-c4", values: []string{"0", "1", "2", "3", "4"}},
	{name: "user_ltv_personalization_fields", desc: "Fields a landing page may personalize with: comma list of first_name, last_name, company, city, country, cf:<field_key>, rec:next_offer (\"\" none)", clear: true},
	{name: "user_ltv_score_weights", desc: "Engagement score weights, volume:N,time:N,scroll:N,video:N,recency:N summing to 100 (\"\" the defaults 40/20/15/15/10)", clear: true},
	{name: "user_ltv_rec_fatigue", desc: "Next-offer fatigue: times,days such as 3,21; 0 turns it off (\"\" the defaults)", clear: true},
}

func registerPrefFlags(cmd *cobra.Command) {
	for _, f := range prefFlags {
		cmd.Flags().String(f.name, "", f.desc)
		if f.values != nil {
			enumFlag(cmd, f.name, newEnum(f.values))
		}
		if f.clear {
			allowEmpty(cmd, f.name)
		}
	}
}

func runUserPrefsUpdate(cmd *cobra.Command, args []string) error {
	userID, err := validateID(args[0])
	if err != nil {
		return err
	}
	body := map[string]interface{}{}
	for _, f := range prefFlags {
		if !cmd.Flags().Changed(f.name) {
			continue
		}
		v, _ := cmd.Flags().GetString(f.name)
		if f.values != nil {
			v = enumValue(cmd, f.name)
		}
		if f.name == "user_daily_email" && v == "never" {
			v = ""
		}
		body[f.name] = v
	}
	if len(body) == 0 {
		return validationError("no preferences specified; pass at least one flag to update").
			WithHint("`p202 user prefs update --help` lists them; `p202 user prefs get " + userID + "` shows the current values.")
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Put("users/"+userID+"/preferences", body)
	if err != nil {
		return err
	}
	render(data)
	return nil
}
