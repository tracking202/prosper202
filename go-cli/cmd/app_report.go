package cmd

import (
	"encoding/json"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// The cross-platform app report (GET /apps/report): Apple's postbacks and
// Android's installs. The server refuses a grouping or a filter that only
// one platform has unless that platform is asked for; the same rules are
// checked here first, so the error names the flag (and says which
// --platform to add) rather than a JSON field, and needs no server.

var appReportSharedGroupings = []string{"day", "registration", "platform"}
var appReportIOSGroupings = []string{"ad-network", "source", "country", "version", "protocol", "conversion-type"}
var appReportAndroidGroupings = []string{"campaign", "match-state", "integrity-state", "goal"}

// appReportAndroidFlagDefs are the install filters the report takes beside
// the postback filters (appFilterFlagDefs, which are iOS's).
var appReportAndroidFlagDefs = []struct {
	flag  string
	param string
	help  string
}{
	{"match-state", "match_state", "Android: only installs in this match state (attributed, organic, bad_token, …)"},
	{"integrity-state", "integrity_state", "Android: only installs whose Play Integrity verdict is in this state"},
	{"trusted", "trusted", "Android: only this trust class (trusted, refuted, unvouched); goals are then counted over it"},
	{"test", "test", "Android: 1 = only test installs, 0 = only real ones"},
	{"aff-campaign-id", "aff_campaign_id", "Android: only installs on this campaign's clicks (`p202 campaign list`)"},
}

// appReportSharedFilters are postback filter flags that both platforms read.
var appReportSharedFilters = map[string]bool{"time-from": true, "time-to": true, "registration-id": true, "registration-ids": true}

var appReportCmd = &cobra.Command{
	Use:   "report",
	Short: "Cross-platform app report: iOS postbacks and Android installs, with the goals they reached",
	Long: "Reports Apple's postbacks by default (--platform ios, what this report meant before Android),\n" +
		"Android installs with --platform android, and both with --platform all. Any platform groups by\n" +
		"day (UTC), registration or platform; iOS also by ad-network, source, country, version, protocol\n" +
		"or conversion-type (Apple's postbacks, decoded through `p202 app encoding`), Android also by\n" +
		"campaign, match-state, integrity-state or goal (the funnel: installs that reached each goal).\n\n" +
		"Every row carries platform, installs, the trust-class counts, goals_reached, revenue and\n" +
		"events. Headline figures count trusted signals only: signature-verified postbacks, attributed\n" +
		"installs. --signature (iOS) or --trusted (Android) recompute them over one class.\n" +
		"Totals are in meta.totals: per platform, and with both platforms {ios, android, combined}.\n" +
		"Android figures are by install: a goal counts in its install's group.",
	Example: "  p202 app report --platform all --group-by registration\n" +
		"  p202 app report --platform android --group-by goal --registration-id 7\n" +
		"  p202 app report --platform ios --group-by ad-network --signature valid",
	RunE: func(cmd *cobra.Command, args []string) error {
		params, err := collectAppReportParams(cmd)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("apps/report", params)
		if err != nil {
			return err
		}
		render(reshapeAppReport(data))
		return nil
	},
}

func registerAppReportFlags(cmd *cobra.Command) {
	cmd.Flags().String("platform", "", "ios (the default), android, or all for both")
	cmd.Flags().String("group-by", "day", "Group by: day, registration, platform; with --platform ios also ad-network, source, country, version, protocol, conversion-type; with --platform android also campaign, match-state, integrity-state, goal")
	cmd.Flags().StringP("limit", "l", "", "Max groups per platform (default 100)")
	registerAppFilterFlags(cmd)
	for _, def := range appReportAndroidFlagDefs {
		cmd.Flags().String(def.flag, "", def.help)
	}
}

// collectAppReportParams validates the report's flags against the platform
// before any client is built.
func collectAppReportParams(cmd *cobra.Command) (map[string]string, error) {
	platform, _ := cmd.Flags().GetString("platform")
	platform = strings.ToLower(strings.TrimSpace(platform))
	sendPlatform := platform != ""
	if platform == "" {
		// The API's default, which is what the report meant before Android
		// installs existed; the answer names its platform either way.
		platform = "ios"
	}
	if platform != "ios" && platform != "android" && platform != "all" {
		return nil, validationError("--platform must be one of: ios, android, all, got %q", platform).
			WithHint("Use --platform all to report both platforms; leave it out for iOS.")
	}
	groupBy, _ := cmd.Flags().GetString("group-by")
	all := append(append(append([]string{}, appReportSharedGroupings...), appReportIOSGroupings...), appReportAndroidGroupings...)
	if groupBy != "" && !containsString(all, groupBy) {
		return nil, validationError("--group-by must be one of: %s, got %q", strings.Join(all, ", "), groupBy)
	}
	if containsString(appReportIOSGroupings, groupBy) && platform != "ios" {
		return nil, validationError("--group-by %s is a dimension of Apple's postbacks only", groupBy).
			WithHint("Use --platform ios (or leave --platform out), or group by %s to see both platforms.", strings.Join(appReportSharedGroupings, ", "))
	}
	if containsString(appReportAndroidGroupings, groupBy) && platform != "android" {
		return nil, validationError("--group-by %s is a dimension of Android installs only", groupBy).
			WithHint("Use --platform android, or group by %s with --platform all to see both platforms.", strings.Join(appReportSharedGroupings, ", "))
	}

	params := map[string]string{}
	for _, def := range appFilterFlagDefs {
		v, _ := cmd.Flags().GetString(def.flag)
		if v == "" {
			continue
		}
		if !appReportSharedFilters[def.flag] && platform != "ios" {
			return nil, validationError("--%s filters Apple's postbacks only", def.flag).
				WithHint("Use --platform ios (or leave --platform out) to report iOS alone.")
		}
		params[def.param] = v
	}
	for _, def := range appReportAndroidFlagDefs {
		v, _ := cmd.Flags().GetString(def.flag)
		if v == "" {
			continue
		}
		if platform != "android" {
			return nil, validationError("--%s filters Android installs only", def.flag).
				WithHint("Use --platform android to report Android alone.")
		}
		params[def.param] = v
	}
	if v, ok := params["match_state"]; ok && !matchStates[v] {
		return nil, validationError("--match-state must be one of: attributed, organic, third_party, unavailable, pending_click, bad_token, foreign_click, implausible, outside_window, duplicate_click, pending_integrity, integrity_failed, integrity_unverified; got %q", v)
	}
	if v, ok := params["integrity_state"]; ok && !integrityStates[v] {
		return nil, validationError("--integrity-state must be one of: not_requested, received, missing, pending, valid, invalid, error, skipped; got %q", v)
	}
	if v, ok := params["trusted"]; ok && v != "trusted" && v != "refuted" && v != "unvouched" {
		return nil, validationError("--trusted must be one of: trusted, refuted, unvouched; got %q", v)
	}
	if v, ok := params["test"]; ok && v != "0" && v != "1" {
		return nil, validationError("--test must be 0 or 1, got %q", v)
	}
	if v, ok := params["aff_campaign_id"]; ok && !positiveID.MatchString(v) {
		return nil, validationError("--aff-campaign-id must be a positive whole number, got %q", v).
			WithHint("`p202 campaign list` lists campaign ids.")
	}
	for _, f := range []string{"time_from", "time_to"} {
		if v, ok := params[f]; ok {
			if _, err := strconv.ParseInt(v, 10, 64); err != nil {
				return nil, validationError("--%s must be a unix timestamp in seconds, got %q", strings.ReplaceAll(f, "_", "-"), v)
			}
		}
	}
	if sendPlatform {
		params["platform"] = platform
	}
	if groupBy != "" {
		params["group_by"] = groupBy
	}
	limit, err := pagingFlagValue(cmd, "limit")
	if err != nil {
		return nil, err
	}
	if limit != "" {
		params["limit"] = limit
	}
	return params, nil
}

// reshapeAppReport lifts data.groups to the top-level data array — the shape
// every list command renders — moving group_by, platform and the totals
// into meta, so a table shows the groups and --json keeps everything.
// Anything unexpected is passed through untouched.
func reshapeAppReport(data []byte) []byte {
	var envelope struct {
		Data struct {
			GroupBy  string            `json:"group_by"`
			Platform string            `json:"platform"`
			Groups   []json.RawMessage `json:"groups"`
			Totals   json.RawMessage   `json:"totals"`
		} `json:"data"`
		Meta map[string]interface{} `json:"meta"`
	}
	if err := json.Unmarshal(data, &envelope); err != nil || envelope.Data.Groups == nil {
		return data
	}
	meta := envelope.Meta
	if meta == nil {
		meta = map[string]interface{}{}
	}
	meta["group_by"] = envelope.Data.GroupBy
	if envelope.Data.Platform != "" {
		meta["platform"] = envelope.Data.Platform
	}
	if len(envelope.Data.Totals) > 0 {
		meta["totals"] = envelope.Data.Totals
	}
	reshaped, err := json.Marshal(map[string]interface{}{
		"data": envelope.Data.Groups,
		"meta": meta,
	})
	if err != nil {
		return data
	}
	return reshaped
}
