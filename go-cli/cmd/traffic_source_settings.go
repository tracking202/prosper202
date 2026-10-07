package cmd

import (
	"errors"
	"fmt"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// A traffic source's custom variables and an account's pixels, as Setup >
// Traffic Sources edits them (capabilities features.setup_section):
//
//	p202 ppc-network variable list|create|update|delete <ppc_network_id> ...
//	p202 ppc-account pixel list|create|update|delete <ppc_account_id> ...
//
// The API holds them to the page's rules; these commands check what they can
// before any request (ids, at least one field to change) and leave the rest
// to the server's field errors, which name the field and the rule.

func newPpcNetworkVariableCmd() *cobra.Command {
	group := &cobra.Command{
		Use:     "variable",
		Aliases: []string{"variables", "var"},
		Short:   "Manage a traffic source's custom variables (the extra parameters its tracking links carry)",
		Long: "A traffic source's custom variables are the extra parameters its tracking links\n" +
			"carry, each with the placeholder the traffic source fills in: parameter=placeholder,\n" +
			"e.g. adid={ad_id}. `p202 tracker get-url` writes the live ones into every link of the\n" +
			"source's accounts. A variable whose parameter is a built-in token (c1-c4, utm_*,\n" +
			"t202kw, t202ref, t202b) sets that token's default instead.\n\n" +
			"Setup > Traffic Sources > variables edits the same list. Needs a role with\n" +
			"remove_traffic_source and access_to_setup_section (Super user or Admin; the\n" +
			"page shows the variables dialog only to them) and a key with ppc-networks scope.",
	}

	list := &cobra.Command{
		Use:     "list <ppc_network_id>",
		Short:   "List a traffic source's custom variables",
		Example: "  p202 ppc-network variable list 3",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			return setupGet("ppc-networks/"+id+"/variables", "Setup > Traffic Sources")
		},
	}

	create := &cobra.Command{
		Use:     "create <ppc_network_id>",
		Short:   "Add a custom variable to a traffic source",
		Example: "  p202 ppc-network variable create 3 --name 'Ad id' --parameter adid --placeholder '{ad_id}'",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			body := map[string]string{}
			for _, name := range []string{"name", "parameter", "placeholder"} {
				v, _ := cmd.Flags().GetString(name)
				if v == "" {
					return validationError("--%s is required", name).
						WithHint("A variable has a name (shown in reports), a parameter (the link's key) and a placeholder (what the traffic source fills in), e.g. --name 'Ad id' --parameter adid --placeholder '{ad_id}'.")
				}
				body[name] = v
			}
			idemKey, _ := cmd.Flags().GetString("idempotency-key")
			return setupWrite(func(c *api.Client) ([]byte, error) {
				return c.PostIdempotent("ppc-networks/"+id+"/variables", body, idemKey)
			})
		},
	}
	registerIdempotencyKeyFlag(create)

	update := &cobra.Command{
		Use:     "update <ppc_network_id> <variable_id>",
		Short:   "Change a traffic source's custom variable",
		Example: "  p202 ppc-network variable update 3 7 --placeholder '{{ad.id}}'",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			variableID, err := validateID(args[1])
			if err != nil {
				return err
			}
			body := map[string]string{}
			for _, name := range []string{"name", "parameter", "placeholder"} {
				if cmd.Flags().Changed(name) {
					body[name], _ = cmd.Flags().GetString(name)
				}
			}
			if len(body) == 0 {
				return validationError("no fields specified; pass --name, --parameter or --placeholder")
			}
			return setupWrite(func(c *api.Client) ([]byte, error) {
				return c.Put("ppc-networks/"+id+"/variables/"+variableID, body)
			})
		},
	}

	for _, cmd := range []*cobra.Command{create, update} {
		cmd.Flags().String("name", "", "The variable's name in reports")
		cmd.Flags().String("parameter", "", "The link's parameter, e.g. adid (no spaces, &, #, ?, =)")
		cmd.Flags().String("placeholder", "", "What the traffic source fills in, e.g. {ad_id} (no spaces, &, #, ?)")
	}

	del := &cobra.Command{
		Use:   "delete <ppc_network_id> <variable_id>",
		Short: "Remove a traffic source's custom variable (new links stop carrying it)",
		Long: "Retires the variable, as the Setup dialog does: new tracking links stop carrying it,\n" +
			"and clicks already recorded with it keep their values.",
		Example: "  p202 ppc-network variable delete 3 7 --dry-run\n  p202 ppc-network variable delete 3 7 --force",
		Args:    deleteArgsValidatorN(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			return runBulkOrSingleDelete(cmd, args[1:], deleteSpec{
				endpoint:    "ppc-networks/" + id + "/variables",
				noun:        "variable",
				plural:      "variables",
				context:     " from traffic source " + id,
				explain:     explainSetupNotFound,
				idsHintText: fmt.Sprintf("Comma-separate variable ids, e.g. --ids 7,8 (find them with `p202 ppc-network variable list %s`).", id),
			})
		},
	}
	registerDeleteFlags(del, "variable")

	group.AddCommand(list, create, update, del)
	return group
}

