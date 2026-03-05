package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"

	"p202/internal/api"
	"p202/internal/metrics"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

type crudField struct {
	Name     string
	Desc     string
	Required bool
	QueryKey string
	Aliases  []string
}

type crudEntity struct {
	Name       string
	Aliases    []string
	Plural     string
	Endpoint   string
	Fields     []crudField
	ListParams []crudField
}

type fkResolutionSpec struct {
	Endpoint    string
	IDFields    []string
	NameField   string
	OutputField string
}

var fkResolutionMap = map[string]fkResolutionSpec{
	"aff_campaign_id": {
		Endpoint:    "campaigns",
		IDFields:    []string{"aff_campaign_id", "id"},
		NameField:   "aff_campaign_name",
		OutputField: "campaign_name",
	},
	"aff_network_id": {
		Endpoint:    "aff-networks",
		IDFields:    []string{"aff_network_id", "id"},
		NameField:   "aff_network_name",
		OutputField: "aff_network_name",
	},
	"ppc_network_id": {
		Endpoint:    "ppc-networks",
		IDFields:    []string{"ppc_network_id", "id"},
		NameField:   "ppc_network_name",
		OutputField: "ppc_network_name",
	},
	"ppc_account_id": {
		Endpoint:    "ppc-accounts",
		IDFields:    []string{"ppc_account_id", "id"},
		NameField:   "ppc_account_name",
		OutputField: "ppc_account_name",
	},
	"landing_page_id": {
		Endpoint:    "landing-pages",
		IDFields:    []string{"landing_page_id", "id"},
		NameField:   "landing_page_url",
		OutputField: "landing_page_url",
	},
	"text_ad_id": {
		Endpoint:    "text-ads",
		IDFields:    []string{"text_ad_id", "id"},
		NameField:   "text_ad_name",
		OutputField: "text_ad_name",
	},
	"rotator_id": {
		Endpoint:    "rotators",
		IDFields:    []string{"id"},
		NameField:   "name",
		OutputField: "rotator_name",
	},
}

func capitalize(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}

func parseDataObject(data []byte) (map[string]interface{}, error) {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil, err
	}
	if obj, ok := parsed["data"].(map[string]interface{}); ok {
		return obj, nil
	}
	return parsed, nil
}

func parseDataArray(data []byte) ([]map[string]interface{}, error) {
	var parsed map[string]interface{}
	if err := json.Unmarshal(data, &parsed); err != nil {
		return nil, err
	}
	rawItems, ok := parsed["data"].([]interface{})
	if !ok {
		return nil, fmt.Errorf("response did not include a data array")
	}
	items := make([]map[string]interface{}, 0, len(rawItems))
	for _, raw := range rawItems {
		obj, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		items = append(items, obj)
	}
	return items, nil
}

func extractIntField(obj map[string]interface{}, keys ...string) (int, bool) {
	for _, key := range keys {
		val, exists := obj[key]
		if !exists || val == nil {
			continue
		}
		switch v := val.(type) {
		case float64:
			return int(v), true
		case int:
			return v, true
		case int64:
			return int(v), true
		case string:
			if parsed, err := strconv.Atoi(v); err == nil {
				return parsed, true
			}
		}
	}
	return 0, false
}

func cloneMutableFields(source map[string]interface{}, fields []crudField) map[string]interface{} {
	out := map[string]interface{}{}
	for _, f := range fields {
		if val, ok := source[f.Name]; ok {
			out[f.Name] = val
		}
	}
	return out
}

func parseIDList(raw string) ([]string, error) {
	parts := strings.Split(raw, ",")
	out := make([]string, 0, len(parts))
	seen := map[string]bool{}
	for _, part := range parts {
		id := strings.TrimSpace(part)
		if id == "" || seen[id] {
			continue
		}
		if _, err := strconv.Atoi(id); err != nil {
			return nil, fmt.Errorf("invalid ID %q: must be a numeric value", id)
		}
		seen[id] = true
		out = append(out, id)
	}
	return out, nil
}

