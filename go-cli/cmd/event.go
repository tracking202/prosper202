package cmd

import (
	"encoding/json"
	"errors"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// Web events (plan §2.2): POST /events, keyed by click id. The same
// evidence a pixel's event= or a page's p202.track() reports; the click's
// campaign's goals decide what it is worth.

var eventCmd = &cobra.Command{
	Use:   "event",
	Short: "Report web events on a click (evaluated by its campaign's goals)",
}

var (
	eventIDPattern   = regexp.MustCompile(`^[\x21-\x3F\x41-\x7E][\x21-\x7E]{0,127}$`)
	eventNamePattern = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$`)
	clickIDPattern   = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)
	// The server's amount grammar: a plain decimal, no exponent, at most 5 places.
	eventRevenuePattern = regexp.MustCompile(`^-?(0|[1-9][0-9]{0,11})(\.[0-9]{1,5})?$`)
)

var eventSendCmd = &cobra.Command{
	Use:   "send",
	Short: "Send an event (or a file of events) for a click",
	Long: "Stores the event on the click and evaluates the click's campaign goals, in one\n" +
		"transaction: a goal it reaches records a conversion (and tells the traffic source when\n" +
		"the campaign pays for it and notifies). --id is your id for the event: sending the same\n" +
		"id again is answered as a duplicate and records nothing, so a retry is always safe.\n\n" +
		"  p202 event send --click-id 123 --name purchase --id ORD-1 --revenue 49 --props '{\"plan\":\"pro\"}'\n" +
		"  p202 event send --click-id 123 --file events.json   # a JSON list of events, or {\"events\": […]}",
	RunE: func(cmd *cobra.Command, args []string) error {
		rawClick, _ := cmd.Flags().GetString("click-id")
		if rawClick == "" {
			return validationError("required flag --click-id is missing").
				WithHint("Use the click id from `p202 click list`.")
		}
		if !clickIDPattern.MatchString(rawClick) {
			return validationError("--click-id must be a whole number greater than 0: %s", rawClick).
				WithHint("Use the click id from `p202 click list`.")
		}
		clickID, err := strconv.ParseInt(rawClick, 10, 64)
		if err != nil {
			return validationError("--click-id is out of range: %s", rawClick)
		}

		file, _ := cmd.Flags().GetString("file")
		var events []interface{}
		if file != "" {
			for _, f := range []string{"name", "id", "occurred-at", "revenue", "transaction-id", "props"} {
				if cmd.Flags().Changed(f) {
					return validationError("--file and --%s are exclusive", f).
						WithHint("Put every field in the file's events, or send one event with the flags.")
				}
			}
			events, err = readEventsFile(file)
			if err != nil {
				return err
			}
		} else {
			event, err := eventFromFlags(cmd)
			if err != nil {
				return err
			}
			events = []interface{}{event}
		}

		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		idemKey, _ := cmd.Flags().GetString("idempotency-key")
		data, err := c.PostIdempotent("events", map[string]interface{}{"click_id": clickID, "events": events}, idemKey)
		if err != nil {
			return hintEventError(err)
		}
		render(data)
		return nil
	},
}

// eventFromFlags builds one event from the quick flags. Numbers are checked
// here and sent as JSON numbers: the server refuses a string where it takes
// a number, and a CLI that quietly cast "1e3" would send 1000.
func eventFromFlags(cmd *cobra.Command) (map[string]interface{}, error) {
	name, _ := cmd.Flags().GetString("name")
	id, _ := cmd.Flags().GetString("id")
	if name == "" {
		return nil, validationError("required flag --name is missing").
			WithHint("The event name a goal's trigger names, e.g. --name purchase; `p202 goal list --campaign-id <id>` shows them.")
	}
	if !eventNamePattern.MatchString(name) {
		return nil, validationError("--name %q is not an event name: 1-64 of letters, digits and _ . : -, starting with a letter, digit or _", name)
	}
	if id == "" {
		return nil, validationError("required flag --id is missing").
			WithHint("Your id for this event (an order number, a UUID): resending the same --id is recorded once, so a retry is safe.")
	}
	if !eventIDPattern.MatchString(id) {
		return nil, validationError("--id %q must be 1-128 printable characters without spaces, not starting with @", id)
	}
	event := map[string]interface{}{"event_id": id, "name": name}
	if cmd.Flags().Changed("occurred-at") {
		v, _ := cmd.Flags().GetString("occurred-at")
		t, err := strconv.ParseInt(v, 10, 64)
		if err != nil || t < 0 || t > 4294967295 || strconv.FormatInt(t, 10) != v {
			return nil, validationError("--occurred-at must be a unix time in seconds: %s", v).
				WithHint("Omit it to use the server's clock (then a retry later is still the same event).")
		}
		event["occurred_at"] = t
	}
	if cmd.Flags().Changed("revenue") {
		v, _ := cmd.Flags().GetString("revenue")
		if !eventRevenuePattern.MatchString(v) {
			return nil, validationError("--revenue must be a decimal number with at most 5 decimal places: %s", v)
		}
		event["revenue"] = json.Number(v)
	}
	if cmd.Flags().Changed("transaction-id") {
		v, _ := cmd.Flags().GetString("transaction-id")
		if strings.TrimSpace(v) == "" {
			return nil, validationError("--transaction-id is empty; omit it or give the network's id")
		}
		event["transaction_id"] = v
	}
	if cmd.Flags().Changed("props") {
		v, _ := cmd.Flags().GetString("props")
		var props map[string]interface{}
		if err := decodeOneJSON([]byte(v), &props); err != nil || props == nil {
			return nil, validationError("--props must be one JSON object: %s", v).
				WithHint(`For example --props '{"plan":"pro","seats":3}'.`)
		}
		event["properties"] = props
	}
	return event, nil
}

// readEventsFile reads a JSON list of events, or an object whose "events"
// is one, from a file or stdin (-).
func readEventsFile(file string) ([]interface{}, error) {
	var data []byte
	var err error
	if file == "-" {
		data, err = io.ReadAll(os.Stdin)
	} else {
		data, err = os.ReadFile(file)
	}
	if err != nil {
		return nil, validationError("reading %s: %v", file, err)
	}
	var raw interface{}
	if err := decodeOneJSON(data, &raw); errors.Is(err, errTrailingJSON) {
		return nil, validationError("%s holds more than one JSON value: %v; nothing was sent", file, err).
			WithHint(`Put every event in one list, [{"event_id": "a", …}, {"event_id": "b", …}], or one {"events": [...]}.`)
	} else if err != nil {
		return nil, validationError("%s is not JSON: %v", file, err).
			WithHint(`The file is a list of events, [{"event_id": "…", "name": "…"}], or {"events": [...]}.`)
	}
	if obj, ok := raw.(map[string]interface{}); ok {
		for k := range obj {
			if k != "events" {
				return nil, validationError("%s has a field %q; the file holds only events", file, k).
					WithHint("Pass the click with --click-id, and put the events in a list (or under \"events\").")
			}
		}
		raw = obj["events"]
	}
	list, ok := raw.([]interface{})
	if !ok || len(list) == 0 {
		return nil, validationError("%s holds no list of events", file).
			WithHint(`The file is a list of events, [{"event_id": "…", "name": "…"}], or {"events": [...]}.`)
	}
	return list, nil
}

func hintEventError(err error) error {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) {
		return err
	}
	switch apiErr.Status {
	case 404:
		return withHint(err, "No such click in this account: `p202 click list` shows the click ids.")
	case 409:
		return withHint(err, "That event id was already used for a different event on this click. Send a new --id for a new event; resend the original unchanged to confirm it.")
	case 422:
		for field := range apiErr.FieldErrors {
			if strings.HasPrefix(field, "events") {
				return withHint(err, "Fix the named event field; documentation/api/23-events.md has the format.")
			}
		}
	}
	return err
}

func init() {
	eventSendCmd.Flags().String("click-id", "", "The click the events belong to (`p202 click list`)")
	eventSendCmd.Flags().String("name", "", "The event name, e.g. purchase")
	eventSendCmd.Flags().String("id", "", "Your id for the event: resending it is recorded once")
	eventSendCmd.Flags().String("occurred-at", "", "When it happened, unix seconds (default: now, by the server's clock)")
	eventSendCmd.Flags().String("revenue", "", "What it was worth (paid by a goal valued from the event's amount)")
	eventSendCmd.Flags().String("transaction-id", "", "The network's id for it, kept on the conversion")
	eventSendCmd.Flags().String("props", "", `Properties as a JSON object, e.g. '{"plan":"pro"}'`)
	eventSendCmd.Flags().StringP("file", "F", "", "Send the events in a JSON file (- for stdin) instead of one from the flags")
	registerIdempotencyKeyFlag(eventSendCmd)

	eventCmd.AddCommand(eventSendCmd)
	rootCmd.AddCommand(eventCmd)
}
