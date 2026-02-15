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
}

// --- Core CRUD ---

var userListCmd = &cobra.Command{
	Use:   "list",
	Short: "List all users",
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
	Use:   "get <id>",
	Short: "Get a user with roles",
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
	Use:   "delete <id>",
	Short: "Delete a user (soft-delete)",
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
}

var userRoleListCmd = &cobra.Command{
	Use:   "list",
	Short: "List all available roles",
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
	Use:   "assign <user_id>",
	Short: "Assign a role to a user",
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
	Use:   "remove <user_id> <role_id>",
	Short: "Remove a role from a user",
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
}

var userAPIKeyListCmd = &cobra.Command{
	Use:   "list <user_id>",
	Short: "List API keys for a user",
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
	Use:   "create <user_id>",
	Short: "Create an API key for a user",
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
	Use:   "delete <user_id> <api_key>",
	Short: "Delete an API key",
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
}

var userPrefsGetCmd = &cobra.Command{
	Use:   "get <user_id>",
	Short: "Get user preferences",
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
}