func resolveForeignKeyNames(c *api.Client, rows []map[string]interface{}) error {
	if len(rows) == 0 {
		return nil
	}

	activeFields := map[string]fkResolutionSpec{}
	for field, spec := range fkResolutionMap {
		for _, row := range rows {
			rawID := scalarString(row[field])
			if rawID != "" && rawID != "0" {
				activeFields[field] = spec
				break
			}
		}
	}
	if len(activeFields) == 0 {
		return nil
	}

	lookupsByEndpoint := map[string]map[string]string{}
	for _, spec := range activeFields {
		if _, exists := lookupsByEndpoint[spec.Endpoint]; exists {
			continue
		}

		referenceRows, err := fetchAllRows(c, spec.Endpoint)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Warning: resolve names lookup failed for %s: %v. Falling back to raw IDs.\n", spec.Endpoint, err)
			lookupsByEndpoint[spec.Endpoint] = map[string]string{}
			continue
		}

		lookup := map[string]string{}
		for _, referenceRow := range referenceRows {
			id := firstStringFromRow(referenceRow, spec.IDFields...)
			if id == "" {
				continue
			}
			name := scalarString(referenceRow[spec.NameField])
			if name == "" {
				name = "id:" + id
			}
			lookup[id] = name
		}
		lookupsByEndpoint[spec.Endpoint] = lookup
	}

	for _, row := range rows {
		for field, spec := range activeFields {
			rawID := scalarString(row[field])
			if rawID == "" || rawID == "0" {
				continue
			}
			lookup := lookupsByEndpoint[spec.Endpoint]
			if resolved, ok := lookup[rawID]; ok && resolved != "" {
				row[spec.OutputField] = resolved
			} else {
				row[spec.OutputField] = "id:" + rawID
			}
		}
	}

	return nil
}

func crudFieldNames(fields []crudField, requiredOnly bool) []string {
	names := make([]string, 0, len(fields))
	for _, f := range fields {
		if requiredOnly && !f.Required {
			continue
		}
		names = append(names, f.Name)
	}
	return names
}

func buildCrudCreateExample(entityName string, fields []crudField) string {
	parts := []string{"p202 " + entityName + " create"}
	for _, f := range fields {
		if f.Required {
			parts = append(parts, fmt.Sprintf("--%s '<value>'", f.Name))
		}
	}
	return strings.Join(parts, " ")
}

