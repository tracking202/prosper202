package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"p202/internal/api"
	syncdata "p202/internal/sync"

	"github.com/spf13/cobra"
)

var syncDependencyOrder = []string{
	"aff-networks",
	"ppc-networks",
	"ppc-accounts",
	"campaigns",
	"landing-pages",
	"text-ads",
	"trackers",
}

var syncEntityIDFields = map[string][]string{
	"aff-networks":  {"aff_network_id", "id"},
	"ppc-networks":  {"ppc_network_id", "id"},
	"ppc-accounts":  {"ppc_account_id", "id"},
	"campaigns":     {"aff_campaign_id", "id"},
	"landing-pages": {"landing_page_id", "id"},
	"text-ads":      {"text_ad_id", "id"},
	"trackers":      {"tracker_id", "id"},
}

var syncFKDependencies = map[string]map[string]string{
	"ppc-accounts": {
		"ppc_network_id": "ppc-networks",
	},
	"campaigns": {
		"aff_network_id": "aff-networks",
	},
	"landing-pages": {
		"aff_campaign_id": "campaigns",
	},
	"text-ads": {
		"aff_campaign_id": "campaigns",
		"landing_page_id": "landing-pages",
	},
	"trackers": {
		"aff_campaign_id": "campaigns",
		"ppc_account_id":  "ppc-accounts",
		"landing_page_id": "landing-pages",
		"text_ad_id":      "text-ads",
	},
}

