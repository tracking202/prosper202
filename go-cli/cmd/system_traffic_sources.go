package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// The traffic sources Setup › Traffic Sources' rename moved out of their
// accounts' user before 1.9.79, over GET /system/traffic-source-owners and
// POST /system/traffic-source-owners/repair:
//
//	p202 system traffic-source-owners show
//	p202 system traffic-source-owners repair <ppc_network_id> [--to-user-id N]
//	p202 system traffic-source-owners repair --all
//
// The server lists, and repairs, only on an Admin or Super user key whose role
// has access_to_settings; a repair also needs a key whose user may act on both
// users (Account › Users' rule). Nothing is moved by itself: repair reads the
// report first, says what it would move, and asks (--force skips the question).

const tsoNeeds = "Needs an Admin or Super user key whose role has access_to_settings (as Account › Settings asks)"

type tsoParty struct {
	UserID             int     `json:"user_id"`
	UserName           *string `json:"user_name"`
	UserDeleted        *bool   `json:"user_deleted"`
	UserActive         *bool   `json:"user_active"`
	PpcAccounts        int     `json:"ppc_accounts"`
	RemovedPpcAccounts int     `json:"removed_ppc_accounts"`
	Clicks             int64   `json:"clicks"`
	Conversions        int64   `json:"conversions"`
}

type tsoEntry struct {
	PpcNetworkID   int        `json:"ppc_network_id"`
	PpcNetworkName string     `json:"ppc_network_name"`
	Removed        bool       `json:"removed"`
	Owner          tsoParty   `json:"owner"`
	NamedBy        []tsoParty `json:"named_by"`
	RepairTo       *int       `json:"repair_to_user_id"`
	Ambiguous      []string   `json:"ambiguous"`
}

// tsoReport is GET /system/traffic-source-owners, read strictly: a missing
// list is a malformed answer, never "nothing to repair".
type tsoReport struct {
	Data struct {
		Sources []tsoEntry `json:"sources"`
	} `json:"data"`
}

type tsoRepaired struct {
	PpcNetworkID int    `json:"ppc_network_id"`
	Name         string `json:"ppc_network_name"`
	Removed      bool   `json:"removed"`
	From         int    `json:"from_user_id"`
	To           int    `json:"to_user_id"`
}

type tsoSkipped struct {
	PpcNetworkID int    `json:"ppc_network_id"`
	Reason       string `json:"reason"`
	Message      string `json:"message"`
}

type tsoRepairAnswer struct {
	Data struct {
		Repaired []tsoRepaired `json:"repaired"`
		Skipped  []tsoSkipped  `json:"skipped"`
	} `json:"data"`
}

func tsoError(err error) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 404 {
		return withHint(err, "This server has no traffic source report (capabilities features.traffic_source_owners): upgrade Prosper202 to 1.9.79 or later.")
	}
	return err
}

func readTsoReport(c *api.Client) ([]byte, tsoReport, error) {
	var report tsoReport
	data, err := c.Get("system/traffic-source-owners", nil)
	if err != nil {
		return nil, report, tsoError(err)
	}
	if err := json.Unmarshal(data, &report); err != nil || report.Data.Sources == nil {
		if err == nil {
			err = errors.New("sources is missing")
		}
		return nil, report, systemMalformed("reading the traffic source report", err)
	}
	return data, report, nil
}

func tsoUser(p tsoParty) string {
	if p.UserName == nil {
		return fmt.Sprintf("user %d (no such user)", p.UserID)
	}
	switch {
	case p.UserDeleted != nil && *p.UserDeleted:
		return fmt.Sprintf("user %d (%s, deleted)", p.UserID, *p.UserName)
	case p.UserActive != nil && !*p.UserActive:
		return fmt.Sprintf("user %d (%s, switched off)", p.UserID, *p.UserName)
	}
	return fmt.Sprintf("user %d (%s)", p.UserID, *p.UserName)
}

func tsoAccounts(p tsoParty) string {
	s := fmt.Sprintf("%d traffic source account(s)", p.PpcAccounts+p.RemovedPpcAccounts)
	if p.RemovedPpcAccounts > 0 {
		s += fmt.Sprintf(", %d removed", p.RemovedPpcAccounts)
	}
	return s + fmt.Sprintf(", %d click(s), %d conversion(s)", p.Clicks, p.Conversions)
}

