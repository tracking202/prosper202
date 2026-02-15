package cmd

import (
	"fmt"
	"os"
	"strings"

	"p202/internal/api"
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		roleID, _ := cmd.Flags().GetString("role_id")
		if roleID == "" {
			return fmt.Errorf("required flag --role_id is missing")
		}
		data, err := c.Post("users/"+args[0]+"/roles", map[string]interface{}{
			"role_id": roleID,
		})
		if err != nil {
			return err
		}
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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
		output.Render(data, jsonOutput)
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

	// Preferences flags
	userPrefsUpdateCmd.Flags().String("user_tracking_domain", "", "Tracking domain")
	userPrefsUpdateCmd.Flags().String("user_account_currency", "", "Currency (3-letter code)")
	userPrefsUpdateCmd.Flags().String("user_slack_incoming_webhook", "", "Slack webhook URL")
	userPrefsUpdateCmd.Flags().String("user_daily_email", "", "Daily email: on/off")
	userPrefsUpdateCmd.Flags().String("ipqs_api_key", "", "IPQS fraud detection API key")

	// Wire up subcommands
	userRoleCmd.AddCommand(userRoleListCmd, userRoleAssignCmd, userRoleRemoveCmd)
	userAPIKeyCmd.AddCommand(userAPIKeyListCmd, userAPIKeyCreateCmd, userAPIKeyDeleteCmd)
	userPrefsCmd.AddCommand(userPrefsGetCmd, userPrefsUpdateCmd)

	userCmd.AddCommand(userListCmd, userGetCmd, userCreateCmd, userUpdateCmd, userDeleteCmd)
	userCmd.AddCommand(userRoleCmd, userAPIKeyCmd, userPrefsCmd)
	rootCmd.AddCommand(userCmd)
}
