package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

var helpCmd = &cobra.Command{
	Use:   "help",
	Short: "Help and documentation commands",
}

var agentSchemaCmd = &cobra.Command{
	Use:   "agent-schema",
	Short: "Output a machine-readable JSON manifest of all commands, flags, examples, and workflows",
	Long: `Output a complete JSON manifest describing every command in the CLI.

Designed for AI agents and automation tools that need to discover all available
commands, understand their flags and arguments, and construct correct invocations
without trial-and-error.

The manifest includes:
  - Every command with its flags, types, defaults, and descriptions
  - Usage examples for each command
  - Output field hints so agents know what to parse
  - Related commands for workflow chaining
  - Pre-built multi-step workflows (e.g. setting up tracking from scratch)

Pipe through jq or python3 -m json.tool for pretty-printing.`,
	Example: `  p202 help agent-schema
  p202 help agent-schema 2>/dev/null | jq '.commands["campaign list"]'
  p202 help agent-schema 2>/dev/null | jq '.workflows'`,
	RunE: func(cmd *cobra.Command, args []string) error {
		schema := buildAgentSchema()
		data, err := json.MarshalIndent(schema, "", "  ")
		if err != nil {
			return fmt.Errorf("failed to marshal schema: %w", err)
		}
		fmt.Println(string(data))
		return nil
	},
}

func buildAgentSchema() map[string]interface{} {
	schema := map[string]interface{}{
		"application":  rootCmd.Name(),
		"version":      rootCmd.Version,
		"description":  "Prosper202 CLI — manage affiliate tracking campaigns, traffic sources, reports, and multi-instance sync.",
		"global_flags": extractGlobalFlags(),
		"commands":     extractAllCommands(rootCmd, ""),
		"workflows":    buildWorkflows(),
		"exit_codes": map[string]interface{}{
			"0": "Success",
			"1": "Validation error (bad input, missing flags)",
			"2": "Authentication or authorization failure",
			"3": "Network error (connection timeout, DNS failure)",
			"4": "Server error (API returned 5xx)",
			"5": "Partial failure (bulk operation with some failures)",
			"6": "Resource not found (404)",
			"7": "Blocked by --agent-mode restrictions",
		},
		"agent_mode": map[string]interface{}{
			"description": "Pass --agent-mode for AI agent use. It enables: JSON output by default, secret redaction, audit logging, and non-interactive mode (requires --force for destructive operations).",
			"blocked_commands": []string{
				"config set-url",
				"config set-key",
			},
			"safety_features": []string{
				"Secrets redacted in all output",
				"Mutations logged to ~/.p202/agent_audit.log",
				"Confirmation prompts require --force (no interactive stdin)",
				"JSON output enabled by default",
			},
		},
	}
	return schema
}

func extractGlobalFlags() map[string]interface{} {
	flags := map[string]interface{}{}
	rootCmd.PersistentFlags().VisitAll(func(f *pflag.Flag) {
		if f.Hidden {
			return
		}
		entry := map[string]interface{}{
			"type":        f.Value.Type(),
			"default":     f.DefValue,
			"description": f.Usage,
		}
		if f.Shorthand != "" {
			entry["shorthand"] = f.Shorthand
		}
		flags[f.Name] = entry
	})
	return flags
}

func extractAllCommands(parent *cobra.Command, prefix string) map[string]interface{} {
	commands := map[string]interface{}{}

	for _, cmd := range parent.Commands() {
		if cmd.Hidden || cmd.Name() == "help" || cmd.Name() == "completion" {
			continue
		}

		path := cmd.Name()
		if prefix != "" {
			path = prefix + " " + cmd.Name()
		}

		// If this command has subcommands, recurse into them
		if cmd.HasSubCommands() {
			subCmds := extractAllCommands(cmd, path)
			for k, v := range subCmds {
				commands[k] = v
			}

			// Also include the parent if it has its own RunE (e.g. "analytics")
			if cmd.RunE == nil && cmd.Run == nil {
				continue
			}
		}

		entry := extractCommand(cmd, path)
		commands[path] = entry
	}

	return commands
}

func extractCommand(cmd *cobra.Command, path string) map[string]interface{} {
	entry := map[string]interface{}{
		"full_command": "p202 " + path,
		"description":  cmd.Short,
	}

	if cmd.Long != "" {
		entry["long_description"] = cmd.Long
	}

	// Arguments
	args := extractArguments(cmd)
	if len(args) > 0 {
		entry["arguments"] = args
	}

	// Flags (local only, not inherited)
	flags := extractFlags(cmd)
	if len(flags) > 0 {
		entry["flags"] = flags
	}

	// Aliases
	if len(cmd.Aliases) > 0 {
		entry["aliases"] = cmd.Aliases
	}

	// Agent metadata (examples, output_fields, related, allowed_values, mutating)
	if meta, ok := agentMeta[path]; ok {
		if len(meta.Examples) > 0 {
			entry["examples"] = meta.Examples
		}
		if len(meta.OutputFields) > 0 {
			entry["output_fields"] = meta.OutputFields
		}
		if len(meta.Related) > 0 {
			entry["related"] = meta.Related
		}
		if len(meta.AllowedValues) > 0 {
			entry["allowed_values"] = meta.AllowedValues
		}
		if meta.Mutating {
			entry["mutating"] = true
		}
	}

	// Mark user_content_fields for the entity if applicable
	cmdParts := strings.Fields(path)
	if len(cmdParts) > 0 {
		if fields, ok := userContentFieldsByEntity[cmdParts[0]]; ok {
			entry["user_content_fields"] = fields
		}
	}

	return entry
}