// tsoPlan is the sentence that says what a repair of e moves.
func tsoPlan(e tsoEntry, to int) string {
	var target tsoParty
	for _, p := range e.NamedBy {
		if p.UserID == to {
			target = p
		}
	}
	removed := ""
	if e.Removed {
		removed = " It is removed, and stays removed."
	}
	return fmt.Sprintf("Traffic source %d (%q) is in %s's account; %s of %s name it. This moves it to %s.%s",
		e.PpcNetworkID, e.PpcNetworkName, tsoUser(e.Owner), tsoAccounts(target), tsoUser(target), tsoUser(target), removed)
}

func tsoUserIDs(parties []tsoParty) string {
	ids := make([]string, 0, len(parties))
	for _, p := range parties {
		ids = append(ids, strconv.Itoa(p.UserID))
	}
	return strings.Join(ids, ", ")
}

var systemTrafficSourceOwnersCmd = &cobra.Command{
	Use:   "traffic-source-owners",
	Short: "Traffic sources in one user's account that another user's traffic source accounts name (taken before 1.9.79): show, repair",
	Long: "Before 1.9.79, renaming a traffic source on Setup › Traffic Sources moved it into the account of\n" +
		"the user who saved the rename, whoever's it was; its traffic source accounts stayed behind. `show`\n" +
		"lists every source other users' accounts name, with the counts on each user's accounts; `repair`\n" +
		"moves one back, or every one the report names a user for. Nothing is moved by itself: the same rows\n" +
		"also come from accounts the API put on another user's source before 1.9.77, or a move made on\n" +
		"purpose, and the report marks those it cannot tell apart (ambiguous).\n\n" + tsoNeeds + ".",
}

var systemTrafficSourceOwnersShowCmd = &cobra.Command{
	Use:   "show",
	Short: "List the traffic sources other users' accounts name: who has each now, whose accounts name it, and what a repair would do",
	Long: "One row per source: the user it is in now, each user whose traffic source accounts name it (with\n" +
		"their accounts, clicks and conversions), repair_to (the user `repair` moves it to without\n" +
		"--to-user-id; blank when ambiguous) and why it is ambiguous: owner_has_accounts (its own user has\n" +
		"accounts on it too), several_users, saved_after_rename (an account was saved after the source,\n" +
		"or in its second: the shape the API made before 1.9.77), no_such_user, deleted_user (a source\n" +
		"never moves into a deleted user's account; a switched-off user's is shown and still repaired).\n" +
		"--json carries the account ids too.\n\n" +
		tsoNeeds + ", and system:read.",
	Example: "  p202 system traffic-source-owners show\n  p202 system traffic-source-owners show --json",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, report, err := readTsoReport(c)
		if err != nil {
			return err
		}
		if jsonOutput || ndjsonOutput {
			render(data)
			return nil
		}
		rows := make([]map[string]interface{}, 0, len(report.Data.Sources))
		for _, e := range report.Data.Sources {
			named := make([]string, 0, len(e.NamedBy))
			for _, p := range e.NamedBy {
				named = append(named, tsoUser(p)+": "+tsoAccounts(p))
			}
			repairTo := ""
			if e.RepairTo != nil {
				repairTo = strconv.Itoa(*e.RepairTo)
			}
			rows = append(rows, map[string]interface{}{
				"id":        e.PpcNetworkID,
				"name":      e.PpcNetworkName,
				"now_in":    tsoUser(e.Owner),
				"named_by":  strings.Join(named, "; "),
				"repair_to": repairTo,
				"ambiguous": strings.Join(e.Ambiguous, ", "),
			})
		}
		if len(rows) == 0 {
			output.Success("No traffic source is named by another user's traffic source accounts.")
			return nil
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": rows})
		if err != nil {
			return fmt.Errorf("encoding the report: %w", err)
		}
		render(encoded)
		return nil
	},
}

