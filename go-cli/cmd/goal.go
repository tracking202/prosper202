package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// Goals (plan §2.2, §5.5): named, versioned outcomes owned by a campaign, an
// app registration or the account. An edit is a new version; conversions keep
// the version that produced them. /goals on the server.

var goalCmd = &cobra.Command{
	Use:     "goal",
	Aliases: []string{"goals"},
	Short:   "Goals: what counts as a conversion, versioned, and which campaigns pay for it",
	Long: "Define what counts (an install, an event with conditions, the Nth occurrence, a running\n" +
		"sum, a step after another goal, a window), and which campaigns pay for it and how much.\n\n" +
		"  p202 goal create --campaign-id 7 --name \"Sale\" --event sale --value 20\n" +
		"  p202 goal create --registration-id 3 --name \"Level 3\" --event level_reached --where \"level gte 3\"\n" +
		"  p202 goal campaign set 12 7 --payout 4.00\n" +
		"  p202 goal reevaluate 12            # preview; add --apply to apply\n\n" +
		"The full definition format is in documentation/api/22-goals.md; --definition takes it as JSON.",
}

// goalDefinitionFlags are the quick flags that build a definition. One list,
// so create, update and validate accept the same ones and a flag cannot be
// registered without being read.
var goalDefinitionFlags = []string{
	"name", "event", "install", "where", "count", "sum-prop", "sum-gte", "after",
	"within-days", "within-from", "no-window", "repeat", "repeat-max", "value", "value-from-property", "no-value",
}

var goalPredicateOps = []string{"eq", "neq", "gt", "gte", "lt", "lte", "in", "exists"}

func registerGoalDefinitionFlags(cmd *cobra.Command) {
	cmd.Flags().String("definition", "", "The whole definition as JSON (exclusive with the quick flags)")
	cmd.Flags().StringP("file", "F", "", "Read the definition JSON from a file (- for stdin)")
	cmd.Flags().String("name", "", "Goal name (unique per owner)")
	cmd.Flags().String("event", "", "Trigger: the event name that reaches the goal")
	cmd.Flags().Bool("install", false, "Trigger: the install itself")
	cmd.Flags().String("where", "", "Conditions on the event, \"<prop> <op> <value>\", several separated by ; (ANDed); ops: "+strings.Join(goalPredicateOps, ", "))
	cmd.Flags().String("count", "", "Reached on the Nth matching event (default 1)")
	cmd.Flags().String("sum-prop", "", "Reached when the running sum of this property (or $revenue) reaches --sum-gte")
	cmd.Flags().String("sum-gte", "", "The running-sum threshold")
	cmd.Flags().String("after", "", "Goal ids that must be reached first, comma-separated")
	cmd.Flags().String("within-days", "", "Only events within this many days of --within-from")
	cmd.Flags().String("within-from", "", "Window anchor: install or click")
	cmd.Flags().Bool("no-window", false, "Remove the window (update)")
	cmd.Flags().String("repeat", "", "once (default) or each")
	cmd.Flags().String("repeat-max", "", "With --repeat each: the most times it can be reached (1-10000; required for a --sum-prop goal)")
	cmd.Flags().String("value", "", "Fixed value of reaching the goal, e.g. 4.00")
	cmd.Flags().String("value-from-property", "", "Value from an event property (default the event's revenue)")
	cmd.Flags().Lookup("value-from-property").NoOptDefVal = "$revenue"
	cmd.Flags().Bool("no-value", false, "Tracked, not paid (value none)")
}

func goalQuickFlagsUsed(cmd *cobra.Command) []string {
	var used []string
	for _, f := range goalDefinitionFlags {
		if cmd.Flags().Changed(f) {
			used = append(used, "--"+f)
		}
	}
	return used
}

