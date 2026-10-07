package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// The code the UI's Setup section hands out, from the API that builds it the
// way the pages do (GET /landing-pages/{id}/code, GET
// /conversions/postback-code; capabilities features.setup_section):
//
//	p202 landing-page code <id> [--offer campaign:<id>|rotator:<id> ...]
//	p202 conversion postback-url [--type simple|advanced] [--campaign] [--amount] [--subid] [--scheme]
//	p202 conversion pixel [--type simple|advanced|universal] [--iframe] [...]
//
// Under --json (or --ndjson/--csv/--quiet) each prints the API's answer as
// sent, so an agent reads every snippet; in a table-mode terminal the
// snippets are printed as text to copy, each under the sentence the page
// puts above it.

// setupOfferRe is one offer of an advanced landing page, as the API reads it.
var setupOfferRe = regexp.MustCompile(`^(campaign|rotator):[1-9][0-9]{0,9}$`)

// setupMaxOffers is SetupCodeController::MAX_OFFERS.
const setupMaxOffers = 100

const setupOfferHint = "Give each offer as --offer campaign:<id> (ids from `p202 campaign list`) or --offer rotator:<id> (`p202 rotator list`), in the order they appear on the page."

// setupMachineOutput is whether the answer is printed as the API sent it.
func setupMachineOutput() bool {
	return jsonOutput || ndjsonOutput || csvOutput || quietOutput
}

// setupRequestError attaches the next step to a failed Setup request: a 404
// from a server that does not advertise the Setup API is that server's age,
// not a wrong id, and the class-wide "run list" hint would send the caller
// looking for an id that is fine.
func setupRequestError(c *api.Client, err error, page string) error {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	if apiErr.Status == 404 && !c.SupportsCapability("features", "setup_section") && c.CapabilitiesError() == nil {
		return withHint(err, "This server has no Setup API (capabilities features.setup_section): upgrade Prosper202, or copy the code from %s in the UI.", page)
	}
	if apiErr.Status == 404 {
		if hint := setupNotFoundHint(apiErr.Message); hint != "" {
			return withHint(err, "%s", hint)
		}
	}
	if apiErr.Status == 403 {
		// The roles the installer seeds with each permission the Setup
		// routes ask for (DataSeeder::seedRolePermissions).
		for _, p := range []struct{ permission, roles string }{
			{"'remove_traffic_source'", "remove_traffic_source (Super user or Admin), as Setup > Traffic Sources does for its variables dialog"},
			{"'access_to_setup_section'", "access_to_setup_section (Super user, Admin or Campaign manager), as the Setup pages do"},
		} {
			if strings.Contains(apiErr.Message, p.permission) {
				return withHint(err, "The key's user needs a role with %s: `p202 user role list` shows the roles, `p202 user role assign <user_id> <role_id>` grants one.", p.roles)
			}
		}
	}
	if apiErr.Status == 422 {
		for field := range apiErr.FieldErrors {
			if field == "offers" || strings.HasPrefix(field, "offers[") {
				return withHint(err, "%s", setupOfferHint)
			}
		}
	}
	return err
}

// setupNotFound reads which record a Setup 404 names (the controllers' own
// sentences), so the hint can name the command that lists that record: the
// class-wide "run list" would name the failing command's sibling, which for
// `ppc-account pixel list 99` is itself.
var setupNotFound = []struct {
	re   *regexp.Regexp
	hint string
}{
	{regexp.MustCompile(`^Variable \d+ not found on traffic source (\d+)$`), "`p202 ppc-network variable list %s` lists that traffic source's variables and their ids."},
	{regexp.MustCompile(`^Pixel \d+ not found on traffic source account (\d+)$`), "`p202 ppc-account pixel list %s` lists that account's pixels and their ids."},
	{regexp.MustCompile(`^Traffic source account \d+ not found$`), "No live traffic source account of yours has that id: `p202 ppc-account list` lists them."},
	{regexp.MustCompile(`^Traffic source \d+ not found$`), "No live traffic source of yours has that id: `p202 ppc-network list` lists them."},
	{regexp.MustCompile(`^Landing page \d+ not found$`), "No live landing page of yours has that id: `p202 landing-page list` lists them (the public lpip= id works too)."},
}

