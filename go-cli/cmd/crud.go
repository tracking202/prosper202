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

func registerCRUD(entity crudEntity) {
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
				{Name: "aff_campaign_cpc", Desc: "Cost per click"},
				{Name: "aff_campaign_payout", Desc: "Default payout"},
				{Name: "aff_network_id", Desc: "Affiliate network ID"},
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
			},
		},
		{
			Name:     "tracker",
			Plural:   "trackers",
			Endpoint: "trackers",
			Fields: []crudField{
				{Name: "tracker_name", Desc: "Tracker name", Required: true},
				{Name: "aff_campaign_id", Desc: "Campaign ID", Required: true},
				{Name: "ppc_account_id", Desc: "PPC account ID"},
				{Name: "landing_page_id", Desc: "Landing page ID"},
				{Name: "tracker_cpc", Desc: "Cost per click override"},
			},
		},
		{
			Name:     "landing-page",
			Plural:   "landing pages",
			Endpoint: "landing-pages",
			Fields: []crudField{
				{Name: "landing_page_name", Desc: "Landing page name", Required: true},
				{Name: "landing_page_url", Desc: "Landing page URL", Required: true},
			},
		},
		{
			Name:     "text-ad",
			Plural:   "text ads",
			Endpoint: "text-ads",
			Fields: []crudField{
				{Name: "text_ad_name", Desc: "Text ad name", Required: true},
				{Name: "text_ad_headline", Desc: "Headline"},
				{Name: "text_ad_body", Desc: "Body text"},
				{Name: "text_ad_display_url", Desc: "Display URL"},
			},
		},
	}

	for _, e := range entities {
		registerCRUD(e)
	}
}