// readGoalDefinitionJSON returns the definition given by --definition or
// --file, or nil when neither was set. A value that is not a JSON object is
// refused here, naming the flag, rather than sent for the server to reject.
func readGoalDefinitionJSON(cmd *cobra.Command) (map[string]interface{}, error) {
	raw, _ := cmd.Flags().GetString("definition")
	file, _ := cmd.Flags().GetString("file")
	if raw != "" && file != "" {
		return nil, validationError("--definition and --file are mutually exclusive")
	}
	source := "--definition"
	if file != "" {
		source = "--file"
		var data []byte
		var err error
		if file == "-" {
			data, err = io.ReadAll(os.Stdin)
		} else {
			data, err = os.ReadFile(file)
		}
		if err != nil {
			return nil, validationError("reading %s: %v", file, err).WithHint("Pass a readable path, or - to read the definition from stdin.")
		}
		raw = string(data)
	}
	if raw == "" {
		return nil, nil
	}
	var def map[string]interface{}
	dec := json.NewDecoder(strings.NewReader(raw))
	dec.UseNumber()
	if err := dec.Decode(&def); err != nil || def == nil {
		return nil, validationError("%s is not a JSON object", source).
			WithHint("A definition is an object, e.g. {\"name\":\"Sale\",\"trigger\":{\"event\":\"sale\"},\"value\":{\"type\":\"fixed\",\"amount\":\"20.00\"}} — see documentation/api/22-goals.md.")
	}
	return def, nil
}

// parseGoalWhere reads one --where "<prop> <op> <value>". The value is read as
// JSON when it is JSON (3, 2.5, true, "x", ["US","CA"]) and as a string
// otherwise; for "in", a bare a,b,c is a list of strings.
func parseGoalWhere(spec string) (map[string]interface{}, error) {
	spec = strings.TrimSpace(spec)
	fields := strings.Fields(spec)
	if len(fields) < 2 {
		return nil, validationError("--where %q needs a property and an operator", spec).
			WithHint("Write \"<prop> <op> <value>\", e.g. --where \"level gte 3\" or --where \"coupon exists\"; ops: %s.", strings.Join(goalPredicateOps, ", "))
	}
	prop, op := fields[0], strings.ToLower(fields[1])
	known := false
	for _, o := range goalPredicateOps {
		known = known || o == op
	}
	if !known {
		return nil, validationError("--where %q: unknown operator %q; use one of: %s", spec, op, strings.Join(goalPredicateOps, ", "))
	}
	predicate := map[string]interface{}{"prop": prop, "op": op}
	rest := strings.TrimSpace(spec[len(fields[0]):])
	rest = strings.TrimSpace(rest[len(fields[1]):])
	if op == "exists" {
		if rest != "" {
			return nil, validationError("--where %q: exists takes no value", spec)
		}
		return predicate, nil
	}
	if rest == "" {
		return nil, validationError("--where %q: %s needs a value", spec, op).WithHint("e.g. --where \"%s %s 3\".", prop, op)
	}
	var value interface{}
	dec := json.NewDecoder(strings.NewReader(rest))
	dec.UseNumber()
	if err := dec.Decode(&value); err != nil || dec.More() {
		value = rest
		if op == "in" {
			var list []interface{}
			for _, part := range strings.Split(rest, ",") {
				list = append(list, strings.TrimSpace(part))
			}
			value = list
		}
	}
	predicate["value"] = value
	return predicate, nil
}

func goalInt(cmd *cobra.Command, flag string) (interface{}, error) {
	v, _ := cmd.Flags().GetString(flag)
	n, err := strconv.Atoi(strings.TrimSpace(v))
	if err != nil || n < 1 {
		return nil, validationError("--%s must be a whole number of at least 1, got %q", flag, v)
	}
	return n, nil
}

