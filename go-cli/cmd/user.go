package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"p202/internal/api"
	configpkg "p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
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
		name, _ := cmd.Flags().GetString("user_name")
		email, _ := cmd.Flags().GetString("user_email")
		if name == "" {
			return validationError("required flag --user-name is missing")
		}
		if email == "" {
			return validationError("required flag --user-email is missing")
		}
		body := map[string]interface{}{
			"user_name":  name,
			"user_email": email,
		}
		// Secure password input: a hidden prompt (asked twice), or one line
		// of piped stdin. --user-pass works too, into shell history.
		pass, _ := cmd.Flags().GetString("user_pass")
		if pass == "" {
			var err error
			if pass, err = readNewPassword("Password (hidden): "); err != nil {
				return err
			}
		}
		if pass == "" {
			return validationError("password is required").WithHint(passwordInputHint)
		}
		body["user_pass"] = pass

		for _, f := range []string{"user_fname", "user_lname", "user_timezone", "user_active"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("users", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update a user: rename, name, email, timezone, active, password",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		for _, f := range []string{"user_name", "user_fname", "user_lname", "user_email", "user_timezone", "user_active"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				body[f] = v
			}
		}
		setPassword, _ := cmd.Flags().GetBool("set-password")
		askCurrent, _ := cmd.Flags().GetBool("current-password")
		if setPassword && cmd.Flags().Changed("user_pass") {
			return validationError("--set-password and --user-pass both set the password; use one").
				WithHint("--set-password reads it without echo (prompt or piped stdin); --user-pass leaves it in shell history.")
		}
		// The current password comes first: a piped caller sends it on the
		// first line and the new one on the second.
		if askCurrent {
			current, err := readSecret("Your current password (hidden): ")
			if err != nil {
				return err
			}
			if current == "" {
				return validationError("--current-password read an empty value").WithHint(passwordInputHint)
			}
			body["current_password"] = current
		}
		if setPassword {
			pass, err := readNewPassword("New password (hidden): ")
			if err != nil {
				return err
			}
			if pass == "" {
				return validationError("--set-password read an empty password; nothing was changed").WithHint(passwordInputHint)
			}
			body["user_pass"] = pass
		} else if pass, _ := cmd.Flags().GetString("user_pass"); pass != "" {
			body["user_pass"] = pass
		}
		if askCurrent && body["user_pass"] == nil {
			return validationError("--current-password is only needed with a new password").
				WithHint("Add --set-password (or --user-pass) to change the password.")
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one flag to update")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("users/"+args[0], body)
		if err != nil && body["user_pass"] != nil && body["current_password"] == nil && needsCurrentPassword(err) {
			// Your own password: the server wants the current one too. At a
			// terminal, ask and try once more; through a pipe, say how.
			if !isTerminal(os.Stdin) {
				return withHint(err, "Changing your own password needs your current password: re-run with --current-password "+
					"and pipe it on the first line of stdin (the new one on the second, with --set-password), or run it at a terminal to be asked.")
			}
			current, rerr := readSecret("Your current password (hidden): ")
			if rerr != nil {
				return rerr
			}
			body["current_password"] = current
			data, err = c.Put("users/"+args[0], body)
		}
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// passwordInputHint is the recovery step for a missing password value.
const passwordInputHint = "Type it at the hidden prompt, or pipe it on stdin (one line); it is never echoed. A --user-pass value works too, but stays in your shell history."

// needsCurrentPassword reports whether err is the server asking for the
// current password (a 422 naming current_password).
func needsCurrentPassword(err error) bool {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 422 {
		return false
	}
	_, ok := apiErr.FieldErrors["current_password"]
	return ok
}

var userDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete a user (soft-delete); supports --ids for bulk",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "users", "user")
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
	Use:   "assign <user_id> <role_id>",
	Short: "Assign a role to a user",
	Args:  cobra.RangeArgs(1, 2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		roleIDStr := roleIDFrom(cmd, args)
		if roleIDStr == "" {
			return validationError("role id is required (pass it as the second argument or via --role-id)").WithHint("`p202 user role list` lists role ids.")
		}
		roleID, err := strconv.Atoi(roleIDStr)
		if err != nil {
			return validationError("role_id must be an integer: %s", roleIDStr).WithHint("`p202 user role list` lists role ids.")
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
	Args:  cobra.RangeArgs(1, 2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		roleID := roleIDFrom(cmd, args)
		if roleID == "" {
			return validationError("role id is required (pass it as the second argument or via --role-id)").WithHint("`p202 user role list` lists role ids.")
		}
		if dryRun, _ := cmd.Flags().GetBool("dry-run"); dryRun {
			return renderDeletePreviews(c, "users/"+args[0]+"/roles", []string{roleID})
		}
		if api.StagedMode() {
			return stageDeletes(c, "users/"+args[0]+"/roles", []string{roleID})
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			ok, err := confirmAction(cmd, "Remove role %s from user %s?", roleID, args[0])
			if err != nil {
				return err
			}
			if !ok {
				fmt.Fprintln(os.Stderr, "Cancelled.")
				return nil
			}
		}
		if err := c.Delete("users/" + args[0] + "/roles/" + roleID); err != nil {
			return err
		}
		output.Success("Role %s removed from user %s.", roleID, args[0])
		return nil
	},
}

// roleIDFrom resolves the role id from the second positional arg or --role-id.
func roleIDFrom(cmd *cobra.Command, args []string) string {
	if len(args) >= 2 {
		return args[1]
	}
	v, _ := cmd.Flags().GetString("role_id")
	return strings.TrimSpace(v)
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
	Long: "Create an API key. --scope attenuates the key: `read` for a key that can\n" +
		"never write (reporting agents), `write` for full read/write, or granular\n" +
		"`stage` for a propose-only key, or granular `<area>:read`/`<area>:write`/\n" +
		"`<area>:stage` tokens (comma-separated), e.g.\n" +
		"`reports:read,forecast-events:read`. Without --scope the key has full\n" +
		"access (`*`). A scoped key cannot mint a key broader than itself.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		// Flag validation runs before the client is built: it needs no
		// configuration, and ordering it after would answer `--scope ""` on
		// an unconfigured CLI with "no URL configured" instead of naming the
		// real mistake.
		scope, _ := cmd.Flags().GetString("scope")
		scope = strings.TrimSpace(scope)
		// Omitting --scope means full access, by design. Passing it *empty*
		// does not: that is a caller whose intended scope came out blank
		// (`--scope "$SCOPE"` with SCOPE unset), and silently minting a
		// full-access credential is the worst possible reading of it. The
		// server rejects an explicitly empty scope too; this refuses before
		// a key is created at all.
		if cmd.Flags().Changed("scope") && scope == "" {
			return validationError("--scope was given an empty value").
				WithHint("Name the scope (`read`, `write`, `stage`, or `<area>:read`/`<area>:write`/`<area>:stage`, comma-separated), " +
					"or omit --scope entirely to mint a full-access key on purpose.")
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		var body interface{}
		if scope != "" {
			body = map[string]string{"scope": scope}
		}
		data, err := c.Post("users/"+args[0]+"/api-keys", body)
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
		if dryRun, _ := cmd.Flags().GetBool("dry-run"); dryRun {
			return renderDeletePreviews(c, "users/"+args[0]+"/api-keys", []string{args[1]})
		}
		if api.StagedMode() {
			return stageDeletes(c, "users/"+args[0]+"/api-keys", []string{args[1]})
		}
		force, _ := cmd.Flags().GetBool("force")
		if !force {
			ok, err := confirmAction(cmd, "Delete API key for user %s?", args[0])
			if err != nil {
				return err
			}
			if !ok {
				fmt.Fprintln(os.Stderr, "Cancelled.")
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
	Short: "Replace an API key: a new key with the same scope and label, while the old one works for 24 hours more",
	Long: "Replace an API key, as Replace does in My Account > Personal Settings > API keys.\n" +
		"The new key has the old key's scope and label and is shown once. The old key keeps\n" +
		"working for 24 hours, so whatever holds it can be switched without an outage, and is\n" +
		"then refused with a message saying it was replaced.\n\n" +
		"To end the old key at once, delete it: `p202 user apikey delete <user_id> <old_api_key>`.\n" +
		"To make a key with another scope, use `p202 user apikey create <user_id> --scope <scope>`.\n" +
		"An AI app's key is replaced by connecting the app again.",
	Args: cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		// Flags are checked before the client is built, so the error names
		// the flag rather than an unrelated missing configuration.
		userID := args[0]
		if cmd.Flags().Changed("scope") {
			if scopeFlag, _ := cmd.Flags().GetString("scope"); strings.TrimSpace(scopeFlag) == "" {
				return validationError("--scope was given an empty value").
					WithHint("Omit --scope: the replacement carries the old key's scope.")
			}
			return validationError("rotate does not take --scope: the replacement carries the old key's scope").
				WithHint("%s", "For a key with another scope run `p202 user apikey create "+userID+" --scope <scope>`, then end the old one with `p202 user apikey delete "+userID+" <old_api_key>`.")
		}
		if keepOld, _ := cmd.Flags().GetBool("keep-old"); keepOld {
			return validationError("rotate does not take --keep-old: the old key works for 24 hours more and then ends").
				WithHint("%s", "To add a key and keep the old one for good, run `p202 user apikey create "+userID+"`.")
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}

		oldAPIKey := args[1]
		updateConfig, _ := cmd.Flags().GetBool("update-config")
		forceConfigUpdate, _ := cmd.Flags().GetBool("force-config-update")

		// The old key goes in the body: it works for a day more, and a path is logged.
		replacedData, err := c.Post("users/"+userID+"/api-keys/replace", map[string]string{"api_key": oldAPIKey})
		if err != nil {
			var apiErr *api.APIError
			if errors.As(err, &apiErr) {
				switch apiErr.Status {
				case 404:
					return withHint(err, "%s", "Check the old key against `p202 user apikey list "+userID+"` (each key shows its first 8 characters). A server older than 1.9.80 has no replace: there, run `p202 user apikey create "+userID+"` and then `p202 user apikey delete "+userID+" <old_api_key>`.")
				case 409:
					return withHint(err, "%s", "A key already replaced ends by itself: `p202 user apikey list "+userID+"` shows its ends_at, and `p202 user apikey delete "+userID+" <old_api_key>` ends it now. An AI app's key is replaced by connecting the app again.")
				}
			}
			return err
		}
		replacedObj, err := parseDataObject(replacedData)
		if err != nil {
			return fmt.Errorf("failed to parse the replace api-key response: %w", err)
		}
		newAPIKey, err := extractAPIKey(replacedObj)
		if err != nil {
			return err
		}
		var endsAt int64
		if old, ok := replacedObj["replaced"].(map[string]interface{}); ok {
			if n, ok := old["ends_at"].(json.Number); ok {
				endsAt, _ = n.Int64()
			} else if f, ok := old["ends_at"].(float64); ok {
				endsAt = int64(f)
			}
		}
		if endsAt <= 0 {
			return fmt.Errorf("the replace api-key response did not say when the old key ends")
		}
		oldKeyEnds := time.Unix(endsAt, 0).UTC().Format(time.RFC3339)

		configUpdated := false
		configUpdateSkipped := false
		configProfile := ""
		if updateConfig {
			cfg, err := configpkg.Load()
			if err != nil {
				return err
			}
			// Compare and write through the resolved profile, never the
			// legacy top-level key, which is empty for a profile-based config.
			p, resolvedName, err := cfg.EnsureProfile(profileName)
			if err != nil {
				return err
			}
			configProfile = resolvedName
			if p.APIKey == oldAPIKey || forceConfigUpdate {
				p.APIKey = newAPIKey
				if err := cfg.Save(); err != nil {
					return err
				}
				configUpdated = true
			} else {
				configUpdateSkipped = true
			}
		}

		newScope, _ := replacedObj["scope"].(string)
		if newScope == "" {
			newScope = "*"
		}
		out := map[string]interface{}{
			"user_id":               userID,
			"new_api_key":           newAPIKey,
			"scope":                 newScope,
			"label":                 replacedObj["label"],
			"old_key_deleted":       false,
			"old_key_ends_at":       endsAt,
			"old_key_ends":          oldKeyEnds,
			"config_updated":        configUpdated,
			"config_update_skipped": configUpdateSkipped,
			"config_profile":        configProfile,
		}
		encoded, err := json.Marshal(out)
		if err != nil {
			return fmt.Errorf("encoding output: %w", err)
		}
		// stderr: stdout carries data only.
		fmt.Fprintf(os.Stderr, "The old key keeps working until %s. To end it now: p202 user apikey delete %s <old_api_key>\n", oldKeyEnds, userID)
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

// --- Identity linking key subcommands ---

var userIdentityKeyCmd = &cobra.Command{
	Use:   "identity-key",
	Short: "Show or rotate the key your server signs customer ids with",
	Long: "The identity linking key is what your own server signs customer ids with, so a\n" +
		"`cust` on a public pixel or tracking link joins a person's journeys only when it\n" +
		"carries `cust_sig`:\n\n" +
		"  cust_sig = hex(HMAC-SHA256(linking_key, \"<cust_type>:<cust>\"))\n\n" +
		"cust_type defaults to custom; email_md5/email_sha256 digests are signed in lower case.",
}

var userIdentityKeyGetCmd = &cobra.Command{
	Use:   "get <user_id>",
	Short: "Show the identity linking key (minted on first use)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/"+args[0]+"/identity-key", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var userIdentityKeyRotateCmd = &cobra.Command{
	Use:   "rotate <user_id>",
	Short: "Replace the identity linking key; signatures made with the old key stop linking",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		force, _ := cmd.Flags().GetBool("force")
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		// A proposal changes nothing until someone applies it, so only a
		// direct rotate asks first.
		if !api.StagedMode() && !force {
			ok, err := confirmAction(cmd, "Rotate the identity linking key for user %s? Every cust_sig signed with the current key stops linking.", args[0])
			if err != nil {
				return err
			}
			if !ok {
				// stderr: stdout carries data only (TestCancelledDeletesKeepStdoutClean).
				fmt.Fprintln(os.Stderr, "Cancelled.")
				return nil
			}
		}
		data, err := c.Post("users/"+args[0]+"/identity-key/rotate", map[string]interface{}{})
		if err != nil {
			return err
		}
		render(data)
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
	Long: "Get user preferences. A secret is never shown, only whether one is stored: (stored) or (not stored),\n" +
		"and {\"stored\": true} under --json.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("users/"+args[0]+"/preferences", nil)
		if err != nil {
			return err
		}
		renderPrefs(data)
		return nil
	},
}

var userPrefsUpdateCmd = &cobra.Command{
	Use:   "update <user_id>",
	Short: "Update user preferences (profile, report defaults, integration keys, LTV settings)",
	Long: "Sets the preferences the settings pages set: Personal settings (time zone is on `user update`), the report\n" +
		"defaults, Integrations' network keys and LTV › Settings. Each value is checked against the page's own choices\n" +
		"before any request; a free-text value given as \"\" clears it. Changing --user-account-currency re-prices every\n" +
		"campaign's payout into the new currency, as Personal settings does, through the exchange-rate service. A secret\n" +
		"is shown back only as (stored) or (not stored).",
	Example: "  p202 user prefs update 1 --user-pref-time-predefined last7 --user-pref-limit 50\n" +
		"  p202 user prefs update 1 --user-tracking-domain \"\"     # back to this install's own domain\n" +
		"  p202 user prefs update 1 --user-account-currency EUR",
	Args: cobra.ExactArgs(1),
	RunE: runUserPrefsUpdate,
}

func init() {
	// User CRUD flags
	userCreateCmd.Flags().String("user_name", "", "Username (required)")
	userCreateCmd.Flags().String("user_email", "", "Email (required)")
	userCreateCmd.Flags().String("user_pass", "", "Password, 8-72 characters (omit it to be asked without echo, or to pipe it on stdin; a value here stays in shell history)")
	userCreateCmd.Flags().String("user_active", "", "1=active (default), 0=inactive")
	enumFlag(userCreateCmd, "user_active", newEnum(binaryValues))
	userCreateCmd.Flags().String("user_fname", "", "First name")
	userCreateCmd.Flags().String("user_lname", "", "Last name")
	userCreateCmd.Flags().String("user_timezone", "", "Timezone (default: UTC)")

	userUpdateCmd.Flags().String("user_name", "", "New username (the sign-in name): renames the account, as the Users page does; needs a user you may manage, even yourself")
	userUpdateCmd.Flags().String("user_fname", "", "First name")
	userUpdateCmd.Flags().String("user_lname", "", "Last name")
	userUpdateCmd.Flags().String("user_email", "", "Email")
	userUpdateCmd.Flags().String("user_pass", "", "New password, 8-72 characters (prefer --set-password: a value here stays in shell history)")
	userUpdateCmd.Flags().Bool("set-password", false, "Set a new password, read without echo: asked twice at a terminal, or one line of piped stdin")
	userUpdateCmd.Flags().Bool("current-password", false, "Send your current password, which changing your OWN password needs (asked without echo, or the first line of piped stdin)")
	userUpdateCmd.Flags().String("user_timezone", "", "Time zone, e.g. America/New_York (a PHP time zone name)")
	userUpdateCmd.Flags().String("user_active", "", "1=active, 0=inactive")
	enumFlag(userUpdateCmd, "user_active", newEnum(binaryValues))

	registerDeleteFlags(userDeleteCmd, "user")
	registerIdempotencyKeyFlag(userCreateCmd)

	// Role flags
	userRoleAssignCmd.Flags().String("role_id", "", "Role ID (alternative to the second positional arg)")
	userRoleRemoveCmd.Flags().String("role_id", "", "Role ID (alternative to the second positional arg)")
	registerSingleDeleteFlags(userRoleRemoveCmd)

	// API key flags
	registerSingleDeleteFlags(userAPIKeyDeleteCmd)
	userAPIKeyCreateCmd.Flags().String("scope", "", "Scope for the new key: *, read, write, stage, or comma-separated <area>:read/<area>:write/<area>:stage tokens (`read,stage` is the propose-only agent shape; default: full access)")
	emptyHint(userAPIKeyCreateCmd, "scope", "Name the scope (`read`, `write`, `stage`, or `<area>:read`/`<area>:write`/`<area>:stage`, comma-separated), "+
		"or omit --scope entirely to mint a full-access key on purpose.")
	// Taken until 1.9.80, when rotate made a key and deleted the old one;
	// kept so a script that passes one is told what to run, not "unknown flag".
	userAPIKeyRotateCmd.Flags().String("scope", "", "No longer taken: the replacement carries the old key's scope")
	userAPIKeyRotateCmd.Flags().Bool("keep-old", false, "No longer taken: the old key works for 24 hours more, then ends")
	userAPIKeyRotateCmd.Flags().BoolP("force", "f", false, "No longer needed: nothing is deleted, so nothing is confirmed")
	emptyHint(userAPIKeyRotateCmd, "scope", "Omit --scope: the replacement carries the old key's scope.")
	for _, retired := range []string{"scope", "keep-old", "force"} {
		_ = userAPIKeyRotateCmd.Flags().MarkHidden(retired)
	}
	userAPIKeyRotateCmd.Flags().Bool("update-config", false, "Update local ~/.p202/config.json with the new API key")
	userAPIKeyRotateCmd.Flags().Bool("force-config-update", false, "Update local config even if current key does not match old key")

	registerPrefFlags(userPrefsUpdateCmd)

	// Wire up subcommands
	userRoleCmd.AddCommand(userRoleListCmd, userRoleAssignCmd, userRoleRemoveCmd)
	userAPIKeyCmd.AddCommand(userAPIKeyListCmd, userAPIKeyCreateCmd, userAPIKeyDeleteCmd, userAPIKeyRotateCmd)
	userPrefsCmd.AddCommand(userPrefsGetCmd, userPrefsUpdateCmd)
	userIdentityKeyRotateCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	userIdentityKeyCmd.AddCommand(userIdentityKeyGetCmd, userIdentityKeyRotateCmd)

	userCmd.AddCommand(userListCmd, userGetCmd, userCreateCmd, userUpdateCmd, userDeleteCmd)
	userCmd.AddCommand(userRoleCmd, userAPIKeyCmd, userPrefsCmd, userIdentityKeyCmd)
	rootCmd.AddCommand(userCmd)
}
