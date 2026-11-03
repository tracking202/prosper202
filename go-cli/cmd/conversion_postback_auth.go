package cmd

import (
	"encoding/json"
	"errors"
	"fmt"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Postback authentication (Account > API Integrations > Postback
// authentication, /conversions/postback-auth): the token the account's
// postback and pixel URLs carry as p202_token, whether a postback or pixel
// without it is refused, and its rotation.

// postbackGraceMaxHours is PostbackAuthController::MAX_GRACE_HOURS.
const postbackGraceMaxHours = 720

var conversionPostbackAuthCmd = &cobra.Command{
	Use:   "postback-auth",
	Short: "Postback authentication: the token your postbacks and pixels carry, and whether it is required",
	Long: "Click ids count up, so a bare postback lets anyone who guesses one record or reverse a\n" +
		"conversion. Your postbacks and pixels (`p202 conversion postback-url`, `p202 conversion pixel`)\n" +
		"carry your account's token as p202_token. When token_required is on, gpb.php, pb.php, upx.php,\n" +
		"px.php and gpx.php refuse a request without the token or a signature (403, nothing recorded); a\n" +
		"wrong token is refused either way. Accounts from before 1.9.80 start with it off.\n" +
		"A sender that can compute an HMAC can sign each request with p202_ts and p202_sig instead\n" +
		"(`get` prints how). A pixel's token can be read on the advertiser's page: it stops guessing,\n" +
		"not someone who saw the page.",
}

var conversionPostbackAuthGetCmd = &cobra.Command{
	Use:   "get",
	Short: "Show the postback token, whether it is required, and how to sign a postback",
	Long: "Prints the token (made the first time, as handing out a postback makes it), token_required,\n" +
		"when the token the last rotation replaced stops working, and the signing parameters. Needs a role\n" +
		"with access_to_setup_section.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/postback-auth", nil)
		if err != nil {
			return withHint(err, "%s", "Reading it needs a role with access_to_setup_section, as Setup > Postback / Pixel does.")
		}
		render(data)
		return nil
	},
}

var conversionPostbackAuthSetCmd = &cobra.Command{
	Use:   "set",
	Short: "Require the postback token (1), or accept postbacks and pixels without it (0)",
	Long: "--token-required 1 refuses every postback and pixel of your account that carries neither the\n" +
		"token nor a signature: give your networks the postbacks from `p202 conversion postback-url` first.\n" +
		"0 accepts them again; a wrong token is still refused. Needs a role with access_to_api_integrations.",
	Example: "  p202 conversion postback-auth set --token-required 1",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		if !cmd.Flags().Changed("token_required") {
			return validationError("--token-required is required: 1 to require the token, 0 to accept postbacks without it").
				WithHint("%s", "`p202 conversion postback-auth get` shows the current value.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("conversions/postback-auth", map[string]interface{}{
			"token_required": enumValue(cmd, "token_required") == "1",
		})
		if err != nil {
			return withHint(err, "%s", "Changing it needs a role with access_to_api_integrations.")
		}
		render(data)
		return nil
	},
}

var conversionPostbackAuthRotateCmd = &cobra.Command{
	Use:   "rotate",
	Short: "Replace the postback token; the old one keeps working for --grace-hours",
	Long: "Makes a new token. The old one keeps working for --grace-hours (default 168, a week; at most 720)\n" +
		"while your networks move to the new one; 0 stops it at once, for a token that leaked. A token\n" +
		"an earlier rotation kept working is dropped. Every postback handed out afterwards carries the new one.\n" +
		"Needs a role with access_to_api_integrations.",
	Example: "  p202 conversion postback-auth rotate\n" +
		"  p202 conversion postback-auth rotate --grace-hours 0",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		hours, _ := cmd.Flags().GetInt("grace_hours")
		if hours < 0 || hours > postbackGraceMaxHours {
			return validationError("--grace-hours must be from 0 to %d, not %d", postbackGraceMaxHours, hours).
				WithHint("%s", "Use 0 to stop the old token at once, or the hours it should keep working (168 is a week).")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("conversions/postback-auth/rotate", map[string]interface{}{"grace_hours": hours})
		if err != nil {
			return withHint(err, "%s", "Rotating it needs a role with access_to_api_integrations.")
		}
		render(data)
		return nil
	},
}

// postbackTokenFor reads the account's postback token for a postback this
// CLI sends itself (conversion simulate). A key that may not read it (403),
// or a server from before 1.9.80 (404), gets "": the postback then goes
// without, and its answer says whether that is accepted.
func postbackTokenFor(c *api.Client) (string, error) {
	data, err := c.Get("conversions/postback-auth", nil)
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && (apiErr.Status == 403 || apiErr.Status == 404) {
			return "", nil
		}
		return "", fmt.Errorf("reading the postback token: %w", err)
	}
	var answer struct {
		Data struct {
			Token string `json:"token"`
		} `json:"data"`
	}
	if err := json.Unmarshal(data, &answer); err != nil {
		return "", fmt.Errorf("reading the postback token: %w", err)
	}
	return answer.Data.Token, nil
}

func init() {
	conversionPostbackAuthSetCmd.Flags().String("token_required", "", "1 refuses postbacks and pixels without the token or a signature, 0 accepts them")
	enumFlag(conversionPostbackAuthSetCmd, "token_required", newEnum(binaryValues))
	conversionPostbackAuthRotateCmd.Flags().Int("grace_hours", 168, "Hours the old token keeps working (0 to 720; 0 stops it at once)")
	conversionPostbackAuthCmd.AddCommand(conversionPostbackAuthGetCmd, conversionPostbackAuthSetCmd, conversionPostbackAuthRotateCmd)
	conversionCmd.AddCommand(conversionPostbackAuthCmd)
}
