package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
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
	var failures []multiProfileFetch
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
			if err == nil && check != nil {
				err = check(obj)
			}
			if err == nil {
				// A profile whose cost read the spend ledger says so beside its
				// figures; carried aside, never summed (runMultiProfileSummary).
				var top struct {
					SpendLedger map[string]interface{} `json:"spend_ledger"`
				}
				if json.Unmarshal(data, &top) == nil && top.SpendLedger != nil {
					obj = cloneMap(obj)
					obj[spendLedgerKey] = top.SpendLedger
				}
			}
			if err != nil {
				ch <- multiProfileFetch{profile: profile, err: notASummary(profile, err)}
				return
			}
			ch <- multiProfileFetch{profile: profile, data: obj}
		}()
	}

	wg.Wait()
	close(ch)

	for result := range ch {
		if result.err != nil {
			failures = append(failures, result)
			continue
		}
		results[result.profile] = result.data
	}

	// By profile, not by arrival: which failure is named first, and which
	// one the error below unwraps to, must not change between two runs.
	sort.Slice(failures, func(i, j int) bool { return failures[i].profile < failures[j].profile })
	errorsOut := make([]string, 0, len(failures))
	rawErrors := make([]error, 0, len(failures))
	for _, f := range failures {
		errorsOut = append(errorsOut, fmt.Sprintf("%s: %v", f.profile, f.err))
		rawErrors = append(rawErrors, f.err)
	}
	if len(results) == 0 && len(errorsOut) > 0 {
		if refused := licenceRefusal(rawErrors...); refused != nil {
			// Keep the licence category (exit 6, the trial hint) when every
			// profile was refused for it, instead of a generic failure.
			return nil, errorsOut, fmt.Errorf("all profile requests failed: %w", refused)
		}
		failed := &allProfilesFailed{msg: "all profile requests failed: " + strings.Join(errorsOut, "; ")}
		if oneKindOfFailure(rawErrors) {
			failed.cause = rawErrors[0]
		}
		return nil, errorsOut, failed
	}
	return results, errorsOut, nil
}

// oneKindOfFailure reports whether every error would be answered alike: the
// same category, exit code and hint, as Execute resolves them. Only then may
// the failure of every profile take one's category and hint (every server
// unreachable is a network failure); a 422 beside a profile with no URL,
// both exit 1, is not one kind, nor a refused key beside a masked one.
func oneKindOfFailure(errs []error) bool {
	for _, e := range errs[1:] {
		if api.ErrorCategory(e) != api.ErrorCategory(errs[0]) ||
			exitCodeForError(e) != exitCodeForError(errs[0]) ||
			hintFor(e) != hintFor(errs[0]) {
			return false
		}
	}
	return true
}

// notASummary is a profile whose answer cannot be read as a summary: a
// failed profile, and the server's failure. The hint names no profile, so
// two such failures are one kind.
func notASummary(profile string, err error) *CLIError {
	return &CLIError{
		Category: "server", ExitCode: ExitServer, Cause: err,
		Message: fmt.Sprintf("the server's answer is not a summary: %v (`p202 report summary --profile %s --json` shows it)", err, profile),
		Hint:    "Read the server's raw answer with the `p202 report summary --profile <name> --json` the message names; a proxy in front of it may have answered instead.",
	}
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
	// One JSON value and nothing after it, as json.Unmarshal reads one.
	var more json.RawMessage
	if err := dec.Decode(&more); err != io.EOF {
		return nil, fmt.Errorf("the response has more after its JSON value")
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
	ledgers := map[string]interface{}{}
	for name, row := range profileData {
		if l, ok := row[spendLedgerKey]; ok {
			ledgers[name] = l
			delete(row, spendLedgerKey)
		}
	}
	total, err := totalSummaries(profileData)
	if err != nil {
		return err
	}
	payload, err := buildMultiProfilePayload(profileData, total, errorsOut)
	if err != nil {
		return err
	}
	if len(ledgers) > 0 {
		payload, err = withSpendLedgers(payload, ledgers)
		if err != nil {
			return err
		}
	}
	render(payload)
	if !jsonOutput {
		if note := spendLedgerProfilesNote(ledgers, len(profileData)); note != "" {
			fmt.Fprintln(os.Stderr, note)
		}
		for _, e := range errorsOut {
			fmt.Fprintf(os.Stderr, "Warning: the TOTAL leaves out a profile that failed: %s\n", e)
		}
		for _, w := range total.warnings() {
			fmt.Fprintf(os.Stderr, "Warning: %s\n", w)
		}
	}
	return nil
}

// spendLedgerKey carries a profile's `spend_ledger` beside its row until
// the TOTAL is built.
const spendLedgerKey = "\x00spend_ledger"

// withSpendLedgers adds meta.spend_ledger: each profile whose cost read the
// spend ledger, with what it said (how far the spend data reaches).
func withSpendLedgers(payload []byte, ledgers map[string]interface{}) ([]byte, error) {
	var out map[string]interface{}
	if err := json.Unmarshal(payload, &out); err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	if meta, ok := out["meta"].(map[string]interface{}); ok {
		meta["spend_ledger"] = ledgers
	}
	encoded, err := json.Marshal(out)
	if err != nil {
		return nil, fmt.Errorf("encoding output: %w", err)
	}
	return encoded, nil
}

// spendLedgerProfilesNote names, for a human view, the profiles whose cost
// read the spend ledger: the TOTAL then adds ledger cost to CPC cost.
func spendLedgerProfilesNote(ledgers map[string]interface{}, profiles int) string {
	if len(ledgers) == 0 {
		return ""
	}
	names := make([]string, 0, len(ledgers))
	for name, l := range ledgers {
		reach := ""
		if m, ok := l.(map[string]interface{}); ok {
			if at, ok := m["reaches_at"].(string); ok {
				reach = " (spend data reaches " + at + ")"
			}
		}
		names = append(names, name+reach)
	}
	sort.Strings(names)
	others := ""
	if len(ledgers) < profiles {
		others = "; the other profiles' cost is their clicks' CPC"
	}
	return "Note: cost read from the spend ledger in " + strings.Join(names, ", ") + others + "."
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
