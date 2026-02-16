package cmd

import (
	"sort"

	configpkg "p202/internal/config"

	"github.com/spf13/cobra"
)

var allowedDefaultKeys = map[string]bool{
	"report.period":          true,
	"report.time_from":       true,
	"report.time_to":         true,
	"report.aff_campaign_id": true,
	"report.ppc_account_id":  true,
	"report.aff_network_id":  true,
	"report.ppc_network_id":  true,
	"report.landing_page_id": true,
	"report.country_id":      true,
	"report.breakdown":       true,
	"report.sort":            true,
	"report.sort_dir":        true,
	"report.limit":           true,
	"report.offset":          true,
	"report.interval":        true,
	"crud.aff_campaign_id":   true,
	"crud.ppc_account_id":    true,
	"crud.aff_network_id":    true,
	"crud.ppc_network_id":    true,
	"crud.landing_page_id":   true,
	"crud.text_ad_id":        true,
	"crud.rotator_id":        true,
	"crud.country_id":        true,
}

func supportedDefaultKeys() []string {
	keys := make([]string, 0, len(allowedDefaultKeys))
	for key := range allowedDefaultKeys {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}

func isSupportedDefaultKey(key string) bool {
	return allowedDefaultKeys[key]
}

func getConfigDefault(scope, key string) string {
	profile, err := configpkg.LoadProfile()
	if err != nil {
		return ""
	}
	return profile.GetDefault(scope + "." + key)
}

func getStringFlagOrDefault(cmd *cobra.Command, scope, key string) string {
	if v, _ := cmd.Flags().GetString(key); v != "" {
		return v
	}
	return getConfigDefault(scope, key)
}
