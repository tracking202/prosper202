package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	configpkg "p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
	"golang.org/x/term"
)

var userCmd = &cobra.Command{
	Use:   "user",
	Short: "Manage users, roles, API keys, and preferences",
	Long: "Manage Prosper202 users, roles, API keys, and preferences.\n\n" +
		"Subcommands: list, get, create, update, delete, role, apikey, prefs.\n" +
		"Passwords are prompted securely when not passed via flag.",
}

// --- Core CRUD ---

var userListCmd = &cobra.Command{
	Use:     "list",
	Short:   "List all users",
	Long:    "List all users with their IDs, names, emails, and active status.",
	Example: "  p202 user list --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userGetCmd = &cobra.Command{
	Use:     "get <id>",
	Short:   "Get a user with roles",
	Long:    "Retrieve a user by ID including their assigned roles.",
	Example: "  p202 user get 1 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a new user",
	Long: "Create a new user. Required: --user_name and --user_email.\n\n" +
		"Password is prompted securely if not passed via --user_pass.",
	Example: "  p202 user create --user_name admin --user_email admin@example.com --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		name, _ := cmd.Flags().GetString("user_name")
		email, _ := cmd.Flags().GetString("user_email")
		if name == "" {
			return fmt.Errorf("required flag --user_name is missing")
		}
		if email == "" {
			return fmt.Errorf("required flag --user_email is missing")
		}
		body := map[string]interface{}{
			"user_name":  name,
			"user_email": email,
		}
		// Secure password input
		pass, _ := cmd.Flags().GetString("user_pass")
		if pass == "" {
			fmt.Fprint(os.Stderr, "Password (hidden): ")
			passBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
			fmt.Fprintln(os.Stderr)
			if err != nil {
				return fmt.Errorf("reading password: %w", err)
			}
			pass = string(passBytes)
		}
		if pass == "" {
			return fmt.Errorf("password is required")
		}
		body["user_pass"] = pass

		for _, f := range []string{"user_fname", "user_lname", "user_timezone"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		data, err := c.Post("users", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a user",
	Long: "Update a user by ID. Pass only the fields you want to change.\n\n" +
		"If --user_pass is passed without a value, the password is prompted securely.",
	Example: "  p202 user update 1 --user_email newemail@example.com --json\n" +
		"  p202 user update 1 --user_active 0 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		for _, f := range []string{"user_fname", "user_lname", "user_email", "user_timezone", "user_active"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		// Secure password: if --user_pass flag is present, prompt
		if cmd.Flags().Changed("user_pass") {
			pass, _ := cmd.Flags().GetString("user_pass")
			if pass == "" {
				fmt.Fprint(os.Stderr, "New password (hidden): ")
				passBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
				fmt.Fprintln(os.Stderr)
				if err != nil {
					return fmt.Errorf("reading password: %w", err)
				}
				pass = string(passBytes)
			}
			if pass != "" {
				body["user_pass"] = pass
			}
		}
		if len(body) == 0 {
			return fmt.Errorf("no fields specified; pass at least one flag to update")
		}
		data, err := c.Put("users/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userDeleteCmd = &cobra.Command{
	Use:     "delete <id>",
	Short:   "Delete a user (soft-delete)",
	Long:    "Soft-delete a user by ID. Prompts for confirmation unless --force is passed.",
	Example: "  p202 user delete 1 --force",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete user %s? [y/N] ", args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("users/" + args[0]); err != nil {
			return err
		}
		output.Success("User %s deleted.", args[0])
		return nil
	},
}

// --- Role subcommands ---

var userRoleCmd = &cobra.Command{
	Use:   "role",
	Short: "Manage user roles",
	Long:  "Manage user role assignments. Subcommands: list, assign, remove.",
}

var userRoleListCmd = &cobra.Command{
	Use:     "list",
	Short:   "List all available roles",
	Long:    "List all available roles with their IDs and names.",
	Example: "  p202 user role list --json",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/roles", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userRoleAssignCmd = &cobra.Command{
	Use:     "assign <user_id>",
	Short:   "Assign a role to a user",
	Long:    "Assign a role to a user by user ID and role ID.",
	Example: "  p202 user role assign 1 --role_id 2 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		roleIDStr, _ := cmd.Flags().GetString("role_id")
		if roleIDStr == "" {
			return fmt.Errorf("required flag --role_id is missing")
		}
		roleID, err := strconv.Atoi(roleIDStr)
		if err != nil {
			return fmt.Errorf("--role_id must be an integer: %s", roleIDStr)
		}
		data, err := c.Post("users/"+args[0]+"/roles", map[string]interface{}{
			"role_id": roleID,
		})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userRoleRemoveCmd = &cobra.Command{
	Use:     "remove <user_id> <role_id>",
	Short:   "Remove a role from a user",
	Long:    "Remove a role from a user. Prompts for confirmation unless --force is passed.",
	Example: "  p202 user role remove 1 2 --force",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Remove role %s from user %s? [y/N] ", args[1], args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("users/" + args[0] + "/roles/" + args[1]); err != nil {
			return err
		}
		output.Success("Role %s removed from user %s.", args[1], args[0])
		return nil
	},
}