var syncCmd = &cobra.Command{
	Use:   "sync <entity|all>",
	Short: "Sync entities from one profile to another",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		entity := strings.TrimSpace(args[0])
		entities, err := selectedSyncEntities(entity)
		if err != nil {
			return err
		}

		fromProfile, _ := cmd.Flags().GetString("from")
		toProfile, _ := cmd.Flags().GetString("to")
		fromProfile = strings.TrimSpace(fromProfile)
		toProfile = strings.TrimSpace(toProfile)
		if fromProfile == "" || toProfile == "" {
			return fmt.Errorf("--from and --to are required")
		}

		dryRun, _ := cmd.Flags().GetBool("dry-run")
		skipErrors, _ := cmd.Flags().GetBool("skip-errors")
		forceUpdate, _ := cmd.Flags().GetBool("force-update")

		sourceClient, err := api.NewFromProfile(fromProfile)
		if err != nil {
			return err
		}
		targetClient, err := api.NewFromProfile(toProfile)
		if err != nil {
			return err
		}

		sourceData, err := fetchPortableEntityData(sourceClient)
		if err != nil {
			return fmt.Errorf("fetch source profile data: %w", err)
		}
		targetData, err := fetchPortableEntityData(targetClient)
		if err != nil {
			return fmt.Errorf("fetch target profile data: %w", err)
		}

		sourceLookups := buildEntityLookups(sourceData)
		targetLookups := buildEntityLookups(targetData)
		idMap := syncdata.NewIDMapping()
		results := map[string]syncdata.EntityResult{}

		for _, currentEntity := range entities {
			result := syncdata.EntityResult{}
			targetIndex := buildEntityIndex(currentEntity, targetData[currentEntity], targetLookups)
			sourceRows := append([]map[string]interface{}(nil), sourceData[currentEntity]...)
			sort.SliceStable(sourceRows, func(i, j int) bool {
				return naturalKeyForEntity(currentEntity, sourceRows[i], sourceLookups) < naturalKeyForEntity(currentEntity, sourceRows[j], sourceLookups)
			})

			for _, sourceRow := range sourceRows {
				key := naturalKeyForEntity(currentEntity, sourceRow, sourceLookups)
				if key == "" {
					err := fmt.Errorf("source record has empty natural key")
					if !handleSyncRecordError(currentEntity, key, err, skipErrors, &result) {
						return err
					}
					continue
				}

				targetRow, exists := targetIndex[key]
				payload, payloadErr := buildSyncPayload(currentEntity, sourceRow, sourceLookups, targetLookups)
				if payloadErr != nil {
					if !handleSyncRecordError(currentEntity, key, payloadErr, skipErrors, &result) {
						return payloadErr
					}
					continue
				}

				if exists {
					sourceComparable := normalizeComparableRecord(currentEntity, sourceRow, sourceLookups)
					targetComparable := normalizeComparableRecord(currentEntity, targetRow, targetLookups)
					if comparableEqual(sourceComparable, targetComparable) {
						result.Skipped++
						recordEntityMapping(currentEntity, sourceRow, targetRow, idMap)
						continue
					}
					if !forceUpdate {
						result.Skipped++
						continue
					}

					targetID := firstStringFromRow(targetRow, syncEntityIDFields[currentEntity]...)
					if targetID == "" {
						err := fmt.Errorf("target record missing ID for key %q", key)
						if !handleSyncRecordError(currentEntity, key, err, skipErrors, &result) {
							return err
						}
						continue
					}

					if !dryRun {
						if _, err := targetClient.Put(portableEntities[currentEntity]+"/"+targetID, payload); err != nil {
							if !handleSyncRecordError(currentEntity, key, err, skipErrors, &result) {
								return err
							}
							continue
						}
						updated := cloneMap(payload)
						updated[syncEntityIDFields[currentEntity][0]] = parseNumericOrString(targetID)
						targetIndex[key] = updated
						replaceEntityRecord(targetData, currentEntity, key, updated, targetLookups)
						targetLookups = buildEntityLookups(targetData)
					}

					recordEntityMapping(currentEntity, sourceRow, targetRow, idMap)
					result.Synced++
					continue
				}

				if dryRun {
					result.Synced++
					continue
				}

				resp, err := targetClient.Post(portableEntities[currentEntity], payload)
				if err != nil {
					if !handleSyncRecordError(currentEntity, key, err, skipErrors, &result) {
						return err
					}
					continue
				}

				createdID := extractCreatedEntityID(currentEntity, resp)
				created := cloneMap(payload)
				if createdID != "" {
					created[syncEntityIDFields[currentEntity][0]] = parseNumericOrString(createdID)
				}
				targetData[currentEntity] = append(targetData[currentEntity], created)
				targetLookups = buildEntityLookups(targetData)
				targetIndex[naturalKeyForEntity(currentEntity, created, targetLookups)] = created
				recordEntityMappingByID(currentEntity, sourceRow, createdID, idMap)

				result.Synced++
			}

			results[currentEntity] = result
		}

		payload, _ := json.Marshal(map[string]interface{}{
			"source":       fromProfile,
			"target":       toProfile,
			"dry_run":      dryRun,
			"force_update": forceUpdate,
			"results":      results,
		})
		render(payload)
		return nil
	},
}

func selectedSyncEntities(entity string) ([]string, error) {
	if entity == "all" {
		return append([]string(nil), syncDependencyOrder...), nil
	}
	if _, ok := portableEntities[entity]; !ok {
		return nil, fmt.Errorf("unsupported entity %q", entity)
	}
	return []string{entity}, nil
}

func buildEntityIndex(entity string, rows []map[string]interface{}, lookups entityLookups) map[string]map[string]interface{} {
	index := map[string]map[string]interface{}{}
	for _, row := range rows {
		key := naturalKeyForEntity(entity, row, lookups)
		if key == "" {
			continue
		}
		index[key] = row
	}
	return index
}

func buildSyncPayload(entity string, sourceRow map[string]interface{}, sourceLookups, targetLookups entityLookups) (map[string]interface{}, error) {
	payload := stripImmutableFields(entity, sourceRow)
	deps := syncFKDependencies[entity]
	for fkField, refEntity := range deps {
		rawSourceID := scalarString(sourceRow[fkField])
		if rawSourceID == "" {
			continue
		}

		sourceNatural := referenceNaturalByEntity(refEntity, rawSourceID, sourceLookups)
		if sourceNatural == "" {
			return nil, fmt.Errorf("unresolvable source foreign key %s=%s (entity %s)", fkField, rawSourceID, entity)
		}

		targetID := referenceIDByNatural(refEntity, sourceNatural, targetLookups)
		if targetID == "" {
			return nil, fmt.Errorf("unresolvable target foreign key %s via %s=%s", fkField, refEntity, sourceNatural)
		}

		payload[fkField] = parseNumericOrString(targetID)
	}

	return payload, nil
}

