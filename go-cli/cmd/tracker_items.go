package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"p202/internal/api"

	"github.com/spf13/cobra"
)

// trackerItemsMax bounds one --items list: each item is a request or two,
// sent one after another, and an MCP call is stopped at mcpCallTimeout.
const trackerItemsMax = 50

// trackerItemID is the form an *_id field and an update's id take in an item.
var trackerItemID = regexp.MustCompile(`^[0-9]{1,20}$`)

// trackerItem is one entry of --items, checked: its body as a single call's
// flags would build it, and on an update the tracker it names.
type trackerItem struct {
	index int
	id    string
	body  map[string]string
}

// parseTrackerItems reads --items: a JSON list of objects keyed by the
// entity's field names (and id, on an update), each checked as the flags of
// one call are, before anything is sent. Values are text or numbers, as the
// flags take them.
func parseTrackerItems(raw string, entity crudEntity, update bool) ([]trackerItem, error) {
	names := make([]string, 0, len(entity.Fields)+1)
	fields := map[string]crudField{}
	for _, f := range entity.Fields {
		names = append(names, f.Name)
		fields[f.Name] = f
	}
	if update {
		names = append(names, "id")
	}
	sort.Strings(names)
	takes := "An item takes: " + strings.Join(names, ", ") + "."

	if dup, err := jsonDuplicateKey([]byte(raw), "items"); err != nil {
		return nil, validationError("--items is not JSON: %v", err).
			WithHint("Pass a list of objects, e.g. --items '[{\"aff_campaign_id\": \"3\", \"ppc_account_id\": \"7\"}]'.")
	} else if dup != "" {
		return nil, validationError("--items: %s is given twice; only one would be written", dup)
	}
	var list []json.RawMessage
	if err := json.Unmarshal([]byte(raw), &list); err != nil || list == nil {
		return nil, validationError("--items must be a JSON list of objects").
			WithHint("Pass e.g. --items '[{\"aff_campaign_id\": \"3\"}, {\"aff_campaign_id\": \"4\"}]'.")
	}
	if len(list) == 0 {
		return nil, validationError("--items is an empty list; there is nothing to send")
	}
	if len(list) > trackerItemsMax {
		return nil, validationError("--items has %d items; one call takes at most %d", len(list), trackerItemsMax).
			WithHint("Send the rest in another call.")
	}

	items := make([]trackerItem, 0, len(list))
	seenIDs := map[string]int{}
	for i, rawItem := range list {
		var obj map[string]json.RawMessage
		if err := json.Unmarshal(rawItem, &obj); err != nil || obj == nil {
			return nil, validationError("--items: item %d is not an object", i).WithHint("%s", takes)
		}
		item := trackerItem{index: i, body: map[string]string{}}
		keys := make([]string, 0, len(obj))
		for k := range obj {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			_, isField := fields[k]
			if !isField && (!update || k != "id") {
				return nil, validationError("--items: item %d has %s, which a %s does not take", i, k, entity.Name).WithHint("%s", takes)
			}
			v, err := mcpStringValue(obj[k])
			if strings.TrimSpace(string(obj[k])) == "null" {
				// Decoded into a string, null reads as "".
				err = errors.New("null")
			}
			if err != nil {
				return nil, validationError("--items: item %d: %s must be text or a number, not %s", i, k, mcpClip(strings.TrimSpace(string(obj[k]))))
			}
			if k == "id" {
				if !trackerItemID.MatchString(v) {
					return nil, validationError("--items: item %d: id must be a numeric id, not %q", i, mcpClip(v))
				}
				if first, again := seenIDs[v]; again {
					return nil, validationError("--items: items %d and %d both name %s %s; only one of them would be written", first, i, entity.Name, v).
						WithHint("Put every change to one %s in one item.", entity.Name)
				}
				seenIDs[v] = i
				item.id = v
				continue
			}
			f := fields[k]
			switch {
			case v == "" && (!update || !f.Clearable):
				return nil, validationError("--items: item %d: %s is empty", i, k).
					WithHint("Leave %s out of the item to leave it unset, or give it a value.", k)
			case v != "" && strings.HasSuffix(k, "_id") && !trackerItemID.MatchString(v):
				return nil, validationError("--items: item %d: %s must be a numeric id, not %q", i, k, mcpClip(v))
			case v != "" && f.Enum != nil && !containsString(f.Enum, v):
				return nil, validationError("--items: item %d: %s must be one of %s, not %q", i, k, strings.Join(f.Enum, ", "), mcpClip(v))
			}
			if f.Date {
				if _, err := crudDateValue(f, v, update); err != nil {
					return nil, validationError("--items: item %d: %s", i, err.Error())
				}
			}
			item.body[k] = v
		}
		if update {
			if item.id == "" {
				return nil, validationError("--items: item %d has no id; an update names the %s it changes", i, entity.Name).WithHint("%s", takes)
			}
			if len(item.body) == 0 {
				return nil, validationError("--items: item %d changes nothing; give at least one field beside id", i).WithHint("%s", takes)
			}
		} else {
			var missing []string
			for _, f := range entity.Fields {
				if f.Required && item.body[f.Name] == "" {
					missing = append(missing, f.Name)
				}
			}
			if len(missing) > 0 {
				return nil, validationError("--items: item %d has no %s, which a %s needs", i, strings.Join(missing, " or "), entity.Name).WithHint("%s", takes)
			}
			if entity.Check != nil {
				body := item.body
				if err := entity.Check(func(name string) (string, error) { return body[name], nil }); err != nil {
					return nil, withHint(fmt.Errorf("--items: item %d: %w", i, err), "%s", takes)
				}
			}
		}
		items = append(items, item)
	}
	return items, nil
}

