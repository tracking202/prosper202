package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
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
		id, err := validateID(args[0])
		if err != nil {
			return withHint(err, "Pass the redirector's id from `p202 rotator list`.")
		}
		body := map[string]interface{}{}
		for _, f := range []string{"name", "default_url", "default_campaign", "default_lp"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("--name, or one of --default-url, --default-campaign, --default-lp (the default is one of them; setting one clears the others).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("rotators/"+id, body)
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
	// everyVisitorHint says how to write the rule a missing criterion would
	// have made: the redirects read a rule with no criterion as every visitor.
	everyVisitorHint = `To send every visitor to this rule, say so: --criteria-json '[{"type":"country","statement":"is","value":"ALL"}]'.`
)

// ruleListError refuses a rule's criteria or redirects given as an empty
// list. The server refuses a rule with none, as Setup › Redirectors does;
// one from before 1.9.79 stored it, and its redirects read a rule with no
// criterion as every visitor.
func ruleListError(flag string, value interface{}) error {
	list, ok := value.([]interface{})
	if !ok || len(list) > 0 {
		return nil
	}
	if flag == "--criteria-json" {
		return validationError("--criteria-json is an empty list: a rule needs at least one criterion").
			WithHint("%s %s", everyVisitorHint, criteriaJSONHint)
	}
	return validationError("--redirects-json is an empty list: a rule needs at least one destination").
		WithHint("%s", redirectsJSONHint)
}

// rotatorRuleSetHint says what a rule set is, for its refusals.
const rotatorRuleSetHint = "A rule set is every rule the redirector is to have, in the order they are tried: " +
	"a JSON array of rules as `p202 rotator get <id> --json` shows them under data.rules, or {\"rules\": [...]}. " +
	"Keep a rule's id to keep the rule, leave the id out to add one, leave a rule out to remove it; --dry-run shows what would change."

// rotatorMaxRuleSetBytes is the API's body limit.
const rotatorMaxRuleSetBytes = 1 << 20

// readAtMost reads no more than limit bytes of path, so a path naming
// something huge (or endless, like /dev/zero) is refused by the size check
// rather than read into memory first.
func readAtMost(path string, limit int64) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	data, err := io.ReadAll(io.LimitReader(f, limit))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	return data, err
}

var rotatorSetRulesCmd = &cobra.Command{
	Use:   "set-rules <rotator_id>",
	Short: "Replace a redirector's whole rule set, in the order the rules are tried, in one write",
	Long: "Sends every rule the redirector is to have to PUT /rotators/{id}/rules, which writes the set in one\n" +
		"transaction, as Setup › Redirector saves it: all of it, or nothing. The redirects try the rules in the\n" +
		"order given; the first active rule whose criteria all match sends the visitor.\n\n" +
		"Read the set with `p202 rotator get <id> --json` (data.rules), change it, and send it back. A rule sent\n" +
		"with its id keeps the id (its clicks stay its own), one without an id is added, and a rule left out is\n" +
		"removed (the role needs remove_rotator_rule). Each rule needs a rule_name, at least one criterion\n" +
		"(value ALL matches everyone) and at least one destination; a split test (splittest 1) needs a weight on\n" +
		"each destination. --dry-run answers the set as it would be stored and what would change, writing nothing.",
	Example: "  p202 rotator get 3 --json | jq '.data.rules' > rules.json   # edit rules.json, then:\n" +
		"  p202 rotator set-rules 3 --file rules.json --dry-run\n" +
		"  p202 rotator set-rules 3 --file rules.json\n" +
		"  p202 rotator set-rules 3 --rules '[{\"rule_name\":\"Mobile\",\"criteria\":[{\"type\":\"device\",\"value\":\"mobile\"}],\"redirects\":[{\"redirect_campaign\":12}]}]'",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		id, err := validateID(args[0])
		if err != nil {
			return withHint(err, "Pass the redirector's id from `p202 rotator list`.")
		}
		body, err := readRotatorRuleSet(cmd)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		var data []byte
		if dryRun, _ := cmd.Flags().GetBool("dry-run"); dryRun {
			data, err = c.PutDryRun("rotators/"+id+"/rules", body)
		} else {
			data, err = c.Put("rotators/"+id+"/rules", body)
		}
		if err != nil {
			return rotatorRuleSetError(err, id)
		}
		render(data)
		return nil
	},
}

