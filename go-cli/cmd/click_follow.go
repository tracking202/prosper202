package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/signal"
	"sort"
	"strconv"
	"syscall"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/output"
)

// clickFollowLookback is how far behind the newest click each poll reads
// again. A click's time is set when the redirect runs, and its row can
// commit after a later click's (two requests in flight at once), so a
// cursor that only moved forward would skip it. Rows already printed are
// recognised by id and not printed twice.
const clickFollowLookback = 15 * time.Second

// clickFollowMinInterval keeps --interval from hammering the server; tests
// lower it.
var clickFollowMinInterval = time.Second

// clickFollowPageSize is the page each poll reads with; tests lower it to
// exercise paging.
var clickFollowPageSize = 500

// followColumns are the human view's columns: what the Spy page shows first.
var followColumns = []string{"click_id", "click_time", "aff_campaign_name", "ppc_account_name", "keyword", "ip_address", "country_name", "referer", "click_lead"}

type followedClick struct {
	id   int64
	time int64
	row  map[string]interface{}
}

// followClicks is the Spy page (tracking202/spy): the newest clicks, then
// each new one as it arrives, by polling GET /clicks. The cursor is the
// newest click time the server returned, never this machine's clock, so
// clock skew between the two cannot drop clicks.
func followClicks(cmd *cobra.Command, c *api.Client, params map[string]string) error {
	interval, _ := cmd.Flags().GetDuration("interval")
	if interval < clickFollowMinInterval {
		return validationError("--interval must be at least %s; got %s", clickFollowMinInterval, interval)
	}
	stopAfter, _ := cmd.Flags().GetDuration("stop-after")
	if stopAfter < 0 {
		return validationError("--stop-after must be a positive duration, or 0 to follow until interrupted; got %s", stopAfter)
	}
	backlog := 10
	if v, _ := cmd.Flags().GetString("limit"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 500 {
			return validationError("--limit must be a whole number from 1 to 500 with --follow (the clicks shown before following); got %q", v)
		}
		backlog = n
	}
	if opts := renderOpts(); opts.CSV {
		return validationError("--follow streams rows as they arrive, which CSV cannot do").
			WithHint("Use --ndjson for one JSON object per click, or drop --csv for the table view.")
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if stopAfter > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, stopAfter)
		defer cancel()
	}

	seen := map[int64]int64{} // click id -> click time
	var newest int64

	// The backlog is the newest --limit clicks. The lookback window behind
	// the newest of them is read too and marked seen, so a click that
	// --limit left out is not printed later as though it had just arrived.
	q := copyParams(params)
	q["limit"] = strconv.Itoa(backlog)
	data, err := c.Get("clicks", q)
	if err != nil {
		return err
	}
	first, err := followRows(data)
	if err != nil {
		return err
	}
	for _, r := range first {
		if r.time > newest {
			newest = r.time
		}
	}
	if newest > 0 {
		window, err := followWindow(c, params, strconv.FormatInt(newest-int64(clickFollowLookback/time.Second), 10))
		if err != nil {
			return err
		}
		byID := map[int64]followedClick{}
		for _, r := range append(first, window...) {
			byID[r.id] = r
			if r.time > newest {
				newest = r.time
			}
		}
		all := make([]followedClick, 0, len(byID))
		for _, r := range byID {
			all = append(all, r)
		}
		sortClicks(all)
		cut := len(all) - backlog
		if cut < 0 {
			cut = 0
		}
		for _, r := range all[:cut] {
			seen[r.id] = r.time
		}
		if err := emitFollowed(all[cut:], seen); err != nil {
			return err
		}
	}

	poll := func() error {
		from := ""
		if newest > 0 {
			from = strconv.FormatInt(newest-int64(clickFollowLookback/time.Second), 10)
		}
		rows, err := followWindow(c, params, from)
		if err != nil {
			return err
		}
		var fresh []followedClick
		for _, r := range rows {
			if _, ok := seen[r.id]; !ok {
				fresh = append(fresh, r)
			}
			if r.time > newest {
				newest = r.time
			}
		}
		if err := emitFollowed(fresh, seen); err != nil {
			return err
		}
		// Forget what the next window can no longer return.
		floor := newest - int64(clickFollowLookback/time.Second)
		for id, t := range seen {
			if t < floor {
				delete(seen, id)
			}
		}
		return nil
	}

	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			// --stop-after is the window watched, not the last tick before it:
			// with a 5s interval and --stop-after 10s the second tick and the
			// deadline land together, and returning here dropped every click
			// of the last interval (measured: a click 5s into a 10s watch
			// was never printed). Read once more when the time ran out; an
			// interrupt stops at once.
			if errors.Is(ctx.Err(), context.DeadlineExceeded) {
				return poll()
			}
			return nil
		case <-ticker.C:
		}
		if err := poll(); err != nil {
			if ctx.Err() != nil && !errors.Is(ctx.Err(), context.DeadlineExceeded) {
				return nil // interrupted mid-poll
			}
			return err
		}
	}
}