func runTsoRepair(cmd *cobra.Command, args []string) error {
	if err := refuseStagedSystem(cmd); err != nil {
		return err
	}
	all, _ := cmd.Flags().GetBool("all")
	force, _ := cmd.Flags().GetBool("force")
	toUser := 0
	if cmd.Flags().Changed("to-user-id") {
		v, _ := cmd.Flags().GetString("to-user-id")
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 16777215 || strconv.Itoa(n) != v {
			return validationError("--to-user-id must be a user id, a whole number from 1 to 16777215, got %q", v).
				WithHint("`p202 system traffic-source-owners show` lists the users whose accounts name each source.")
		}
		toUser = n
	}
	if all && (len(args) > 0 || toUser != 0) {
		return validationError("--all repairs every source the report names a user for, and takes no source id or --to-user-id").
			WithHint("Repair an ambiguous source by itself: `p202 system traffic-source-owners repair <ppc_network_id> --to-user-id <user_id>`.")
	}
	if !all && len(args) != 1 {
		return validationError("name the traffic source to move back (its ppc_network_id), or pass --all").
			WithHint("`p202 system traffic-source-owners show` lists them.")
	}
	sourceID := 0
	if !all {
		n, err := strconv.Atoi(args[0])
		if err != nil || n < 1 || n > 16777215 || strconv.Itoa(n) != args[0] {
			return validationError("the traffic source id must be a whole number from 1 to 16777215, got %q", args[0]).
				WithHint("`p202 system traffic-source-owners show` lists the sources by id.")
		}
		sourceID = n
	}

	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	_, report, err := readTsoReport(c)
	if err != nil {
		return err
	}

	if all {
		return runTsoRepairAll(cmd, c, report, force)
	}

	var entry *tsoEntry
	for i := range report.Data.Sources {
		if report.Data.Sources[i].PpcNetworkID == sourceID {
			entry = &report.Data.Sources[i]
		}
	}
	if entry == nil {
		return validationError("traffic source %d is not in the report: no other user's traffic source account names it, or there is no such source. Nothing was sent", sourceID).
			WithHint("`p202 system traffic-source-owners show` lists the sources a repair can move.")
	}
	to := toUser
	body := map[string]interface{}{"ppc_network_id": sourceID}
	if toUser != 0 {
		body["to_user_id"] = toUser
		var named *tsoParty
		for i := range entry.NamedBy {
			if entry.NamedBy[i].UserID == toUser {
				named = &entry.NamedBy[i]
			}
		}
		if named == nil {
			return validationError("no traffic source account of user %d names traffic source %d; it goes back only to one of users %s. Nothing was sent", toUser, sourceID, tsoUserIDs(entry.NamedBy)).
				WithHint("Pass --to-user-id with one of the users `p202 system traffic-source-owners show` lists under named_by.")
		}
		if named.UserDeleted != nil && *named.UserDeleted {
			return validationError("user %d is deleted, and a traffic source is never moved into a deleted user's account. Nothing was sent", toUser).
				WithHint("Leave the source where it is, or name another user `p202 system traffic-source-owners show` lists under named_by.")
		}
	} else {
		if entry.RepairTo == nil {
			return validationError("traffic source %d is ambiguous (%s), so it moves only to a user you name: one of users %s. Nothing was sent",
				sourceID, strings.Join(entry.Ambiguous, ", "), tsoUserIDs(entry.NamedBy)).
				WithHint("Check which user it belongs to, then run it again with --to-user-id <user_id>.")
		}
		to = *entry.RepairTo
	}

	if !force {
		fmt.Fprintln(os.Stderr, tsoPlan(*entry, to))
		ok, err := confirmAction(cmd, "Move traffic source %d to user %d?", sourceID, to)
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	data, err := c.Post("system/traffic-source-owners/repair", body)
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && apiErr.Status == 422 {
			return withHint(err, "Nothing was moved. Run `p202 system traffic-source-owners show` to see the source as it is now.")
		}
		// Auth::requireMayManageUser(): the key is an admin's, but one of the
		// two users is the Super user or holds an admin role.
		if errors.As(err, &apiErr) && apiErr.Status == 403 && (strings.Contains(apiErr.Message, "Super user") || strings.Contains(apiErr.Message, "add_edit_delete_admin")) {
			return withHint(err, "Nothing was moved: one of the two users is the Super user or an Admin, and only the Super user (user 1) moves a source into or out of those accounts. Run it with user 1's key.")
		}
		return tsoError(err)
	}
	var answer tsoRepairAnswer
	if err := json.Unmarshal(data, &answer); err != nil || len(answer.Data.Repaired) != 1 {
		if err == nil {
			err = errors.New("repaired does not name the one source")
		}
		return systemMalformed("moving the traffic source", err)
	}
	render(data)
	moved := answer.Data.Repaired[0]
	output.Success("Moved traffic source %d (%q) from user %d to user %d. Its accounts' clicks are queued for the cron job's report rollup.",
		moved.PpcNetworkID, moved.Name, moved.From, moved.To)
	return nil
}