// applyGoalQuickFlags writes the quick flags that were set into def (a new
// definition, or the current one on update). Each flag replaces its part of
// the definition and leaves the rest as it was.
func applyGoalQuickFlags(cmd *cobra.Command, def map[string]interface{}) error {
	changed := cmd.Flags().Changed
	if changed("name") {
		v, _ := cmd.Flags().GetString("name")
		def["name"] = v
	}

	if changed("install") && (changed("event") || changed("where")) {
		return validationError("--install is the install itself; it takes no --event or --where")
	}
	if changed("install") {
		def["trigger"] = map[string]interface{}{"install": true}
	} else if changed("event") || changed("where") {
		trigger, _ := def["trigger"].(map[string]interface{})
		next := map[string]interface{}{}
		if trigger != nil {
			if ev, ok := trigger["event"]; ok {
				next["event"] = ev
			}
			if w, ok := trigger["where"]; ok {
				next["where"] = w
			}
		}
		if changed("event") {
			v, _ := cmd.Flags().GetString("event")
			next["event"] = v
		}
		if changed("where") {
			raw, _ := cmd.Flags().GetString("where")
			where := []interface{}{}
			for _, spec := range strings.Split(raw, ";") {
				if strings.TrimSpace(spec) == "" {
					continue
				}
				p, err := parseGoalWhere(spec)
				if err != nil {
					return err
				}
				where = append(where, p)
			}
			next["where"] = where
		}
		def["trigger"] = next
	}

	if changed("count") && (changed("sum-prop") || changed("sum-gte")) {
		return validationError("--count and --sum-prop/--sum-gte are exclusive: a goal counts events or sums a property")
	}
	if changed("count") {
		n, err := goalInt(cmd, "count")
		if err != nil {
			return err
		}
		def["threshold"] = map[string]interface{}{"count": n}
	}
	if changed("sum-prop") != changed("sum-gte") {
		return validationError("--sum-prop and --sum-gte go together").WithHint("e.g. --sum-prop amount --sum-gte 20 (reached when the purchases add up to 20).")
	}
	if changed("sum-prop") {
		prop, _ := cmd.Flags().GetString("sum-prop")
		gte, _ := cmd.Flags().GetString("sum-gte")
		def["threshold"] = map[string]interface{}{"sum": map[string]interface{}{"prop": prop, "gte": gte}}
	}

	if changed("after") {
		raw, _ := cmd.Flags().GetString("after")
		after := []interface{}{}
		for _, part := range strings.Split(raw, ",") {
			part = strings.TrimSpace(part)
			if part == "" {
				continue
			}
			n, err := strconv.Atoi(part)
			if err != nil || n < 1 {
				return validationError("--after takes goal ids, comma-separated; %q is not one", part).WithHint("`p202 goal list` shows the ids.")
			}
			after = append(after, n)
		}
		def["after"] = after
	}

	if changed("no-window") && (changed("within-days") || changed("within-from")) {
		return validationError("--no-window removes the window; it takes no --within-days or --within-from")
	}
	if changed("no-window") {
		def["within"] = nil
	}
	if changed("within-days") != changed("within-from") {
		return validationError("--within-days and --within-from go together").WithHint("e.g. --within-days 7 --within-from install.")
	}
	if changed("within-days") {
		days, err := goalInt(cmd, "within-days")
		if err != nil {
			return err
		}
		from, _ := cmd.Flags().GetString("within-from")
		if from != "install" && from != "click" {
			return validationError("--within-from must be one of: install, click, got %q", from)
		}
		def["within"] = map[string]interface{}{"days": days, "from": from}
	}

	if changed("repeat-max") && !changed("repeat") {
		if r, ok := def["repeat"].(map[string]interface{}); !ok || r["mode"] != "each" {
			return validationError("--repeat-max needs --repeat each")
		}
	}
	if changed("repeat") || changed("repeat-max") {
		mode, _ := cmd.Flags().GetString("repeat")
		if !changed("repeat") {
			mode = "each"
		}
		if mode != "once" && mode != "each" {
			return validationError("--repeat must be one of: once, each, got %q", mode)
		}
		repeat := map[string]interface{}{"mode": mode}
		if changed("repeat-max") {
			if mode != "each" {
				return validationError("--repeat-max needs --repeat each")
			}
			n, err := goalInt(cmd, "repeat-max")
			if err != nil {
				return err
			}
			repeat["max"] = n
		}
		def["repeat"] = repeat
	}

	valueFlags := 0
	for _, f := range []string{"value", "value-from-property", "no-value"} {
		if changed(f) {
			valueFlags++
		}
	}
	if valueFlags > 1 {
		return validationError("--value, --value-from-property and --no-value are exclusive")
	}
	switch {
	case changed("value"):
		v, _ := cmd.Flags().GetString("value")
		def["value"] = map[string]interface{}{"type": "fixed", "amount": v}
	case changed("value-from-property"):
		v, _ := cmd.Flags().GetString("value-from-property")
		def["value"] = map[string]interface{}{"type": "from_property", "prop": v}
	case changed("no-value"):
		def["value"] = map[string]interface{}{"type": "none"}
	}
	return requireSumRepeatMax(def)
}

