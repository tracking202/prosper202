package cmd

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Postback authentication (Account > API Integrations > Postback
// authentication, /conversions/postback-auth): what the account's postbacks
// and pixels must present (the mode), the token its URLs carry as
// p202_token, and the signing secret a signed postback is keyed with.

// postbackGraceMaxHours is PostbackAuthController::MAX_GRACE_HOURS.
const postbackGraceMaxHours = 720

// postbackAuthModes are PostbackAuthMode's values; postbackCredentials are
// PostbackCredential's.
var postbackAuthModes = []string{"off", "token", "signed"}
var postbackCredentials = []string{"token", "signing_secret"}

var conversionPostbackAuthCmd = &cobra.Command{
	Use:   "postback-auth",
	Short: "Postback authentication: the token your postbacks and pixels carry, the signing secret, and what is required",
	Long: "Click ids count up, so a bare postback lets anyone who guesses one record or reverse a\n" +
		"conversion. Your postbacks and pixels (`p202 conversion postback-url`, `p202 conversion pixel`)\n" +
		"carry your account's token as p202_token. A sender that can compute an HMAC signs instead,\n" +
		"with p202_ts and p202_sig keyed with the signing secret, which no URL carries. The mode says\n" +
		"what gpb.php, pb.php, upx.php, px.php and gpx.php require: off (nothing; accounts from before\n" +
		"1.9.80 start here), token (the token or a signature; new accounts) or signed (a signature only:\n" +
		"the token alone is refused, and so is every pixel, which cannot sign). Under token and\n" +
		"signed a wrong token or signature is refused; off accepts any. A pixel's token can be read on the advertiser's page: it\n" +
		"stops guessing, not someone who saw the page.",
}

var conversionPostbackAuthGetCmd = &cobra.Command{
	Use:   "get",
	Short: "Show the mode, the postback token, the signing secret, and how to sign a postback",
	Long: "Prints mode, the token and the signing secret (each made the first time, as handing out a\n" +
		"postback makes them), when the ones the last rotations replaced stop working, and the signing\n" +
		"parameters. Needs a role with access_to_api_integrations: it hands out the signing secret.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/postback-auth", nil)
		if err != nil {
			return withHint(err, "%s", "Reading it needs a role with access_to_api_integrations, as Account > API Integrations does.")
		}
		render(data)
		return nil
	},
}

var conversionPostbackAuthReadinessCmd = &cobra.Command{
	Use:   "readiness",
	Short: "Who a stricter mode would refuse: the last 7 days' postbacks and pixels by what each carried",
	Long: "Run this before `p202 conversion postback-auth set --mode token`. It reads the inbound log:\n" +
		"how many of your postbacks and pixels of the last 7 days carried nothing or a wrong token\n" +
		"(without_credentials), with the token alone (token_only) or signed, and lists the senders by\n" +
		"endpoint and the campaign they recorded on. safe_to_require_token is true only when every\n" +
		"request seen carried the token or a signature; it is null, not true, when none arrived or\n" +
		"some could not be read. Give each sender listed its URL from `p202 conversion postback-url`,\n" +
		"then look again. covers_since says how far back the log reaches when that is under 7 days.",
	Example: "  p202 conversion postback-auth readiness\n" +
		"  p202 conversion postback-auth readiness --json",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("conversions/postback-auth/readiness", nil)
		if err != nil {
			return withHint(err, "%s", "Reading it needs a role with access_to_api_integrations, as Account > API Integrations does.")
		}
		render(data)
		return nil
	},
}