func runTsoRepairAll(cmd *cobra.Command, c *api.Client, report tsoReport, force bool) error {
	shown := map[int]bool{}
	for _, e := range report.Data.Sources {
		if e.RepairTo != nil {
			shown[e.PpcNetworkID] = true
		}
	}
	ambiguous := len(report.Data.Sources) - len(shown)
	if len(shown) == 0 {
		output.Success("No traffic source can be moved without naming its user (%d ambiguous). Nothing was sent.", ambiguous)
		return nil
	}
	if !force {
		for _, e := range report.Data.Sources {
			if e.RepairTo != nil {
				fmt.Fprintln(os.Stderr, tsoPlan(e, *e.RepairTo))
			}
		}
		if ambiguous > 0 {
			fmt.Fprintf(os.Stderr, "%d ambiguous source(s) are left as they are; repair each by itself with --to-user-id.\n", ambiguous)
		}
		ok, err := confirmAction(cmd, "Move these %d traffic source(s)?", len(shown))
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	data, err := c.Post("system/traffic-source-owners/repair", map[string]interface{}{"all": true})
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && apiErr.Status >= 500 {
			return withHint(err, "Some sources may have been moved before the failure (the message names them). `p202 system traffic-source-owners show` lists what remains; running this again moves only that.")
		}
		return tsoError(err)
	}
	var answer tsoRepairAnswer
	if err := json.Unmarshal(data, &answer); err != nil || answer.Data.Repaired == nil || answer.Data.Skipped == nil {
		if err == nil {
			err = errors.New("repaired or skipped is missing")
		}
		return systemMalformed("moving the traffic sources", err)
	}
	render(data)
	var unshown []string
	for _, r := range answer.Data.Repaired {
		if !shown[r.PpcNetworkID] {
			unshown = append(unshown, strconv.Itoa(r.PpcNetworkID))
		}
	}
	output.Success("Moved %d traffic source(s); %d left as they are (skipped lists why).", len(answer.Data.Repaired), len(answer.Data.Skipped))
	if len(unshown) > 0 {
		output.Success("Moved without being listed above, because the report named a user for them by the time of the move: %s.", strings.Join(unshown, ", "))
	}
	return nil
}

var systemTrafficSourceOwnersRepairCmd = &cobra.Command{
	Use:   "repair <ppc_network_id> | --all",
	Short: "Move a traffic source back to the user whose traffic source accounts name it (asks first)",
	Long: "Moves the source to the user `show` names under repair_to; --to-user-id moves it to another user\n" +
		"whose accounts name it, the only way to move an ambiguous source. --all moves every source the\n" +
		"report names a user for, and leaves the ambiguous ones. It reads the report first, says what it\n" +
		"would move and asks (--force skips the question). Only the source's user changes: its name stays,\n" +
		"and a removed source stays removed. Its accounts' clicks are queued for the cron job's report\n" +
		"rollup. Each move is logged on the server. Cannot be staged.\n\n" +
		tsoNeeds + ", whose user may act on both users (Account › Users' rule), and system:write.",
	Example: "  p202 system traffic-source-owners repair 12\n" +
		"  p202 system traffic-source-owners repair 12 --to-user-id 3\n" +
		"  p202 system traffic-source-owners repair --all --force",
	Args: cobra.MaximumNArgs(1),
	RunE: runTsoRepair,
}

func init() {
	systemTrafficSourceOwnersRepairCmd.Flags().String("to-user-id", "", "Move it to this user, one whose traffic source accounts name it")
	systemTrafficSourceOwnersRepairCmd.Flags().Bool("all", false, "Move every source the report names a user for")
	systemTrafficSourceOwnersRepairCmd.Flags().BoolP("force", "f", false, "Move it without asking")
	systemTrafficSourceOwnersCmd.AddCommand(systemTrafficSourceOwnersShowCmd, systemTrafficSourceOwnersRepairCmd)
	systemCmd.AddCommand(systemTrafficSourceOwnersCmd)
}
