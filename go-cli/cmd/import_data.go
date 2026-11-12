package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// immutableFieldsByEntity are the fields of an exported (GET) record that a
// create or an update on another account or install must not carry: the
// record's ids and owner, what the server assigns (public ids, times), and
// the version/etag every GET adds. The server refuses a read-only field on a
// create, and on an update unless it holds the target record's own value, so
// a body built from a source record has to leave them out. A rotator's rules
// are written through /rotators/{id}/rules and its auto-monetizer by the
// Setup page, so neither travels in the rotator's own body.
var immutableFieldsByEntity = map[string][]string{
	"campaigns":     {"id", "user_id", "aff_campaign_id", "aff_campaign_time", "aff_campaign_id_public", "aff_campaign_deleted", "version", "etag"},
	"aff-networks":  {"id", "user_id", "aff_network_id", "aff_network_deleted", "version", "etag"},
	"ppc-networks":  {"id", "user_id", "ppc_network_id", "ppc_network_deleted", "version", "etag"},
	"ppc-accounts":  {"id", "user_id", "ppc_account_id", "ppc_account_deleted", "version", "etag"},
	"rotators":      {"id", "user_id", "auto_monetizer", "rules"},
	"trackers":      {"id", "user_id", "tracker_id", "tracker_id_public", "tracker_time", "version", "etag"},
	"landing-pages": {"id", "user_id", "landing_page_id", "landing_page_id_public", "landing_page_deleted", "version", "etag"},
	"text-ads":      {"id", "user_id", "text_ad_id", "text_ad_deleted", "version", "etag"},
}

var dataImportCmd = &cobra.Command{
	Use:   "import <entity> <file>",
	Short: "Import entities from JSON",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		entity := args[0]
		endpoint, ok := portableEntities[entity]
		if !ok {
			return validationError("unsupported entity %q (supported: %s)", entity, strings.Join(sortedPortableEntities(), ", ")).WithHint("Pass one of those as the first argument, e.g. `p202 import campaigns campaigns.json`.")
		}

		raw, err := os.ReadFile(args[1])
		if err != nil {
			return err
		}
		records, err := parseImportRecords(raw)
		if err != nil {
			return err
		}

		dryRun, _ := cmd.Flags().GetBool("dry-run")
		skipErrors, _ := cmd.Flags().GetBool("skip-errors")

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		imported := 0
		staged := 0
		failed := 0
		errorsOut := make([]string, 0)
		changeIDs := make([]string, 0)
		var failures []importFailure // under --skip-errors, the records refused so far

		for i, rec := range records {
			body := stripImmutableFields(entity, rec)
			if dryRun {
				imported++
				continue
			}

			created, err := c.Post(endpoint, body)
			if err != nil {
				// A refused key, no connection or the install's licence
				// refusal would meet every later record too, so they stop
				// --skip-errors as well. %w keeps the record's own category,
				// exit code and hint (auth 2, network 3, licence 6, ...).
				if !skipErrors || abortsImport(err) {
					refused := ""
					if len(failures) > 0 {
						refused = fmt.Sprintf(", %d refused before it (%s)", len(failures), describeImportFailures(failures))
					}
					stopped := fmt.Errorf("import failed at record %d of %d (%d imported and %d staged before it%s, the rest not attempted): %w",
						i+1, len(records), imported, staged, refused, err)
					return importStoppedHint(stopped, err, entity, i+1, len(records), imported+staged, failures)
				}
				failed++
				failures = append(failures, importFailure{record: i + 1, err: err})
				errorsOut = append(errorsOut, fmt.Sprintf("record %d: %v", i+1, err))
				continue
			}
			// Under --staged the server records a proposal instead of the
			// row. Counting those as imported would report records that do
			// not exist yet and that nobody has approved.
			if changeID, isStaged := stagedChangeID(created); isStaged {
				staged++
				changeIDs = append(changeIDs, changeID)
				continue
			}
			imported++
		}

		out := map[string]interface{}{
			"entity":   entity,
			"total":    len(records),
			"imported": imported,
			"failed":   failed,
			"dry_run":  dryRun,
		}
		if staged > 0 {
			out["staged"] = staged
			out["change_ids"] = changeIDs
			output.Success(
				"Staged %d of %d records for approval; none exist yet. Review with `p202 change list` "+
					"and apply each with `p202 change apply <change_id>`.",
				staged, len(records))
		}
		if len(errorsOut) > 0 {
			out["errors"] = errorsOut
		}

		encoded, err := json.Marshal(out)
		if err != nil {
			// Same reason as the delete paths in crud.go: rendering nil
			// prints nothing and the command would exit 0, losing the
			// change_ids that are the only handle on staged proposals.
			return fmt.Errorf("encoding import summary: %w", err)
		}
		render(encoded)
		// --skip-errors goes on past a failed record; it does not make the
		// import a success. It exited 0 with every record failed, which a
		// script reads as everything imported. The summary lists each.
		if failed > 0 {
			hint := fmt.Sprintf("Fix %s and import a file of just them: running the whole file again sends every record already imported a second time.",
				recordsNamed(failureRecords(failures)))
			if note := mayExistNote(entity, failures); note != "" {
				hint += " " + note
			}
			return partialFailureError("failed to import %d of %d record(s); the summary lists each under errors", failed, len(records)).
				WithHint("%s", hint)
		}
		return nil
	},
}