// readRotatorRuleSet returns the request body --rules or --file gives:
// {"rules": [...]}. Anything that is not a list of rules, or an object
// holding one, is refused here, naming the flag.
func readRotatorRuleSet(cmd *cobra.Command) (map[string]interface{}, error) {
	raw, _ := cmd.Flags().GetString("rules")
	file, _ := cmd.Flags().GetString("file")
	source := "--rules"
	switch {
	case raw != "" && file != "":
		return nil, validationError("--rules and --file are mutually exclusive").
			WithHint("Give the set once: inline with --rules, or from a file (- for stdin) with --file.")
	case raw == "" && file == "":
		return nil, validationError("a rule set is required: --rules '<json>' or --file <path> (- for stdin)").
			WithHint("%s", rotatorRuleSetHint)
	case file != "":
		source = "--file"
		var data []byte
		var err error
		if file == "-" {
			if isTerminal(os.Stdin) {
				return nil, validationError("--file - reads the rule set from stdin, and stdin is a terminal").
					WithHint("Pipe the set in (`p202 rotator set-rules <id> --file - < rules.json`), or pass its path.")
			}
			data, err = io.ReadAll(io.LimitReader(os.Stdin, rotatorMaxRuleSetBytes+1))
		} else {
			data, err = readAtMost(file, rotatorMaxRuleSetBytes+1)
		}
		if err != nil {
			return nil, validationError("reading %s: %v", file, err).WithHint("Pass a readable path, or - to read the set from stdin.")
		}
		raw = string(data)
	}
	if len(raw) > rotatorMaxRuleSetBytes {
		return nil, validationError("%s is over the server's 1 MB limit for a request", source).
			WithHint("A redirector's rule set is far smaller; check that %s holds the set and nothing else.", source)
	}
	var value interface{}
	if err := decodeOneJSON([]byte(raw), &value); errors.Is(err, errTrailingJSON) {
		return nil, validationError("%s holds more than one JSON value: %v", source, err).WithHint("%s", rotatorRuleSetHint)
	} else if err != nil {
		return nil, validationError("%s is not JSON: %v", source, err).WithHint("%s", rotatorRuleSetHint)
	}
	var body map[string]interface{}
	prefix := ""
	switch v := value.(type) {
	case []interface{}:
		body, prefix = map[string]interface{}{"rules": v}, "rules"
	case map[string]interface{}:
		if _, ok := v["rules"]; !ok {
			if _, isAnswer := v["data"]; isAnswer {
				return nil, validationError("%s holds an API answer, not a rule set", source).
					WithHint("Send the rules from it: `p202 rotator get <id> --json | jq '.data.rules'` is the set to edit.")
			}
			return nil, validationError("%s is an object without \"rules\"", source).WithHint("%s", rotatorRuleSetHint)
		}
		body = v
	default:
		return nil, validationError("%s must be a JSON array of rules, or {\"rules\": [...]}", source).WithHint("%s", rotatorRuleSetHint)
	}
	if dup, err := jsonDuplicateKey([]byte(raw), prefix); err == nil && dup != "" {
		return nil, validationError("%s gives %s twice; only one would be written", source, dup).
			WithHint("Give each key once in each rule, criterion and destination.")
	}
	return body, nil
}

// rotatorRuleSetError adds the next step to a refused rule set.
func rotatorRuleSetError(err error, id string) error {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch {
	case apiErr.Status == 404 && strings.Contains(strings.ToLower(apiErr.Message), "rotator"):
		return withHint(err, "No redirector %s on this account; `p202 rotator list` shows the ids.", id)
	case apiErr.Status == 404:
		// The router's bare 404: a server from before PUT /rotators/{id}/rules.
		return withHint(err, "This server has no PUT /rotators/{id}/rules; upgrade it to 1.9.79 or later, or change the rules one at a time with `p202 rotator rule-create`, `p202 rotator rule-update` and `p202 rotator rule-delete`.")
	case apiErr.Status == 422:
		return withHint(err, "Nothing was written. Fix the fields named (rules.N is the rule at index N of the set) and send the whole set again; `p202 rotator get %s` shows the set as stored, and --dry-run checks a set without writing it.", id)
	}
	return err
}