// requireSumRepeatMax refuses a sum threshold that repeats with no max, the
// way the server does (definition.repeat.max): one event can cross many
// multiples of a sum, so the number of times it is reached must be bounded.
// Checked on the definition the flags produced, so an update that makes a
// repeating goal a sum (or a sum goal repeat) is caught too.
func requireSumRepeatMax(def map[string]interface{}) error {
	threshold, _ := def["threshold"].(map[string]interface{})
	if _, isSum := threshold["sum"]; !isSum {
		return nil
	}
	repeat, _ := def["repeat"].(map[string]interface{})
	if repeat["mode"] != "each" {
		return nil
	}
	if limit, ok := repeat["max"]; ok && limit != nil {
		return nil
	}
	return validationError("a sum goal that repeats needs --repeat-max (1-10000): one event can cross many multiples of --sum-gte, so the times it is reached must be bounded").
		WithHint("Add --repeat-max, e.g. --repeat each --repeat-max 12, or reach it once with --repeat once.")
}

// goalDefinitionFromFlags builds a new definition from --definition/--file
// or the quick flags; exactly one of the two ways.
func goalDefinitionFromFlags(cmd *cobra.Command) (map[string]interface{}, error) {
	def, err := readGoalDefinitionJSON(cmd)
	if err != nil {
		return nil, err
	}
	quick := goalQuickFlagsUsed(cmd)
	if def != nil && len(quick) > 0 {
		return nil, validationError("--definition/--file and the quick flags (%s) are exclusive", strings.Join(quick, ", ")).
			WithHint("Put everything in the JSON, or build the definition from the flags alone.")
	}
	if def != nil {
		return def, nil
	}
	if len(quick) == 0 {
		return nil, validationError("no definition given").
			WithHint("Pass --name with --event (or --install), e.g. `--name Sale --event sale --value 20`, or the whole definition with --definition/--file.")
	}
	def = map[string]interface{}{}
	if err := applyGoalQuickFlags(cmd, def); err != nil {
		return nil, err
	}
	if _, ok := def["name"]; !ok {
		return nil, validationError("required flag --name is missing").WithHint("Every goal has a name, unique per owner, e.g. --name \"Reached level 3\".")
	}
	if _, ok := def["trigger"]; !ok {
		return nil, validationError("a goal needs a trigger: --event <name> or --install").
			WithHint("e.g. --event purchase, optionally with --where \"amount gte 20\".")
	}
	return def, nil
}

// goalOwnerBody reads which owner a new goal belongs to: exactly one of
// --campaign-id, --registration-id and --account.
func goalOwnerBody(cmd *cobra.Command) (map[string]interface{}, error) {
	campaign, _ := cmd.Flags().GetString("campaign-id")
	registration, _ := cmd.Flags().GetString("registration-id")
	account, _ := cmd.Flags().GetBool("account")
	set := 0
	for _, on := range []bool{campaign != "", registration != "", account} {
		if on {
			set++
		}
	}
	if set != 1 {
		return nil, validationError("name the goal's owner with exactly one of --campaign-id, --registration-id, --account").
			WithHint("--campaign-id for a campaign's own goal (`p202 campaign list`), --registration-id for an app's (`p202 app list`), --account for goals every app of the account shares.")
	}
	body := map[string]interface{}{}
	switch {
	case campaign != "":
		n, err := strconv.Atoi(campaign)
		if err != nil || n < 1 {
			return nil, validationError("--campaign-id must be a campaign id, got %q", campaign).WithHint("`p202 campaign list` shows the ids.")
		}
		body["scope"], body["scope_id"] = "campaign", n
	case registration != "":
		n, err := strconv.Atoi(registration)
		if err != nil || n < 1 {
			return nil, validationError("--registration-id must be an app registration id, got %q", registration).WithHint("`p202 app list` shows the ids.")
		}
		body["scope"], body["scope_id"] = "registration", n
	default:
		body["scope"] = "account"
	}
	return body, nil
}