func parseImportRecords(raw []byte) ([]map[string]interface{}, error) {
	var arr []interface{}
	if err := json.Unmarshal(raw, &arr); err == nil {
		return importObjects(arr, "")
	}

	var obj map[string]interface{}
	if err := json.Unmarshal(raw, &obj); err != nil {
		return nil, withHint(fmt.Errorf("invalid import file JSON: %w", err), "The file must be JSON as written by `p202 export <entity> --output <file>`: an array of records, or an object with a `data` array.")
	}

	rawData, ok := obj["data"]
	if !ok {
		return nil, validationError("import file must be a JSON array or object with data array").WithHint("Produce it with `p202 export <entity> --output <file>`, or wrap your records as {\"data\": [...]}.")
	}
	items, ok := rawData.([]interface{})
	if !ok {
		return nil, validationError("import file data field must be an array").WithHint("Produce it with `p202 export <entity> --output <file>`, or wrap your records as {\"data\": [...]}.")
	}
	return importObjects(items, "data")
}

// importObjects is the file's records, every one an object: an item that is
// not (null, a number, a list) is refused naming its index, never skipped,
// since a skip shifts every later record's number, which the import's errors
// and hints name (CLAUDE.md #4).
func importObjects(items []interface{}, field string) ([]map[string]interface{}, error) {
	out := make([]map[string]interface{}, 0, len(items))
	where := "the file's array"
	if field != "" {
		where = "the file's `" + field + "` array"
	}
	for i, item := range items {
		rec, ok := item.(map[string]interface{})
		if !ok || rec == nil {
			return nil, validationError("record %d (index %d of %s) is not a JSON object", i+1, i, where).
				WithHint("Every record must be an object of the entity's fields, as `p202 export <entity> --output <file>` writes them; remove or fix that item.")
		}
		out = append(out, rec)
	}
	return out, nil
}

func stripImmutableFields(entity string, rec map[string]interface{}) map[string]interface{} {
	out := map[string]interface{}{}
	skip := map[string]bool{}
	for _, key := range immutableFieldsByEntity[entity] {
		skip[key] = true
	}
	for k, v := range rec {
		if skip[k] {
			continue
		}
		out[k] = v
	}
	return out
}

func init() {
	dataImportCmd.Flags().Bool("dry-run", false, "Validate import and count records without creating them")
	dataImportCmd.Flags().Bool("skip-errors", false, "Continue importing after record-level failures; exits 5 when any record failed, and a 401 or 403 still stops it")
	rootCmd.AddCommand(dataImportCmd)
}

// importFailure is a record --skip-errors went past: its number and error.
type importFailure struct {
	record int
	err    error
}

func failureRecords(failures []importFailure) []int {
	out := make([]int, 0, len(failures))
	for _, f := range failures {
		out = append(out, f.record)
	}
	return out
}