var rotatorRuleCreateCmd = &cobra.Command{
	Use:   "rule-create <rotator_id>",
	Short: "Add a routing rule to a redirector/rotator (criteria + redirect targets)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		ruleName, _ := cmd.Flags().GetString("rule_name")
		if ruleName == "" {
			return validationError("required flag --rule-name is missing")
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
				return validationError("invalid --criteria-json: %s", err.Error()).WithHint("%s", criteriaJSONHint)
			}
			if err := ruleListError("--criteria-json", criteria); err != nil {
				return err
			}
			body["criteria"] = criteria
		} else if code, _ := cmd.Flags().GetString("country"); code != "" {
			// Sugar: build a country criterion from an ISO code so the caller
			// never has to know the exact "Name(CC)" value string.
			value := countryCriteriaValue(code)
			if value == "" {
				return validationError("unknown country code %q (see `rotator criteria-values --search ...`); or use --criteria-json", code)
			}
			body["criteria"] = []map[string]string{{"type": "country", "statement": "is", "value": value}}
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return validationError("invalid --redirects-json: %s", err.Error()).WithHint("%s", redirectsJSONHint)
			}
			if err := ruleListError("--redirects-json", redirects); err != nil {
				return err
			}
			body["redirects"] = redirects
		} else if camp, _ := cmd.Flags().GetString("redirect-campaign"); camp != "" {
			// Sugar: a single-campaign redirect at full weight.
			body["redirects"] = []map[string]string{{"redirect_campaign": camp, "weight": "100", "name": "to campaign " + camp}}
		}
		// A rule with no criterion matches every visitor: the server refuses
		// one, and one from before 1.9.79 stored it.
		if _, ok := body["criteria"]; !ok {
			return validationError("a rule needs a criterion: pass --criteria-json or --country").
				WithHint("%s %s", everyVisitorHint, criteriaJSONHint)
		}
		if _, ok := body["redirects"]; !ok {
			return validationError("a rule needs a destination: pass --redirects-json or --redirect-campaign").
				WithHint("%s", redirectsJSONHint)
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
	// Accept the rule id as a second positional OR via --rule-id, matching the
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
			return validationError("rule id is required (pass it as the second argument or via --rule-id)").
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
				return validationError("invalid --criteria-json: %s", err.Error()).WithHint("%s", criteriaJSONHint)
			}
			if err := ruleListError("--criteria-json", criteria); err != nil {
				return err
			}
			body["criteria"] = criteria
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return validationError("invalid --redirects-json: %s", err.Error()).WithHint("%s", redirectsJSONHint)
			}
			if err := ruleListError("--redirects-json", redirects); err != nil {
				return err
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
	rotatorCreateCmd.Flags().String("default_url", "", "Default destination: an http(s) URL (one of --default-url, --default-campaign, --default-lp)")
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
	rotatorRuleCreateCmd.Flags().String("country", "", "Sugar: ISO country code (e.g. US) -> a country `is` criterion; avoids hand-writing --criteria-json")
	rotatorRuleCreateCmd.Flags().String("redirect-campaign", "", "Sugar: redirect to this campaign id at full weight; avoids hand-writing --redirects-json")

	registerDeleteFlags(rotatorRuleDeleteCmd, "rule")

	rotatorRuleUpdateCmd.Flags().String("rule_id", "", "Rule ID (alternative to the second positional arg)")
	rotatorRuleUpdateCmd.Flags().String("rule_name", "", "Rule name")
	rotatorRuleUpdateCmd.Flags().String("splittest", "", "Enable split test")
	enumFlag(rotatorRuleUpdateCmd, "splittest", newEnum(binaryValues))
	rotatorRuleUpdateCmd.Flags().String("status", "", "Rule status (1 = active)")
	enumFlag(rotatorRuleUpdateCmd, "status", newEnum(binaryValues))
	rotatorRuleUpdateCmd.Flags().String("criteria_json", "", `Criteria JSON array, e.g. [{"type":"country","statement":"is","value":"United States(US)"}]`)
	rotatorRuleUpdateCmd.Flags().String("redirects_json", "", `The rule's whole redirects JSON array: one sent with its id (`+"`p202 rotator get`"+`) keeps it, one left out is removed, e.g. [{"id":"12","redirect_url":"...","weight":"50","name":"A"}]`)

	addReportFilters(rotatorStatsCmd)

	rotatorSetRulesCmd.Flags().String("rules", "", `The rule set as JSON: an array of rules as `+"`p202 rotator get <id> --json`"+` shows them under data.rules, or {"rules": [...]}`)
	rotatorSetRulesCmd.Flags().StringP("file", "F", "", "Read the rule set JSON from a file (- for stdin)")
	rotatorSetRulesCmd.Flags().Bool("dry-run", false, "Answer the set as it would be stored and what would change, and write nothing")

	rotatorCmd.AddCommand(rotatorListCmd, rotatorGetCmd, rotatorStatsCmd, rotatorCreateCmd, rotatorUpdateCmd, rotatorDeleteCmd)
	rotatorCmd.AddCommand(rotatorRuleCreateCmd, rotatorRuleDeleteCmd, rotatorRuleUpdateCmd, rotatorSetRulesCmd)
	rootCmd.AddCommand(rotatorCmd)
}