// goalPayoutBody reads the campaign terms flags shared by `goal create` (for
// a campaign goal) and `goal campaign set`.
func goalPayoutBody(cmd *cobra.Command, body map[string]interface{}) error {
	if cmd.Flags().Changed("payout") && cmd.Flags().Changed("clear-payout") {
		return validationError("--payout and --clear-payout are mutually exclusive")
	}
	if cmd.Flags().Changed("payout") {
		v, _ := cmd.Flags().GetString("payout")
		if _, err := strconv.ParseFloat(v, 64); err != nil {
			return validationError("--payout must be an amount, e.g. 4.00, got %q", v)
		}
		body["payout"] = v
	}
	if cmd.Flags().Changed("clear-payout") {
		body["payout"] = nil
	}
	if cmd.Flags().Changed("notify") {
		v, _ := cmd.Flags().GetString("notify")
		b, err := strconv.ParseBool(v)
		if err != nil {
			return validationError("--notify must be true or false, got %q", v)
		}
		body["notify_traffic_source"] = b
	}
	return nil
}

// hintGoalError names the command that produces the value a refused goal
// write needed; other failures keep their class hint.
func hintGoalError(err error) error {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 422 {
		return err
	}
	for field := range apiErr.FieldErrors {
		switch {
		case field == "scope_id":
			return withHint(err, "--campaign-id takes an id from `p202 campaign list`; --registration-id one from `p202 app list`.")
		case strings.HasPrefix(field, "definition.after"):
			return withHint(err, "--after names live goals of the same owner: `p202 goal list --campaign-id <id>` (or --registration-id / --account) shows them.")
		case strings.HasPrefix(field, "definition"):
			return withHint(err, "Fix the named part of the definition; `p202 goal validate` checks one without saving, and documentation/api/22-goals.md has the format.")
		case field == "campaign_id":
			return withHint(err, "A campaign's own goal belongs to that campaign; share one between campaigns with a --registration-id or --account goal.")
		}
	}
	return err
}

// ── Commands ────────────────────────────────────────────────────────

var goalListCmd = &cobra.Command{
	Use:   "list",
	Short: "List goals (filter by owner)",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		campaign, _ := cmd.Flags().GetString("campaign-id")
		registration, _ := cmd.Flags().GetString("registration-id")
		account, _ := cmd.Flags().GetBool("account")
		if (campaign != "" && registration != "") || (account && (campaign != "" || registration != "")) {
			return validationError("filter by one owner: --campaign-id, --registration-id or --account")
		}
		if campaign != "" {
			params["campaign_id"] = campaign
		}
		if registration != "" {
			params["registration_id"] = registration
		}
		if account {
			params["scope"] = "account"
		}
		if archived, _ := cmd.Flags().GetBool("include-archived"); archived {
			params["include_archived"] = "1"
		}
		return runPagedList(cmd, "goals", params)
	},
}

var goalGetCmd = newAppGetCmd("goals", "Get a goal: its current definition, the campaigns paying for it, its live outcome count")

var goalCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a goal for a campaign, an app registration or the account",
	Long: "Creates version 1 of a goal. Build the definition from the quick flags, or pass the\n" +
		"whole JSON with --definition/--file. A campaign goal with a value is payable on its\n" +
		"campaign straight away (--payout overrides the value there, --not-payable tracks it only).",
	RunE: func(cmd *cobra.Command, args []string) error {
		body, err := goalOwnerBody(cmd)
		if err != nil {
			return err
		}
		def, err := goalDefinitionFromFlags(cmd)
		if err != nil {
			return err
		}
		body["definition"] = def
		campaignTerms := cmd.Flags().Changed("payout") || cmd.Flags().Changed("notify") || cmd.Flags().Changed("not-payable")
		if campaignTerms && body["scope"] != "campaign" {
			return validationError("--payout, --notify and --not-payable apply to a campaign goal").
				WithHint("Create the goal, then `p202 goal campaign set <goal-id> <campaign-id> --payout …` for each campaign that pays for it.")
		}
		if notPayable, _ := cmd.Flags().GetBool("not-payable"); notPayable {
			if cmd.Flags().Changed("payout") || cmd.Flags().Changed("notify") {
				return validationError("--not-payable takes no --payout or --notify")
			}
			body["payable"] = false
		}
		if err := goalPayoutBody(cmd, body); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("goals", body, idemKey)
		if err != nil {
			return hintGoalError(err)
		}
		render(data)
		return nil
	},
}

var goalUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Edit a goal (a new version; conversions keep the version that produced them)",
	Long: "Replaces the goal's definition with a new version. --definition/--file give the whole\n" +
		"new definition; the quick flags change only their part of the current one. The same\n" +
		"definition again is not a new version. Past conversions keep their version until\n" +
		"`p202 goal reevaluate <id> --apply`.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		def, err := readGoalDefinitionJSON(cmd)
		if err != nil {
			return err
		}
		quick := goalQuickFlagsUsed(cmd)
		if def != nil && len(quick) > 0 {
			return validationError("--definition/--file and the quick flags (%s) are exclusive", strings.Join(quick, ", "))
		}
		if def == nil && len(quick) == 0 {
			return validationError("no fields specified; pass at least one flag to update").
				WithHint("Pass a quick flag (e.g. --value 5, --count 3, --where \"level gte 5\") or the whole definition with --definition/--file.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		if def == nil {
			current, err := c.Get("goals/"+args[0], nil)
			if err != nil {
				return err
			}
			var envelope struct {
				Data struct {
					Definition map[string]interface{} `json:"definition"`
				} `json:"data"`
			}
			if err := json.Unmarshal(current, &envelope); err != nil || envelope.Data.Definition == nil {
				return withHint(fmt.Errorf("reading goal %s: the server's response carries no definition to change", args[0]),
					"Pass the whole new definition with --definition or --file instead.")
			}
			def = envelope.Data.Definition
			if err := applyGoalQuickFlags(cmd, def); err != nil {
				return err
			}
		}
		data, err := c.Put("goals/"+args[0], map[string]interface{}{"definition": def})
		if err != nil {
			return hintGoalError(err)
		}
		render(data)
		return nil
	},
}

var goalDeleteCmd = &cobra.Command{
	Use:   "delete [id]",
	Short: "Archive a goal (its versions, outcomes and conversions are kept)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		return bulkOrSingleDelete(cmd, "goals", "goal")
	},
}