func setupNotFoundHint(message string) string {
	for _, nf := range setupNotFound {
		if m := nf.re.FindStringSubmatch(message); m != nil {
			if len(m) > 1 {
				return fmt.Sprintf(nf.hint, m[1])
			}
			return nf.hint
		}
	}
	return ""
}

func newLandingPageCodeCmd(entity crudEntity) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "code <id>",
		Short: "Print a landing page's tracking code (Setup > Get LP Code)",
		Long: "Prints what Setup > Get LP Code hands out for the landing page: the script to put\n" +
			"above </body> of the page visitors arrive on, and the ways out to the offer.\n\n" +
			"A simple page (landing_page_type 0) links out to its own campaign: an outbound link\n" +
			"(go.php?lpip=), a PHP redirect page, or a JavaScript redirect page.\n" +
			"An advanced page (landing_page_type 1) links out to each offer you name, in order:\n" +
			"  --offer campaign:<id>  a campaign (`p202 campaign list`), via go.php?acip=\n" +
			"  --offer rotator:<id>   a redirector (`p202 rotator list`), via go.php?rpi=\n" +
			"each with its own outbound link and PHP redirect page.\n\n" +
			"The links are scheme-relative (//host/...), as the page writes them, so they work on\n" +
			"an http or an https landing page. Accepts the internal landing_page_id, or the public\n" +
			"id the code carries (lpip=). Needs a role with access_to_setup_section.",
		Example: "  p202 landing-page code 12\n" +
			"  p202 landing-page code 14 --offer campaign:3 --offer rotator:2\n" +
			"  p202 landing-page code 14 --offer campaign:3,campaign:5 --json",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := validateID(args[0])
			if err != nil {
				return err
			}
			offers, _ := cmd.Flags().GetStringSlice("offer")
			if len(offers) > setupMaxOffers {
				return validationError("at most %d offers at once, got %d", setupMaxOffers, len(offers))
			}
			for i, offer := range offers {
				if !setupOfferRe.MatchString(offer) {
					return validationError("--offer %q (offer %d) is not campaign:<id> or rotator:<id>", offer, i+1).WithHint("%s", setupOfferHint)
				}
			}
			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			params := map[string]string{}
			if len(offers) > 0 {
				params["offers"] = strings.Join(offers, ",")
			}
			data, err := c.Get("landing-pages/"+id+"/code", params)
			if err != nil && isNotFoundErr(err) {
				if internal := resolvePublicID(c, entity, id); internal != "" && internal != id {
					// Said, not silent, as `landing-page get` says it.
					fmt.Fprintf(os.Stderr, "No landing page has id %s; showing landing page %s, whose public %s is %s.\n",
						id, internal, entity.PublicIDField, id)
					data, err = c.Get("landing-pages/"+internal+"/code", params)
				}
			}
			if err != nil {
				return setupRequestError(c, err, "Setup > Get LP Code")
			}
			if setupMachineOutput() {
				render(data)
				return nil
			}
			return printLandingPageCode(data)
		},
	}
	cmd.Flags().StringSlice("offer", nil, "An advanced page's offer, campaign:ID or rotator:ID; repeat the flag (or comma-separate) for each, in order")
	return cmd
}

type landingPageCode struct {
	Data struct {
		LandingPageID      int             `json:"landing_page_id"`
		LandingPageType    string          `json:"landing_page_type"`
		Nickname           string          `json:"landing_page_nickname"`
		URL                string          `json:"landing_page_url"`
		Loader             string          `json:"loader"`
		OutboundLink       string          `json:"outbound_link"`
		OutboundPHP        string          `json:"outbound_php"`
		OutboundJavascript string          `json:"outbound_javascript"`
		Segments           json.RawMessage `json:"segments"`
		Offers             []struct {
			Position     int    `json:"position"`
			Type         string `json:"type"`
			ID           int    `json:"id"`
			Name         string `json:"name"`
			OutboundLink string `json:"outbound_link"`
			OutboundPHP  string `json:"outbound_php"`
		} `json:"offers"`
	} `json:"data"`
}

