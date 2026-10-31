package cmd

import (
	"encoding/json"
	"fmt"
	"net/url"
	"regexp"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// The click filter (Personal settings › Click filtering, and
// /users/{id}/click-filter): which built-in checks mark a click filtered,
// the account's own address list, and a test of one address. Every command
// acts on the key's own account unless --user-id names another the key may
// manage.

// clickFilterWindows are ClickFilterSettings::WINDOWS' keys
// (TestClickFilterWindowsAreTheServers holds them together).
var clickFilterWindows = []string{"1h", "6h", "24h", "7d"}

// clickFilterReasons are ClicksController::FILTER_REASONS, what GET /clicks
// filters filter_reason by.
var clickFilterReasons = []string{"sign_in_address", "crawler_range", "repeat_visit", "filter_list", "bot", "not_recorded"}

// clickFilterActions are ClickFilterEntry::ACTIONS.
var clickFilterActions = []string{"count", "filter"}

// clickFilterAddressPattern is what an address or a block can be spelled
// with; the server reads it strictly (AddressRange::parse()).
var clickFilterAddressPattern = regexp.MustCompile(`^[0-9A-Fa-f:.]{2,45}(?:/[0-9]{1,3})?$`)

var clickFilterCmd = &cobra.Command{
	Use:   "click-filter",
	Short: "See and change why clicks are filtered: the built-in checks, your address list, and an address test",
	Long: "A filtered click is recorded and redirected, and left out of Real clicks. Three built-in checks\n" +
		"filter one, all on by default: an address a user of this install last signed in from\n" +
		"(sign_in_addresses), the built-in search engine crawler ranges (crawler_ranges), and a repeat\n" +
		"click from the same address within the repeat window (repeat_visits, repeat_window). The address\n" +
		"list beats them: an entry is always counted (count, which beats every check) or always filtered\n" +
		"(filter). Bot detection, which reads the browser, always applies. `p202 click get` says which rule\n" +
		"filtered a click (filter_reason). Every command acts on your own account; --user-id names another\n" +
		"you may manage.",
}

var clickFilterGetCmd = &cobra.Command{
	Use:   "get",
	Short: "Show the click filter's checks, repeat window and address list",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Get(path, nil)
		if err != nil {
			return err
		}
		return renderClickFilter(data)
	},
}

var clickFilterSetCmd = &cobra.Command{
	Use:   "set",
	Short: "Switch the built-in checks on or off, and choose the repeat window",
	Long: "Changes only the settings given; the rest keep their values. Changes apply to clicks from now on.\n" +
		"The defaults are every check on and a 24h window, which keeps an address until the daily cleanup.",
	Example: "  p202 click-filter set --repeat-window 6h\n" +
		"  p202 click-filter set --sign-in-addresses 0     # count clicks from your own sign-in address",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		for _, f := range []string{"sign_in_addresses", "crawler_ranges", "repeat_visits"} {
			if cmd.Flags().Changed(f) {
				body[f] = enumValue(cmd, f) == "1"
			}
		}
		if cmd.Flags().Changed("repeat_window") {
			body["repeat_window"] = enumValue(cmd, "repeat_window")
		}
		if len(body) == 0 {
			return validationError("no settings given; pass at least one of --sign-in-addresses, --crawler-ranges, --repeat-visits, --repeat-window").
				WithHint("%s", "`p202 click-filter get` shows the current values.")
		}
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Put(path, body)
		if err != nil {
			return err
		}
		return renderClickFilter(data)
	},
}

var clickFilterListCmd = &cobra.Command{
	Use:   "list",
	Short: "Show the address list: addresses and blocks always counted or always filtered",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Get(path, nil)
		if err != nil {
			return err
		}
		var answer struct {
			Data struct {
				Entries []json.RawMessage `json:"entries"`
			} `json:"data"`
		}
		if err := json.Unmarshal(data, &answer); err != nil {
			return fmt.Errorf("reading the click filter: %w", err)
		}
		if answer.Data.Entries == nil {
			answer.Data.Entries = []json.RawMessage{}
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": answer.Data.Entries})
		if err != nil {
			return fmt.Errorf("encoding the address list: %w", err)
		}
		render(encoded)
		return nil
	},
}

