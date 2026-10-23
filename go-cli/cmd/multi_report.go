package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"sort"
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

// fetchMultiProfileObjects reads endpoint's `data` object from each profile,
// its numbers kept as the server wrote them (json.Number). A profile whose
// answer check refuses is a failed profile, named in the errors.
func fetchMultiProfileObjects(endpoint string, params map[string]string, profiles []string, check func(map[string]interface{}) error) (map[string]map[string]interface{}, []string, error) {
	results := map[string]map[string]interface{}{}
	errorsOut := make([]string, 0)
	rawErrors := make([]error, 0)
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
			obj, err := parseDataObjectNumbers(data)
			if err != nil {
				ch <- multiProfileFetch{profile: profile, err: err}
				return
			}
			if check != nil {
				if err := check(obj); err != nil {
					ch <- multiProfileFetch{profile: profile, err: &CLIError{
						Category: "server", ExitCode: ExitServer, Cause: err,
						Message: fmt.Sprintf("the server's answer is not a summary: %v (`p202 report summary --profile %s --json` shows it)", err, profile),
						Hint:    fmt.Sprintf("Read that server's raw answer with `p202 report summary --profile %s --json`; a proxy in front of it may have answered instead.", profile),
					}}
					return
				}
			}
			ch <- multiProfileFetch{profile: profile, data: obj}
		}()
	}

	wg.Wait()
	close(ch)

	for result := range ch {
		if result.err != nil {
			errorsOut = append(errorsOut, fmt.Sprintf("%s: %v", result.profile, result.err))
			rawErrors = append(rawErrors, result.err)
			continue
		}
		results[result.profile] = result.data
	}

	sort.Strings(errorsOut)
	if len(results) == 0 && len(errorsOut) > 0 {
		if refused := licenceRefusal(rawErrors...); refused != nil {
			// Keep the licence category (exit 6, the trial hint) when every
			// profile was refused for it, instead of a generic failure.
			return nil, errorsOut, fmt.Errorf("all profile requests failed: %w", refused)
		}
		failed := &allProfilesFailed{msg: "all profile requests failed: " + strings.Join(errorsOut, "; ")}
		// One kind of failure everywhere (every server unreachable, every key
		// refused) keeps that kind's category, exit code and hint.
		same := true
		for _, e := range rawErrors[1:] {
			same = same && exitCodeForError(e) == exitCodeForError(rawErrors[0])
		}
		if same {
			failed.cause = rawErrors[0]
		}
		return nil, errorsOut, failed
	}
	return results, errorsOut, nil
}

// allProfilesFailed names every profile's failure and, when they are all of
// one kind, unwraps to the first so its category and hint stand.
type allProfilesFailed struct {
	msg   string
	cause error
}

func (e *allProfilesFailed) Error() string { return e.msg }
func (e *allProfilesFailed) Unwrap() error { return e.cause }

// parseDataObjectNumbers is parseDataObject with every number a json.Number,
// so a total is computed from the digits the server sent.
func parseDataObjectNumbers(data []byte) (map[string]interface{}, error) {
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.UseNumber()
	var parsed map[string]interface{}
	if err := dec.Decode(&parsed); err != nil {
		return nil, err
	}
	if obj, ok := parsed["data"].(map[string]interface{}); ok {
		return obj, nil
	}
	return parsed, nil
}

// runMultiProfileSummary is `report summary` and `dashboard` across
// profiles: each profile's row, then the TOTAL. What the TOTAL leaves out,
// and the profiles that failed, are in `meta` and `errors` under --json and
// on stderr otherwise.
func runMultiProfileSummary(params map[string]string, profiles []string) error {
	profileData, errorsOut, err := fetchMultiProfileObjects("reports/summary", params, profiles, checkSummaryRow)
	if err != nil {
		return err
	}
	total, err := totalSummaries(profileData)
	if err != nil {
		return err
	}
	payload, err := buildMultiProfilePayload(profileData, total, errorsOut)
	if err != nil {
		return err
	}
	render(payload)
	if !jsonOutput {
		for _, e := range errorsOut {
			fmt.Fprintf(os.Stderr, "Warning: the TOTAL leaves out a profile that failed: %s\n", e)
		}
		for _, w := range total.warnings() {
			fmt.Fprintf(os.Stderr, "Warning: %s\n", w)
		}
	}
	return nil
}

func buildMultiProfilePayload(profileData map[string]map[string]interface{}, total summaryTotal, errorsOut []string) ([]byte, error) {
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

	totalRow := cloneMap(total.row)
	totalRow["profile"] = "TOTAL"
	rows = append(rows, totalRow)

	payload, err := json.Marshal(map[string]interface{}{
		"data":       rows,
		"profiles":   profiles,
		"aggregated": total.row,
		"errors":     errorsOut,
		"meta": map[string]interface{}{
			"currencies":   total.currencies,
			"not_totalled": total.notTotalled,
			"warnings":     total.warnings(),
		},
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
