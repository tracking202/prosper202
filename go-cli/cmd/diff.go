package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"
	configpkg "p202/internal/config"

	"github.com/spf13/cobra"
)

type entityLookups struct {
	affNetworks    map[string]string
	affNetworkIDs  map[string]string
	ppcNetworks    map[string]string
	ppcNetworkIDs  map[string]string
	ppcAccounts    map[string]string
	ppcAccountIDs  map[string]string
	campaigns      map[string]string
	campaignIDs    map[string]string
	landingPages   map[string]string
	landingPageIDs map[string]string
	textAds        map[string]string
	textAdIDs      map[string]string
	rotators       map[string]string
	rotatorIDs     map[string]string
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

		if fromClient.SupportsCapability("sync_features", "sync_plan") {
			sourceConn, srcErr := loadProfileConnection(fromProfile)
			targetConn, tgtErr := loadProfileConnection(toProfile)
			if srcErr == nil && tgtErr == nil {
				serverPayload := map[string]interface{}{
					"entity": target,
					"source": sourceConn,
					"target": targetConn,
				}
				if resp, srvErr := fromClient.Post("sync/plan", serverPayload); srvErr == nil {
					render(resp)
					return nil
				}
			}
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
		if entity == "rotators" {
			rows = enrichRotatorsWithRules(c, rows)
		}
		out[entity] = rows
	}
	return out, nil
}

func enrichRotatorsWithRules(c *api.Client, rows []map[string]interface{}) []map[string]interface{} {
	if len(rows) == 0 {
		return rows
	}

	enriched := make([]map[string]interface{}, 0, len(rows))
	for _, row := range rows {
		copyRow := cloneMap(row)
		rotatorID := firstStringFromRow(row, "id")
		if rotatorID == "" {
			copyRow["rules"] = []interface{}{}
			enriched = append(enriched, copyRow)
			continue
		}

		raw, err := c.Get("rotators/"+rotatorID, nil)
		if err != nil {
			copyRow["rules"] = []interface{}{}
			enriched = append(enriched, copyRow)
			continue
		}

		detail, err := parseDataObject(raw)
		if err != nil {
			copyRow["rules"] = []interface{}{}
			enriched = append(enriched, copyRow)
			continue
		}

		if rules, ok := detail["rules"]; ok {
			copyRow["rules"] = rules
		} else {
			copyRow["rules"] = []interface{}{}
		}
		enriched = append(enriched, copyRow)
	}
	return enriched
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
	affByID, affByNatural := buildIDLookup(data["aff-networks"], "aff_network_id", "id", "aff_network_name")
	ppcNetByID, ppcNetByNatural := buildIDLookup(data["ppc-networks"], "ppc_network_id", "id", "ppc_network_name")
	ppcAccountByID, ppcAccountByNatural := buildIDLookup(data["ppc-accounts"], "ppc_account_id", "id", "ppc_account_name")
	campaignByID, campaignByNatural := buildIDLookup(data["campaigns"], "aff_campaign_id", "id", "aff_campaign_name")
	landingByID, landingByNatural := buildIDLookup(data["landing-pages"], "landing_page_id", "id", "landing_page_url")
	textAdByID, textAdByNatural := buildIDLookup(data["text-ads"], "text_ad_id", "id", "text_ad_name")
	rotatorByID, rotatorByNatural := buildIDLookup(data["rotators"], "id", "public_id")

	return entityLookups{
		affNetworks:    affByID,
		affNetworkIDs:  affByNatural,
		ppcNetworks:    ppcNetByID,
		ppcNetworkIDs:  ppcNetByNatural,
		ppcAccounts:    ppcAccountByID,
		ppcAccountIDs:  ppcAccountByNatural,
		campaigns:      campaignByID,
		campaignIDs:    campaignByNatural,
		landingPages:   landingByID,
		landingPageIDs: landingByNatural,
		textAds:        textAdByID,
		textAdIDs:      textAdByNatural,
		rotators:       rotatorByID,
		rotatorIDs:     rotatorByNatural,
	}
}

