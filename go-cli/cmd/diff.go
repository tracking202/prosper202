package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

type entityLookups struct {
	affNetworks  map[string]string
	ppcNetworks  map[string]string
	ppcAccounts  map[string]string
	campaigns    map[string]string
	landingPages map[string]string
	textAds      map[string]string
}

var diffCmd = &cobra.Command{
	Use:   "diff <entity|all>",
	Short: "Compare entities between two profiles",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		target := strings.TrimSpace(args[0])
		if target == "" {
			return fmt.Errorf("entity is required")
		}
		if target != "all" {
			if _, ok := portableEntities[target]; !ok {
				return fmt.Errorf("unsupported entity %q", target)
			}
		}

		fromProfile, _ := cmd.Flags().GetString("from")
		toProfile, _ := cmd.Flags().GetString("to")
		fromProfile = strings.TrimSpace(fromProfile)
		toProfile = strings.TrimSpace(toProfile)
		if fromProfile == "" || toProfile == "" {
			return fmt.Errorf("--from and --to are required")
		}

		fromClient, err := api.NewFromProfile(fromProfile)
		if err != nil {
			return err
		}
		toClient, err := api.NewFromProfile(toProfile)
		if err != nil {
			return err
		}

		fromData, err := fetchPortableEntityData(fromClient)
		if err != nil {
			return fmt.Errorf("fetch source profile data: %w", err)
		}
		toData, err := fetchPortableEntityData(toClient)
		if err != nil {
			return fmt.Errorf("fetch target profile data: %w", err)
		}

		fromLookups := buildEntityLookups(fromData)
		toLookups := buildEntityLookups(toData)

		if target == "all" {
			entities := sortedPortableEntities()
			results := map[string]interface{}{}
			overall := map[string]int{
				"only_in_source": 0,
				"only_in_target": 0,
				"changed":        0,
				"identical":      0,
			}
			for _, entity := range entities {
				result := diffEntity(entity, fromData[entity], toData[entity], fromLookups, toLookups)
				results[entity] = result
				overall["only_in_source"] += toInt(result["only_in_source_count"])
				overall["only_in_target"] += toInt(result["only_in_target_count"])
				overall["changed"] += toInt(result["changed_count"])
				overall["identical"] += toInt(result["identical_count"])
			}

			payload, _ := json.Marshal(map[string]interface{}{
				"from":    fromProfile,
				"to":      toProfile,
				"summary": overall,
				"data":    results,
			})
			render(payload)
			return nil
		}

		result := diffEntity(target, fromData[target], toData[target], fromLookups, toLookups)
		payload, _ := json.Marshal(map[string]interface{}{
			"from": fromProfile,
			"to":   toProfile,
			"data": result,
		})
		render(payload)
		return nil
	},
}

func fetchPortableEntityData(c *api.Client) (map[string][]map[string]interface{}, error) {
	out := map[string][]map[string]interface{}{}
	for _, entity := range sortedPortableEntities() {
		rows, err := fetchAllRows(c, portableEntities[entity])
		if err != nil {
			return nil, fmt.Errorf("%s: %w", entity, err)
		}
		out[entity] = rows
	}
	return out, nil
}

func sortedPortableEntities() []string {
	entities := make([]string, 0, len(portableEntities))
	for name := range portableEntities {
		entities = append(entities, name)
	}
	sort.Strings(entities)
	return entities
}

func buildEntityLookups(data map[string][]map[string]interface{}) entityLookups {
	return entityLookups{
		affNetworks:  buildIDLookup(data["aff-networks"], "aff_network_id", "id", "aff_network_name"),
		ppcNetworks:  buildIDLookup(data["ppc-networks"], "ppc_network_id", "id", "ppc_network_name"),
		ppcAccounts:  buildIDLookup(data["ppc-accounts"], "ppc_account_id", "id", "ppc_account_name"),
		campaigns:    buildIDLookup(data["campaigns"], "aff_campaign_id", "id", "aff_campaign_name"),
		landingPages: buildIDLookup(data["landing-pages"], "landing_page_id", "id", "landing_page_url"),
		textAds:      buildIDLookup(data["text-ads"], "text_ad_id", "id", "text_ad_name"),
	}
}

