package cmd

import (
	"encoding/json"
	"fmt"
	"math"
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
		return nil, validationError("use only one of --all-profiles, --profiles, or --group")
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
			return nil, validationError("no profiles configured").WithHint("Add one with `p202 config add-profile <name> --url <url> --key <key>`.")
		}
		return names, nil
	}

	if groupTag != "" {
		matches := cfg.ResolveGroup(groupTag)
		if len(matches) == 0 {
			return nil, validationError("no profiles found for group %q", groupTag).WithHint("Tag profiles with `p202 config tag-profile <profile> %s`; `p202 config list-profiles` shows current tags.", groupTag)
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
			return nil, validationError("profile %q not found", name).WithHint("`p202 config list-profiles` shows configured profiles; add one with `p202 config add-profile <name> --url <url> --key <key>`.")
		}
		seen[name] = true
		profiles = append(profiles, name)
	}
	if len(profiles) == 0 {
		return nil, validationError("--profiles requires at least one valid profile name").WithHint("Comma-separate names from `p202 config list-profiles`, e.g. --profiles prod,staging.")
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
			// A masked profile's totals are hidden, not zero: it is a failed
			// profile, named, not a quiet hole in the sum.
			if maskedFigures(data) {
				ch <- multiProfileFetch{profile: profile, err: errMaskedFigures("Summing across profiles")}
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

// aggregateNumericFields sums each numeric field across profiles. A NaN or
// infinite value (or sum) is an error naming the profile: json.Marshal can't encode it.
func aggregateNumericFields(rows map[string]map[string]interface{}) (map[string]interface{}, error) {
	aggregate := map[string]interface{}{}
	keys := map[string]bool{}
	names := make([]string, 0, len(rows))
	for name, row := range rows {
		names = append(names, name)
		for key := range row {
			keys[key] = true
		}
	}
	sort.Strings(names)

	keyList := make([]string, 0, len(keys))
	for key := range keys {
		keyList = append(keyList, key)
	}
	sort.Strings(keyList)

	for _, key := range keyList {
		total := 0.0
		seenNumeric := false
		for _, name := range names {
			val, ok := parseFloat(rows[name][key])
			if !ok {
				continue
			}
			if math.IsNaN(val) || math.IsInf(val, 0) {
				return nil, withHint(fmt.Errorf("profile %s field %s: not a finite number: %v", name, key, rows[name][key]),
					"`p202 report summary --profile %s --json` shows that server's raw response.", name)
			}
			total += val
			seenNumeric = true
		}
		if math.IsInf(total, 0) {
			return nil, withHint(fmt.Errorf("field %s: the sum across profiles is not a finite number", key),
				"Read each server separately with `p202 report summary --profile <name> --json`.")
		}
		if seenNumeric {
			aggregate[key] = total
		}
	}

	return aggregate, nil
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

func buildMultiProfilePayload(profileData map[string]map[string]interface{}, aggregated map[string]interface{}, errorsOut []string) ([]byte, error) {
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

	payload, err := json.Marshal(map[string]interface{}{
		"data":       rows,
		"profiles":   profiles,
		"aggregated": aggregated,
		"errors":     errorsOut,
	})
	if err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	return payload, nil
}

func addMultiProfileFlags(cmd *cobra.Command) {
	cmd.Flags().Bool("all-profiles", false, "Run against all configured profiles")
	cmd.Flags().String("profiles", "", "Comma-separated profile names")
}