// describeImportFailures names each refused record with its error, the first
// five in full.
func describeImportFailures(failures []importFailure) string {
	parts := make([]string, 0, len(failures))
	for i, f := range failures {
		if i == 5 {
			parts = append(parts, fmt.Sprintf("and %d more", len(failures)-5))
			break
		}
		parts = append(parts, fmt.Sprintf("record %d: %v", f.record, f.err))
	}
	return strings.Join(parts, "; ")
}

// recordsNamed is "record 2", or "records 2, 4 to 6".
func recordsNamed(records []int) string {
	if len(records) == 1 {
		return "record " + strconv.Itoa(records[0])
	}
	return "records " + recordList(records)
}

// recordList spells record numbers as runs: "2, 4 to 6".
func recordList(records []int) string {
	parts := []string{}
	for i := 0; i < len(records); {
		j := i
		for j+1 < len(records) && records[j+1] == records[j]+1 {
			j++
		}
		if j == i {
			parts = append(parts, strconv.Itoa(records[i]))
		} else {
			parts = append(parts, fmt.Sprintf("%d to %d", records[i], records[j]))
		}
		i = j + 1
	}
	return strings.Join(parts, ", ")
}

// mayExistNote names the records whose create failed without saying it did
// not happen (a 5xx, the connection): the server may have written them
// before the answer was lost (CLAUDE.md #13), so they are looked up first.
func mayExistNote(entity string, failures []importFailure) string {
	var unsure []int
	for _, f := range failures {
		if cat := api.ErrorCategory(f.err); cat == "server" || cat == "network" {
			unsure = append(unsure, f.record)
		}
	}
	if len(unsure) == 0 {
		return ""
	}
	noun := "R" + recordsNamed(unsure)[1:] + " failed with a server or connection error and may exist already"
	return fmt.Sprintf("%s: check `p202 %s list` first and leave out any that is there.", noun, strings.TrimSuffix(entity, "s"))
}

// importStoppedHint is the next step for an import that stopped at record
// at of total, with sent records imported or staged and failures refused
// before it under --skip-errors: the stopping error's own step, then the
// records to send next, the refused ones (fixed) and at to total, since
// running the whole file again sends the sent ones a second time
// (CLAUDE.md #13). A 429 says nothing was done and to run the same command
// again, true of the request and not of the import, so its wait is named
// instead. A create that failed with a 5xx or on the connection may have
// been written, so the record is looked up first.
func importStoppedHint(stopped, err error, entity string, at, total, sent int, failures []importFailure) error {
	if sent == 0 && len(failures) == 0 {
		if note := mayExistNote(entity, []importFailure{{record: at, err: err}}); note != "" {
			return withHint(stopped, "%s", strings.TrimSpace(rawHintFor(err)+" "+note))
		}
		return stopped
	}
	next := append(failureRecords(failures), rangeOf(at, total)...)
	before := fmt.Sprintf("records 1 to %d were already sent", at-1)
	if at == 2 {
		before = "record 1 was already sent"
	}
	if len(failures) > 0 {
		before = fmt.Sprintf("the other records before %d were already sent", at)
	}
	resume := fmt.Sprintf("import a file of %s only: %s (%d imported or staged), "+
		"and running the whole file again sends them a second time.", recordsNamed(next), before, sent)
	if len(failures) > 0 {
		resume += fmt.Sprintf(" Fix %s first: the message says why each was refused.", recordsNamed(failureRecords(failures)))
	}
	if note := mayExistNote(entity, append(append([]importFailure{}, failures...), importFailure{record: at, err: err})); note != "" {
		resume += " " + note
	}
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == http.StatusTooManyRequests {
		wait := "a minute"
		if apiErr.RetryAfter > 0 {
			wait = fmt.Sprintf("%d seconds (the server's Retry-After)", apiErr.RetryAfter)
		}
		return withHint(stopped, "Rate limited at record %d. Wait %s, then %s", at, wait, resume)
	}
	if first := strings.TrimSpace(rawHintFor(err)); first != "" {
		return withHint(stopped, "%s Then %s", first, resume)
	}
	return withHint(stopped, "Fix what the message names, then %s", resume)
}

func rangeOf(from, to int) []int {
	out := make([]int, 0, to-from+1)
	for n := from; n <= to; n++ {
		out = append(out, n)
	}
	return out
}