func buildIDLookup(rows []map[string]interface{}, idFields ...string) map[string]string {
	if len(idFields) < 2 {
		return map[string]string{}
	}
	nameField := idFields[len(idFields)-1]
	keys := idFields[:len(idFields)-1]

	out := map[string]string{}
	for _, row := range rows {
		id := firstStringFromRow(row, keys...)
		if id == "" {
			continue
		}
		name := scalarString(row[nameField])
		if name == "" {
			name = "id:" + id
		}
		out[id] = name
	}
	return out
}

func diffEntity(entity string, sourceRows, targetRows []map[string]interface{}, sourceLookups, targetLookups entityLookups) map[string]interface{} {
	sourceIndex := map[string]map[string]interface{}{}
	targetIndex := map[string]map[string]interface{}{}
	sourceKeyOrder := make([]string, 0, len(sourceRows))
	targetKeyOrder := make([]string, 0, len(targetRows))

	for _, row := range sourceRows {
		key := naturalKeyForEntity(entity, row, sourceLookups)
		if key == "" {
			continue
		}
		if _, exists := sourceIndex[key]; !exists {
			sourceKeyOrder = append(sourceKeyOrder, key)
		}
		sourceIndex[key] = row
	}
	for _, row := range targetRows {
		key := naturalKeyForEntity(entity, row, targetLookups)
		if key == "" {
			continue
		}
		if _, exists := targetIndex[key]; !exists {
			targetKeyOrder = append(targetKeyOrder, key)
		}
		targetIndex[key] = row
	}

	sort.Strings(sourceKeyOrder)
	sort.Strings(targetKeyOrder)

	onlyInSource := make([]map[string]interface{}, 0)
	onlyInTarget := make([]map[string]interface{}, 0)
	changed := make([]map[string]interface{}, 0)
	identicalCount := 0

	seen := map[string]bool{}
	for _, key := range sourceKeyOrder {
		seen[key] = true
		sourceRow := sourceIndex[key]
		targetRow, exists := targetIndex[key]
		if !exists {
			onlyInSource = append(onlyInSource, map[string]interface{}{
				"key":    key,
				"record": normalizeComparableRecord(entity, sourceRow, sourceLookups),
			})
			continue
		}

		sourceComparable := normalizeComparableRecord(entity, sourceRow, sourceLookups)
		targetComparable := normalizeComparableRecord(entity, targetRow, targetLookups)
		if comparableEqual(sourceComparable, targetComparable) {
			identicalCount++
			continue
		}

		changed = append(changed, map[string]interface{}{
			"key":            key,
			"source":         sourceComparable,
			"target":         targetComparable,
			"changed_fields": changedFields(sourceComparable, targetComparable),
		})
	}

	for _, key := range targetKeyOrder {
		if seen[key] {
			continue
		}
		targetRow := targetIndex[key]
		onlyInTarget = append(onlyInTarget, map[string]interface{}{
			"key":    key,
			"record": normalizeComparableRecord(entity, targetRow, targetLookups),
		})
	}

	return map[string]interface{}{
		"entity":               entity,
		"only_in_source_count": len(onlyInSource),
		"only_in_target_count": len(onlyInTarget),
		"changed_count":        len(changed),
		"identical_count":      identicalCount,
		"only_in_source":       onlyInSource,
		"only_in_target":       onlyInTarget,
		"changed":              changed,
	}
}

func naturalKeyForEntity(entity string, row map[string]interface{}, lookups entityLookups) string {
	switch entity {
	case "aff-networks":
		return scalarString(row["aff_network_name"])
	case "ppc-networks":
		return scalarString(row["ppc_network_name"])
	case "ppc-accounts":
		return scalarString(row["ppc_account_name"])
	case "campaigns":
		return scalarString(row["aff_campaign_name"])
	case "landing-pages":
		return scalarString(row["landing_page_url"])
	case "text-ads":
		return scalarString(row["text_ad_name"])
	case "trackers":
		campaign := remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
		account := remapForeignKey(row, "ppc_account_id", lookups.ppcAccounts)
		landing := remapForeignKey(row, "landing_page_id", lookups.landingPages)
		textAd := remapForeignKey(row, "text_ad_id", lookups.textAds)
		return strings.Join([]string{
			"campaign=" + campaign,
			"ppc_account=" + account,
			"landing_page=" + landing,
			"text_ad=" + textAd,
			"rotator=" + scalarString(row["rotator_id"]),
			"click_cpc=" + scalarString(row["click_cpc"]),
			"click_cpa=" + scalarString(row["click_cpa"]),
			"click_cloaking=" + scalarString(row["click_cloaking"]),
		}, "|")
	default:
		return scalarString(row["id"])
	}
}