var clickFilterListAddCmd = &cobra.Command{
	Use:   "add <address>",
	Short: "Always count or always filter clicks from an address or a CIDR block",
	Long: "Adds an IPv4 or IPv6 address, or a CIDR block, to the list. count: its clicks are always counted,\n" +
		"whatever the checks say (a client's office that tests your links). filter: always filtered (a click\n" +
		"farm). An address can be claimed through a proxy header, so keep count for addresses you trust.",
	Example: "  p202 click-filter list add 203.0.113.0/24 --action filter --note \"click farm\"\n" +
		"  p202 click-filter list add 198.51.100.7 --action count --note \"client QA office\"",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := checkClickFilterAddress(args[0]); err != nil {
			return err
		}
		if !cmd.Flags().Changed("action") {
			return validationError("--action is required: count (always count its clicks) or filter (always filter them)")
		}
		body := map[string]interface{}{"address": args[0], "action": enumValue(cmd, "action")}
		if cmd.Flags().Changed("note") {
			note, _ := cmd.Flags().GetString("note")
			body["note"] = note
		}
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Post(path+"/entries", body)
		if err != nil {
			return withHint(err, "`p202 click-filter list` shows the entries; `p202 click-filter list update <entry-id>` changes one.")
		}
		render(data)
		return nil
	},
}

var clickFilterListUpdateCmd = &cobra.Command{
	Use:   "update <entry-id>",
	Short: "Change an entry's address, action or note",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		entryID, err := validateID(args[0])
		if err != nil {
			return err
		}
		body := map[string]interface{}{}
		if cmd.Flags().Changed("address") {
			address, _ := cmd.Flags().GetString("address")
			if err := checkClickFilterAddress(address); err != nil {
				return err
			}
			body["address"] = address
		}
		if cmd.Flags().Changed("action") {
			body["action"] = enumValue(cmd, "action")
		}
		if cmd.Flags().Changed("note") {
			note, _ := cmd.Flags().GetString("note")
			body["note"] = note
		}
		if len(body) == 0 {
			return validationError("nothing to change; pass --address, --action or --note")
		}
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Put(path+"/entries/"+entryID, body)
		if err != nil {
			return withHint(err, "`p202 click-filter list` shows the entries and their ids.")
		}
		render(data)
		return nil
	},
}

var clickFilterListRemoveCmd = &cobra.Command{
	Use:   "remove [entry-id]",
	Short: "Remove an entry; its addresses are filtered by the other rules again",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		if len(args) == 1 {
			if _, err := validateID(args[0]); err != nil {
				return err
			}
		}
		path, _, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		return runBulkOrSingleDelete(cmd, args, deleteSpec{
			endpoint:    path + "/entries",
			noun:        "click filter entry",
			plural:      "click filter entries",
			idsHintText: "Comma-separate entry ids, e.g. --ids 3,4 (`p202 click-filter list` shows them).",
			verb:        "remove",
			past:        "removed",
		})
	},
}