// printLandingPageCode prints the code as the page lays it out, as text.
func printLandingPageCode(data []byte) error {
	var code landingPageCode
	if err := json.Unmarshal(data, &code); err != nil || code.Data.Loader == "" {
		return &CLIError{Category: "server", ExitCode: ExitServer,
			Message: fmt.Sprintf("the server's answer is not landing-page code (%v)", err),
			Hint:    "Check the server with `p202 system health`; rerun with --json to see what it sent."}
	}
	d := code.Data
	var b strings.Builder
	fmt.Fprintf(&b, "Landing page %d %q (%s): %s\n", d.LandingPageID, d.Nickname, d.LandingPageType, d.URL)
	b.WriteString("Test every link yourself before you run traffic to it.\n\n")
	b.WriteString("1. Landing page code\n")
	b.WriteString("Put this right above the </body> tag of ONLY the page your visitors first arrive on, not in a template every page of the site includes.\n\n")
	b.WriteString(strings.TrimSpace(d.Loader) + "\n\n")
	if d.LandingPageType == "simple" {
		b.WriteString("2. Link out to the offer: choose one of the three.\n\n")
		b.WriteString("Option 1: outbound redirect link (use it as the link to the offer on your page)\n")
		b.WriteString(d.OutboundLink + "\n\n")
		b.WriteString("Option 2: outbound PHP redirect (save it as a page on your site, e.g. redirect.php, and link to that page; cloaks your affiliate link)\n")
		b.WriteString(strings.TrimSpace(d.OutboundPHP) + "\n\n")
		b.WriteString("Option 3: outbound JavaScript redirect (a page that lets other tracking tags fire before the visitor leaves)\n")
		b.WriteString(strings.TrimSpace(d.OutboundJavascript) + "\n\n")
	} else {
		b.WriteString("2. Link out to each offer: use its outbound link as that offer's link on your page, or save its PHP redirect as a page on your site and link to that.\n\n")
		for _, o := range d.Offers {
			fmt.Fprintf(&b, "Offer %d: %s %d %q\n", o.Position, o.Type, o.ID, o.Name)
			b.WriteString("Outbound link:\n" + o.OutboundLink + "\n")
			b.WriteString("PHP redirect:\n" + strings.TrimSpace(o.OutboundPHP) + "\n\n")
		}
	}
	if segments := orderedStrings(d.Segments); len(segments) > 0 {
		b.WriteString("Dynamic content: the loader fills any element named after one of these, e.g.\n")
		b.WriteString("  <span name=\"t202Country\" t202Default=\"Your Country\">Your Country</span>\n")
		for _, kv := range segments {
			fmt.Fprintf(&b, "  %-17s %s\n", kv[0], kv[1])
		}
	}
	fmt.Print(b.String())
	return nil
}

// orderedStrings reads a JSON object of strings in the order it was sent (a
// Go map would not keep it): the segments come in the page's order, the
// visitor's country first. Anything else reads as nothing to list.
func orderedStrings(raw json.RawMessage) [][2]string {
	dec := json.NewDecoder(bytes.NewReader(raw))
	if tok, err := dec.Token(); err != nil || tok != json.Delim('{') {
		return nil
	}
	var out [][2]string
	for dec.More() {
		key, err := dec.Token()
		if err != nil {
			return nil
		}
		var value string
		if err := dec.Decode(&value); err != nil {
			return nil
		}
		name, _ := key.(string)
		out = append(out, [2]string{name, value})
	}
	return out
}

// postbackCode is GET /conversions/postback-code's answer.
type postbackCode struct {
	Data struct {
		Scheme     string `json:"scheme"`
		CampaignID *int   `json:"campaign_id"`
		Simple     struct {
			Pixel       string `json:"pixel"`
			PostbackURL string `json:"postback_url"`
		} `json:"simple"`
		Advanced struct {
			Pixel       string `json:"pixel"`
			PostbackURL string `json:"postback_url"`
		} `json:"advanced"`
		Universal struct {
			Javascript string `json:"javascript"`
			Iframe     string `json:"iframe"`
		} `json:"universal"`
	} `json:"data"`
}

