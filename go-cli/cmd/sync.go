package cmd

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"strings"
	"time"

	"p202/internal/api"
	syncdata "p202/internal/sync"
	"p202/internal/syncstate"

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

type syncOptions struct {
	DryRun      bool
	SkipErrors  bool
	ForceUpdate bool
	Incremental bool
}

type syncRunOutput struct {
	Results     map[string]syncdata.EntityResult
	IDMap       *syncdata.IDMapping
	SourceNames map[string]map[string]string
	SourceHash  map[string]map[string]string
}

var syncCmd = &cobra.Command{
	Use:   "sync <entity|all>",
	Short: "Sync entities from one profile to another",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		opts, fromProfile, toProfile, err := readSyncFlags(cmd)
		if err != nil {
			return err
		}
		return executeSync(args[0], fromProfile, toProfile, opts)
	},
}

var syncStatusCmd = &cobra.Command{
	Use:   "status",
	Short: "Show sync status and drift summary between profiles",
	RunE: func(cmd *cobra.Command, args []string) error {
		fromProfile, _ := cmd.Flags().GetString("from")
		toProfile, _ := cmd.Flags().GetString("to")
		fromProfile = strings.TrimSpace(fromProfile)
		toProfile = strings.TrimSpace(toProfile)
		if fromProfile == "" || toProfile == "" {
			return fmt.Errorf("--from and --to are required")
		}

		manifest, err := syncstate.LoadManifest(fromProfile, toProfile)
		if err != nil {
			return err
		}

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
			return err
		}
		targetData, err := fetchPortableEntityData(targetClient)
		if err != nil {
			return err
		}

		perEntity := map[string]map[string]interface{}{}
		for _, entity := range syncDependencyOrder {
			sourceIDs := collectEntityIDs(entity, sourceData[entity])
			targetIDs := collectEntityIDs(entity, targetData[entity])
			mappings := manifest.Mappings[entity]

			missingSource := 0
			missingTarget := 0
			for sourceID, entry := range mappings {
				if !sourceIDs[sourceID] {
					missingSource++
				}
				if !targetIDs[entry.TargetID] {
					missingTarget++
				}
			}

			newSource := 0
			for sourceID := range sourceIDs {
				if _, ok := mappings[sourceID]; !ok {
					newSource++
				}
			}

			perEntity[entity] = map[string]interface{}{
				"source_count":      len(sourceData[entity]),
				"target_count":      len(targetData[entity]),
				"mapping_count":     len(mappings),
				"new_source":        newSource,
				"missing_on_source": missingSource,
				"missing_on_target": missingTarget,
			}
		}

		payload, _ := json.Marshal(map[string]interface{}{
			"source":    fromProfile,
			"target":    toProfile,
			"last_sync": manifest.LastSync,
			"data":      perEntity,
		})
		render(payload)
		return nil
	},
}

var syncHistoryCmd = &cobra.Command{
	Use:   "history",
	Short: "Show sync history between profiles",
	RunE: func(cmd *cobra.Command, args []string) error {
		fromProfile, _ := cmd.Flags().GetString("from")
		toProfile, _ := cmd.Flags().GetString("to")
		fromProfile = strings.TrimSpace(fromProfile)
		toProfile = strings.TrimSpace(toProfile)
		if fromProfile == "" || toProfile == "" {
			return fmt.Errorf("--from and --to are required")
		}

		manifest, err := syncstate.LoadManifest(fromProfile, toProfile)
		if err != nil {
			return err
		}

		payload, _ := json.Marshal(map[string]interface{}{
			"source":    fromProfile,
			"target":    toProfile,
			"last_sync": manifest.LastSync,
			"data":      manifest.History,
		})
		render(payload)
		return nil
	},
}

var reSyncCmd = &cobra.Command{
	Use:   "re-sync",
	Short: "Run incremental sync using stored sync manifest",
	RunE: func(cmd *cobra.Command, args []string) error {
		opts, fromProfile, toProfile, err := readSyncFlags(cmd)
		if err != nil {
			return err
		}
		opts.Incremental = true
		return executeSync("all", fromProfile, toProfile, opts)
	},
}