// refuseFieldFlagsWithItems: --items carries every item's fields, so a field
// flag beside it would be ambiguous (for every item, or for none?).
func refuseFieldFlagsWithItems(cmd *cobra.Command, entity crudEntity) error {
	for _, f := range entity.Fields {
		if cmd.Flags().Changed(f.Name) {
			return validationError("--%s cannot be given with --items, which carries each item's fields", f.Name).
				WithHint("Put %s into each item instead.", f.Name)
		}
	}
	return nil
}

// trackerLink is what creating one tracker and reading its link came to.
type trackerLink struct {
	// landed: the create was answered as written; what follows did not
	// necessarily work.
	landed    bool
	staged    string // the change id, when the create was only proposed
	answer    []byte // the create's answer, as the install gave it
	tracker   map[string]interface{}
	trackerID int
	url       map[string]interface{}
}

// createTrackerLink creates one tracker and reads its tracking URL. A
// non-nil error with landed true means the tracker exists (or may) and must
// not be created again.
func createTrackerLink(c *api.Client, body, tokens map[string]string, idemKey string) (trackerLink, error) {
	var link trackerLink
	createdData, err := c.PostIdempotent("trackers", body, idemKey)
	if err != nil {
		return link, err
	}
	link.landed, link.answer = true, createdData
	if changeID, staged := stagedChangeID(createdData); staged {
		link.staged = changeID
		return link, nil
	}
	createdObj, err := parseDataObject(createdData)
	if err != nil {
		return link, fmt.Errorf("failed to parse tracker create response: %w", err)
	}
	link.tracker = createdObj
	trackerID, ok := extractIntField(createdObj, "tracker_id", "id")
	if !ok {
		return link, validationError("tracker create response did not include tracker_id").
			WithHint("Run `p202 tracker create` and `p202 tracker get-url <id>` separately to see which step fails.")
	}
	link.trackerID = trackerID
	urlData, err := c.Get(fmt.Sprintf("trackers/%d/url", trackerID), tokens)
	if err != nil {
		// The tracker exists now: running create-with-url again
		// would make a second one.
		return link, withHint(err, "The tracker was created (tracker_id %d); do not re-run create-with-url. Run `p202 tracker get-url %d` for its link.", trackerID, trackerID)
	}
	urlObj, err := parseDataObject(urlData)
	if err != nil {
		return link, fmt.Errorf("failed to parse tracker url response: %w", err)
	}
	link.url = urlObj
	return link, nil
}

// itemRefusal reports whether a failed request was answered as refused
// (nothing written), and whether the items after it would meet the same
// refusal. Any other failure (no answer, a 5xx, a bare 402 from something in
// front of the install) leaves the write unknown (CLAUDE.md #13).
func itemRefusal(err error) (refused, stopsTheRest bool) {
	var apiErr *api.APIError
	if !errors.As(err, &apiErr) || apiErr.Status < 400 || apiErr.Status >= 500 {
		return false, true
	}
	category := api.ErrorCategory(err)
	if apiErr.Status == http.StatusPaymentRequired && category != "licence" {
		return false, true
	}
	switch category {
	case "auth", "licence", "rate_limited":
		return true, true
	}
	return true, false
}

func itemError(err error, hint string) map[string]interface{} {
	e := map[string]interface{}{"category": api.ErrorCategory(err), "message": err.Error()}
	if e["category"] == "" {
		e["category"] = "server"
	}
	if hint == "" {
		hint = api.HintFor(err)
	}
	if hint != "" {
		e["hint"] = hint
	}
	return e
}

