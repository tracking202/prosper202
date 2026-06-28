package cmd

import (
	"fmt"
	"sort"
	"strings"

	"github.com/spf13/cobra"
)

// countryNames maps ISO 3166-1 alpha-2 codes to the country name. The rotator
// criteria value format is "Name(CC)", so --country US builds "United States(US)".
// Covers the common affiliate geos; use raw --criteria_json for anything absent.
var countryNames = map[string]string{
	"US": "United States", "CA": "Canada", "GB": "United Kingdom", "AU": "Australia",
	"NZ": "New Zealand", "IE": "Ireland", "NL": "Netherlands", "DE": "Germany",
	"FR": "France", "ES": "Spain", "IT": "Italy", "PT": "Portugal", "BE": "Belgium",
	"CH": "Switzerland", "AT": "Austria", "SE": "Sweden", "NO": "Norway",
	"DK": "Denmark", "FI": "Finland", "PL": "Poland", "CZ": "Czechia",
	"RO": "Romania", "GR": "Greece", "HU": "Hungary", "RU": "Russia",
	"UA": "Ukraine", "TR": "Turkey", "BR": "Brazil", "MX": "Mexico",
	"AR": "Argentina", "CL": "Chile", "CO": "Colombia", "PE": "Peru",
	"IN": "India", "PK": "Pakistan", "BD": "Bangladesh", "ID": "Indonesia",
	"PH": "Philippines", "VN": "Vietnam", "TH": "Thailand", "MY": "Malaysia",
	"SG": "Singapore", "JP": "Japan", "KR": "South Korea", "CN": "China",
	"HK": "Hong Kong", "TW": "Taiwan", "ZA": "South Africa", "NG": "Nigeria",
	"EG": "Egypt", "KE": "Kenya", "SA": "Saudi Arabia", "AE": "United Arab Emirates",
	"IL": "Israel", "GB-UK": "United Kingdom",
}

// countryCriteriaValue returns the "Name(CC)" rotator-criteria value for a code,
// or "" if the code is unknown.
func countryCriteriaValue(code string) string {
	code = strings.ToUpper(strings.TrimSpace(code))
	if code == "UK" {
		code = "GB"
	}
	if name, ok := countryNames[code]; ok {
		return fmt.Sprintf("%s(%s)", name, code)
	}
	return ""
}

var rotatorCriteriaValuesCmd = &cobra.Command{
	Use:   "criteria-values",
	Short: "List valid rotator criteria values (e.g. the exact country strings)",
	Long:  "Prints the value strings rules expect, so you never guess the `United States(US)` format. Filter with --search.",
	RunE: func(cmd *cobra.Command, args []string) error {
		ctype, _ := cmd.Flags().GetString("type")
		search := strings.ToLower(mustString(cmd, "search"))
		if ctype != "" && ctype != "country" {
			return validationError("only --type country is supported")
		}
		rows := make([]map[string]interface{}, 0, len(countryNames))
		for code, name := range countryNames {
			if code == "GB-UK" {
				continue
			}
			value := fmt.Sprintf("%s(%s)", name, code)
			if search != "" && !strings.Contains(strings.ToLower(value), search) && !strings.Contains(strings.ToLower(code), search) {
				continue
			}
			rows = append(rows, map[string]interface{}{"code": code, "name": name, "value": value})
		}
		sort.Slice(rows, func(i, j int) bool { return rows[i]["name"].(string) < rows[j]["name"].(string) })
		render(rowsToJSON(rows))
		return nil
	},
}

func init() {
	rotatorCriteriaValuesCmd.Flags().String("type", "country", "Criteria type (country)")
	rotatorCriteriaValuesCmd.Flags().String("search", "", "Filter values by substring")
	rotatorCmd.AddCommand(rotatorCriteriaValuesCmd)
}