func newPpcAccountPixelCmd() *cobra.Command {
	group := &cobra.Command{
		Use:     "pixel",
		Aliases: []string{"pixels"},
		Short:   "Manage the pixels a traffic source account fires on a conversion",
		Long: "An account's pixels report its conversions back to the traffic source. Types\n" +
			"(--type-id): 1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server),\n" +
			"5 Raw (markup as given), 6 Bot202 Facebook Pixel Assistant. For every type except\n" +
			"Raw the code is the URL (several separated by spaces), with tokens such as\n" +
			"[[subid]], [[payout]] or [[transactionid]] filled in when it fires.\n\n" +
			"A Postback pixel can also take a correction URL (--correction-url), where corrections\n" +
			"go when a conversion it announced is replaced; one per code URL, in the same order.\n\n" +
			"Setup > Traffic Sources > an account's Advanced edits the same pixels. Needs a role\n" +
			"with access_to_setup_section and a key with ppc-accounts scope.",
	}

	list := &cobra.Command{
		Use:     "list <ppc_account_id>",
		Short:   "List a traffic source account's pixels",
		Example: "  p202 ppc-account pixel list 4",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			return setupGet("ppc-accounts/"+id+"/pixels", "Setup > Traffic Sources")
		},
	}

	create := &cobra.Command{
		Use:   "create <ppc_account_id>",
		Short: "Add a pixel to a traffic source account",
		Example: "  p202 ppc-account pixel create 4 --type-id 4 --code 'https://network.example/pb?click=[[subid]]&payout=[[payout]]'\n" +
			"  p202 ppc-account pixel create 4 --type-id 1 --code 'https://ads.example/px.gif?c=[[subid]]'",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			body := pixelBody(cmd)
			for _, name := range []string{"pixel_type_id", "pixel_code"} {
				if _, ok := body[name]; !ok {
					return validationError("--type-id and --code are required").
						WithHint("--type-id: 1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server), 5 Raw, 6 Bot202 Facebook Pixel Assistant; --code: the pixel's URL (or markup, for Raw).")
				}
			}
			idemKey, _ := cmd.Flags().GetString("idempotency-key")
			return setupWrite(func(c *api.Client) ([]byte, error) {
				return c.PostIdempotent("ppc-accounts/"+id+"/pixels", body, idemKey)
			})
		},
	}
	registerIdempotencyKeyFlag(create)

	update := &cobra.Command{
		Use:     "update <ppc_account_id> <pixel_id>",
		Short:   "Change a traffic source account's pixel",
		Example: "  p202 ppc-account pixel update 4 9 --correction-url 'https://network.example/fix?tx=[[transactionid]]'\n  p202 ppc-account pixel update 4 9 --correction-url ''",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			pixelID, err := validateID(args[1])
			if err != nil {
				return err
			}
			body := pixelBody(cmd)
			if len(body) == 0 {
				return validationError("no fields specified; pass --type-id, --code or --correction-url")
			}
			return setupWrite(func(c *api.Client) ([]byte, error) {
				return c.Put("ppc-accounts/"+id+"/pixels/"+pixelID, body)
			})
		},
	}

	for _, cmd := range []*cobra.Command{create, update} {
		cmd.Flags().String("type-id", "", "Pixel type: 1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server), 5 Raw, 6 Bot202 Facebook Pixel Assistant")
		enumFlag(cmd, "type-id", newEnum([]string{"1", "2", "3", "4", "5", "6"},
			enumHint("1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server), 5 Raw (markup as given), 6 Bot202 Facebook Pixel Assistant.")))
		cmd.Flags().String("code", "", "The pixel's URL (several separated by spaces), or the markup for Raw")
		cmd.Flags().String("correction-url", "", "Postback pixels only: where corrections go, one URL per code URL in the same order")
	}
	allowEmpty(update, "correction-url")

	del := &cobra.Command{
		Use:     "delete <ppc_account_id> <pixel_id>",
		Short:   "Remove a pixel from a traffic source account (with its correction URL)",
		Example: "  p202 ppc-account pixel delete 4 9 --dry-run\n  p202 ppc-account pixel delete 4 9 --force",
		Args:    deleteArgsValidatorN(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			return runBulkOrSingleDelete(cmd, args[1:], deleteSpec{
				endpoint:    "ppc-accounts/" + id + "/pixels",
				noun:        "pixel",
				plural:      "pixels",
				context:     " from traffic source account " + id,
				explain:     explainSetupNotFound,
				idsHintText: fmt.Sprintf("Comma-separate pixel ids, e.g. --ids 9,10 (find them with `p202 ppc-account pixel list %s`).", id),
			})
		},
	}
	registerDeleteFlags(del, "pixel")

	group.AddCommand(list, create, update, del)
	return group
}

// explainSetupNotFound names the list to read ids from when a delete (or its
// preview) answers 404 for a record the Setup controllers name.
func explainSetupNotFound(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 404 {
		if hint := setupNotFoundHint(apiErr.Message); hint != "" {
			return withHint(err, "%s", hint)
		}
	}
	return err
}

// pixelBody is the fields the caller set, by the API's names.
func pixelBody(cmd *cobra.Command) map[string]interface{} {
	body := map[string]interface{}{}
	if cmd.Flags().Changed("type-id") {
		v, _ := cmd.Flags().GetString("type-id")
		body["pixel_type_id"] = v
	}
	if cmd.Flags().Changed("code") {
		v, _ := cmd.Flags().GetString("code")
		body["pixel_code"] = v
	}
	if cmd.Flags().Changed("correction-url") {
		v, _ := cmd.Flags().GetString("correction-url")
		body["correction_url"] = v
	}
	return body
}

// setupGet renders one Setup read.
func setupGet(path, page string) error {
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := c.Get(path, nil)
	if err != nil {
		return setupRequestError(c, err, page)
	}
	render(data)
	return nil
}

// setupWrite renders one Setup write (or, under --staged, the proposal it
// was recorded as).
func setupWrite(do func(*api.Client) ([]byte, error)) error {
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	data, err := do(c)
	if err != nil {
		return setupRequestError(c, err, "Setup > Traffic Sources")
	}
	render(data)
	return nil
}