// itemsRun collects each item's answer, in order, and what stopped the run.
type itemsRun struct {
	verb    string // what a done item was: "created", "updated"
	total   int
	answers []map[string]interface{}
	counts  map[string]int
	stopped string // why the rest were not sent; "" while running
	done    []string
	notDone []string
}

func newItemsRun(verb string, total int) *itemsRun {
	return &itemsRun{verb: verb, total: total, counts: map[string]int{}}
}

func (r *itemsRun) add(answer map[string]interface{}) {
	status, _ := answer["status"].(string)
	r.counts[status]++
	r.answers = append(r.answers, answer)
	index := answer["index"].(int)
	switch status {
	case r.verb, "staged":
		what := ""
		if id, ok := answer["tracker_id"].(int); ok {
			what = fmt.Sprintf(" (tracker %d)", id)
		} else if change, ok := answer["change_id"].(string); ok {
			what = " (proposal " + change + ")"
		}
		r.done = append(r.done, fmt.Sprintf("item %d%s", index, what))
	default:
		r.notDone = append(r.notDone, fmt.Sprintf("item %d %s", index, status))
	}
}

// finish renders every item's answer and ends as a partial failure when an
// item was not done, naming what was, so a caller that reads only the error
// still knows which items not to send again.
func (r *itemsRun) finish() error {
	summary := map[string]int{}
	for _, s := range []string{r.verb, "staged", "error", "unconfirmed", "not_attempted"} {
		summary[s] = r.counts[s]
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": r.answers, "summary": summary})
	if err != nil {
		return fmt.Errorf("encoding the items' answers: %w", err)
	}
	render(encoded)
	if len(r.notDone) == 0 {
		return nil
	}
	done := "none"
	if len(r.done) > 0 {
		done = strings.Join(r.done, ", ")
	}
	msg := fmt.Sprintf("%d of %d items were not done (%s). Done: %s.", len(r.notDone), r.total, strings.Join(r.notDone, ", "), done)
	if r.stopped != "" {
		msg += " " + r.stopped
	}
	e := partialFailureError("%s", msg)
	e.Hint = "Send again only the items that were not done; an unconfirmed item's answer says how to check it first."
	return e
}

// runTrackerItemsCreate is `tracker create-with-url --items`.
func runTrackerItemsCreate(cmd *cobra.Command, entity crudEntity, raw string) error {
	if err := refuseFieldFlagsWithItems(cmd, entity); err != nil {
		return err
	}
	items, err := parseTrackerItems(raw, entity, false)
	if err != nil {
		return err
	}
	tokens, err := linkTokenParams(cmd)
	if err != nil {
		return err
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	idemKey, _ := cmd.Flags().GetString("idempotency-key")
	run := newItemsRun("created", len(items))
	for _, item := range items {
		answer := map[string]interface{}{"index": item.index}
		if run.stopped != "" {
			answer["status"] = "not_attempted"
			run.add(answer)
			continue
		}
		key := ""
		if strings.TrimSpace(idemKey) != "" {
			// One key per item, so a call sent again with the same key
			// replays each item's create.
			key = strings.TrimSpace(idemKey) + "-" + strconv.Itoa(item.index)
		}
		link, err := createTrackerLink(c, item.body, tokens, key)
		switch {
		case err == nil && link.staged != "":
			answer["status"], answer["change_id"] = "staged", link.staged
		case err == nil:
			answer["status"], answer["tracker_id"] = "created", link.trackerID
			answer["tracker"], answer["tracking_url"] = link.tracker, link.url
		case link.trackerID != 0:
			// Created; only its link was not read.
			answer["status"], answer["tracker_id"], answer["tracker"] = "created", link.trackerID, link.tracker
			answer["tracking_url"] = nil
			answer["error"] = itemError(err, fmt.Sprintf("The tracker was created; do not send this item again. Run `p202 tracker get-url %d` for its link.", link.trackerID))
		case link.landed:
			answer["status"] = "unconfirmed"
			answer["error"] = itemError(err, "The install answered the create, but not with a tracker id: look with `p202 tracker list` before sending this item again.")
			run.stopped = fmt.Sprintf("The items after item %d were not sent, because its create was answered in a way that does not say what was stored.", item.index)
		default:
			refused, stops := itemRefusal(err)
			if refused {
				answer["status"], answer["error"] = "error", itemError(err, "")
			} else {
				answer["status"] = "unconfirmed"
				hint := fmt.Sprintf("The tracker may have been created: look with `p202 tracker list --aff-campaign-id %s` before sending this item again.", item.body["aff_campaign_id"])
				if key != "" {
					hint = fmt.Sprintf("Sending the call again with the same --idempotency-key replays this item's create (key %s) instead of making a second tracker.", key)
				}
				answer["error"] = itemError(err, hint)
			}
			if stops {
				run.stopped = fmt.Sprintf("The items after item %d were not sent, because item %d's failure would meet them too, or left what was stored unknown.", item.index, item.index)
			}
		}
		run.add(answer)
	}
	return run.finish()
}

// runTrackerItemsUpdate is `tracker update --items`: one PUT per item, as
// `tracker update <id>` sends it.
func runTrackerItemsUpdate(cmd *cobra.Command, entity crudEntity, raw string) error {
	if err := refuseFieldFlagsWithItems(cmd, entity); err != nil {
		return err
	}
	if entity.Check != nil {
		// An update's Check reads the stored record (registerCRUD's update);
		// --items does not, so it is offered only where there is none
		// (TestTrackerItemsEntityHasNoCheck).
		return validationError("--items is not offered for %s update", entity.Name)
	}
	items, err := parseTrackerItems(raw, entity, true)
	if err != nil {
		return err
	}
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	run := newItemsRun("updated", len(items))
	for _, item := range items {
		id, _ := strconv.Atoi(item.id)
		answer := map[string]interface{}{"index": item.index, "tracker_id": id}
		if run.stopped != "" {
			answer["status"] = "not_attempted"
			run.add(answer)
			continue
		}
		body := map[string]interface{}{}
		for k, v := range item.body {
			body[k] = v
			if f := crudFieldNamed(entity, k); f.Date {
				day, err := crudDateValue(f, v, true)
				if err != nil {
					return err
				}
				body[k] = day
			}
		}
		data, err := c.Put(entity.Endpoint+"/"+item.id, body)
		if err != nil {
			refused, stops := itemRefusal(err)
			if refused {
				answer["status"], answer["error"] = "error", itemError(err, "")
			} else {
				answer["status"] = "unconfirmed"
				answer["error"] = itemError(err, "The update may have been written. It writes the same values when sent again, so this item can be sent again as it is.")
			}
			if stops {
				run.stopped = fmt.Sprintf("The items after item %d were not sent, because item %d's failure would meet them too, or left what was stored unknown.", item.index, item.index)
			}
			run.add(answer)
			continue
		}
		if changeID, staged := stagedChangeID(data); staged {
			delete(answer, "tracker_id")
			answer["status"], answer["change_id"] = "staged", changeID
			run.add(answer)
			continue
		}
		obj, err := parseDataObject(data)
		if err != nil {
			// Answered as written: what it holds is unread, not undone.
			answer["status"], answer["tracker"] = "updated", nil
			answer["error"] = itemError(err, fmt.Sprintf("The update was answered as written; `p202 tracker get %s` shows the tracker.", item.id))
			run.add(answer)
			continue
		}
		answer["status"], answer["tracker"] = "updated", obj
		run.add(answer)
	}
	return run.finish()
}

func crudFieldNamed(entity crudEntity, name string) crudField {
	for _, f := range entity.Fields {
		if f.Name == name {
			return f
		}
	}
	return crudField{}
}

// itemsFlagUsage is --items' help on both commands.
const itemsFlagUsage = "Several trackers at once: a JSON list of objects keyed by field name, each answered on its own; at most 50"

// addTrackerItemsToUpdate gives `tracker update` its --items: the id moves
// into each item, so the positional becomes optional.
func addTrackerItemsToUpdate(trackerCmd *cobra.Command, entity crudEntity) {
	var update *cobra.Command
	for _, c := range trackerCmd.Commands() {
		if c.Name() == "update" {
			update = c
		}
	}
	if update == nil {
		panic("tracker has no update command")
	}
	single := update.RunE
	update.Use = "update [<id>]"
	update.Args = cobra.MaximumNArgs(1)
	update.Flags().String("items", "", itemsFlagUsage+", each with its id")
	update.Example += "\n  p202 tracker update --items '[{\"id\": \"12\", \"text_ad_id\": \"4\"}, {\"id\": \"13\", \"text_ad_id\": \"5\"}]'"
	update.RunE = func(cmd *cobra.Command, args []string) error {
		raw, _ := cmd.Flags().GetString("items")
		if cmd.Flags().Changed("items") {
			if len(args) > 0 {
				return validationError("an id cannot be given with --items, which names each item's tracker").
					WithHint("Put \"id\" into each item instead.")
			}
			return runTrackerItemsUpdate(cmd, entity, raw)
		}
		if len(args) == 0 {
			return validationError("tracker update needs the tracker's id, or --items for several").
				WithHint("`p202 tracker list` shows the ids.")
		}
		return single(cmd, args)
	}
}