// followWindow reads every click from `from` (unix seconds; "" for no lower
// bound) through now, across pages. The list is
// newest first, so a click arriving mid-read pushes rows down a page: a row
// can be read twice, which the caller's seen-set absorbs, but never skipped.
func followWindow(c *api.Client, params map[string]string, from string) ([]followedClick, error) {
	q := copyParams(params)
	if from != "" {
		q["time_from"] = from
	}
	q["limit"] = strconv.Itoa(clickFollowPageSize)
	var all []followedClick
	for offset := 0; ; offset += clickFollowPageSize {
		q["offset"] = strconv.Itoa(offset)
		data, err := c.Get("clicks", q)
		if err != nil {
			return nil, err
		}
		rows, err := followRows(data)
		if err != nil {
			return nil, err
		}
		all = append(all, rows...)
		if len(rows) < clickFollowPageSize {
			return all, nil
		}
	}
}

func followRows(data []byte) ([]followedClick, error) {
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal(data, &resp); err != nil {
		return nil, fmt.Errorf("parsing the clicks response: %w", err)
	}
	rows := make([]followedClick, 0, len(resp.Data))
	for _, row := range resp.Data {
		id, okID := intField(row["click_id"])
		t, okTime := intField(row["click_time"])
		if !okID || !okTime {
			return nil, &CLIError{
				Category: "server",
				Message:  fmt.Sprintf("a click in the response has no readable click_id/click_time (%v, %v)", row["click_id"], row["click_time"]),
				ExitCode: ExitServer,
				Hint:     "--follow orders and de-duplicates clicks by those two fields. Run `p202 system health`; the server may predate them.",
			}
		}
		rows = append(rows, followedClick{id: id, time: t, row: row})
	}
	return rows, nil
}

func intField(v interface{}) (int64, bool) {
	switch x := v.(type) {
	case float64:
		return int64(x), x == float64(int64(x))
	case string:
		n, err := strconv.ParseInt(x, 10, 64)
		return n, err == nil
	case json.Number:
		n, err := x.Int64()
		return n, err == nil
	}
	return 0, false
}

// emitFollowed prints clicks oldest first, as Spy adds them, and records
// them as seen. JSON is one object per line here (a stream cannot be one
// JSON document); the table view prints each batch under its own header.
func emitFollowed(rows []followedClick, seen map[int64]int64) error {
	if len(rows) == 0 {
		return nil
	}
	sortClicks(rows)
	batch := make([]map[string]interface{}, 0, len(rows))
	for _, r := range rows {
		if _, dup := seen[r.id]; dup {
			continue
		}
		seen[r.id] = r.time
		batch = append(batch, r.row)
	}
	if len(batch) == 0 {
		return nil
	}
	data, err := json.Marshal(map[string]interface{}{"data": batch})
	if err != nil {
		return fmt.Errorf("encoding %d clicks: %w", len(batch), err)
	}
	opts := renderOpts()
	if opts.JSON || opts.NDJSON {
		opts.JSON, opts.NDJSON = false, true
	} else if len(opts.Fields) == 0 {
		opts.Fields = followColumns
	}
	output.RenderWith(data, opts)
	return nil
}

// sortClicks orders clicks oldest first: by time, then id.
func sortClicks(rows []followedClick) {
	sort.Slice(rows, func(i, j int) bool {
		if rows[i].time != rows[j].time {
			return rows[i].time < rows[j].time
		}
		return rows[i].id < rows[j].id
	})
}

func copyParams(params map[string]string) map[string]string {
	q := make(map[string]string, len(params)+3)
	for k, v := range params {
		q[k] = v
	}
	return q
}