var postbackTypes = []string{"simple", "advanced", "universal"}

// registerPostbackCodeFlags adds the page's choices: the pixel type, the
// campaign the advanced pixel reports under, the amount, the sub id macro and
// the protocol.
func registerPostbackCodeFlags(cmd *cobra.Command, types []string, typeHint string) {
	cmd.Flags().String("type", "", "Pixel type: "+strings.Join(types, ", ")+" (default simple; advanced when --campaign is given)")
	enumFlag(cmd, "type", newEnum(types, enumHint(typeHint)))
	cmd.Flags().String("campaign", "", "Campaign ID the advanced pixel reports under (fills cid; from p202 campaign list)")
	cmd.Flags().String("amount", "", "Amount: a number, or your network's payout macro such as {payout}; empty pays the campaign's payout")
	cmd.Flags().String("subid", "", "Your network's sub id macro, such as {aff_sub}, #s1#, xxC1xx or [=SID=]; empty leaves subid= for you to fill")
	cmd.Flags().String("scheme", "", "http or https (default: the scheme the tracking domain is reached on)")
	enumFlag(cmd, "scheme", newEnum([]string{"http", "https"}))
}

// postbackCodeQuery reads the choices, before any request: the pixel type,
// and the query the API takes.
func postbackCodeQuery(cmd *cobra.Command) (string, map[string]string, error) {
	kind, _ := cmd.Flags().GetString("type")
	campaign, _ := cmd.Flags().GetString("campaign")
	if kind == "" {
		kind = "simple"
		if campaign != "" {
			kind = "advanced"
		}
	}
	params := map[string]string{}
	if campaign != "" {
		if kind != "advanced" {
			return "", nil, validationError("--campaign fills the advanced pixel's cid; the %s pixel has none", kind).
				WithHint("Use --type advanced with --campaign, or drop --campaign.")
		}
		id, err := validateID(campaign)
		if err != nil {
			return "", nil, withHint(err, "--campaign is a campaign id from `p202 campaign list`.")
		}
		params["campaign_id"] = id
	}
	for _, name := range []string{"amount", "subid", "scheme"} {
		if v, _ := cmd.Flags().GetString(name); v != "" {
			params[name] = v
		}
	}
	return kind, params, nil
}

// fetchPostbackCode asks the API for the snippets and reads its answer.
func fetchPostbackCode(params map[string]string) ([]byte, *postbackCode, error) {
	c, err := api.NewFromConfig()
	if err != nil {
		return nil, nil, err
	}
	data, err := c.Get("conversions/postback-code", params)
	if err != nil {
		return nil, nil, setupRequestError(c, err, "Setup > Postback / Pixel")
	}
	var code postbackCode
	if err := json.Unmarshal(data, &code); err != nil || code.Data.Simple.PostbackURL == "" {
		return nil, nil, &CLIError{Category: "server", ExitCode: ExitServer,
			Message: fmt.Sprintf("the server's answer is not postback code (%v)", err),
			Hint:    "Check the server with `p202 system health`; rerun with --json to see what it sent."}
	}
	return data, &code, nil
}

