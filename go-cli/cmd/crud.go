package cmd

import (
	"fmt"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

type crudField struct {
	Name     string
	Desc     string
	Required bool
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
				if v, _ := cmd.Flags().GetString(p.Name); v != "" {
					params[p.Name] = v
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
			output.Render(data, jsonOutput)
			return nil
		},
	}
	listCmd.Flags().String("page", "", "Page number")
	listCmd.Flags().StringP("limit", "l", "", "Max results")
	listCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	for _, p := range entity.ListParams {
		listCmd.Flags().String(p.Name, "", p.Desc)
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
			output.Render(data, jsonOutput)
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
				if v, _ := cmd.Flags().GetString(f.Name); v != "" {
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
			output.Render(data, jsonOutput)
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
			output.Render(data, jsonOutput)
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
				{Name: "filter[aff_network_id]", Desc: "Filter by affiliate network ID"},
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
		},
	}

	var trackerCmd *cobra.Command
	for _, e := range entities {
		cmd := registerCRUD(e)
		if e.Name == "tracker" {
			trackerCmd = cmd
		}
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
				output.Render(data, jsonOutput)
				return nil
			},
		}
		trackerCmd.AddCommand(getURLCmd)
	}
}
