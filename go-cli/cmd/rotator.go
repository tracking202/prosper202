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

var rotatorCmd = &cobra.Command{
	Use:   "rotator",
	Short: "Manage rotators and rules",
}

var rotatorListCmd = &cobra.Command{
	Use:   "list",
	Short: "List rotators",
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
			encoded, _ := json.Marshal(map[string]interface{}{
				"data": rows,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  len(rows),
					"offset": 0,
				},
			})
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
	Short: "Get a rotator with rules",
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

var rotatorCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a rotator",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		name, _ := cmd.Flags().GetString("name")
		if name == "" {
			return fmt.Errorf("required flag --name is missing")
		}
		body := map[string]interface{}{"name": name}
		for _, f := range []string{"default_url", "default_campaign", "default_lp"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		data, err := c.Post("rotators", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a rotator",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		for _, f := range []string{"name", "default_url", "default_campaign", "default_lp"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if len(body) == 0 {
			return fmt.Errorf("no fields specified; pass at least one flag to update")
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
	Short: "Delete a rotator and all its rules",
	Args: func(cmd *cobra.Command, args []string) error {
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			return cobra.MaximumNArgs(0)(cmd, args)
		}
		return cobra.ExactArgs(1)(cmd, args)
	},
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			idList, parseErr := parseIDList(idsFlag)
			if parseErr != nil {
				return parseErr
			}
			if len(idList) == 0 {
				return fmt.Errorf("--ids requires at least one ID")
			}

			force, _ := cmd.Flags().GetBool("force")
			if !force {
				fmt.Printf("Delete %d rotators and all their rules? [y/N] ", len(idList))
				var answer string
				fmt.Scanln(&answer)
				answer = strings.ToLower(strings.TrimSpace(answer))
				if answer != "y" && answer != "yes" {
					fmt.Println("Cancelled.")
					return nil
				}
			}

			deleted := 0
			failed := 0
			for _, id := range idList {
				if err := c.Delete("rotators/" + id); err != nil {
					failed++
					fmt.Fprintf(os.Stderr, "Failed to delete rotator %s: %v\n", id, err)
					continue
				}
				deleted++
			}
			output.Success("Deleted %d of %d rotators.", deleted, len(idList))
			if failed > 0 {
				return partialFailureError("failed to delete %d rotators", failed)
			}
			return nil
		}

		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete rotator %s and all its rules? [y/N] ", args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("rotators/" + args[0]); err != nil {
			return err
		}
		output.Success("Rotator %s deleted.", args[0])
		return nil
	},
}

var rotatorRuleCreateCmd = &cobra.Command{
	Use:   "rule-create <rotator_id>",
	Short: "Create a rule for a rotator",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		ruleName, _ := cmd.Flags().GetString("rule_name")
		if ruleName == "" {
			return fmt.Errorf("required flag --rule_name is missing")
		}
		body := map[string]interface{}{
			"rule_name": ruleName,
		}
		if v, _ := cmd.Flags().GetString("splittest"); v != "" {
			body["splittest"] = v
		}
		if v, _ := cmd.Flags().GetString("criteria_json"); v != "" {
			var criteria interface{}
			if err := json.Unmarshal([]byte(v), &criteria); err != nil {
				return fmt.Errorf("invalid --criteria_json: %w", err)
			}
			body["criteria"] = criteria
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return fmt.Errorf("invalid --redirects_json: %w", err)
			}
			body["redirects"] = redirects
		}
		data, err := c.Post("rotators/"+args[0]+"/rules", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var rotatorRuleDeleteCmd = &cobra.Command{
	Use:   "rule-delete <rotator_id> <rule_id>",
	Short: "Delete a rotator rule",
	Args: func(cmd *cobra.Command, args []string) error {
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			return cobra.ExactArgs(1)(cmd, args)
		}
		return cobra.ExactArgs(2)(cmd, args)
	},
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idsFlag, _ := cmd.Flags().GetString("ids")
		if strings.TrimSpace(idsFlag) != "" {
			idList, parseErr := parseIDList(idsFlag)
			if parseErr != nil {
				return parseErr
			}
			if len(idList) == 0 {
				return fmt.Errorf("--ids requires at least one rule ID")
			}
			rotatorID := args[0]
			force, _ := cmd.Flags().GetBool("force")
			if !force {
				fmt.Printf("Delete %d rules from rotator %s? [y/N] ", len(idList), rotatorID)
				var answer string
				fmt.Scanln(&answer)
				answer = strings.ToLower(strings.TrimSpace(answer))
				if answer != "y" && answer != "yes" {
					fmt.Println("Cancelled.")
					return nil
				}
			}

			deleted := 0
			failed := 0
			for _, ruleID := range idList {
				if err := c.Delete("rotators/" + rotatorID + "/rules/" + ruleID); err != nil {
					failed++
					fmt.Fprintf(os.Stderr, "Failed to delete rule %s from rotator %s: %v\n", ruleID, rotatorID, err)
					continue
				}
				deleted++
			}
			output.Success("Deleted %d of %d rules from rotator %s.", deleted, len(idList), rotatorID)
			if failed > 0 {
				return partialFailureError("failed to delete %d rules", failed)
			}
			return nil
		}

		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete rule %s from rotator %s? [y/N] ", args[1], args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("rotators/" + args[0] + "/rules/" + args[1]); err != nil {
			return err
		}
		output.Success("Rule %s deleted from rotator %s.", args[1], args[0])
		return nil
	},
}

