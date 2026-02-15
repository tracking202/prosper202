package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"sync"

	"p202/internal/api"
	configpkg "p202/internal/config"

	"github.com/spf13/cobra"
)

type multiProfileFetch struct {
	profile string
	data    map[string]interface{}
	err     error
}

func resolveMultiProfiles(cmd *cobra.Command) ([]string, error) {
	allProfiles, _ := cmd.Flags().GetBool("all-profiles")
	profilesRaw, _ := cmd.Flags().GetString("profiles")
	groupTag, _ := cmd.Flags().GetString("group")

	profilesRaw = strings.TrimSpace(profilesRaw)
	groupTag = strings.TrimSpace(groupTag)

	selectedModes := 0
	if allProfiles {
		selectedModes++
	}
	if profilesRaw != "" {
		selectedModes++
	}
	if groupTag != "" {
		selectedModes++
	}
	if selectedModes > 1 {
		return nil, fmt.Errorf("use only one of --all-profiles, --profiles, or --group")
	}
	if selectedModes == 0 {
		return nil, nil
	}

	cfg, err := configpkg.Load()
	if err != nil {
		return nil, err
	}
	available := map[string]bool{}
	for _, name := range cfg.ProfileNames() {
		available[name] = true
	}

	if allProfiles {
		names := cfg.ProfileNames()
		if len(names) == 0 {
			return nil, fmt.Errorf("no profiles configured")
		}
		return names, nil
	}

	if groupTag != "" {
		matches := cfg.ResolveGroup(groupTag)
		if len(matches) == 0 {
			return nil, fmt.Errorf("no profiles found for group %q", groupTag)
		}
		return matches, nil
	}

	parts := strings.Split(profilesRaw, ",")
	profiles := make([]string, 0, len(parts))
	seen := map[string]bool{}
	for _, part := range parts {
		name := strings.TrimSpace(part)
		if name == "" || seen[name] {
			continue
		}
		if !available[name] {
			return nil, fmt.Errorf("profile %q not found", name)
		}
		seen[name] = true
		profiles = append(profiles, name)
	}
	if len(profiles) == 0 {
		return nil, fmt.Errorf("--profiles requires at least one valid profile name")
	}
	sort.Strings(profiles)
	return profiles, nil
}

func fetchMultiProfileObjects(endpoint string, params map[string]string, profiles []string) (map[string]map[string]interface{}, []string, error) {
	results := map[string]map[string]interface{}{}
	errorsOut := make([]string, 0)
	ch := make(chan multiProfileFetch, len(profiles))
	var wg sync.WaitGroup

	for _, profile := range profiles {
		profile := profile
		wg.Add(1)
		go func() {
			defer wg.Done()

			client, err := api.NewFromProfile(profile)
			if err != nil {
				ch <- multiProfileFetch{profile: profile, err: err}
				return
			}
			data, err := client.Get(endpoint, params)
			if err != nil {
				ch <- multiProfileFetch{profile: profile, err: err}
				return
			}
			obj, err := parseDataObject(data)
			if err != nil {
				ch <- multiProfileFetch{profile: profile, err: err}
				return
			}
			ch <- multiProfileFetch{profile: profile, data: obj}
		}()
	}

	wg.Wait()
	close(ch)

	for result := range ch {
		if result.err != nil {
			errorsOut = append(errorsOut, fmt.Sprintf("%s: %v", result.profile, result.err))
			continue
		}
		results[result.profile] = result.data
	}

	sort.Strings(errorsOut)
	if len(results) == 0 && len(errorsOut) > 0 {
		return nil, errorsOut, fmt.Errorf("all profile requests failed")
	}
	return results, errorsOut, nil
}

func aggregateNumericFields(rows map[string]map[string]interface{}) map[string]interface{} {
	aggregate := map[string]interface{}{}
	keys := map[string]bool{}
	for _, row := range rows {
		for key := range row {
			keys[key] = true
		}
	}

	keyList := make([]string, 0, len(keys))
	for key := range keys {
		keyList = append(keyList, key)
	}
	sort.Strings(keyList)

	for _, key := range keyList {
		total := 0.0
		seenNumeric := false
		for _, row := range rows {
			val, ok := parseFloat(row[key])
			if !ok {
				continue
			}
			total += val
			seenNumeric = true
		}
		if seenNumeric {
			aggregate[key] = total
		}
	}

	return aggregate
}

func parseFloat(v interface{}) (float64, bool) {
	switch val := v.(type) {
	case float64:
		return val, true
	case float32:
		return float64(val), true
	case int:
		return float64(val), true
	case int64:
		return float64(val), true
	case string:
		trimmed := strings.TrimSpace(val)
		if trimmed == "" {
			return 0, false
		}
		parsed, err := strconv.ParseFloat(trimmed, 64)
		if err != nil {
			return 0, false
		}
		return parsed, true
	default:
		return 0, false
	}
}

func buildMultiProfilePayload(profileData map[string]map[string]interface{}, aggregated map[string]interface{}, errorsOut []string) []byte {
	names := make([]string, 0, len(profileData))
	for name := range profileData {
		names = append(names, name)
	}
	sort.Strings(names)

	rows := make([]map[string]interface{}, 0, len(names)+1)
	profiles := map[string]map[string]interface{}{}
	for _, name := range names {
		record := cloneMap(profileData[name])
		record["profile"] = name
		rows = append(rows, record)
		profiles[name] = cloneMap(profileData[name])
	}

	totalRow := cloneMap(aggregated)
	totalRow["profile"] = "TOTAL"
	rows = append(rows, totalRow)

	payload, _ := json.Marshal(map[string]interface{}{
		"data":       rows,
		"profiles":   profiles,
		"aggregated": aggregated,
		"errors":     errorsOut,
	})
	return payload
}

func addMultiProfileFlags(cmd *cobra.Command) {
	cmd.Flags().Bool("all-profiles", false, "Run against all configured profiles")
	cmd.Flags().String("profiles", "", "Comma-separated profile names")
	cmd.Flags().String("group", "", "Run against all profiles in a tag group")
}
