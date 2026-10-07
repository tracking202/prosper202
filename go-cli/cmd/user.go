package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"

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
			return validationError("required flag --user_name is missing")
		}
		if email == "" {
			return validationError("required flag --user_email is missing")
		}
		body := map[string]interface{}{
			"user_name":  name,
			"user_email": email,
		}
		// Secure password input: a hidden prompt (asked twice), or one line
		// of piped stdin. --user_pass works too, into shell history.
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
			return validationError("--set-password and --user_pass both set the password; use one").
				WithHint("--set-password reads it without echo (prompt or piped stdin); --user_pass leaves it in shell history.")
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
				WithHint("Add --set-password (or --user_pass) to change the password.")
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
const passwordInputHint = "Type it at the hidden prompt, or pipe it on stdin (one line); it is never echoed. A --user_pass value works too, but stays in your shell history."

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
			return validationError("role id is required (pass it as the second argument or via --role_id)").WithHint("`p202 user role list` lists role ids.")
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
			return validationError("role id is required (pass it as the second argument or via --role_id)").WithHint("`p202 user role list` lists role ids.")
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

// roleIDFrom resolves the role id from the second positional arg or --role_id.
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
	Short: "Rotate an API key by creating a new one and optionally deleting the old one",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		// As in create: the flag is checked before the client, so the error
		// names the bad flag rather than an unrelated missing configuration.
		if scopeFlag, _ := cmd.Flags().GetString("scope"); cmd.Flags().Changed("scope") && strings.TrimSpace(scopeFlag) == "" {
			return validationError("--scope was given an empty value").
				WithHint("Name the new key's scope, or omit --scope to carry the old key's scope forward.")
		}

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

		// Rotation must never widen access: without an explicit --scope the
		// old key's scope is looked up (by its unique 8-char prefix in the
		// masked listing) and carried onto the new key. A failed lookup
		// fails the rotation rather than silently minting a full-access key.
		scope, _ := cmd.Flags().GetString("scope")
		scope = strings.TrimSpace(scope)
		if scope == "" {
			if len(oldAPIKey) < 8 {
				// Too short to match a masked prefix, so the old scope
				// cannot be established — and an unknown scope must never
				// become full access.
				return validationError("old API key %q is too short to identify a key", oldAPIKey).
					WithHint("Pass the full old key, or set --scope explicitly to state the new key's scope.")
			}
			listData, lerr := c.Get("users/"+userID+"/api-keys", nil)
			if lerr != nil {
				return fmt.Errorf("looking up the old key's scope before rotating: %w", lerr)
			}
			rows, perr := parseDataArray(listData)
			if perr != nil {
				return fmt.Errorf("parsing api-key list while rotating: %w", perr)
			}
			prefix := oldAPIKey[:8]
			matches := 0
			carried := ""
			for _, row := range rows {
				masked, _ := row["api_key"].(string)
				if strings.HasPrefix(masked, prefix) {
					matches++
					if s, ok := row["scope"].(string); ok {
						carried = s
					}
				}
			}
			if matches > 1 {
				return validationError("multiple keys share the prefix %s; cannot infer the old key's scope", prefix).
					WithHint("Pass --scope explicitly; `p202 user apikey list " + userID + "` shows each key's scope.")
			}
			if matches == 0 {
				// Falling through here would mint an unscoped key: the old
				// key's scope is unknown, and "unknown" must never resolve
				// to full access.
				return validationError("no key for user %s starts with %s; cannot infer the old key's scope", userID, prefix).
					WithHint("Check the old key value, or pass --scope explicitly; `p202 user apikey list " + userID + "` shows each key's prefix and scope.")
			}
			if carried != "" && carried != "*" {
				scope = carried
			}
		}

		var createBody interface{}
		if scope != "" && scope != "*" {
			createBody = map[string]string{"scope": scope}
		}
		createdData, err := c.Post("users/"+userID+"/api-keys", createBody)
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
				// Prompt via the shared helper so the question and the outcome go
				// to stderr; this command renders a JSON result on stdout, which
				// the prompt text used to corrupt. The new key exists by now, so
				// an unanswerable question keeps the old one instead of failing
				// a rotate that already happened; the result reports it.
				ok, err := confirmAction(cmd, "Delete old API key for user %s?", userID)
				if err != nil {
					fmt.Fprintln(os.Stderr, "Kept the old key: no answer could be read. Delete it with `p202 user apikey delete`, or pass --force (delete) or --keep-old (keep) next time.")
				} else if !ok {
					fmt.Fprintln(os.Stderr, "Skipping old key deletion.")
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
		configProfile := ""
		if updateConfig {
			cfg, err := configpkg.Load()
			if err != nil {
				return err
			}
			// Compare and write through the resolved profile. This used to read
			// the legacy top-level cfg.APIKey, which is empty for every
			// profile-based config, so the match never fired and --update-config
			// was a no-op unless --force-config-update was also passed.
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

		newScope := scope
		if newScope == "" {
			newScope = "*"
		}
		out := map[string]interface{}{
			"user_id":               userID,
			"new_api_key":           newAPIKey,
			"scope":                 newScope,
			"old_key_deleted":       deletedOld,
			"old_key_kept":          keepOld || !deletedOld,
			"config_updated":        configUpdated,
			"config_update_skipped": configUpdateSkipped,
			"config_profile":        configProfile,
		}
		encoded, err := json.Marshal(out)
		if err != nil {
			return fmt.Errorf("encoding output: %w", err)
		}
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
	Short: "Update user preferences (profile, report defaults, integration keys, LTV settings)",
	Long: "Sets the preferences the settings pages set: Personal settings (time zone is on `user update`), the report\n" +
		"defaults, Integrations' network keys and LTV › Settings. Each value is checked against the page's own choices\n" +
		"before any request; a free-text value given as \"\" clears it. Changing --user_account_currency re-prices every\n" +
		"campaign's payout into the new currency, as Personal settings does, through the exchange-rate service.",
	Example: "  p202 user prefs update 1 --user_daily_email 07 --user_pref_time_predefined last7\n" +
		"  p202 user prefs update 1 --user_tracking_domain \"\"     # back to this install's own domain\n" +
		"  p202 user prefs update 1 --user_account_currency EUR",
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
	userAPIKeyRotateCmd.Flags().String("scope", "", "Scope for the replacement key (default: the old key's scope, carried over)")
	emptyHint(userAPIKeyCreateCmd, "scope", "Name the scope (`read`, `write`, `stage`, or `<area>:read`/`<area>:write`/`<area>:stage`, comma-separated), "+
		"or omit --scope entirely to mint a full-access key on purpose.")
	emptyHint(userAPIKeyRotateCmd, "scope", "Name the new key's scope, or omit --scope to carry the old key's scope forward.")
	userAPIKeyRotateCmd.Flags().Bool("keep-old", false, "Do not delete the old API key")
	userAPIKeyRotateCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt when deleting old key")
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