var rotatorRuleUpdateCmd = &cobra.Command{
	Use:   "rule-update <rotator_id> <rule_id>",
	Short: "Update a rotator rule",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
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
				return fmt.Errorf("invalid --criteria_json: %w", err)
			}
			body["criteria"] = criteria
		}
		if v, _ := cmd.Flags().GetString("redirects_json"); v != "" {
			var redirects interface{}
			if err := json.Unmarshal([]byte(v), &redirects); err != nil {
				return fmt.Errorf("invalid --redirects_json: %w", err)
			}
			body["redirects"] = redirects
		}
		if len(body) == 0 {
			return fmt.Errorf("no fields specified; pass at least one flag to update")
		}

		data, err := c.Put("rotators/"+args[0]+"/rules/"+args[1], body)
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

	rotatorCreateCmd.Flags().String("name", "", "Rotator name (required)")
	rotatorCreateCmd.Flags().String("default_url", "", "Default redirect URL")
	rotatorCreateCmd.Flags().String("default_campaign", "", "Default campaign ID")
	rotatorCreateCmd.Flags().String("default_lp", "", "Default landing page ID")

	rotatorUpdateCmd.Flags().String("name", "", "Rotator name")
	rotatorUpdateCmd.Flags().String("default_url", "", "Default redirect URL")
	rotatorUpdateCmd.Flags().String("default_campaign", "", "Default campaign ID")
	rotatorUpdateCmd.Flags().String("default_lp", "", "Default landing page ID")

	rotatorDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	rotatorDeleteCmd.Flags().String("ids", "", "Comma-separated rotator IDs to delete in bulk")

	rotatorRuleCreateCmd.Flags().String("rule_name", "", "Rule name (required)")
	rotatorRuleCreateCmd.Flags().String("splittest", "", "Enable split test (0|1)")
	rotatorRuleCreateCmd.Flags().String("criteria_json", "", `Criteria JSON array, e.g. [{"type":"country","statement":"is","value":"US"}]`)
	rotatorRuleCreateCmd.Flags().String("redirects_json", "", `Redirects JSON array, e.g. [{"redirect_url":"...","weight":"50","name":"A"}]`)

	rotatorRuleDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	rotatorRuleDeleteCmd.Flags().String("ids", "", "Comma-separated rule IDs to delete in bulk")

	rotatorRuleUpdateCmd.Flags().String("rule_name", "", "Rule name")
	rotatorRuleUpdateCmd.Flags().String("splittest", "", "Enable split test (0|1)")
	rotatorRuleUpdateCmd.Flags().String("status", "", "Rule status (0|1)")
	rotatorRuleUpdateCmd.Flags().String("criteria_json", "", `Criteria JSON array, e.g. [{"type":"country","statement":"is","value":"US"}]`)
	rotatorRuleUpdateCmd.Flags().String("redirects_json", "", `Redirects JSON array, e.g. [{"redirect_url":"...","weight":"50","name":"A"}]`)

	rotatorCmd.AddCommand(rotatorListCmd, rotatorGetCmd, rotatorCreateCmd, rotatorUpdateCmd, rotatorDeleteCmd)
	rotatorCmd.AddCommand(rotatorRuleCreateCmd, rotatorRuleDeleteCmd, rotatorRuleUpdateCmd)
	rootCmd.AddCommand(rotatorCmd)
}
