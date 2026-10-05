package cmd

import (
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

var attributionCmd = &cobra.Command{
	Use:   "attribution",
	Short: "Multi-touch attribution: models, credit reports and journeys over your own clicks, plus platform-signed postbacks (SKAdNetwork, AdAttributionKit)",
}

// attributionModelTypes is the server's model enum (Prosper202\Attribution\ModelType).
// tests/Attribution/ModelListIsTheEnumTest fails if the two ever differ, so
// the CLI can never offer a model the engine cannot compute.
var attributionModelTypes = []string{"last_touch", "first_touch", "linear", "time_decay", "position_based"}

// attributionDimensions is the server's report dimension list
// (Prosper202\Attribution\AttributionReports::dimensions), checked the same way.
var attributionCohorts = []string{"conversion", "click"}

var attributionDimensions = []string{"campaign", "traffic_source", "landing_page", "keyword", "c1", "c2", "c3", "c4", "country", "device", "day"}

var attributionPeriods = []string{"today", "yesterday", "last7", "last30", "last90"}

// attributionModelStatuses are Prosper202\Attribution\Model's statuses.
var attributionModelStatuses = []string{"active", "inactive"}

var positiveIntPattern = regexp.MustCompile(`^[1-9][0-9]*$`)

// wholeNumberPattern is 0 or a positive whole number without leading zeros (what the server accepts for offset).
var wholeNumberPattern = regexp.MustCompile(`^(0|[1-9][0-9]*)$`)
var unixTimePattern = regexp.MustCompile(`^[0-9]{1,10}$`)

func containsString(list []string, v string) bool {
	for _, s := range list {
		if s == v {
			return true
		}
	}
	return false
}

// --- Model subcommands ---

var attrModelCmd = &cobra.Command{
	Use:   "model",
	Short: "Manage attribution models (every account has one default; credits are computed for every active model)",
}

var attrModelListCmd = &cobra.Command{
	Use:   "list",
	Short: "List attribution models",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if v, _ := cmd.Flags().GetString("type"); v != "" {
			params["type"] = v
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/models", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelGetCmd = &cobra.Command{
	Use:   "get <id>",
	Short: "Get an attribution model",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveIntPattern.MatchString(args[0]) {
			return validationError("model id must be a positive whole number, got %q", args[0]).
				WithHint("Run `p202 attribution model list` to find model ids.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/models/"+args[0], nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// attributionModelBody reads the definition flags shared by create and
// update into the request body, as the types the API takes. A value that is
// not what its flag says is refused here, naming the flag, before any
// client is built.
func attributionModelBody(cmd *cobra.Command) (map[string]interface{}, error) {
	body := map[string]interface{}{}
	if v, _ := cmd.Flags().GetString("model-name"); v != "" {
		body["model_name"] = v
	}
	if v, _ := cmd.Flags().GetString("model-type"); v != "" {
		body["model_type"] = v
	}
	if v, _ := cmd.Flags().GetString("weighting-config"); v != "" {
		var parsed map[string]interface{}
		if err := json.Unmarshal([]byte(v), &parsed); err != nil || parsed == nil {
			return nil, validationError("invalid --weighting-config: a JSON object is required").
				WithHint(`time_decay takes '{"half_life_hours":48}', position_based '{"first_weight":0.4,"last_weight":0.4}'; the other types take none. Quote it so the shell keeps it intact.`)
		}
		body["weighting_config"] = parsed
	}
	if cmd.Flags().Changed("lookback-days") {
		n, _ := cmd.Flags().GetInt("lookback-days")
		if n < 1 || n > 365 {
			return nil, validationError("invalid --lookback-days %d; a whole number of days from 1 to 365", n)
		}
		body["lookback_days"] = n
	}
	if v, _ := cmd.Flags().GetString("status"); v != "" {
		body["status"] = v
	}
	if on, _ := cmd.Flags().GetBool("default"); on {
		body["is_default"] = true
	}
	return body, nil
}

var attrModelCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create an attribution model; its credits are computed for existing conversions by the next worker run",
	RunE: func(cmd *cobra.Command, args []string) error {
		body, err := attributionModelBody(cmd)
		if err != nil {
			return err
		}
		if body["model_name"] == nil {
			return validationError("required flag --model-name is missing")
		}
		if body["model_type"] == nil {
			return validationError("required flag --model-type is missing; valid: %s", strings.Join(attributionModelTypes, ", "))
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("attribution/models", body, idemKey)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelUpdateCmd = &cobra.Command{
	Use:   "update <id>",
	Short: "Update an attribution model (changing its type resets its weighting config to that type's defaults)",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveIntPattern.MatchString(args[0]) {
			return validationError("model id must be a positive whole number, got %q", args[0]).
				WithHint("Run `p202 attribution model list` to find model ids.")
		}
		body, err := attributionModelBody(cmd)
		if err != nil {
			return err
		}
		if len(body) == 0 {
			return validationError("no fields specified; pass at least one of --model-name, --model-type, --weighting-config, --lookback-days, --status, --default")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Put("attribution/models/"+args[0], body)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrModelDeleteCmd = &cobra.Command{
	Use:   "delete <id>",
	Short: "Delete an attribution model and its credits (not the default); supports --ids for bulk",
	Args:  deleteArgsValidator,
	RunE: func(cmd *cobra.Command, args []string) error {
		err := bulkOrSingleDelete(cmd, "attribution/models", "attribution model")
		var apiErr *api.APIError
		if asAPIError(err, &apiErr) && apiErr.Status == 409 {
			return withHint(err, "The default model cannot be deleted. Make another model the default first: `p202 attribution model update <other-id> --default`.")
		}
		return err
	},
}

// --- Reports ---

// attributionRangeParams validates the shared range flags.
func attributionRangeParams(cmd *cobra.Command, params map[string]string) error {
	period, _ := cmd.Flags().GetString("period")
	from, _ := cmd.Flags().GetString("time-from")
	to, _ := cmd.Flags().GetString("time-to")
	if period != "" && (from != "" || to != "") {
		return validationError("--period and --time-from/--time-to are exclusive").
			WithHint("Use --period last30, or an explicit --time-from/--time-to range in unix seconds.")
	}
	if period != "" {
		params["period"] = period
	}
	for name, v := range map[string]string{"time-from": from, "time-to": to} {
		if v == "" {
			continue
		}
		if !unixTimePattern.MatchString(v) {
			return validationError("invalid --%s %q: unix time in seconds", name, v)
		}
		params[strings.ReplaceAll(name, "-", "_")] = v
	}
	return nil
}

func registerAttributionRangeFlags(cmd *cobra.Command) {
	cmd.Flags().String("period", "", "Range (default: the last 30 days): {values}")
	enumFlag(cmd, "period", newEnum(attributionPeriods))
	cmd.Flags().String("time-from", "", "Range start, unix seconds")
	cmd.Flags().String("time-to", "", "Range end, unix seconds")
}

var attrBreakdownCmd = &cobra.Command{
	Use:   "breakdown",
	Short: "Attributed conversions, revenue, cost and ROI by a click dimension; --compare-model puts two models side by side",
	Long: `Attributed conversions (sum of credit) and revenue for conversions in the range, grouped by a
dimension of the credited clicks; clicks and cost from the dimension's own clicks; and assisted
conversions (journeys where the dimension had a touch before the converting click).

Without --model the report is "effective": each conversion under its campaign's model override
when that model is active, otherwise the account default.

--cohort click reads credits and assists by the date of the click they land on instead of the
sale's date: each row is what its own clicks in the range earned (whenever they converted) against
their cost, the population the classic reports count.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		groupBy, _ := cmd.Flags().GetString("group-by")
		params["group_by"] = groupBy
		for flag, param := range map[string]string{"model": "model_id", "compare-model": "compare_model_id", "limit": "limit"} {
			v, _ := cmd.Flags().GetString(flag)
			if v == "" {
				continue
			}
			if !positiveIntPattern.MatchString(v) {
				hint := "Run `p202 attribution model list` to find model ids."
				if flag == "limit" {
					hint = "Pass a row count from 1 to 1000."
				}
				return validationError("invalid --%s %q: a positive whole number", flag, v).WithHint("%s", hint)
			}
			if flag == "limit" {
				if n, err := strconv.Atoi(v); err != nil || n > 1000 {
					return validationError("invalid --limit %q; 1 to 1000", v).WithHint("Pass a row count from 1 to 1000.")
				}
			}
			params[param] = v
		}
		if params["model_id"] != "" && params["model_id"] == params["compare_model_id"] {
			return validationError("--compare-model must differ from --model")
		}
		if v, _ := cmd.Flags().GetString("offset"); v != "" {
			// The server refuses leading zeros, so the CLI does too.
			if !wholeNumberPattern.MatchString(v) {
				return validationError("invalid --offset %q: a whole number, 0 or more", v).
					WithHint("Rows come in attributed-revenue order; page with --limit 1000 --offset 0, then 1000, 2000, ... while rows remain (meta.groups is the total).")
			}
			params["offset"] = v
		}
		if v, _ := cmd.Flags().GetString("cohort"); v != "" {
			params["cohort"] = v
		}
		if err := attributionRangeParams(cmd, params); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/reports/breakdown", params)
		if err != nil {
			var apiErr *api.APIError
			if asAPIError(err, &apiErr) && apiErr.Status == 409 {
				return withHint(err, "That model is inactive or invalid, so it has no credits. Check it with `p202 attribution model get <id>` and fix or activate it with `p202 attribution model update`.")
			}
			if params["offset"] != "" && refusesOffset(err) {
				return withHint(err, "This server can't page the attribution report yet (it predates offset); drop --offset to get the first --limit rows.")
			}
			if params["cohort"] != "" && refusesParam(err, "cohort") {
				return withHint(err, "This server can't count by click date yet (it predates --cohort); drop --cohort to read the sales made in the range.")
			}
			return err
		}
		render(data)
		return nil
	},
}

var attrJourneysCmd = &cobra.Command{
	Use:   "journeys",
	Short: "Journey metrics: length distribution, time to convert, one-touch share by browser",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if err := attributionRangeParams(cmd, params); err != nil {
			return err
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/reports/journeys", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrJourneyCmd = &cobra.Command{
	Use:   "journey <conv_id>",
	Short: "One conversion's journey: its touches, the identity signals that linked them, and every model's credit",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if !positiveIntPattern.MatchString(args[0]) {
			return validationError("conversion id must be a positive whole number, got %q", args[0]).
				WithHint("Run `p202 conversion list` to find conversion ids.")
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/conversions/"+args[0]+"/journey", nil)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

var attrQueueCmd = &cobra.Command{
	Use:   "queue",
	Short: "The attribution worker's backlog: conversions waiting, failing (with the error), and why each was queued",
	RunE: func(cmd *cobra.Command, args []string) error {
		params := map[string]string{}
		if cmd.Flags().Changed("limit") {
			n, _ := cmd.Flags().GetInt("limit")
			if n < 1 || n > 500 {
				return validationError("invalid --limit %d; 1 to 500", n)
			}
			params["limit"] = strconv.Itoa(n)
		}
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("attribution/queue", params)
		if err != nil {
			return err
		}
		render(data)
		return nil
	},
}

// asAPIError is errors.As for *api.APIError, nil-safe.
func asAPIError(err error, target **api.APIError) bool {
	if err == nil {
		return false
	}
	return errors.As(err, target)
}

func init() {

	attrModelListCmd.Flags().StringP("type", "t", "", "Filter by type")
	enumFlag(attrModelListCmd, "type", newEnum(attributionModelTypes))

	for _, c := range []*cobra.Command{attrModelCreateCmd, attrModelUpdateCmd} {
		c.Flags().String("model-name", "", "Model name")
		c.Flags().String("model-type", "", "Type")
		c.Flags().String("weighting-config", "", `Weighting config JSON object: time_decay {"half_life_hours":48}, position_based {"first_weight":0.4,"last_weight":0.4}`)
		c.Flags().Int("lookback-days", 30, "Days before a conversion whose clicks can earn credit, 1-365")
		c.Flags().String("status", "", "Status")
		c.Flags().Bool("default", false, "Make this the account default model")
	}
	attrModelCreateCmd.Flags().Lookup("model-name").Usage = "Model name (required)"
	attrModelCreateCmd.Flags().Lookup("model-type").Usage = "Type (required)"
	for _, c := range []*cobra.Command{attrModelCreateCmd, attrModelUpdateCmd} {
		enumFlag(c, "model-type", newEnum(attributionModelTypes))
		enumFlag(c, "status", newEnum(attributionModelStatuses))
	}

	registerDeleteFlags(attrModelDeleteCmd, "model")
	registerIdempotencyKeyFlag(attrModelCreateCmd)

	attrModelCmd.AddCommand(attrModelListCmd, attrModelGetCmd, attrModelCreateCmd, attrModelUpdateCmd, attrModelDeleteCmd)

	attrBreakdownCmd.Flags().String("group-by", "campaign", "Dimension")
	enumFlag(attrBreakdownCmd, "group-by", newEnum(attributionDimensions))
	attrBreakdownCmd.Flags().String("model", "", "Model id (default: each campaign's override, else the account default)")
	attrBreakdownCmd.Flags().String("compare-model", "", "A second model id, side by side")
	attrBreakdownCmd.Flags().String("limit", "", "Rows, 1-1000 (default 100)")
	attrBreakdownCmd.Flags().String("offset", "", "Rows to skip, for paging past --limit (meta.groups is the total; needs a server with attribution paging)")
	attrBreakdownCmd.Flags().String("cohort", "", "conversion: the sales made in the range (default); click: what the range's clicks earned, as the classic reports count. Values: {values}")
	enumFlag(attrBreakdownCmd, "cohort", newEnum(attributionCohorts))
	registerAttributionRangeFlags(attrBreakdownCmd)
	registerAttributionRangeFlags(attrJourneysCmd)
	attrQueueCmd.Flags().Int("limit", 50, "Rows of the backlog to list, 1-500")

	attributionCmd.AddCommand(attrModelCmd, attrBreakdownCmd, attrJourneysCmd, attrJourneyCmd, attrQueueCmd)
	rootCmd.AddCommand(attributionCmd)
}