func extractArguments(cmd *cobra.Command) []map[string]interface{} {
	// Parse arguments from the Use string, e.g. "get <id>" -> [{name: "id", required: true}]
	use := cmd.Use
	parts := strings.Fields(use)
	args := []map[string]interface{}{}

	for _, part := range parts[1:] { // skip the command name itself
		if strings.HasPrefix(part, "<") && strings.HasSuffix(part, ">") {
			name := strings.TrimPrefix(strings.TrimSuffix(part, ">"), "<")
			args = append(args, map[string]interface{}{
				"name":     name,
				"required": true,
			})
		} else if strings.HasPrefix(part, "[") && strings.HasSuffix(part, "]") {
			name := strings.TrimPrefix(strings.TrimSuffix(part, "]"), "[")
			args = append(args, map[string]interface{}{
				"name":     name,
				"required": false,
			})
		}
	}

	return args
}

func extractFlags(cmd *cobra.Command) map[string]interface{} {
	flags := map[string]interface{}{}

	cmd.LocalFlags().VisitAll(func(f *pflag.Flag) {
		if f.Hidden {
			return
		}
		// Skip global flags that are inherited
		globalFlags := map[string]bool{
			"json": true, "csv": true, "profile": true, "group": true,
			"agent-mode": true, "fields": true, "max-field-length": true,
			"id-only": true, "dry-run": true,
		}
		if globalFlags[f.Name] {
			return
		}
		entry := map[string]interface{}{
			"type":        f.Value.Type(),
			"default":     f.DefValue,
			"description": f.Usage,
		}
		if f.Shorthand != "" {
			entry["shorthand"] = f.Shorthand
		}

		// Detect required flags
		if ann, ok := cmd.Annotations["cobra_annotation_bash_completion_one_required_flag"]; ok {
			if strings.Contains(ann, f.Name) {
				entry["required"] = true
			}
		}

		flags[f.Name] = entry
	})

	return flags
}

func buildWorkflows() map[string]interface{} {
	return map[string]interface{}{
		"initial_setup": map[string]interface{}{
			"description": "Configure the CLI to connect to a Prosper202 instance",
			"steps": []string{
				"p202 config set-url https://your-prosper202-instance.com",
				"p202 config set-key YOUR_API_KEY",
				"p202 config test",
			},
		},
		"create_tracking_link": map[string]interface{}{
			"description": "Set up a complete tracking link from network to URL",
			"steps": []string{
				"p202 aff-network create --aff_network_name 'My Network'",
				"p202 campaign create --aff_campaign_name 'Offer 1' --aff_campaign_url 'https://offer.example.com' --aff_network_id {aff_network_id from step 1}",
				"p202 ppc-network create --ppc_network_name 'Google Ads'",
				"p202 ppc-account create --ppc_account_name 'Main Account' --ppc_network_id {ppc_network_id from step 3}",
				"p202 tracker create-with-url --aff_campaign_id {aff_campaign_id from step 2} --ppc_account_id {ppc_account_id from step 4}",
			},
		},
		"performance_analysis": map[string]interface{}{
			"description": "Analyze campaign performance and identify winners/losers",
			"steps": []string{
				"p202 report summary --period last7 --json",
				"p202 report breakdown --breakdown campaign --period last7 --sort total_net --sort_dir DESC --json",
				"p202 report breakdown --breakdown country --period last7 --aff_campaign_id {best campaign ID} --sort total_net --sort_dir DESC --json",
				"p202 report daypart --period last7 --aff_campaign_id {best campaign ID} --json",
			},
		},
		"multi_instance_sync": map[string]interface{}{
			"description": "Sync entity configuration from one instance to another",
			"steps": []string{
				"p202 config add-profile prod --url https://prod.example.com --key PROD_KEY",
				"p202 config add-profile staging --url https://staging.example.com --key STAGING_KEY",
				"p202 diff all --from prod --to staging --json",
				"p202 sync all --from prod --to staging --dry-run",
				"p202 sync all --from prod --to staging",
			},
		},
		"user_onboarding": map[string]interface{}{
			"description": "Create a user, assign a role, and generate an API key",
			"steps": []string{
				"p202 user role list --json",
				"p202 user create --user_name newuser --user_email user@example.com --json",
				"p202 user role assign {user_id from step 2} --role_id {role_id from step 1}",
				"p202 user apikey create {user_id from step 2} --json",
			},
		},
	}
}

func init() {
	helpCmd.AddCommand(agentSchemaCmd)
	rootCmd.AddCommand(helpCmd)

	// Register metadata for the agent-schema command itself
	registerMeta("help agent-schema", commandMeta{
		Examples: []string{
			"p202 help agent-schema",
			"p202 help agent-schema 2>/dev/null | jq '.commands | keys | length'",
			"p202 help agent-schema 2>/dev/null | jq '.workflows'",
		},
		OutputFields: []string{"application", "version", "global_flags", "commands", "workflows"},
		Related:      []string{},
	})

	// Sort the command keys for consistent output
	_ = sort.SearchStrings
}