var conversionPostbackURLCmd = &cobra.Command{
	Use:   "postback-url",
	Short: "Print the server-to-server postback URL to give your affiliate network (Setup > Postback / Pixel)",
	Long: "Prints the postback URL Setup > Postback / Pixel shows: gpb.php on this install's\n" +
		"tracking domain, which the network calls with the conversion's sub id (and amount).\n\n" +
		"  --subid   the network's sub id macro, e.g. {aff_sub} (HasOffers), #s2# (Cake)\n" +
		"  --amount  a number or the network's payout macro, e.g. {payout}; empty pays the\n" +
		"            campaign's payout\n" +
		"  --campaign <id>  the advanced postback, which also carries cid\n\n" +
		"Stdout is the URL alone; --json prints every pixel and postback the page shows.\n" +
		"If the network only supports sid, change ?subid= to ?sid=. Needs a role with\n" +
		"access_to_setup_section.",
	Example: "  p202 conversion postback-url --subid '{aff_sub}' --amount '{payout}'\n" +
		"  p202 conversion postback-url --campaign 3 --subid '#s2#'",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		kind, params, err := postbackCodeQuery(cmd)
		if err != nil {
			return err
		}
		data, code, err := fetchPostbackCode(params)
		if err != nil {
			return err
		}
		if setupMachineOutput() {
			render(data)
			return nil
		}
		url := code.Data.Simple.PostbackURL
		if kind == "advanced" {
			url = code.Data.Advanced.PostbackURL
		}
		fmt.Fprintf(os.Stderr, "Server-to-server postback URL, %s (give this to your network):\n", kind)
		if params["subid"] == "" {
			fmt.Fprintln(os.Stderr, "  subid= is empty: put your network's sub id macro after it, or rerun with --subid.")
		}
		if kind == "advanced" && params["campaign_id"] == "" {
			fmt.Fprintln(os.Stderr, "  cid= is empty: rerun with --campaign <id> for the campaign this postback reports under.")
		}
		fmt.Fprintln(os.Stderr, "  If the network only supports sid, change ?subid= to ?sid=.")
		fmt.Println(url)
		return nil
	},
}

var conversionPixelCmd = &cobra.Command{
	Use:   "pixel",
	Short: "Print the conversion pixel for your thank-you page (Setup > Postback / Pixel)",
	Long: "Prints the pixel Setup > Postback / Pixel shows, to put on the conversion or\n" +
		"thank-you page:\n\n" +
		"  --type simple     an image pixel (gpx.php): one click tracked at a time (default)\n" +
		"  --type advanced   the same with cid (--campaign): several clicks at once\n" +
		"  --type universal  the universal smart pixel (upx.php), which also fires your\n" +
		"                    traffic sources' pixels: a JavaScript tag with a fallback for\n" +
		"                    browsers without JavaScript, or a plain iframe with --iframe\n\n" +
		"Stdout is the pixel alone; --json prints every pixel and postback the page shows.\n" +
		"Needs a role with access_to_setup_section.",
	Example: "  p202 conversion pixel\n" +
		"  p202 conversion pixel --type advanced --campaign 3 --amount 12.50\n" +
		"  p202 conversion pixel --type universal --iframe",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		kind, params, err := postbackCodeQuery(cmd)
		if err != nil {
			return err
		}
		iframe, _ := cmd.Flags().GetBool("iframe")
		if iframe && kind != "universal" {
			return validationError("--iframe chooses between the universal pixel's two forms; the %s pixel is an image", kind).
				WithHint("Use --type universal --iframe, or drop --iframe.")
		}
		data, code, err := fetchPostbackCode(params)
		if err != nil {
			return err
		}
		if setupMachineOutput() {
			render(data)
			return nil
		}
		switch {
		case kind == "simple":
			fmt.Fprintln(os.Stderr, "Global tracking pixel (put it on the conversion or thank-you page):")
			fmt.Println(code.Data.Simple.Pixel)
		case kind == "advanced":
			fmt.Fprintln(os.Stderr, "Advanced global tracking pixel (put it on the conversion or thank-you page):")
			fmt.Println(code.Data.Advanced.Pixel)
		case iframe:
			fmt.Fprintln(os.Stderr, "Iframe universal smart pixel (put it on the conversion or thank-you page):")
			fmt.Println(code.Data.Universal.Iframe)
		default:
			fmt.Fprintln(os.Stderr, "JavaScript universal smart pixel (put it on the conversion or thank-you page):")
			fmt.Println(code.Data.Universal.Javascript)
		}
		return nil
	},
}

func init() {
	registerPostbackCodeFlags(conversionPostbackURLCmd, []string{"simple", "advanced"},
		"The universal smart pixel has no postback URL; print it with `p202 conversion pixel --type universal`.")
	registerPostbackCodeFlags(conversionPixelCmd, postbackTypes,
		"simple is the image pixel, advanced adds the campaign (--campaign), universal also fires your traffic sources' pixels.")
	conversionPixelCmd.Flags().Bool("iframe", false, "Print the universal pixel's plain iframe instead of its JavaScript tag")
	conversionCmd.AddCommand(conversionPixelCmd)
}