// --- API Key subcommands ---

var userAPIKeyCmd = &cobra.Command{
	Use:   "apikey",
	Short: "Manage user API keys",
	Long:  "Manage user API keys. Subcommands: list, create, delete, rotate.",
}

var userAPIKeyListCmd = &cobra.Command{
	Use:     "list <user_id>",
	Short:   "List API keys for a user",
	Long:    "List all API keys for a user by their user ID.",
	Example: "  p202 user apikey list 1 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/"+args[0]+"/api-keys", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userAPIKeyCreateCmd = &cobra.Command{
	Use:     "create <user_id>",
	Short:   "Create an API key for a user",
	Long:    "Generate a new API key for a user. The key is returned in the response.",
	Example: "  p202 user apikey create 1 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("users/"+args[0]+"/api-keys", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userAPIKeyDeleteCmd = &cobra.Command{
	Use:     "delete <user_id> <api_key>",
	Short:   "Delete an API key",
	Long:    "Delete a specific API key for a user. Prompts for confirmation unless --force is passed.",
	Example: "  p202 user apikey delete 1 abc123 --force",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Delete API key for user %s? [y/N] ", args[0])
			var answer string
			fmt.Scanln(&answer)
			if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}
		if err := c.Delete("users/" + args[0] + "/api-keys/" + args[1]); err != nil {
			return err
		}
		output.Success("API key deleted for user %s.", args[0])
		return nil
	},
}

var userAPIKeyRotateCmd = &cobra.Command{
	Use:   "rotate <user_id> <old_api_key>",
	Short: "Rotate an API key by creating a new one and optionally deleting the old one",
	Long: "Create a new API key, optionally delete the old one, and optionally update the local CLI config.\n\n" +
		"Use --keep-old to keep both keys active. Use --update-config to update ~/.p202/config.json with the new key.",
	Example: "  p202 user apikey rotate 1 OLD_KEY --force --json\n" +
		"  p202 user apikey rotate 1 OLD_KEY --force --update-config --json",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		userID := args[0]
		oldAPIKey := args[1]
		keepOld, _ := cmd.Flags().GetBool("keep-old")
		force, _ := cmd.Flags().GetBool("force")
		updateConfig, _ := cmd.Flags().GetBool("update-config")
		forceConfigUpdate, _ := cmd.Flags().GetBool("force-config-update")

		createdData, err := c.Post("users/"+userID+"/api-keys", nil)
		if err != nil {
			return err
		}
		createdObj, err := parseDataObject(createdData)
		if err != nil {
			return fmt.Errorf("failed to parse create api-key response: %w", err)
		}
		newAPIKey, err := extractAPIKey(createdObj)
		if err != nil {
			return err
		}

		deletedOld := false
		if !keepOld {
			if !force {
				fmt.Printf("Delete old API key for user %s? [y/N] ", userID)
				var answer string
				fmt.Scanln(&answer)
				if strings.ToLower(answer) != "y" && strings.ToLower(answer) != "yes" {
					fmt.Println("Skipping old key deletion.")
				} else {
					if err := c.Delete("users/" + userID + "/api-keys/" + oldAPIKey); err != nil {
						return err
					}
					deletedOld = true
				}
			} else {
				if err := c.Delete("users/" + userID + "/api-keys/" + oldAPIKey); err != nil {
					return err
				}
				deletedOld = true
			}
		}

		configUpdated := false
		configUpdateSkipped := false
		if updateConfig {
			cfg, err := configpkg.Load()
			if err != nil {
				return err
			}
			if cfg.APIKey == oldAPIKey || forceConfigUpdate {
				cfg.APIKey = newAPIKey
				if err := cfg.Save(); err != nil {
					return err
				}
				configUpdated = true
			} else {
				configUpdateSkipped = true
			}
		}

		out := map[string]interface{}{
			"user_id":               userID,
			"new_api_key":           newAPIKey,
			"old_key_deleted":       deletedOld,
			"old_key_kept":          keepOld || !deletedOld,
			"config_updated":        configUpdated,
			"config_update_skipped": configUpdateSkipped,
		}
		encoded, _ := json.Marshal(out)
		render(encoded)
		return nil
	},
}