func normalizeComparableRecord(entity string, row map[string]interface{}, lookups entityLookups) map[string]interface{} {
	base := stripImmutableFields(entity, row)
	out := map[string]interface{}{}
	for k, v := range base {
		out[k] = v
	}

	switch entity {
	case "ppc-accounts":
		out["ppc_network_id"] = remapForeignKey(row, "ppc_network_id", lookups.ppcNetworks)
	case "campaigns":
		out["aff_network_id"] = remapForeignKey(row, "aff_network_id", lookups.affNetworks)
	case "landing-pages":
		out["aff_campaign_id"] = remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
	case "text-ads":
		out["aff_campaign_id"] = remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
		out["landing_page_id"] = remapForeignKey(row, "landing_page_id", lookups.landingPages)
	case "trackers":
		out["aff_campaign_id"] = remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
		out["ppc_account_id"] = remapForeignKey(row, "ppc_account_id", lookups.ppcAccounts)
		out["landing_page_id"] = remapForeignKey(row, "landing_page_id", lookups.landingPages)
		out["text_ad_id"] = remapForeignKey(row, "text_ad_id", lookups.textAds)
	}

	return out
}

func remapForeignKey(row map[string]interface{}, field string, lookup map[string]string) string {
	rawID := scalarString(row[field])
	if rawID == "" {
		return ""
	}
	if val, ok := lookup[rawID]; ok && val != "" {
		return val
	}
	return "id:" + rawID
}

func firstStringFromRow(row map[string]interface{}, keys ...string) string {
	for _, key := range keys {
		if val := scalarString(row[key]); val != "" {
			return val
		}
	}
	return ""
}

func scalarString(v interface{}) string {
	switch val := v.(type) {
	case nil:
		return ""
	case string:
		return strings.TrimSpace(val)
	case float64:
		if val == float64(int64(val)) {
			return strconv.FormatInt(int64(val), 10)
		}
		return strconv.FormatFloat(val, 'f', -1, 64)
	case int:
		return strconv.Itoa(val)
	case int64:
		return strconv.FormatInt(val, 10)
	case bool:
		if val {
			return "true"
		}
		return "false"
	default:
		return strings.TrimSpace(fmt.Sprintf("%v", val))
	}
}

func comparableEqual(a, b map[string]interface{}) bool {
	aBytes, _ := json.Marshal(a)
	bBytes, _ := json.Marshal(b)
	return bytes.Equal(aBytes, bBytes)
}

func changedFields(a, b map[string]interface{}) []string {
	keys := map[string]bool{}
	for k := range a {
		keys[k] = true
	}
	for k := range b {
		keys[k] = true
	}

	out := make([]string, 0)
	sortedKeys := make([]string, 0, len(keys))
	for k := range keys {
		sortedKeys = append(sortedKeys, k)
	}
	sort.Strings(sortedKeys)

	for _, key := range sortedKeys {
		if scalarString(a[key]) != scalarString(b[key]) {
			out = append(out, key)
		}
	}
	return out
}

func toInt(v interface{}) int {
	switch val := v.(type) {
	case int:
		return val
	case float64:
		return int(val)
	default:
		return 0
	}
}

func init() {
	diffCmd.Flags().String("from", "", "Source profile name")
	diffCmd.Flags().String("to", "", "Target profile name")
	_ = diffCmd.MarkFlagRequired("from")
	_ = diffCmd.MarkFlagRequired("to")

	rootCmd.AddCommand(diffCmd)
}