func executeSync(entityArg, fromProfile, toProfile string, opts syncOptions) error {
	entities, err := selectedSyncEntities(entityArg)
	if err != nil {
		return err
	}

	var releaseLock func()
	if !opts.DryRun {
		releaseLock, err = syncstate.AcquireLock(fromProfile, toProfile)
		if err != nil {
			return err
		}
		defer releaseLock()
	}

	var manifest *syncstate.Manifest
	if opts.Incremental || !opts.DryRun {
		manifest, err = syncstate.LoadManifest(fromProfile, toProfile)
		if err != nil {
			return err
		}
	}

	runOutput, err := runSyncProfiles(entities, fromProfile, toProfile, opts, manifest)
	if err != nil {
		return err
	}

	if !opts.DryRun {
		now := time.Now().UTC()
		for entity, mappings := range runOutput.IDMap.All() {
			for sourceID, targetID := range mappings {
				sourceName := runOutput.SourceNames[entity][sourceID]
				sourceHash := runOutput.SourceHash[entity][sourceID]
				manifest.SetMapping(entity, sourceID, targetID, sourceName, sourceHash, now)
			}
		}
		manifest.RecordHistory(runOutput.Results, opts.DryRun, now)
		if err := syncstate.SaveManifestAtomic(manifest); err != nil {
			return err
		}
	}

	payload, _ := json.Marshal(map[string]interface{}{
		"source":       fromProfile,
		"target":       toProfile,
		"dry_run":      opts.DryRun,
		"force_update": opts.ForceUpdate,
		"incremental":  opts.Incremental,
		"results":      runOutput.Results,
	})
	render(payload)
	return nil
}