var conversionPostbackAuthSetCmd = &cobra.Command{
	Use:   "set",
	Short: "Choose what postbacks and pixels must carry: --mode off, token or signed",
	Long: "--mode token refuses every postback and pixel of your account that carries neither the token\n" +
		"nor a signature: give your networks the postbacks from `p202 conversion postback-url` first,\n" +
		"and check `p202 conversion postback-auth readiness` for senders that still send none.\n" +
		"--mode signed accepts only a signature keyed with the signing secret: the token alone is\n" +
		"refused, and every pixel too, since a browser cannot sign. --mode off accepts every request,\n" +
		"a stale or wrong token included. Needs a role with access_to_api_integrations.",
	Example: "  p202 conversion postback-auth set --mode token\n" +
		"  p202 conversion postback-auth set --mode signed",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		if !cmd.Flags().Changed("mode") {
			return validationError("--mode is required: one of %s", strings.Join(postbackAuthModes, ", ")).
				WithHint("%s", "`p202 conversion postback-auth get` shows the current mode.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("conversions/postback-auth", map[string]interface{}{"mode": enumValue(cmd, "mode")})
		if err != nil {
			return withHint(err, "%s", "Changing it needs a role with access_to_api_integrations.")
		}
		render(data)
		return nil
	},
}

var conversionPostbackAuthRotateCmd = &cobra.Command{
	Use:   "rotate",
	Short: "Replace the postback token or the signing secret; the old one keeps working for --grace-hours",
	Long: "Makes a new --credential (token, the default, or signing_secret). The old one keeps working for\n" +
		"--grace-hours (default 168, a week; at most 720) while your networks or signing servers move to\n" +
		"the new one; 0 stops it at once, for one that leaked. One an earlier rotation kept working is\n" +
		"dropped. Needs a role with access_to_api_integrations.",
	Example: "  p202 conversion postback-auth rotate\n" +
		"  p202 conversion postback-auth rotate --credential signing_secret --grace-hours 0",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		hours, _ := cmd.Flags().GetInt("grace_hours")
		if hours < 0 || hours > postbackGraceMaxHours {
			return validationError("--grace-hours must be from 0 to %d, not %d", postbackGraceMaxHours, hours).
				WithHint("%s", "Use 0 to stop the old one at once, or the hours it should keep working (168 is a week).")
		}
		credential := "token"
		if cmd.Flags().Changed("credential") {
			credential = enumValue(cmd, "credential")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("conversions/postback-auth/rotate", map[string]interface{}{"credential": credential, "grace_hours": hours})
		if err != nil {
			return withHint(err, "%s", "Rotating it needs a role with access_to_api_integrations.")
		}
		render(data)
		return nil
	},
}

// postbackAuth is what a postback this CLI sends itself (conversion
// simulate) authenticates with.
type postbackAuth struct {
	Mode          string `json:"mode"`
	Token         string `json:"token"`
	SigningSecret string `json:"signing_secret"`
}

// postbackAuthFor reads the account's postback credentials. A key that may
// not read them (403), or a server from before 1.9.80 (404), gets none: the
// postback then goes without, and its answer says whether that is accepted.
func postbackAuthFor(c *api.Client) (postbackAuth, error) {
	data, err := c.Get("conversions/postback-auth", nil)
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && (apiErr.Status == 403 || apiErr.Status == 404) {
			return postbackAuth{}, nil
		}
		return postbackAuth{}, fmt.Errorf("reading the postback credentials: %w", err)
	}
	var answer struct {
		Data postbackAuth `json:"data"`
	}
	if err := json.Unmarshal(data, &answer); err != nil {
		return postbackAuth{}, fmt.Errorf("reading the postback credentials: %w", err)
	}
	return answer.Data, nil
}

// rfc3986Escape is PHP's rawurlencode(): A-Z a-z 0-9 - . _ ~ as they are,
// every other byte as %XX in upper-case hex.
func rfc3986Escape(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		ch := s[i]
		if ch >= 'A' && ch <= 'Z' || ch >= 'a' && ch <= 'z' || ch >= '0' && ch <= '9' || ch == '-' || ch == '.' || ch == '_' || ch == '~' {
			b.WriteByte(ch)
		} else {
			fmt.Fprintf(&b, "%%%02X", ch)
		}
	}
	return b.String()
}

// postbackStringToSign is PostbackSignature::stringToSign(): the decoded
// pairs, p202_sig left out, encoded, sorted by name then value as bytes.
func postbackStringToSign(endpoint string, pairs [][2]string) string {
	encoded := make([][2]string, 0, len(pairs))
	for _, p := range pairs {
		if strings.EqualFold(p[0], "p202_sig") {
			continue
		}
		encoded = append(encoded, [2]string{rfc3986Escape(p[0]), rfc3986Escape(p[1])})
	}
	sort.SliceStable(encoded, func(i, j int) bool {
		if encoded[i][0] != encoded[j][0] {
			return encoded[i][0] < encoded[j][0]
		}
		return encoded[i][1] < encoded[j][1]
	})
	parts := make([]string, len(encoded))
	for i, p := range encoded {
		parts[i] = p[0] + "=" + p[1]
	}
	return "P202-POSTBACK-V1\n" + endpoint + "\n" + strings.Join(parts, "&")
}

// signedPostbackQuery is the query of a postback to endpoint carrying pairs,
// signed with the account's signing secret at Unix time now.
func signedPostbackQuery(endpoint string, pairs [][2]string, secret string, now int64) string {
	pairs = append(append([][2]string{}, pairs...), [2]string{"p202_ts", strconv.FormatInt(now, 10)})
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(postbackStringToSign(endpoint, pairs)))
	pairs = append(pairs, [2]string{"p202_sig", hex.EncodeToString(mac.Sum(nil))})
	parts := make([]string, len(pairs))
	for i, p := range pairs {
		parts[i] = rfc3986Escape(p[0]) + "=" + rfc3986Escape(p[1])
	}
	return strings.Join(parts, "&")
}

func init() {
	conversionPostbackAuthSetCmd.Flags().String("mode", "", "What postbacks and pixels must carry ({values})")
	enumFlag(conversionPostbackAuthSetCmd, "mode", newEnum(postbackAuthModes))
	conversionPostbackAuthRotateCmd.Flags().String("credential", "", "What to replace ({values}; default token)")
	enumFlag(conversionPostbackAuthRotateCmd, "credential", newEnum(postbackCredentials))
	conversionPostbackAuthRotateCmd.Flags().Int("grace_hours", 168, "Hours the old one keeps working (0 to 720; 0 stops it at once)")
	conversionPostbackAuthCmd.AddCommand(conversionPostbackAuthGetCmd, conversionPostbackAuthReadinessCmd, conversionPostbackAuthSetCmd, conversionPostbackAuthRotateCmd)
	conversionCmd.AddCommand(conversionPostbackAuthCmd)
}