var goalVersionsCmd = &cobra.Command{
	Use:   "versions <id>",
	Short: "List every version of a goal",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("goals/"+args[0]+"/versions", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var goalOutcomesCmd = &cobra.Command{
	Use:   "outcomes <id>",
	Short: "List a goal's live outcomes (each time a click or install reached it)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("subject-type"); v != "" {
			if v != "click" && v != "install" {
				return validationError("--subject-type must be one of: click, install, got %q", v)
			}
			params["subject_type"] = v
		}
		if v, _ := cmd.Flags().GetString("subject-id"); v != "" {
			params["subject_id"] = v
		}
		if err := collectPagingFlags(cmd, params); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("goals/"+args[0]+"/outcomes", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var goalValidateCmd = &cobra.Command{
	Use:   "validate",
	Short: "Check a definition without saving it (answers the canonical form)",
	RunE: func(cmd *cobra.Command, args []string) error {
		def, err := goalDefinitionFromFlags(cmd)
		if err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Post("goals/validate", map[string]interface{}{"definition": def})
		if err != nil {
			return hintGoalError(err)
		}
		render(data)
		return nil
	},
}

var goalEvaluateCmd = &cobra.Command{
	Use:   "evaluate",
	Short: "Evaluate definitions against events without writing anything",
	Long: "Sends {\"goals\", \"subject\", \"events\"} — the format of the evaluator vectors in\n" +
		"tests/fixtures/app-sdk-contract/goals/ — and prints the outcomes the server's evaluator\n" +
		"reaches. Nothing is stored.",
	RunE: func(cmd *cobra.Command, args []string) error {
		file, _ := cmd.Flags().GetString("file")
		if file == "" {
			return validationError("required flag --file is missing").
				WithHint("Pass a JSON file with goals, subject and events (- for stdin); tests/fixtures/app-sdk-contract/goals/README.md has the format.")
		}
		var data []byte
		var err error
		if file == "-" {
			data, err = io.ReadAll(os.Stdin)
		} else {
			data, err = os.ReadFile(file)
		}
		if err != nil {
			return validationError("reading %s: %v", file, err)
		}
		var body map[string]interface{}
		dec := json.NewDecoder(strings.NewReader(string(data)))
		dec.UseNumber()
		if err := dec.Decode(&body); err != nil || body == nil {
			return validationError("%s is not a JSON object", file).
				WithHint("The body is {\"goals\": […], \"subject\": {…}, \"events\": […]}.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		out, err := c.Post("goals/evaluate", body)
		if err != nil {
			return err
		}
		render(out)
		return nil
	},
}

var goalReevaluateCmd = &cobra.Command{
	Use:   "reevaluate <id>",
	Short: "Re-evaluate past clicks or installs under a goal version (preview; --apply to apply)",
	Long: "Without --apply, shows per subject (a click, or an Android install with --subject-type\n" +
		"install) the outcomes that would be retired and written, and what happens to their\n" +
		"conversions (superseded by the new row, or deleted when the version no longer reaches\n" +
		"the goal). Goals that wait on this one (their definition's after, directly or through\n" +
		"another) are re-decided with it: listed in goals, each row names its goal_id.\n" +
		"With --apply, does it, one subject per transaction. At most --limit subjects per call;\n" +
		"pass the answer's next_after as --after to continue.",
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		for _, f := range []string{"version", "limit", "after"} {
			if v, _ := cmd.Flags().GetString(f); v != "" {
				n, err := strconv.Atoi(v)
				if err != nil || n < 0 || (f != "after" && n < 1) {
					return validationError("--%s must be a whole number, got %q", f, v)
				}
				params[f] = v
			}
		}
		subjectType, _ := cmd.Flags().GetString("subject-type")
		if subjectType != "" && subjectType != "click" && subjectType != "install" {
			return validationError("--subject-type must be one of: click, install; got %q", subjectType).
				WithHint("click re-evaluates a campaign goal's clicks; install re-evaluates Android installs (the default for registration and account goals).")
		}
		apply, _ := cmd.Flags().GetBool("apply")
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		var data []byte
		if apply {
			body := map[string]interface{}{}
			for k, v := range params {
				n, _ := strconv.Atoi(v)
				body[k] = n
			}
			if subjectType != "" {
				body["subject_type"] = subjectType
			}
			data, err = c.Post("goals/"+args[0]+"/reevaluation", body)
		} else {
			if subjectType != "" {
				params["subject_type"] = subjectType
			}
			data, err = c.Get("goals/"+args[0]+"/reevaluation", params)
		}
		if err != nil {
			var apiErr *api.APIError
			if errors.As(err, &apiErr) && apiErr.Status == 422 {
				if _, ok := apiErr.FieldErrors["version"]; ok {
					return withHint(err, "`p202 goal versions "+args[0]+"` lists the goal's versions.")
				}
			}
			return err
		}
		render(data)
		return nil
	},
}

// ── Campaign payouts ────────────────────────────────────────────────

var goalCampaignCmd = &cobra.Command{
	Use:     "campaign",
	Aliases: []string{"campaigns"},
	Short:   "Which campaigns pay for a goal, and how much",
}

var goalCampaignListCmd = &cobra.Command{
	Use:   "list <goal-id>",
	Short: "List the campaigns paying for a goal",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("goals/"+args[0]+"/campaigns", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var goalCampaignSetCmd = &cobra.Command{
	Use:   "set <goal-id> <campaign-id>",
	Short: "Make a campaign pay for a goal, or change its payout (default: the goal's own value)",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		body := map[string]interface{}{}
		if err := goalPayoutBody(cmd, body); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("goals/"+args[0]+"/campaigns/"+args[1], body)
		if err != nil {
			return hintGoalError(err)
		}
		render(data)
		return nil
	},
}

var goalCampaignRemoveCmd = &cobra.Command{
	Use:   "remove <goal-id> <campaign-id>",
	Short: "Stop a campaign paying for a goal (its outcomes there are tracked, not paid, from now on)",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		endpoint := "goals/" + args[0] + "/campaigns"
		if dryRun, _ := cmd.Flags().GetBool("dry-run"); dryRun {
			return renderDeletePreviews(c, endpoint, []string{args[1]})
		}
		if api.StagedMode() {
			return stageDeletes(c, endpoint, []string{args[1]})
		}
		if force, _ := cmd.Flags().GetBool("force"); !force && !confirmPrompt("Stop campaign %s paying for goal %s?", args[1], args[0]) {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
		if err := c.Delete(endpoint + "/" + args[1]); err != nil {
			return err
		}
		output.Success("Campaign %s no longer pays for goal %s.", args[1], args[0])
		return nil
	},
}

func init() {
	registerPagedListFlags(goalListCmd)
	goalListCmd.Flags().String("campaign-id", "", "Only this campaign's own goals")
	goalListCmd.Flags().String("registration-id", "", "Only this app registration's goals")
	goalListCmd.Flags().Bool("account", false, "Only the account's goals")
	goalListCmd.Flags().Bool("include-archived", false, "Include archived goals")

	goalCreateCmd.Flags().String("campaign-id", "", "Owner: this campaign (`p202 campaign list`)")
	goalCreateCmd.Flags().String("registration-id", "", "Owner: this app registration (`p202 app list`)")
	goalCreateCmd.Flags().Bool("account", false, "Owner: the account (shared by every app)")
	goalCreateCmd.Flags().Bool("not-payable", false, "Campaign goal: track it, do not pay for it")
	for _, cmd := range []*cobra.Command{goalCreateCmd, goalCampaignSetCmd} {
		cmd.Flags().String("payout", "", "Campaign payout for reaching the goal (default: the goal's own value)")
		cmd.Flags().String("notify", "", "true/false: tell the traffic source when the goal is reached (default true)")
	}
	goalCampaignSetCmd.Flags().Bool("clear-payout", false, "Go back to the goal's own value")
	registerIdempotencyKeyFlag(goalCreateCmd)
	for _, cmd := range []*cobra.Command{goalCreateCmd, goalUpdateCmd, goalValidateCmd} {
		registerGoalDefinitionFlags(cmd)
	}
	registerDeleteFlags(goalDeleteCmd, "goal")

	goalOutcomesCmd.Flags().String("subject-type", "", "Only click or install subjects")
	goalOutcomesCmd.Flags().String("subject-id", "", "Only this click (or install)")
	goalOutcomesCmd.Flags().StringP("limit", "l", "", "Max results")
	goalOutcomesCmd.Flags().StringP("offset", "o", "", "Pagination offset")

	goalEvaluateCmd.Flags().StringP("file", "F", "", "JSON body: goals, subject, events (- for stdin)")

	goalReevaluateCmd.Flags().String("version", "", "The version to re-evaluate under (default: the current one)")
	goalReevaluateCmd.Flags().String("limit", "", "Clicks per call (default 100, at most 1000)")
	goalReevaluateCmd.Flags().String("after", "", "Continue after this click id (the previous answer's next_after)")
	goalReevaluateCmd.Flags().Bool("apply", false, "Apply the re-evaluation (without it: preview only)")
	goalReevaluateCmd.Flags().String("subject-type", "", "click or install (default: click for a campaign goal, install for a registration or account goal)")

	registerSingleDeleteFlags(goalCampaignRemoveCmd)
	goalCampaignCmd.AddCommand(goalCampaignListCmd, goalCampaignSetCmd, goalCampaignRemoveCmd)

	goalCmd.AddCommand(goalListCmd, goalGetCmd, goalCreateCmd, goalUpdateCmd, goalDeleteCmd, goalVersionsCmd,
		goalOutcomesCmd, goalValidateCmd, goalEvaluateCmd, goalReevaluateCmd, goalCampaignCmd)
	rootCmd.AddCommand(goalCmd)
}
