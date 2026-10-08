package cmd

import (
	"encoding/json"
	"fmt"
	"os"
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

		for i, rec := range records {
			body := stripImmutableFields(entity, rec)
			if dryRun {
				imported++
				continue
			}

			created, err := c.Post(endpoint, body)
			if err != nil {
				failed++
				errorsOut = append(errorsOut, fmt.Sprintf("record %d: %v", i+1, err))
				// --skip-errors goes past a record's own failure, never past
				// the key's: a 401 or 403 fails every later record the same way.
				if !skipErrors || api.ErrorCategory(err) == "auth" {
					// %w: flattened with %s, a 401 exited 1 as a validation
					// error with the --help hint, and the class hint for a
					// read-only or unknown field (remove it from the file's
					// records) never reached the user.
					return fmt.Errorf("import failed at record %d of %d (%d imported and %d staged before it): %w",
						i+1, len(records), imported, staged, err)
				}
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
			return partialFailureError("failed to import %d of %d record(s); the summary lists each under errors", failed, len(records)).
				WithHint("Fix those records and import a file of just them: running the whole file again sends every record already imported a second time.")
		}
		return nil
	},
}

func parseImportRecords(raw []byte) ([]map[string]interface{}, error) {
	var arr []map[string]interface{}
	if err := json.Unmarshal(raw, &arr); err == nil {
		return arr, nil
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
	out := make([]map[string]interface{}, 0, len(items))
	for _, item := range items {
		rec, ok := item.(map[string]interface{})
		if !ok {
			continue
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
