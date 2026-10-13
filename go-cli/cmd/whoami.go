package cmd

import (
	"encoding/json"
	"errors"
	"fmt"

	"github.com/spf13/cobra"

	"p202/internal/api"
	configpkg "p202/internal/config"
)

// errNoPrincipal marks an answer from a server that authenticated the key
// but predates the principal block.
var errNoPrincipal = errors.New("no principal in the capabilities response")

// principal is who the configured key acts as: the `principal` block of
// GET /capabilities (the key's user, roles and scopes).
type principal struct {
	UserID int      `json:"user_id"`
	Roles  []string `json:"roles"`
	Scopes []string `json:"scopes"`
}

// fetchPrincipal reads the principal through the ordinary request path, so a
// key the server refuses is an auth error (exit 2) with its hint. A server
// that answers without one predates it: that is said, not guessed around.
func fetchPrincipal(c *api.Client) (principal, error) {
	data, err := c.Get("capabilities", nil)
	if err != nil {
		return principal{}, err
	}
	var resp struct {
		Data struct {
			Principal *principal `json:"principal"`
		} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return principal{}, fmt.Errorf("parsing the capabilities response: %w", err)
	}
	if resp.Data.Principal == nil || resp.Data.Principal.UserID <= 0 {
		return principal{}, &CLIError{
			Category: "server",
			Message:  "the server's capabilities do not say which user the key acts as",
			ExitCode: ExitServer,
			Hint:     "This server predates it; the key did authenticate. Upgrade Prosper202, or read the account with `p202 user list`.",
			Cause:    errNoPrincipal,
		}
	}
	return *resp.Data.Principal, nil
}

var whoamiCmd = &cobra.Command{
	Use:   "whoami",
	Short: "Show the user, roles and scopes the configured API key acts as",
	Long: "Asks the server who the configured key belongs to: its user id and username, the\n" +
		"user's roles, and the key's scopes (`*` is full access; `read`, `campaigns:write`\n" +
		"and the like narrow it). Fails with exit 2 when the server refuses the key.\n\n" +
		"  p202 whoami\n" +
		"  p202 whoami --profile staging --json",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		p, err := fetchPrincipal(c)
		if err != nil {
			return err
		}
		out := map[string]interface{}{
			"user_id": p.UserID,
			"roles":   p.Roles,
			"scopes":  p.Scopes,
		}
		if profile, name, perr := configpkg.LoadProfileWithName(""); perr == nil {
			out["profile"] = name
			out["url"] = profile.URL
		}
		// The username is the account's own record, which a key may read
		// (GET /users/{self}); a narrowed key without users:read cannot, and
		// the rest of the answer stands without it.
		if data, uerr := c.Get(fmt.Sprintf("users/%d", p.UserID), nil); uerr == nil {
			var user struct {
				Data map[string]interface{} `json:"data"`
			}
			if json.Unmarshal(data, &user) == nil {
				for _, k := range []string{"user_name", "user_email"} {
					if v, ok := user.Data[k]; ok {
						out[k] = v
					}
				}
			}
		} else {
			out["user_name_unavailable"] = uerr.Error()
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": out})
		if err != nil {
			return fmt.Errorf("encoding whoami: %w", err)
		}
		render(encoded)
		return nil
	},
}

func init() {
	rootCmd.AddCommand(whoamiCmd)
}