func runSyncProfiles(entities []string, fromProfile, toProfile string, opts syncOptions, manifest *syncstate.Manifest) (*syncRunOutput, error) {
	sourceClient, err := api.NewFromProfile(fromProfile)
	if err != nil {
		return nil, err
	}
	targetClient, err := api.NewFromProfile(toProfile)
	if err != nil {
		return nil, err
	}

	sourceData, err := fetchPortableEntityData(sourceClient)
	if err != nil {
		return nil, fmt.Errorf("fetch source profile data: %w", err)
	}
	targetData, err := fetchPortableEntityData(targetClient)
	if err != nil {
		return nil, fmt.Errorf("fetch target profile data: %w", err)
	}

	sourceLookups := buildEntityLookups(sourceData)
	targetLookups := buildEntityLookups(targetData)

	idMap := syncdata.NewIDMapping()
	sourceNames := map[string]map[string]string{}
	sourceHashes := map[string]map[string]string{}
	results := map[string]syncdata.EntityResult{}

	for _, currentEntity := range entities {
		result := syncdata.EntityResult{}
		targetIndex := buildEntityIndex(currentEntity, targetData[currentEntity], targetLookups)
		sourceRows := append([]map[string]interface{}(nil), sourceData[currentEntity]...)
		sort.SliceStable(sourceRows, func(i, j int) bool {
			return naturalKeyForEntity(currentEntity, sourceRows[i], sourceLookups) < naturalKeyForEntity(currentEntity, sourceRows[j], sourceLookups)
		})

		sourceNames[currentEntity] = map[string]string{}
		sourceHashes[currentEntity] = map[string]string{}

		for _, sourceRow := range sourceRows {
			key := naturalKeyForEntity(currentEntity, sourceRow, sourceLookups)
			sourceID := firstStringFromRow(sourceRow, syncEntityIDFields[currentEntity]...)
			if sourceID != "" {
				sourceNames[currentEntity][sourceID] = key
			}
			if key == "" {
				err := fmt.Errorf("source record has empty natural key")
				if !handleSyncRecordError(currentEntity, key, err, opts.SkipErrors, &result) {
					return nil, err
				}
				continue
			}

			sourceComparable := normalizeComparableRecord(currentEntity, sourceRow, sourceLookups)
			sourceHash := comparableHash(sourceComparable)
			if sourceID != "" {
				sourceHashes[currentEntity][sourceID] = sourceHash
			}

			if opts.Incremental && manifest != nil && sourceID != "" {
				if entry, exists := manifest.GetMapping(currentEntity, sourceID); exists {
					if entry.SourceHash == sourceHash {
						result.Skipped++
						idMap.Set(currentEntity, sourceID, entry.TargetID)
						continue
					}
					if !opts.ForceUpdate {
						result.Skipped++
						continue
					}

					payload, payloadErr := buildSyncPayload(currentEntity, sourceRow, sourceLookups, targetLookups)
					if payloadErr != nil {
						if !handleSyncRecordError(currentEntity, key, payloadErr, opts.SkipErrors, &result) {
							return nil, payloadErr
						}
						continue
					}

					if !opts.DryRun {
						if _, err := targetClient.Put(portableEntities[currentEntity]+"/"+entry.TargetID, payload); err != nil {
							if !handleSyncRecordError(currentEntity, key, err, opts.SkipErrors, &result) {
								return nil, err
							}
							continue
						}
						updated := cloneMap(payload)
						updated[syncEntityIDFields[currentEntity][0]] = parseNumericOrString(entry.TargetID)
						targetData[currentEntity] = append(targetData[currentEntity], updated)
						targetLookups = buildEntityLookups(targetData)
					}

					idMap.Set(currentEntity, sourceID, entry.TargetID)
					result.Synced++
					continue
				}
			}

			targetRow, exists := targetIndex[key]
			payload, payloadErr := buildSyncPayload(currentEntity, sourceRow, sourceLookups, targetLookups)
			if payloadErr != nil {
				if !handleSyncRecordError(currentEntity, key, payloadErr, opts.SkipErrors, &result) {
					return nil, payloadErr
				}
				continue
			}

			if exists {
				targetComparable := normalizeComparableRecord(currentEntity, targetRow, targetLookups)
				if comparableEqual(sourceComparable, targetComparable) {
					result.Skipped++
					recordEntityMapping(currentEntity, sourceRow, targetRow, idMap)
					continue
				}
				if !opts.ForceUpdate {
					result.Skipped++
					continue
				}

				targetID := firstStringFromRow(targetRow, syncEntityIDFields[currentEntity]...)
				if targetID == "" {
					err := fmt.Errorf("target record missing ID for key %q", key)
					if !handleSyncRecordError(currentEntity, key, err, opts.SkipErrors, &result) {
						return nil, err
					}
					continue
				}

				if !opts.DryRun {
					if _, err := targetClient.Put(portableEntities[currentEntity]+"/"+targetID, payload); err != nil {
						if !handleSyncRecordError(currentEntity, key, err, opts.SkipErrors, &result) {
							return nil, err
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

			if opts.DryRun {
				result.Synced++
				continue
			}

			resp, err := targetClient.Post(portableEntities[currentEntity], payload)
			if err != nil {
				if !handleSyncRecordError(currentEntity, key, err, opts.SkipErrors, &result) {
					return nil, err
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

	return &syncRunOutput{
		Results:     results,
		IDMap:       idMap,
		SourceNames: sourceNames,
		SourceHash:  sourceHashes,
	}, nil
}

func readSyncFlags(cmd *cobra.Command) (syncOptions, string, string, error) {
	fromProfile, _ := cmd.Flags().GetString("from")
	toProfile, _ := cmd.Flags().GetString("to")
	fromProfile = strings.TrimSpace(fromProfile)
	toProfile = strings.TrimSpace(toProfile)
	if fromProfile == "" || toProfile == "" {
		return syncOptions{}, "", "", fmt.Errorf("--from and --to are required")
	}

	dryRun, _ := cmd.Flags().GetBool("dry-run")
	skipErrors, _ := cmd.Flags().GetBool("skip-errors")
	forceUpdate, _ := cmd.Flags().GetBool("force-update")

	return syncOptions{
		DryRun:      dryRun,
		SkipErrors:  skipErrors,
		ForceUpdate: forceUpdate,
	}, fromProfile, toProfile, nil
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

func comparableHash(row map[string]interface{}) string {
	data, _ := json.Marshal(row)
	sum := sha1.Sum(data)
	return hex.EncodeToString(sum[:])
}

func collectEntityIDs(entity string, rows []map[string]interface{}) map[string]bool {
	out := map[string]bool{}
	for _, row := range rows {
		id := firstStringFromRow(row, syncEntityIDFields[entity]...)
		if id != "" {
			out[id] = true
		}
	}
	return out
}

func addSyncFlags(cmd *cobra.Command) {
	cmd.Flags().String("from", "", "Source profile name")
	cmd.Flags().String("to", "", "Target profile name")
	cmd.Flags().Bool("dry-run", false, "Show sync actions without writing")
	cmd.Flags().Bool("skip-errors", false, "Continue processing after record-level failures")
	cmd.Flags().Bool("force-update", false, "Update mismatched target records")
	_ = cmd.MarkFlagRequired("from")
	_ = cmd.MarkFlagRequired("to")
}

func init() {
	addSyncFlags(syncCmd)
	addSyncFlags(syncStatusCmd)
	addSyncFlags(syncHistoryCmd)
	addSyncFlags(reSyncCmd)

	syncCmd.AddCommand(syncStatusCmd, syncHistoryCmd)
	rootCmd.AddCommand(syncCmd, reSyncCmd)
}