var clickFilterTestCmd = &cobra.Command{
	Use:   "test <ip>",
	Short: "Test whether a click from an address would be filtered, and by which rule",
	Long: "Asks every rule about one address and answers the decision (filtered, reason), the list entry it\n" +
		"falls in, and each check's own answer. Read only: nothing is recorded. repeat_visit says whether\n" +
		"the address has a click in the window already, so its next one would be filtered.",
	Example: "  p202 click-filter test 203.0.113.7",
	Args:    cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !clickFilterAddressPattern.MatchString(args[0]) || strings.Contains(args[0], "/") {
			return validationError("%q is not an IP address; test one address, such as 203.0.113.7", args[0])
		}
		path, c, err := clickFilterPath(cmd)
		if err != nil {
			return err
		}
		data, err := c.Get(path+"/test", map[string]string{"ip": args[0]})
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// checkClickFilterAddress refuses, before any request, what cannot be an
// address or a block; the server's check is the strict one.
func checkClickFilterAddress(address string) error {
	if !clickFilterAddressPattern.MatchString(address) {
		return validationError("%q is not an IP address or a CIDR block, such as 203.0.113.7 or 203.0.113.0/24", address)
	}
	return nil
}

// clickFilterPath validates --user-id, builds the client and answers the
// account's /click-filter path: --user-id's, or the key's own user's.
func clickFilterPath(cmd *cobra.Command) (string, *api.Client, error) {
	userID := ""
	if cmd.Flags().Changed("user_id") {
		raw, _ := cmd.Flags().GetString("user_id")
		id, err := validateID(raw)
		if err != nil {
			return "", nil, err
		}
		userID = id
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return "", nil, err
	}
	if userID == "" {
		p, err := fetchPrincipal(c)
		if err != nil {
			return "", nil, withHint(err, "Pass --user-id to name the account.")
		}
		userID = fmt.Sprint(p.UserID)
	}
	return "users/" + url.PathEscape(userID) + "/click-filter", c, nil
}

// renderClickFilter shows the settings, then the list as a table; --json,
// --ndjson, --csv and --quiet get the answer as the API gave it.
func renderClickFilter(data []byte) error {
	if jsonOutput || ndjsonOutput || quietOutput || csvOutput {
		render(data)
		return nil
	}
	var answer struct {
		Data map[string]json.RawMessage `json:"data"`
	}
	if err := json.Unmarshal(data, &answer); err != nil {
		return fmt.Errorf("reading the click filter: %w", err)
	}
	settings := map[string]json.RawMessage{}
	for _, key := range []string{"sign_in_addresses", "crawler_ranges", "repeat_visits", "repeat_window"} {
		if v, ok := answer.Data[key]; ok {
			settings[key] = v
		}
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": settings})
	if err != nil {
		return fmt.Errorf("encoding the click filter settings: %w", err)
	}
	render(encoded)
	var list []json.RawMessage
	if raw, ok := answer.Data["entries"]; ok {
		if err := json.Unmarshal(raw, &list); err != nil {
			return fmt.Errorf("reading the address list: %w", err)
		}
	}
	if len(list) == 0 {
		return nil
	}
	encoded, err = json.Marshal(map[string]interface{}{"data": list})
	if err != nil {
		return fmt.Errorf("encoding the address list: %w", err)
	}
	fmt.Println()
	render(encoded)
	return nil
}

func init() {
	for _, c := range []*cobra.Command{
		clickFilterGetCmd, clickFilterSetCmd, clickFilterListCmd, clickFilterListAddCmd,
		clickFilterListUpdateCmd, clickFilterListRemoveCmd, clickFilterTestCmd,
	} {
		c.Flags().String("user_id", "", "Account whose click filter this is (default: the API key's own user)")
	}
	for _, f := range []struct{ name, desc string }{
		{"sign_in_addresses", "1 filters clicks from an address a user of this install last signed in from, 0 counts them"},
		{"crawler_ranges", "1 filters clicks from the built-in search engine crawler ranges, 0 counts them"},
		{"repeat_visits", "1 filters a repeat click from one address within the repeat window, 0 counts it"},
	} {
		clickFilterSetCmd.Flags().String(f.name, "", f.desc)
		enumFlag(clickFilterSetCmd, f.name, newEnum(binaryValues))
	}
	clickFilterSetCmd.Flags().String("repeat_window", "", "Repeat window ({values}); 24h lasts until the daily cleanup")
	enumFlag(clickFilterSetCmd, "repeat_window", newEnum(clickFilterWindows))

	clickFilterListAddCmd.Flags().String("action", "", "Its clicks are always counted or always filtered")
	enumFlag(clickFilterListAddCmd, "action", newEnum(clickFilterActions))
	clickFilterListAddCmd.Flags().String("note", "", "What it is, up to 255 characters")

	clickFilterListUpdateCmd.Flags().String("address", "", "New address or CIDR block")
	clickFilterListUpdateCmd.Flags().String("action", "", "count or filter")
	enumFlag(clickFilterListUpdateCmd, "action", newEnum(clickFilterActions))
	clickFilterListUpdateCmd.Flags().String("note", "", "New note (\"\" clears it)")
	allowEmpty(clickFilterListUpdateCmd, "note")

	registerDeleteFlags(clickFilterListRemoveCmd, "entry")

	clickFilterListCmd.AddCommand(clickFilterListAddCmd, clickFilterListUpdateCmd, clickFilterListRemoveCmd)
	clickFilterCmd.AddCommand(clickFilterGetCmd, clickFilterSetCmd, clickFilterListCmd, clickFilterTestCmd)
	rootCmd.AddCommand(clickFilterCmd)
}