func registerCRUD(entity crudEntity) *cobra.Command {
	parentCmd := &cobra.Command{
		Use:     entity.Name,
		Aliases: entity.Aliases,
		Short:   fmt.Sprintf("Manage %s", entity.Plural),
		Long: fmt.Sprintf("Manage %s.\n\n"+
			"Subcommands: list, get, create, update, delete.\n"+
			"All subcommands support --json for machine-readable output, --csv for spreadsheet export,\n"+
			"--fields to filter output columns, --id-only to extract just the primary key,\n"+
			"and --dry-run to preview mutations without executing them.", entity.Plural),
	}

	allFieldNames := crudFieldNames(entity.Fields, false)
	requiredFieldNames := crudFieldNames(entity.Fields, true)

	// list
	listCmd := &cobra.Command{
		Use:   "list",
		Short: fmt.Sprintf("List %s", entity.Plural),
		Long: fmt.Sprintf("List %s with optional filters and pagination.\n\n"+
			"Use --json for machine-readable output. Use --all to fetch every row across all pages.\n"+
			"Use --resolve-names to replace foreign key IDs with human-readable names.", entity.Plural),
		Example: fmt.Sprintf("  p202 %s list --json\n"+
			"  p202 %s list --limit 10\n"+
			"  p202 %s list --all --resolve-names", entity.Name, entity.Name, entity.Name),
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("list", entity.Endpoint)
			defer func() { done(retErr == nil, errString(retErr)) }()
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			params := map[string]string{}
			for _, p := range entity.ListParams {
				queryKey := p.QueryKey
				if queryKey == "" {
					queryKey = p.Name
				}
				flagNames := append([]string{p.Name}, p.Aliases...)
				val := ""
				for _, flagName := range flagNames {
					if v, _ := cmd.Flags().GetString(flagName); v != "" {
						val = v
						break
					}
				}
				if val == "" {
					val = getConfigDefault("crud", p.Name)
				}
				if val != "" {
					params[queryKey] = val
				}
			}
			allRows, _ := cmd.Flags().GetBool("all")
			resolveNames, _ := cmd.Flags().GetBool("resolve-names")
			if resolveNames && !envFlagEnabled("CLI_ENABLE_RESOLVE_NAMES", true) {
				return fmt.Errorf("--resolve-names is disabled (set CLI_ENABLE_RESOLVE_NAMES=1 to enable)")
			}

			if allRows {
				rows, err := fetchAllRowsWithParams(c, entity.Endpoint, params)
				if err != nil {
					return err
				}
				if resolveNames {
					if err := resolveForeignKeyNames(c, rows); err != nil {
						return err
					}
				}
				encoded, _ := json.Marshal(map[string]interface{}{
					"data": rows,
					"pagination": map[string]interface{}{
						"total":  len(rows),
						"limit":  len(rows),
						"offset": 0,
					},
				})
				renderForEntity(encoded, entity.Name)
				return nil
			}

			if v, _ := cmd.Flags().GetString("page"); v != "" {
				params["page"] = v
			}
			if v, _ := cmd.Flags().GetString("limit"); v != "" {
				params["limit"] = v
			}
			if v, _ := cmd.Flags().GetString("offset"); v != "" {
				params["offset"] = v
			}
			data, err := c.Get(entity.Endpoint, params)
			if err != nil {
				return err
			}
			if resolveNames {
				rows, err := parseDataArray(data)
				if err != nil {
					return err
				}
				if err := resolveForeignKeyNames(c, rows); err != nil {
					return err
				}
				var parsed map[string]interface{}
				resp := map[string]interface{}{"data": rows}
				if err := json.Unmarshal(data, &parsed); err == nil {
					if pg, ok := parsed["pagination"]; ok {
						resp["pagination"] = pg
					}
				}
				data, _ = json.Marshal(resp)
			}
			renderForEntity(data, entity.Name)
			return nil
		},
	}
	listCmd.Flags().String("page", "", "Page number")
	listCmd.Flags().StringP("limit", "l", "", "Max results")
	listCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	listCmd.Flags().Bool("all", false, "Fetch all rows across pages")
	listCmd.Flags().Bool("resolve-names", false, "Resolve foreign key IDs to names")
	for _, p := range entity.ListParams {
		listCmd.Flags().String(p.Name, "", p.Desc)
		for _, alias := range p.Aliases {
			listCmd.Flags().String(alias, "", p.Desc+" (legacy alias)")
			_ = listCmd.Flags().MarkHidden(alias)
		}
	}

	// get
	getCmd := &cobra.Command{
		Use:   "get <id>",
		Short: fmt.Sprintf("Get a %s by ID", entity.Name),
		Long: fmt.Sprintf("Retrieve a single %s record by its numeric ID.\n\n"+
			"Returns all fields for the record. Use --json for machine-readable output.", entity.Name),
		Example: fmt.Sprintf("  p202 %s get 42 --json", entity.Name),
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("get", entity.Endpoint)
			defer func() { done(retErr == nil, errString(retErr)) }()
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			data, err := c.Get(entity.Endpoint+"/"+args[0], nil)
			if err != nil {
				return err
			}
			renderForEntity(data, entity.Name)
			return nil
		},
	}

	// create
	createCmd := &cobra.Command{
		Use:   "create",
		Short: fmt.Sprintf("Create a new %s", entity.Name),
		Long: fmt.Sprintf("Create a new %s.\n\nRequired flags: %s\nOptional flags: %s",
			entity.Name,
			strings.Join(requiredFieldNames, ", "),
			strings.Join(allFieldNames, ", ")),
		Example: "  " + buildCrudCreateExample(entity.Name, entity.Fields),
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("create", entity.Endpoint)
			defer func() { done(retErr == nil, errString(retErr)) }()

			body := map[string]string{}
			for _, f := range entity.Fields {
				if v := getStringFlagOrDefault(cmd, "crud", f.Name); v != "" {
					body[f.Name] = v
				}
			}
			for _, f := range entity.Fields {
				if f.Required && body[f.Name] == "" {
					return fmt.Errorf("required flag --%s is missing", f.Name)
				}
			}

			if dryRun {
				render(dryRunResponse(entity.Name+" create", body))
				return nil
			}

			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			data, err := c.Post(entity.Endpoint, body)
			if err != nil {
				return err
			}
			auditLog(entity.Name+" create", fmt.Sprintf("%v", body), "OK")
			renderForEntity(data, entity.Name)
			return nil
		},
	}
	for _, f := range entity.Fields {
		createCmd.Flags().String(f.Name, "", f.Desc)
	}

	// update
	updateCmd := &cobra.Command{
		Use:   "update <id>",
		Short: fmt.Sprintf("Update a %s", entity.Name),
		Long: fmt.Sprintf("Update an existing %s by ID. Pass only the fields you want to change.\n\n"+
			"Available fields: %s", entity.Name, strings.Join(allFieldNames, ", ")),
		Example: fmt.Sprintf("  p202 %s update 42 --%s '<new value>' --json", entity.Name, allFieldNames[0]),
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("update", entity.Endpoint)
			defer func() { done(retErr == nil, errString(retErr)) }()

			body := map[string]string{}
			for _, f := range entity.Fields {
				if v, _ := cmd.Flags().GetString(f.Name); v != "" {
					body[f.Name] = v
				}
			}
			if len(body) == 0 {
				return fmt.Errorf("no fields specified; pass at least one flag to update")
			}

			if dryRun {
				render(dryRunResponse(entity.Name+" update "+args[0], body))
				return nil
			}

			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			data, err := c.Put(entity.Endpoint+"/"+args[0], body)
			if err != nil {
				return err
			}
			auditLog(entity.Name+" update", args[0]+" "+fmt.Sprintf("%v", body), "OK")
			renderForEntity(data, entity.Name)
			return nil
		},
	}
	for _, f := range entity.Fields {
		updateCmd.Flags().String(f.Name, "", f.Desc)
	}

	// delete
	deleteCmd := &cobra.Command{
		Use:   "delete <id>",
		Short: fmt.Sprintf("Delete a %s", entity.Name),
		Long: fmt.Sprintf("Delete a %s by ID. Prompts for confirmation unless --force is passed.\n\n"+
			"Use --ids for bulk deletion of multiple records.", entity.Name),
		Example: fmt.Sprintf("  p202 %s delete 42 --force\n"+
			"  p202 %s delete --ids 1,2,3 --force", entity.Name, entity.Name),
		Args: func(cmd *cobra.Command, args []string) error {
			idsFlag, _ := cmd.Flags().GetString("ids")
			if strings.TrimSpace(idsFlag) != "" {
				return cobra.MaximumNArgs(0)(cmd, args)
			}
			return cobra.ExactArgs(1)(cmd, args)
		},
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("delete", entity.Endpoint)
			defer func() { done(retErr == nil, errString(retErr)) }()

			idsFlag, _ := cmd.Flags().GetString("ids")
			if strings.TrimSpace(idsFlag) != "" {
				idList, parseErr := parseIDList(idsFlag)
				if parseErr != nil {
					return parseErr
				}
				if len(idList) == 0 {
					return fmt.Errorf("--ids requires at least one ID")
				}

				if dryRun {
					render(dryRunResponse(entity.Name+" bulk-delete", map[string]interface{}{"ids": idList}))
					return nil
				}

				force, _ := cmd.Flags().GetBool("force")
				if err := confirmOrFail(fmt.Sprintf("Delete %d %s? [y/N] ", len(idList), entity.Plural), force); err != nil {
					if err == errCancelled {
						return nil
					}
					return err
				}

				c, err := api.NewFromConfig()
				if err != nil {
					return err
				}
				deleted := 0
				failed := 0
				for _, id := range idList {
					if err := c.Delete(entity.Endpoint + "/" + id); err != nil {
						failed++
						fmt.Fprintf(os.Stderr, "Failed to delete %s %s: %v\n", entity.Name, id, err)
						continue
					}
					deleted++
				}
				auditLog(entity.Name+" bulk-delete", strings.Join(idList, ","), fmt.Sprintf("deleted=%d failed=%d", deleted, failed))
				output.Success("Deleted %d of %d %s.", deleted, len(idList), entity.Plural)
				if failed > 0 {
					return partialFailureError("failed to delete %d %s", failed, entity.Plural)
				}
				return nil
			}

			if dryRun {
				render(dryRunResponse(entity.Name+" delete "+args[0], nil))
				return nil
			}

			force, _ := cmd.Flags().GetBool("force")
			if err := confirmOrFail(fmt.Sprintf("Delete %s %s? [y/N] ", entity.Name, args[0]), force); err != nil {
				if err == errCancelled {
					return nil
				}
				return err
			}
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			if err := c.Delete(entity.Endpoint + "/" + args[0]); err != nil {
				return err
			}
			auditLog(entity.Name+" delete", args[0], "OK")
			output.Success("%s %s deleted.", capitalize(entity.Name), args[0])
			return nil
		},
	}
	deleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	deleteCmd.Flags().String("ids", "", "Comma-separated IDs to delete in bulk")

	parentCmd.AddCommand(listCmd, getCmd, createCmd, updateCmd, deleteCmd)
	rootCmd.AddCommand(parentCmd)
	return parentCmd
}