func extractAPIKey(obj map[string]interface{}) (string, error) {
	for _, key := range []string{"api_key", "key", "token"} {
		if value, ok := obj[key]; ok && value != nil {
			if str, ok := value.(string); ok && str != "" {
				return str, nil
			}
		}
	}
	return "", fmt.Errorf("create api-key response did not include api_key")
}

// --- Preferences subcommands ---

var userPrefsCmd = &cobra.Command{
	Use:   "prefs",
	Short: "Manage user preferences",
	Long:  "Manage user preferences. Subcommands: get, update.",
}

var userPrefsGetCmd = &cobra.Command{
	Use:     "get <user_id>",
	Short:   "Get user preferences",
	Long:    "Get preferences for a user including tracking domain, currency, and notification settings.",
	Example: "  p202 user prefs get 1 --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/"+args[0]+"/preferences", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userPrefsUpdateCmd = &cobra.Command{
	Use:   "update <user_id>",
	Short: "Update user preferences",
	Long: "Update preferences for a user. Pass only the fields you want to change.\n\n" +
		"Available: --user_tracking_domain, --user_account_currency, --user_slack_incoming_webhook,\n" +
		"--user_daily_email, --ipqs_api_key.",
	Example: "  p202 user prefs update 1 --user_account_currency USD --json\n" +
		"  p202 user prefs update 1 --user_slack_incoming_webhook https://hooks.slack.com/... --json",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		for _, f := range []string{
			"user_tracking_domain", "user_account_currency",
			"user_slack_incoming_webhook", "user_daily_email", "ipqs_api_key",
		} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		if len(body) == 0 {
			return fmt.Errorf("no preferences specified; pass at least one flag to update")
		}
		data, err := c.Put("users/"+args[0]+"/preferences", body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

func init() {
	// User CRUD flags
	userCreateCmd.Flags().String("user_name", "", "Username (required)")
	userCreateCmd.Flags().String("user_email", "", "Email (required)")
	userCreateCmd.Flags().String("user_pass", "", "Password (prompted securely if omitted)")
	userCreateCmd.Flags().String("user_fname", "", "First name")
	userCreateCmd.Flags().String("user_lname", "", "Last name")
	userCreateCmd.Flags().String("user_timezone", "", "Timezone (default: UTC)")

	userUpdateCmd.Flags().String("user_fname", "", "First name")
	userUpdateCmd.Flags().String("user_lname", "", "Last name")
	userUpdateCmd.Flags().String("user_email", "", "Email")
	userUpdateCmd.Flags().String("user_pass", "", "New password (prompted securely if flag given without value)")
	userUpdateCmd.Flags().String("user_timezone", "", "Timezone")
	userUpdateCmd.Flags().String("user_active", "", "1=active, 0=inactive")

	userDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	// Role flags
	userRoleAssignCmd.Flags().String("role_id", "", "Role ID (required)")
	userRoleRemoveCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")

	// API key flags
	userAPIKeyDeleteCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	userAPIKeyRotateCmd.Flags().Bool("keep-old", false, "Do not delete the old API key")
	userAPIKeyRotateCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt when deleting old key")
	userAPIKeyRotateCmd.Flags().Bool("update-config", false, "Update local ~/.p202/config.json with the new API key")
	userAPIKeyRotateCmd.Flags().Bool("force-config-update", false, "Update local config even if current key does not match old key")

	// Preferences flags
	userPrefsUpdateCmd.Flags().String("user_tracking_domain", "", "Tracking domain")
	userPrefsUpdateCmd.Flags().String("user_account_currency", "", "Currency (3-letter code)")
	userPrefsUpdateCmd.Flags().String("user_slack_incoming_webhook", "", "Slack webhook URL")
	userPrefsUpdateCmd.Flags().String("user_daily_email", "", "Daily email: on/off")
	userPrefsUpdateCmd.Flags().String("ipqs_api_key", "", "IPQS fraud detection API key")

	// Wire up subcommands
	userRoleCmd.AddCommand(userRoleListCmd, userRoleAssignCmd, userRoleRemoveCmd)
	userAPIKeyCmd.AddCommand(userAPIKeyListCmd, userAPIKeyCreateCmd, userAPIKeyDeleteCmd, userAPIKeyRotateCmd)
	userPrefsCmd.AddCommand(userPrefsGetCmd, userPrefsUpdateCmd)

	userCmd.AddCommand(userListCmd, userGetCmd, userCreateCmd, userUpdateCmd, userDeleteCmd)
	userCmd.AddCommand(userRoleCmd, userAPIKeyCmd, userPrefsCmd)
	rootCmd.AddCommand(userCmd)

	userFields := []string{"user_id", "user_name", "user_email", "user_fname", "user_lname", "user_timezone", "user_active"}
	registerMeta("user list", commandMeta{Examples: []string{"p202 user list --json"}, OutputFields: userFields, Related: []string{"user get", "user create"}})
	registerMeta("user get", commandMeta{Examples: []string{"p202 user get 1 --json"}, OutputFields: userFields, Related: []string{"user list", "user update", "user role list"}})
	registerMeta("user create", commandMeta{Examples: []string{"p202 user create --user_name admin --user_email admin@example.com --json"}, OutputFields: userFields, Related: []string{"user list", "user role assign"}})
	registerMeta("user update", commandMeta{Examples: []string{"p202 user update 1 --user_email new@example.com --json"}, OutputFields: userFields, Related: []string{"user get"}})
	registerMeta("user delete", commandMeta{Examples: []string{"p202 user delete 1 --force"}, Related: []string{"user list"}})
	registerMeta("user role list", commandMeta{Examples: []string{"p202 user role list --json"}, OutputFields: []string{"role_id", "role_name"}, Related: []string{"user role assign"}})
	registerMeta("user role assign", commandMeta{Examples: []string{"p202 user role assign 1 --role_id 2 --json"}, Related: []string{"user role list", "user role remove"}})
	registerMeta("user role remove", commandMeta{Examples: []string{"p202 user role remove 1 2 --force"}, Related: []string{"user role list", "user role assign"}})
	registerMeta("user apikey list", commandMeta{Examples: []string{"p202 user apikey list 1 --json"}, OutputFields: []string{"api_key", "created_at"}, Related: []string{"user apikey create"}})
	registerMeta("user apikey create", commandMeta{Examples: []string{"p202 user apikey create 1 --json"}, OutputFields: []string{"api_key"}, Related: []string{"user apikey list", "user apikey rotate"}})
	registerMeta("user apikey delete", commandMeta{Examples: []string{"p202 user apikey delete 1 abc123 --force"}, Related: []string{"user apikey list"}})
	registerMeta("user apikey rotate", commandMeta{Examples: []string{"p202 user apikey rotate 1 OLD_KEY --force --update-config --json"}, OutputFields: []string{"new_api_key", "old_key_deleted", "config_updated"}, Related: []string{"user apikey list", "user apikey create"}})
	registerMeta("user prefs get", commandMeta{Examples: []string{"p202 user prefs get 1 --json"}, OutputFields: []string{"user_tracking_domain", "user_account_currency", "user_slack_incoming_webhook", "user_daily_email", "ipqs_api_key"}, Related: []string{"user prefs update"}})
	registerMeta("user prefs update", commandMeta{Examples: []string{"p202 user prefs update 1 --user_account_currency USD --json"}, OutputFields: []string{"user_tracking_domain", "user_account_currency"}, Related: []string{"user prefs get"}})
}