func referenceNaturalByEntity(entity, sourceID string, lookups entityLookups) string {
	switch entity {
	case "aff-networks":
		return lookups.affNetworks[sourceID]
	case "ppc-networks":
		return lookups.ppcNetworks[sourceID]
	case "ppc-accounts":
		return lookups.ppcAccounts[sourceID]
	case "campaigns":
		return lookups.campaigns[sourceID]
	case "landing-pages":
		return lookups.landingPages[sourceID]
	case "text-ads":
		return lookups.textAds[sourceID]
	default:
		return ""
	}
}

func referenceIDByNatural(entity, natural string, lookups entityLookups) string {
	switch entity {
	case "aff-networks":
		return lookups.affNetworkIDs[natural]
	case "ppc-networks":
		return lookups.ppcNetworkIDs[natural]
	case "ppc-accounts":
		return lookups.ppcAccountIDs[natural]
	case "campaigns":
		return lookups.campaignIDs[natural]
	case "landing-pages":
		return lookups.landingPageIDs[natural]
	case "text-ads":
		return lookups.textAdIDs[natural]
	default:
		return ""
	}
}

func parseNumericOrString(raw string) interface{} {
	if raw == "" {
		return raw
	}
	isDigits := true
	for _, ch := range raw {
		if ch < '0' || ch > '9' {
			isDigits = false
			break
		}
	}
	if isDigits {
		return raw
	}
	return raw
}

func cloneMap(in map[string]interface{}) map[string]interface{} {
	out := map[string]interface{}{}
	for k, v := range in {
		out[k] = v
	}
	return out
}

func extractCreatedEntityID(entity string, resp []byte) string {
	obj, err := parseDataObject(resp)
	if err != nil {
		return ""
	}
	return firstStringFromRow(obj, syncEntityIDFields[entity]...)
}

func recordEntityMapping(entity string, sourceRow, targetRow map[string]interface{}, idMap *syncdata.IDMapping) {
	sourceID := firstStringFromRow(sourceRow, syncEntityIDFields[entity]...)
	targetID := firstStringFromRow(targetRow, syncEntityIDFields[entity]...)
	idMap.Set(entity, sourceID, targetID)
}

func recordEntityMappingByID(entity string, sourceRow map[string]interface{}, targetID string, idMap *syncdata.IDMapping) {
	sourceID := firstStringFromRow(sourceRow, syncEntityIDFields[entity]...)
	idMap.Set(entity, sourceID, targetID)
}

func replaceEntityRecord(targetData map[string][]map[string]interface{}, entity, key string, updated map[string]interface{}, lookups entityLookups) {
	rows := targetData[entity]
	for i, row := range rows {
		if naturalKeyForEntity(entity, row, lookups) == key {
			rows[i] = updated
			targetData[entity] = rows
			return
		}
	}
	targetData[entity] = append(targetData[entity], updated)
}

func handleSyncRecordError(entity, key string, err error, skipErrors bool, result *syncdata.EntityResult) bool {
	result.Failed++
	msg := fmt.Sprintf("%s[%s]: %v", entity, key, err)
	result.Errors = append(result.Errors, msg)
	return skipErrors
}

func init() {
	syncCmd.Flags().String("from", "", "Source profile name")
	syncCmd.Flags().String("to", "", "Target profile name")
	syncCmd.Flags().Bool("dry-run", false, "Show sync actions without writing")
	syncCmd.Flags().Bool("skip-errors", false, "Continue processing after record-level failures")
	syncCmd.Flags().Bool("force-update", false, "Update mismatched target records")
	_ = syncCmd.MarkFlagRequired("from")
	_ = syncCmd.MarkFlagRequired("to")

	rootCmd.AddCommand(syncCmd)
}
