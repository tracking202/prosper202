package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var rotatorCmd = &cobra.Command{
	Use:     "rotator",
	Aliases: []string{"redirector"},
	Short:   "Manage redirectors (rotators and rules)",
}

var rotatorListCmd = &cobra.Command{
	Use:   "list",
	Short: "List all redirectors/rotators",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		allRows, _ := cmd.Flags().GetBool("all")
		if allRows {
			rows, err := fetchAllRows(c, "rotators")
			if err != nil {
				return err
			}
			encoded, err := json.Marshal(map[string]interface{}{
				"data": rows,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  len(rows),
					"offset": 0,
				},
			})
			if err != nil {
				return fmt.Errorf("encoding output: %w", err)
			}
			render(encoded)
			return nil
		}
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("limit"); v != "" {
			params["limit"] = v
		}
		if v, _ := cmd.Flags().GetString("offset"); v != "" {
			params["offset"] = v
		}
		data, err := c.Get("rotators", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get a redirector/rotator and its routing rules by ID",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("rotators/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorStatsCmd = &cobra.Command{
	Use:   "stats <id>",
	Short: "Show a rotator's performance: its totals, each rule, and its default (clicks no rule matched)",
	Long: "The Overview's Rotator Breakdown for one rotator, from GET /rotators/{id}/stats: clicks, leads, income,\n" +
		"cost, net, EPC, conversion rate and ROI for the rotator, for each of its rules (the rule the click matched),\n" +
		"and for its default. The rules and the default add up to the totals; a rule since deleted that still has\n" +
		"clicks in the window is listed with deleted: true. Takes the window and the filters every report takes.\n" +
		"Needs a key with read scope on rotators and reports.",
	Example: "  p202 rotator stats 3 --period last30\n" +
		"  p202 rotator stats 3 --period yesterday --show real",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := validateID(args[0])
		if err != nil {
			return withHint(err, "Pass the rotator's id from `p202 rotator list`.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("rotators/"+id+"/stats", collectReportParams(cmd))
		if err != nil {
			// A missing rotator is "Rotator not found"; a server that
			// predates the endpoint answers the router's bare "Not found".
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 404 {
				if strings.Contains(strings.ToLower(apiErr.Message), "rotator") {
					return withHint(err, "No rotator %s on this account; `p202 rotator list` shows the ids.", id)
				}
				return withHint(err, "This server has no GET /rotators/{id}/stats; upgrade it to read rotator stats.")
			}
			return err
		}
		render(data)
		return nil
	},
}

var rotatorCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a new redirector/rotator for rule-based traffic splitting",
	RunE: func(cmd *cobra.Command, args []string) error {
		name, _ := cmd.Flags().GetString("name")
		if name == "" {
			return validationError("required flag --name is missing").WithHint("Name the redirector, e.g. --name \"Geo split\".")
		}
		body := map[string]interface{}{"name": name}
		for _, f := range []string{"default_url", "default_campaign", "default_lp"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("rotators", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a redirector/rotator's name or defaults",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		for _, f := range []string{"name", "default_url", "default_campaign", "default_lp"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("--name, or one of --default_url, --default_campaign, --default_lp (the default is one of them; setting one clears the others).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("rotators/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a redirector/rotator and all its routing rules",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    "rotators",
			noun:        "rotator",
			plural:      "rotators",
			cascadeOne:  " and all its rules",
			cascadeMany: " and all their rules",
		})
	},
}

// criteriaJSONHint and redirectsJSONHint say what the server takes for a
// rule's criteria and redirects (RotatorsController::ruleParts).
const (
	criteriaJSONHint  = `A JSON array of {"type","statement","value"}: type country, region, city, isp, ip, platform, device or browser; statement is or is_not; value comma-separated (countries as "United States(US)", see ` + "`p202 rotator criteria-values`" + `; ip as single IPv4 or IPv6 addresses, no ranges).`
	redirectsJSONHint = `A JSON array of {"redirect_url" | "redirect_campaign" | "redirect_lp", "weight" (0-100), "name"}: one destination each.`
)

var rotatorRuleCreateCmd = &cobra.Command{
	Use:   "rule-create <rotator_id>",
	Short: "Add a routing rule to a redirector/rotator (criteria + redirect targets)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		ruleName, _ := cmd.Flags().GetString("rule_name")
		if ruleName == "" {
			return validationError("required flag --rule_name is missing")
		}
		body := map[string]interface{}{
			"rule_name": ruleName,
		}
		for _, f := range []string{"splittest", "status"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if v, _ := cmd.Flags().GetString("criteria_json"); v != "" {
			var criteria interface{}
			if err := json.Unmarshal([]byte(v), &criteria); err != nil {
				return validationError("invalid --criteria_json: %s", err.Error()).WithHint("%s", criteriaJSONHint)
			}
			body["criteria"] = criteria
		} else if code, _ := cmd.Flags().GetString("country"); code != "" {
			// Sugar: build a country criterion from an ISO code so the caller
			// never has to know the exact "Name(CC)" value string.
			value := countryCriteriaValue(code)
			if value == "" {
				return validationError("unknown country code %q (see `rotator criteria-values --search ...`); or use --criteria_json", code)
			}
			body["criteria"] = []map[string]string{{"type": "country", "statement": "is", "value": value}}
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return validationError("invalid --redirects_json: %s", err.Error()).WithHint("%s", redirectsJSONHint)
			}
			body["redirects"] = redirects
		} else if camp, _ := cmd.Flags().GetString("redirect-campaign"); camp != "" {
			// Sugar: a single-campaign redirect at full weight.
			body["redirects"] = []map[string]string{{"redirect_campaign": camp, "weight": "100", "name": "to campaign " + camp}}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("rotators/"+args[0]+"/rules", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorRuleDeleteCmd = &cobra.Command{
	Use:   "rule-delete <rotator_id> <rule_id>",
	Short: "Delete a routing rule from a redirector/rotator",
	Args:  deleteArgsValidatorN(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		rotatorID, err := validateID(args[0])
		if err != nil {
			return err
		}
		return runBulkOrSingleDelete(cmd, args[1:], deleteSpec{
			endpoint:    "rotators/" + rotatorID + "/rules",
			noun:        "rule",
			plural:      "rules",
			context:     " from rotator " + rotatorID,
			idsHintText: "Comma-separate rule ids, e.g. --ids 3,4 (find them with `p202 rotator get <rotator_id>`).",
		})
	},
}

var rotatorRuleUpdateCmd = &cobra.Command{
	Use:   "rule-update <rotator_id> <rule_id>",
	Short: "Update a routing rule on a redirector/rotator",
	// Accept the rule id as a second positional OR via --rule_id, matching the
	// flag-flexible style of rule-delete. RangeArgs(1,2) allows either form
	// (and both together); the positional wins and is resolved in RunE.
	Args: cobra.RangeArgs(1, 2),
	RunE: func(cmd *cobra.Command, args []string) error {
		ruleID := ""
		if len(args) >= 2 {
			ruleID = args[1]
		} else {
			ruleID, _ = cmd.Flags().GetString("rule_id")
		}
		ruleID = strings.TrimSpace(ruleID)
		if ruleID == "" {
			return validationError("rule id is required (pass it as the second argument or via --rule_id)").
				WithHint("`p202 rotator get <rotator_id>` lists its rules and their ids.")
		}

		body := map[string]interface{}{}
		if v, _ := cmd.Flags().GetString("rule_name"); v != "" {
			body["rule_name"] = v
		}
		if v, _ := cmd.Flags().GetString("splittest"); v != "" {
			body["splittest"] = v
		}
		if v, _ := cmd.Flags().GetString("status"); v != "" {
			body["status"] = v
		}
		if v, _ := cmd.Flags().GetString("criteria_json"); v != "" {
			var criteria interface{}
			if err := json.Unmarshal([]byte(v), &criteria); err != nil {
				return validationError("invalid --criteria_json: %s", err.Error()).WithHint("%s", criteriaJSONHint)
			}
			body["criteria"] = criteria
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return validationError("invalid --redirects_json: %s", err.Error()).WithHint("%s", redirectsJSONHint)
			}
			body["redirects"] = redirects
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		data, err := c.Put("rotators/"+args[0]+"/rules/"+ruleID, body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	rotatorListCmd.Flags().StringP("limit", "l", "", "Max results")
	rotatorListCmd.Flags().StringP("offset", "o", "", "Pagination offset")
	rotatorListCmd.Flags().Bool("all", false, "Fetch all rows across pages")

	registerIdempotencyKeyFlag(rotatorCreateCmd)
	registerIdempotencyKeyFlag(rotatorRuleCreateCmd)
	rotatorCreateCmd.Flags().String("name", "", "Rotator name (required)")
	rotatorCreateCmd.Flags().String("default_url", "", "Default destination: an http(s) URL (one of --default_url, --default_campaign, --default_lp)")
	rotatorCreateCmd.Flags().String("default_campaign", "", "Default destination: one of your campaign ids")
	rotatorCreateCmd.Flags().String("default_lp", "", "Default destination: one of your landing page ids")

	rotatorUpdateCmd.Flags().String("name", "", "Rotator name")
	rotatorUpdateCmd.Flags().String("default_url", "", "New default: an http(s) URL; replaces the current default, whatever its kind")
	rotatorUpdateCmd.Flags().String("default_campaign", "", "New default: one of your campaign ids; replaces the current default")
	rotatorUpdateCmd.Flags().String("default_lp", "", "New default: one of your landing page ids; replaces the current default")

	registerDeleteFlags(rotatorDeleteCmd, "rotator")

	rotatorRuleCreateCmd.Flags().String("rule_name", "", "Rule name (required)")
	rotatorRuleCreateCmd.Flags().String("splittest", "", "Enable split test")
	enumFlag(rotatorRuleCreateCmd, "splittest", newEnum(binaryValues))
	rotatorRuleCreateCmd.Flags().String("status", "", "Rule status: 1 active (the default), 0 created paused")
	enumFlag(rotatorRuleCreateCmd, "status", newEnum(binaryValues))
	rotatorRuleCreateCmd.Flags().String("criteria_json", "", `Criteria JSON array, e.g. [{"type":"country","statement":"is","value":"United States(US)"}]`)
	rotatorRuleCreateCmd.Flags().String("redirects_json", "", `Redirects JSON array, e.g. [{"redirect_campaign":"90008","weight":"100","name":"A"}]`)
	rotatorRuleCreateCmd.Flags().String("country", "", "Sugar: ISO country code (e.g. US) -> a country `is` criterion; avoids hand-writing --criteria_json")
	rotatorRuleCreateCmd.Flags().String("redirect-campaign", "", "Sugar: redirect to this campaign id at full weight; avoids hand-writing --redirects_json")

	registerDeleteFlags(rotatorRuleDeleteCmd, "rule")

	rotatorRuleUpdateCmd.Flags().String("rule_id", "", "Rule ID (alternative to the second positional arg)")
	rotatorRuleUpdateCmd.Flags().String("rule_name", "", "Rule name")
	rotatorRuleUpdateCmd.Flags().String("splittest", "", "Enable split test")
	enumFlag(rotatorRuleUpdateCmd, "splittest", newEnum(binaryValues))
	rotatorRuleUpdateCmd.Flags().String("status", "", "Rule status (1 = active)")
	enumFlag(rotatorRuleUpdateCmd, "status", newEnum(binaryValues))
	rotatorRuleUpdateCmd.Flags().String("criteria_json", "", `Criteria JSON array, e.g. [{"type":"country","statement":"is","value":"United States(US)"}]`)
	rotatorRuleUpdateCmd.Flags().String("redirects_json", "", `Redirects JSON array, e.g. [{"redirect_url":"...","weight":"50","name":"A"}]`)

	addReportFilters(rotatorStatsCmd)

	rotatorCmd.AddCommand(rotatorListCmd, rotatorGetCmd, rotatorStatsCmd, rotatorCreateCmd, rotatorUpdateCmd, rotatorDeleteCmd)
	rotatorCmd.AddCommand(rotatorRuleCreateCmd, rotatorRuleDeleteCmd, rotatorRuleUpdateCmd)
	rootCmd.AddCommand(rotatorCmd)
}
