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

var immutableFieldsByEntity = map[string][]string{
	"campaigns":     {"id", "user_id", "aff_campaign_id", "aff_campaign_time", "aff_campaign_id_public", "aff_campaign_deleted"},
	"aff-networks":  {"id", "user_id", "aff_network_id", "aff_network_deleted"},
	"ppc-networks":  {"id", "user_id", "ppc_network_id", "ppc_network_deleted"},
	"ppc-accounts":  {"id", "user_id", "ppc_account_id", "ppc_account_deleted"},
	"rotators":      {"id", "user_id"},
	"trackers":      {"id", "user_id", "tracker_id", "tracker_id_public", "tracker_time"},
	"landing-pages": {"id", "user_id", "landing_page_id", "landing_page_deleted"},
	"text-ads":      {"id", "user_id", "text_ad_id", "text_ad_deleted"},
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
				msg := fmt.Sprintf("record %d: %v", i+1, err)
				errorsOut = append(errorsOut, msg)
				if !skipErrors {
					return fmt.Errorf("import failed (%s)", msg)
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

		encoded, _ := json.Marshal(out)
		render(encoded)
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
	dataImportCmd.Flags().Bool("skip-errors", false, "Continue importing after record-level failures")
	rootCmd.AddCommand(dataImportCmd)
}
