package cmd

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"sync"

	"p202/internal/api"
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
	Plural     string
	Endpoint   string
	Fields     []crudField
	ListParams []crudField
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

func registerCRUD(entity crudEntity) *cobra.Command {
	parentCmd := &cobra.Command{
		Use:   entity.Name,
		Short: fmt.Sprintf("Manage %s", entity.Plural),
	}

	// list
	listCmd := &cobra.Command{
		Use:   "list",
		Short: fmt.Sprintf("List %s", entity.Plural),
		RunE: func(cmd *cobra.Command, args []string) error {
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
			render(data)
			return nil
		},
	}
	listCmd.Flags().String("page", "", "Page number")
	listCmd.Flags().StringP("limit", "l", "", "Max results")
	listCmd.Flags().StringP("offset", "o", "", "Pagination offset")
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
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			data, err := c.Get(entity.Endpoint+"/"+args[0], nil)
			if err != nil {
				return err
			}
			render(data)
			return nil
		},
	}

	// create
	createCmd := &cobra.Command{
		Use:   "create",
		Short: fmt.Sprintf("Create a new %s", entity.Name),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
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
			data, err := c.Post(entity.Endpoint, body)
			if err != nil {
				return err
			}
			render(data)
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
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			body := map[string]string{}
			for _, f := range entity.Fields {
				if v, _ := cmd.Flags().GetString(f.Name); v != "" {
					body[f.Name] = v
				}
			}
			if len(body) == 0 {
				return fmt.Errorf("no fields specified; pass at least one flag to update")
			}
			data, err := c.Put(entity.Endpoint+"/"+args[0], body)
			if err != nil {
				return err
			}
			render(data)
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
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			force, _ := cmd.Flags().GetBool("force")
			if !force {
				fmt.Printf("Delete %s %s? [y/N] ", entity.Name, args[0])
				var answer string
				fmt.Scanln(&answer)
				if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
					fmt.Println("Cancelled.")
					return nil
				}
			}
			if err := c.Delete(entity.Endpoint + "/" + args[0]); err != nil {
				return err
			}
			output.Success("%s %s deleted.", capitalize(entity.Name), args[0])
			return nil
		},
	}
	deleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	parentCmd.AddCommand(listCmd, getCmd, createCmd, updateCmd, deleteCmd)
	rootCmd.AddCommand(parentCmd)
	return parentCmd
}

func init() {
	entities := []crudEntity{
		{
			Name:     "campaign",
			Plural:   "campaigns",
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
			Plural:   "affiliate networks",
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
			Plural:   "PPC networks",
			Endpoint: "ppc-networks",
			Fields: []crudField{
				{Name: "ppc_network_name", Desc: "Network name", Required: true},
			},
		},
		{
			Name:     "ppc-account",
			Plural:   "PPC accounts",
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
			Plural:   "trackers",
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
			},
			ListParams: []crudField{
				{Name: "aff_campaign_id", QueryKey: "filter[aff_campaign_id]", Desc: "Filter by campaign ID"},
				{Name: "ppc_account_id", QueryKey: "filter[ppc_account_id]", Desc: "Filter by PPC account ID"},
				{Name: "landing_page_id", QueryKey: "filter[landing_page_id]", Desc: "Filter by landing page ID"},
			},
		},
		{
			Name:     "landing-page",
			Plural:   "landing pages",
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
			Plural:   "text ads",
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
	}

	if campaignCmd != nil {
		cloneCmd := &cobra.Command{
			Use:   "clone <id>",
			Short: "Clone a campaign",
			Args:  cobra.ExactArgs(1),
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
	}

	if trackerCmd != nil {
		getURLCmd := &cobra.Command{
			Use:   "get-url <id>",
			Short: "Get tracking URL for a tracker",
			Args:  cobra.ExactArgs(1),
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
	}
}