func init() {
	entities := []crudEntity{
		{
			Name:     "campaign",
			Plural:   "campaigns (affiliate offers with URLs, payouts, and postback settings)",
			Endpoint: "campaigns",
			Fields: []crudField{
				{Name: "aff_campaign_name", Desc: "Campaign name", Required: true},
				{Name: "aff_campaign_url", Desc: "Primary offer URL", Required: true},
				{Name: "aff_campaign_url_2", Desc: "Offer URL 2"},
				{Name: "aff_campaign_url_3", Desc: "Offer URL 3"},
				{Name: "aff_campaign_url_4", Desc: "Offer URL 4"},
				{Name: "aff_campaign_url_5", Desc: "Offer URL 5"},
				{Name: "aff_campaign_cpc", Desc: "Cost per click"},
				{Name: "aff_campaign_payout", Desc: "Default payout"},
				{Name: "aff_campaign_currency", Desc: "Currency code (e.g. USD)"},
				{Name: "aff_campaign_foreign_payout", Desc: "Foreign currency payout"},
				{Name: "aff_network_id", Desc: "Affiliate network ID"},
				{Name: "aff_campaign_cloaking", Desc: "Enable cloaking (0 or 1)"},
				{Name: "aff_campaign_rotate", Desc: "Enable rotation (0 or 1)"},
				{Name: "aff_campaign_postback_url", Desc: "Postback URL"},
				{Name: "aff_campaign_postback_append", Desc: "Postback append string"},
			},
			ListParams: []crudField{
				{
					Name:     "aff_network_id",
					QueryKey: "filter[aff_network_id]",
					Desc:     "Filter by affiliate network ID",
					Aliases:  []string{"filter[aff_network_id]"},
				},
			},
		},
		{
			Name:     "aff-network",
			Aliases:  []string{"category"},
			Plural:   "categories (affiliate networks)",
			Endpoint: "aff-networks",
			Fields: []crudField{
				{Name: "aff_network_name", Desc: "Network name", Required: true},
				{Name: "dni_network_id", Desc: "DNI network ID"},
				{Name: "aff_network_postback_url", Desc: "Postback URL"},
				{Name: "aff_network_postback_append", Desc: "Postback append string"},
			},
		},
		{
			Name:     "ppc-network",
			Aliases:  []string{"traffic-network"},
			Plural:   "traffic source networks (PPC networks)",
			Endpoint: "ppc-networks",
			Fields: []crudField{
				{Name: "ppc_network_name", Desc: "Network name", Required: true},
			},
		},
		{
			Name:     "ppc-account",
			Aliases:  []string{"traffic-source"},
			Plural:   "traffic sources (PPC accounts)",
			Endpoint: "ppc-accounts",
			Fields: []crudField{
				{Name: "ppc_account_name", Desc: "Account name", Required: true},
				{Name: "ppc_network_id", Desc: "PPC network ID", Required: true},
				{Name: "ppc_account_default", Desc: "Set as default account (0 or 1)"},
			},
			ListParams: []crudField{
				{Name: "ppc_network_id", QueryKey: "filter[ppc_network_id]", Desc: "Filter by PPC network ID"},
			},
		},
		{
			Name:     "tracker",
			Plural:   "trackers (tracking links that tie a traffic source to a campaign and landing page)",
			Endpoint: "trackers",
			Fields: []crudField{
				{Name: "aff_campaign_id", Desc: "Campaign ID", Required: true},
				{Name: "ppc_account_id", Desc: "PPC account ID"},
				{Name: "text_ad_id", Desc: "Text ad ID"},
				{Name: "landing_page_id", Desc: "Landing page ID"},
				{Name: "rotator_id", Desc: "Rotator ID"},
				{Name: "click_cpc", Desc: "Cost per click"},
				{Name: "click_cpa", Desc: "Cost per action"},
				{Name: "click_cloaking", Desc: "Enable cloaking (0 or 1)"},
				{Name: "tracker_id_public", Desc: "Public tracker ID"},
			},
			ListParams: []crudField{
				{Name: "aff_campaign_id", QueryKey: "filter[aff_campaign_id]", Desc: "Filter by campaign ID"},
				{Name: "ppc_account_id", QueryKey: "filter[ppc_account_id]", Desc: "Filter by PPC account ID"},
				{Name: "landing_page_id", QueryKey: "filter[landing_page_id]", Desc: "Filter by landing page ID"},
			},
		},
		{
			Name:     "landing-page",
			Plural:   "landing pages (pre-sell pages visitors see before the offer)",
			Endpoint: "landing-pages",
			Fields: []crudField{
				{Name: "landing_page_url", Desc: "Landing page URL", Required: true},
				{Name: "aff_campaign_id", Desc: "Campaign ID", Required: true},
				{Name: "landing_page_nickname", Desc: "Landing page nickname"},
				{Name: "leave_behind_page_url", Desc: "Leave-behind page URL"},
				{Name: "landing_page_type", Desc: "Landing page type (integer)"},
			},
			ListParams: []crudField{
				{Name: "aff_campaign_id", QueryKey: "filter[aff_campaign_id]", Desc: "Filter by campaign ID"},
			},
		},
		{
			Name:     "text-ad",
			Plural:   "text ads (ad creatives with headline, description, and display URL)",
			Endpoint: "text-ads",
			Fields: []crudField{
				{Name: "text_ad_name", Desc: "Text ad name", Required: true},
				{Name: "text_ad_headline", Desc: "Headline"},
				{Name: "text_ad_description", Desc: "Description text"},
				{Name: "text_ad_display_url", Desc: "Display URL"},
				{Name: "aff_campaign_id", Desc: "Campaign ID"},
				{Name: "landing_page_id", Desc: "Landing page ID"},
				{Name: "text_ad_type", Desc: "Text ad type (integer)"},
			},
			ListParams: []crudField{
				{Name: "aff_campaign_id", QueryKey: "filter[aff_campaign_id]", Desc: "Filter by campaign ID"},
			},
		},
	}

	var trackerCmd *cobra.Command
	var campaignCmd *cobra.Command
	var trackerEntity crudEntity
	var campaignEntity crudEntity
	for _, e := range entities {
		cmd := registerCRUD(e)
		if e.Name == "tracker" {
			trackerCmd = cmd
			trackerEntity = e
		}
		if e.Name == "campaign" {
			campaignCmd = cmd
			campaignEntity = e
		}

		// Register agent metadata for each CRUD subcommand
		allNames := crudFieldNames(e.Fields, false)
		registerMeta(e.Name+" list", commandMeta{
			Examples: []string{
				"p202 " + e.Name + " list --json",
				"p202 " + e.Name + " list --limit 10",
				"p202 " + e.Name + " list --all --resolve-names",
			},
			OutputFields: allNames,
			Related:      []string{e.Name + " get", e.Name + " create"},
		})
		registerMeta(e.Name+" get", commandMeta{
			Examples:     []string{"p202 " + e.Name + " get 42 --json"},
			OutputFields: allNames,
			Related:      []string{e.Name + " list", e.Name + " update"},
		})
		registerMeta(e.Name+" create", commandMeta{
			Examples:     []string{buildCrudCreateExample(e.Name, e.Fields)},
			OutputFields: allNames,
			Related:      []string{e.Name + " list", e.Name + " get"},
			Mutating:     true,
		})
		registerMeta(e.Name+" update", commandMeta{
			Examples:     []string{fmt.Sprintf("p202 %s update 42 --%s '<new value>' --json", e.Name, allNames[0])},
			OutputFields: allNames,
			Related:      []string{e.Name + " get"},
			Mutating:     true,
		})
		registerMeta(e.Name+" delete", commandMeta{
			Examples:     []string{"p202 " + e.Name + " delete 42 --force", "p202 " + e.Name + " delete --ids 1,2,3 --force"},
			OutputFields: []string{},
			Related:      []string{e.Name + " list"},
			Mutating:     true,
		})
	}

	if campaignCmd != nil {
		cloneCmd := &cobra.Command{
			Use:   "clone <id>",
			Short: "Clone a campaign",
			Long:  "Create a copy of an existing campaign with all its settings. Optionally override the name with --name.",
			Example: "  p202 campaign clone 42 --json\n" +
				"  p202 campaign clone 42 --name 'Q2 Offer Copy'",
			Args: cobra.ExactArgs(1),
			RunE: func(cmd *cobra.Command, args []string) error {
				c, err := api.NewFromConfig()
				if err != nil {
					return err
				}

				sourceData, err := c.Get("campaigns/"+args[0], nil)
				if err != nil {
					return err
				}
				sourceObj, err := parseDataObject(sourceData)
				if err != nil {
					return fmt.Errorf("failed to parse source campaign: %w", err)
				}

				payload := cloneMutableFields(sourceObj, campaignEntity.Fields)
				overrideName, _ := cmd.Flags().GetString("name")
				if overrideName != "" {
					payload["aff_campaign_name"] = overrideName
				} else if name, ok := payload["aff_campaign_name"].(string); ok && name != "" {
					payload["aff_campaign_name"] = name + " (Clone)"
				}

				clonedData, err := c.Post("campaigns", payload)
				if err != nil {
					return err
				}
				render(clonedData)
				return nil
			},
		}
		cloneCmd.Flags().String("name", "", "Optional name override for the cloned campaign")
		campaignCmd.AddCommand(cloneCmd)

		registerMeta("campaign clone", commandMeta{
			Examples:     []string{"p202 campaign clone 42 --json", "p202 campaign clone 42 --name 'Q2 Offer Copy'"},
			OutputFields: crudFieldNames(campaignEntity.Fields, false),
			Related:      []string{"campaign get", "campaign list"},
			Mutating:     true,
		})
	}

	if trackerCmd != nil {
		getURLCmd := &cobra.Command{
			Use:     "get-url <id>",
			Short:   "Get tracking URL for a tracker",
			Long:    "Retrieve the tracking URL for a specific tracker by its ID. This is the URL you place in your traffic source.",
			Example: "  p202 tracker get-url 42 --json",
			Args:    cobra.ExactArgs(1),
			RunE: func(cmd *cobra.Command, args []string) error {
				c, err := api.NewFromConfig()
				if err != nil {
					return err
				}
				data, err := c.Get("trackers/"+args[0]+"/url", nil)
				if err != nil {
					return err
				}
				render(data)
				return nil
			},
		}
		createWithURLCmd := &cobra.Command{
			Use:   "create-with-url",
			Short: "Create a tracker and return its tracking URL",
			Long: "Create a new tracker and immediately return both the tracker record and its\n" +
				"tracking URL in a single response. This is the most common way to set up tracking.",
			Example: "  p202 tracker create-with-url --aff_campaign_id 5 --ppc_account_id 3 --json",
			RunE: func(cmd *cobra.Command, args []string) error {
				c, err := api.NewFromConfig()
				if err != nil {
					return err
				}

				body := map[string]string{}
				for _, f := range trackerEntity.Fields {
					if v, _ := cmd.Flags().GetString(f.Name); v != "" {
						body[f.Name] = v
					}
				}
				for _, f := range trackerEntity.Fields {
					if f.Required && body[f.Name] == "" {
						return fmt.Errorf("required flag --%s is missing", f.Name)
					}
				}

				createdData, err := c.Post("trackers", body)
				if err != nil {
					return err
				}
				createdObj, err := parseDataObject(createdData)
				if err != nil {
					return fmt.Errorf("failed to parse tracker create response: %w", err)
				}
				trackerID, ok := extractIntField(createdObj, "tracker_id", "id")
				if !ok {
					return fmt.Errorf("tracker create response did not include tracker_id")
				}

				urlData, err := c.Get(fmt.Sprintf("trackers/%d/url", trackerID), nil)
				if err != nil {
					return err
				}
				urlObj, err := parseDataObject(urlData)
				if err != nil {
					return fmt.Errorf("failed to parse tracker url response: %w", err)
				}

				out := map[string]interface{}{
					"tracker":      createdObj,
					"tracking_url": urlObj,
				}
				encoded, _ := json.Marshal(out)
				render(encoded)
				return nil
			},
		}
		for _, f := range trackerEntity.Fields {
			createWithURLCmd.Flags().String(f.Name, "", f.Desc)
		}

		bulkURLsCmd := &cobra.Command{
			Use:   "bulk-urls",
			Short: "Fetch tracking URLs for multiple trackers",
			Long: "Fetch tracking URLs for multiple trackers concurrently. Optionally filter by\n" +
				"campaign or PPC account. Results include tracker_id and the tracking URL.",
			Example: "  p202 tracker bulk-urls --json\n" +
				"  p202 tracker bulk-urls --aff_campaign_id 5 --concurrency 10 --json",
			RunE: func(cmd *cobra.Command, args []string) error {
				c, err := api.NewFromConfig()
				if err != nil {
					return err
				}

				params := map[string]string{}
				for _, p := range trackerEntity.ListParams {
					if v, _ := cmd.Flags().GetString(p.Name); v != "" {
						if p.QueryKey != "" {
							params[p.QueryKey] = v
						} else {
							params[p.Name] = v
						}
					}
				}
				for _, key := range []string{"limit", "offset"} {
					if v, _ := cmd.Flags().GetString(key); v != "" {
						params[key] = v
					}
				}

				listData, err := c.Get("trackers", params)
				if err != nil {
					return err
				}
				trackers, err := parseDataArray(listData)
				if err != nil {
					return err
				}
				if len(trackers) == 0 {
					render([]byte(`{"data":[]}`))
					return nil
				}

				concurrency, _ := cmd.Flags().GetInt("concurrency")
				if concurrency < 1 {
					return fmt.Errorf("--concurrency must be at least 1")
				}
				if concurrency > len(trackers) {
					concurrency = len(trackers)
				}

				type bulkResult struct {
					index int
					row   map[string]interface{}
					err   error
				}

				jobs := make(chan int, len(trackers))
				results := make(chan bulkResult, len(trackers))
				var wg sync.WaitGroup

				worker := func() {
					defer wg.Done()
					for index := range jobs {
						tracker := trackers[index]
						trackerID, ok := extractIntField(tracker, "tracker_id", "id")
						if !ok {
							results <- bulkResult{index: index, err: fmt.Errorf("tracker row missing tracker_id")}
							continue
						}

						urlData, err := c.Get(fmt.Sprintf("trackers/%d/url", trackerID), nil)
						if err != nil {
							results <- bulkResult{index: index, err: err}
							continue
						}
						urlObj, err := parseDataObject(urlData)
						if err != nil {
							results <- bulkResult{index: index, err: err}
							continue
						}

						row := map[string]interface{}{"tracker_id": trackerID}
						if affCampaignID, ok := extractIntField(tracker, "aff_campaign_id"); ok {
							row["aff_campaign_id"] = affCampaignID
						}
						for key, value := range urlObj {
							row[key] = value
						}
						results <- bulkResult{index: index, row: row}
					}
				}

				for i := 0; i < concurrency; i++ {
					wg.Add(1)
					go worker()
				}
				for i := range trackers {
					jobs <- i
				}
				close(jobs)

				go func() {
					wg.Wait()
					close(results)
				}()

				ordered := make([]map[string]interface{}, len(trackers))
				for result := range results {
					if result.err != nil {
						return result.err
					}
					ordered[result.index] = result.row
				}

				encoded, _ := json.Marshal(map[string]interface{}{"data": ordered})
				render(encoded)
				return nil
			},
		}
		bulkURLsCmd.Flags().String("aff_campaign_id", "", "Filter by campaign ID")
		bulkURLsCmd.Flags().String("ppc_account_id", "", "Filter by PPC account ID")
		bulkURLsCmd.Flags().String("landing_page_id", "", "Filter by landing page ID")
		bulkURLsCmd.Flags().StringP("limit", "l", "", "Max results")
		bulkURLsCmd.Flags().StringP("offset", "o", "", "Pagination offset")
		bulkURLsCmd.Flags().Int("concurrency", 5, "Number of concurrent URL fetches")

		trackerCmd.AddCommand(getURLCmd, createWithURLCmd, bulkURLsCmd)

		registerMeta("tracker get-url", commandMeta{
			Examples:     []string{"p202 tracker get-url 42 --json"},
			OutputFields: []string{"tracking_url", "base_url"},
			Related:      []string{"tracker list", "tracker create-with-url"},
		})
		registerMeta("tracker create-with-url", commandMeta{
			Examples:     []string{"p202 tracker create-with-url --aff_campaign_id 5 --ppc_account_id 3 --json"},
			OutputFields: []string{"tracker", "tracking_url"},
			Related:      []string{"tracker get-url", "tracker list", "campaign list"},
			Mutating:     true,
		})
		registerMeta("tracker bulk-urls", commandMeta{
			Examples:     []string{"p202 tracker bulk-urls --json", "p202 tracker bulk-urls --aff_campaign_id 5 --concurrency 10 --json"},
			OutputFields: []string{"tracker_id", "aff_campaign_id", "tracking_url"},
			Related:      []string{"tracker list", "tracker get-url"},
		})
	}
}