func buildIDLookup(rows []map[string]interface{}, idFields ...string) (map[string]string, map[string]string) {
	if len(idFields) < 2 {
		return map[string]string{}, map[string]string{}
	}
	nameField := idFields[len(idFields)-1]
	keys := idFields[:len(idFields)-1]

	byID := map[string]string{}
	byNatural := map[string]string{}
	for _, row := range rows {
		id := firstStringFromRow(row, keys...)
		if id == "" {
			continue
		}
		name := scalarString(row[nameField])
		if name == "" {
			name = "id:" + id
		}
		byID[id] = name
		if _, exists := byNatural[name]; !exists {
			byNatural[name] = id
		}
	}
	return byID, byNatural
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
	case "rotators":
		return "pub=" + scalarString(row["public_id"])
	case "trackers":
		campaign := remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
		account := remapForeignKey(row, "ppc_account_id", lookups.ppcAccounts)
		landing := remapForeignKey(row, "landing_page_id", lookups.landingPages)
		textAd := remapForeignKey(row, "text_ad_id", lookups.textAds)
		rotator := remapForeignKey(row, "rotator_id", lookups.rotators)
		return strings.Join([]string{
			"campaign=" + campaign,
			"ppc_account=" + account,
			"landing_page=" + landing,
			"text_ad=" + textAd,
			"rotator=" + rotator,
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
	case "rotators":
		out["default_campaign"] = remapForeignKey(row, "default_campaign", lookups.campaigns)
		out["default_lp"] = remapForeignKey(row, "default_lp", lookups.landingPages)
		out["rules"] = normalizeRulesForComparison(row["rules"], lookups)
	case "trackers":
		out["aff_campaign_id"] = remapForeignKey(row, "aff_campaign_id", lookups.campaigns)
		out["ppc_account_id"] = remapForeignKey(row, "ppc_account_id", lookups.ppcAccounts)
		out["landing_page_id"] = remapForeignKey(row, "landing_page_id", lookups.landingPages)
		out["text_ad_id"] = remapForeignKey(row, "text_ad_id", lookups.textAds)
	}

	return out
}

func normalizeRulesForComparison(rawRules interface{}, lookups entityLookups) []interface{} {
	rulesSlice := toInterfaceSlice(rawRules)
	if len(rulesSlice) == 0 {
		return []interface{}{}
	}

	normalized := make([]map[string]interface{}, 0, len(rulesSlice))
	for _, item := range rulesSlice {
		rule, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		normalized = append(normalized, map[string]interface{}{
			"rule_name": scalarString(rule["rule_name"]),
			"splittest": scalarString(rule["splittest"]),
			"status":    scalarString(rule["status"]),
			"criteria":  normalizeCriteria(rule["criteria"]),
			"redirects": normalizeRedirects(rule["redirects"], lookups),
		})
	}

	sort.SliceStable(normalized, func(i, j int) bool {
		left := scalarString(normalized[i]["rule_name"]) + "|" + scalarString(normalized[i]["status"]) + "|" + scalarString(normalized[i]["splittest"])
		right := scalarString(normalized[j]["rule_name"]) + "|" + scalarString(normalized[j]["status"]) + "|" + scalarString(normalized[j]["splittest"])
		return left < right
	})

	out := make([]interface{}, len(normalized))
	for i, rule := range normalized {
		out[i] = rule
	}
	return out
}

func normalizeCriteria(raw interface{}) []interface{} {
	slice := toInterfaceSlice(raw)
	if len(slice) == 0 {
		return []interface{}{}
	}

	result := make([]map[string]interface{}, 0, len(slice))
	for _, item := range slice {
		criterion, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		result = append(result, map[string]interface{}{
			"type":      scalarString(criterion["type"]),
			"statement": scalarString(criterion["statement"]),
			"value":     scalarString(criterion["value"]),
		})
	}

	sort.SliceStable(result, func(i, j int) bool {
		left := scalarString(result[i]["type"]) + "|" + scalarString(result[i]["statement"]) + "|" + scalarString(result[i]["value"])
		right := scalarString(result[j]["type"]) + "|" + scalarString(result[j]["statement"]) + "|" + scalarString(result[j]["value"])
		return left < right
	})

	out := make([]interface{}, len(result))
	for i, criterion := range result {
		out[i] = criterion
	}
	return out
}

func normalizeRedirects(raw interface{}, lookups entityLookups) []interface{} {
	slice := toInterfaceSlice(raw)
	if len(slice) == 0 {
		return []interface{}{}
	}

	result := make([]map[string]interface{}, 0, len(slice))
	for _, item := range slice {
		redirect, ok := item.(map[string]interface{})
		if !ok {
			continue
		}
		result = append(result, map[string]interface{}{
			"redirect_url":      scalarString(redirect["redirect_url"]),
			"redirect_campaign": remapForeignKeyValue(redirect["redirect_campaign"], lookups.campaigns),
			"redirect_lp":       remapForeignKeyValue(redirect["redirect_lp"], lookups.landingPages),
			"weight":            scalarString(redirect["weight"]),
			"name":              scalarString(redirect["name"]),
		})
	}

	sort.SliceStable(result, func(i, j int) bool {
		left := scalarString(result[i]["name"]) + "|" + scalarString(result[i]["weight"]) + "|" + scalarString(result[i]["redirect_url"])
		right := scalarString(result[j]["name"]) + "|" + scalarString(result[j]["weight"]) + "|" + scalarString(result[j]["redirect_url"])
		return left < right
	})

	out := make([]interface{}, len(result))
	for i, redirect := range result {
		out[i] = redirect
	}
	return out
}

func remapForeignKey(row map[string]interface{}, field string, lookup map[string]string) string {
	rawID := scalarString(row[field])
	if rawID == "" || rawID == "0" {
		return ""
	}
	if val, ok := lookup[rawID]; ok && val != "" {
		return val
	}
	return "id:" + rawID
}

func remapForeignKeyValue(raw interface{}, lookup map[string]string) string {
	rawID := scalarString(raw)
	if rawID == "" || rawID == "0" {
		return ""
	}
	if val, ok := lookup[rawID]; ok && val != "" {
		return val
	}
	return "id:" + rawID
}

func toInterfaceSlice(raw interface{}) []interface{} {
	switch v := raw.(type) {
	case []interface{}:
		return v
	case []map[string]interface{}:
		out := make([]interface{}, len(v))
		for i := range v {
			out[i] = v[i]
		}
		return out
	default:
		return nil
	}
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
		aRaw, aExists := a[key]
		bRaw, bExists := b[key]
		if !aExists || !bExists {
			out = append(out, key)
			continue
		}
		aBytes, _ := json.Marshal(aRaw)
		bBytes, _ := json.Marshal(bRaw)
		if !bytes.Equal(aBytes, bBytes) {
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

func loadProfileConnection(profileName string) (map[string]interface{}, error) {
	profile, resolvedName, err := configpkg.LoadProfileWithName(profileName)
	if err != nil {
		return nil, err
	}
	if err := profile.Validate(); err != nil {
		return nil, err
	}
	return map[string]interface{}{
		"name":    resolvedName,
		"url":     profile.URL,
		"api_key": profile.APIKey,
	}, nil
}

func init() {
	diffCmd.Flags().String("from", "", "Source profile name")
	diffCmd.Flags().String("to", "", "Target profile name")
	_ = diffCmd.MarkFlagRequired("from")
	_ = diffCmd.MarkFlagRequired("to")

	rootCmd.AddCommand(diffCmd)
}
