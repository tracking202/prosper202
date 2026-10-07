package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/big"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"p202/internal/api"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

// The UI's Update section (tracking202/update/) as commands, over the Update
// API (POST /clicks/cpc, /conversions/subids, /conversions/subids/delete,
// /conversions/subids/reset, /conversions/uploads):
//
//	p202 click update-cpc         Update CPC
//	p202 conversion mark-subids   Update Subids
//	p202 conversion delete-subids Delete Subids
//	p202 conversion reset-subids  Reset Campaign Subids
//	p202 conversion upload-revenue Upload Revenue Reports
//
// Every write is checked first with the endpoint's own `?dry_run=1` (the same
// request, writing nothing), and the ones that change or remove what is
// recorded ask before writing (--force skips; with no terminal to answer, the
// question fails rather than reading "no"). None can be staged: the server
// refuses `staged=1` on these routes, and so do the commands, before any
// request (CLAUDE.md #14).

const (
	// updateSubidsPerRequest is how many lines of a subid list one request
	// carries. The server takes up to 5,000 (UpdateController::MAX_SUBIDS);
	// a shorter request answers well inside the client's wait.
	updateSubidsPerRequest = 1000
	// updateUploadMaxBytes is the largest body POST /conversions/uploads
	// takes (UpdateController::UPLOAD_MAX_BYTES).
	updateUploadMaxBytes = 8 << 20
)

var (
	updateDayPattern = regexp.MustCompile(`^[0-9]{4}-[0-9]{2}-[0-9]{2}$`)
	updateCPCPattern = regexp.MustCompile(`^[0-9]{1,2}(\.[0-9]{1,5})?$|^\.[0-9]{1,5}$`)
	// updateFilterIDPattern is an id filter as the server reads it: 0 for
	// every one, or a positive id of at most nine digits.
	updateFilterIDPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,8})$`)
)

// refuseStagedUpdate stops a command before any request when --staged is on.
// The server refuses staged=1 on the Update routes; refusing here as well
// says so before a dry run, a prompt or a partial list has happened.
func refuseStagedUpdate(cmd *cobra.Command) error {
	if !api.StagedMode() {
		return nil
	}
	return validationError("--staged cannot apply to `%s`: the Update endpoints write at once and cannot be recorded as a proposal", cmd.CommandPath()).
		WithHint("Drop --staged. --dry-run shows exactly what it would change without writing anything.")
}

// updateMalformed is a 2xx answer that is not what the Update API returns: the
// server's (or a proxy's) failure, never the caller's flags.
func updateMalformed(what string, err error) error {
	return &CLIError{
		Category: "server",
		ExitCode: ExitServer,
		Message:  fmt.Sprintf("%s: the server's answer is not the Update API's (%v)", what, err),
		Hint:     "Check the server with `p202 system health`; a proxy in front of it may have answered instead.",
		Cause:    err,
	}
}

// updateRequestError attaches the next step to a failed Update request.
// retrySafe is said when the failure may have happened after some of the
// write landed (a 5xx, a dropped connection), for the writes that are safe
// to send again; "" for a dry run, which writes nothing.
func updateRequestError(err error, retrySafe string) error {
	var apiErr *api.APIError
	if errors.As(err, &apiErr) && apiErr.Status == 404 {
		return withHint(err, "This server has no Update API (capabilities features.update_section): upgrade Prosper202, or use the Update pages in the UI.")
	}
	var reqErr *api.RequestError
	if errors.As(err, &reqErr) && reqErr.Op == api.OpDownloadTooLarge {
		return withHint(err, "The server's answer is larger than this client reads. Check the report with --dry-run on a part of it, or upload it through the UI (Update > Upload Revenue Reports).")
	}
	if retrySafe == "" {
		return err
	}
	if errors.As(err, &apiErr) && apiErr.Status >= 500 {
		return withHint(err, "%s", retrySafe)
	}
	if errors.As(err, &reqErr) && reqErr.Kind == "network" {
		return withHint(err, "The request may still have been running on the server when the connection ended. %s", retrySafe)
	}
	return err
}

// ─── p202 click update-cpc ──────────────────────────────────────────────

// cpcFilterFlags are the id filters of update-cpc: flag => what it names.
var cpcFilterFlags = []struct{ flag, what, list string }{
	{"aff-network-id", "category", "aff-network"},
	{"aff-campaign-id", "campaign", "campaign"},
	{"ppc-network-id", "traffic source", "ppc-network"},
	{"ppc-account-id", "traffic source account", "ppc-account"},
	{"landing-page-id", "landing page", "landing-page"},
	{"text-ad-id", "text ad", "text-ad"},
}

// cpcAnswer is what POST /clicks/cpc answers, read strictly: a count that is
// missing is a malformed answer, never 0.
type cpcAnswer struct {
	Data struct {
		DryRun         bool   `json:"dry_run"`
		Matching       *int64 `json:"matching"`
		ThroughClickID *int64 `json:"through_click_id"`
		Updated        *int64 `json:"updated"`
		CPC            string `json:"cpc"`
		From           string `json:"from"`
		To             string `json:"to"`
		Timezone       string `json:"timezone"`
		Filters        map[string]struct {
			ID    json.Number `json:"id"`
			Name  string      `json:"name"`
			Value string      `json:"value"`
		} `json:"filters"`
	} `json:"data"`
}

func readCPCAnswer(data []byte, what string) (cpcAnswer, error) {
	var a cpcAnswer
	if err := json.Unmarshal(data, &a); err != nil {
		return a, updateMalformed(what, err)
	}
	if a.Data.Matching == nil || a.Data.ThroughClickID == nil {
		return a, updateMalformed(what, errors.New("matching or through_click_id is missing"))
	}
	return a, nil
}

// cpcBody reads update-cpc's flags into the request body, refusing each bad
// value by name before any request (the server refuses them too).
func cpcBody(cmd *cobra.Command) (map[string]interface{}, error) {
	body := map[string]interface{}{}
	for _, d := range []struct{ flag, which string }{{"from", "first"}, {"to", "last"}} {
		v, _ := cmd.Flags().GetString(d.flag)
		v = strings.TrimSpace(v)
		if v == "" {
			return nil, validationError("--%s is required: the %s day to update, YYYY-MM-DD", d.flag, d.which).
				WithHint("Name the days in the account's time zone, e.g. --from 2026-10-01 --to 2026-10-07 (one day: the same date twice).")
		}
		if _, err := time.Parse("2006-01-02", v); err != nil || !updateDayPattern.MatchString(v) {
			return nil, validationError("--%s must be a day written YYYY-MM-DD, got %q", d.flag, v).
				WithHint("Write the day as YYYY-MM-DD, e.g. --%s 2026-10-01.", d.flag)
		}
		body[d.flag] = v
	}
	if body["from"].(string) > body["to"].(string) {
		return nil, validationError("--to (%s) is before --from (%s)", body["to"], body["from"]).
			WithHint("Swap them: --from is the first day, --to the last.")
	}

	cpc, _ := cmd.Flags().GetString("cpc")
	cpc = strings.TrimPrefix(strings.TrimSpace(cpc), "$")
	if cpc == "" {
		return nil, validationError("--cpc is required: what each click cost, in dollars").
			WithHint("E.g. --cpc 0.25; up to five decimals (--cpc 0.00125).")
	}
	if !updateCPCPattern.MatchString(cpc) {
		if f, err := strconv.ParseFloat(cpc, 64); err == nil && f > 99.99999 {
			return nil, validationError("--cpc can be at most 99.99999, got %s", cpc).
				WithHint("A click's cost is stored with two whole digits and five decimals.")
		}
		return nil, validationError("--cpc must be dollars with up to two whole digits and five decimals, got %q", cpc).
			WithHint("E.g. --cpc 0.25 or --cpc .00125.")
	}
	body["cpc"] = cpc

	for _, f := range cpcFilterFlags {
		v, _ := cmd.Flags().GetString(f.flag)
		if v == "" {
			continue
		}
		if !updateFilterIDPattern.MatchString(v) {
			return nil, validationError("--%s must be a %s id (or 0 for every %s), got %q", f.flag, f.what, f.what, v).
				WithHint("Find the id with `p202 %s list`.", f.list)
		}
		n, _ := strconv.Atoi(v)
		body[strings.ReplaceAll(f.flag, "-", "_")] = n
	}
	if m, _ := cmd.Flags().GetString("method-of-promotion"); m != "" {
		body["method_of_promotion"] = m
	}
	return body, nil
}

// cpcSelection says which clicks a check counted, in words.
func cpcSelection(a cpcAnswer) string {
	var narrowed []string
	for _, f := range cpcFilterFlags {
		key := strings.ReplaceAll(f.flag, "-", "_")
		if filter, ok := a.Data.Filters[key]; ok && filter.ID.String() != "0" && filter.ID.String() != "" {
			narrowed = append(narrowed, fmt.Sprintf("%s %q (%s)", f.what, filter.Name, filter.ID.String()))
		}
	}
	if m, ok := a.Data.Filters["method_of_promotion"]; ok && m.Value != "" {
		narrowed = append(narrowed, strings.ToLower(m.Name))
	}
	where := ""
	if len(narrowed) > 0 {
		where = ", " + strings.Join(narrowed, ", ")
	}
	return fmt.Sprintf("from %s to %s (%s)%s", a.Data.From, a.Data.To, a.Data.Timezone, where)
}

// renderCPC prints an answer: as the API sent it under --json, otherwise one
// flat record.
func renderCPC(data []byte, a cpcAnswer) error {
	if jsonOutput {
		render(data)
		return nil
	}
	row := map[string]interface{}{
		"dry_run":          a.Data.DryRun,
		"matching":         *a.Data.Matching,
		"through_click_id": *a.Data.ThroughClickID,
		"cpc":              a.Data.CPC,
		"from":             a.Data.From,
		"to":               a.Data.To,
		"timezone":         a.Data.Timezone,
	}
	if a.Data.Updated != nil {
		row["updated"] = *a.Data.Updated
	}
	for key, f := range a.Data.Filters {
		if key == "method_of_promotion" {
			row[key] = f.Name
			continue
		}
		row[key] = f.Name
		if id := f.ID.String(); id != "0" && id != "" {
			row[key] = fmt.Sprintf("%s (%s)", f.Name, id)
		}
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": row})
	if err != nil {
		return fmt.Errorf("encoding the CPC update: %w", err)
	}
	render(encoded)
	return nil
}

func runClickUpdateCPC(cmd *cobra.Command, _ []string) error {
	if err := refuseStagedUpdate(cmd); err != nil {
		return err
	}
	body, err := cpcBody(cmd)
	if err != nil {
		return err
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	force, _ := cmd.Flags().GetBool("force")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}

	// The check: how many clicks, and the highest click id among them.
	data, err := c.PostUpdate("clicks/cpc", map[string]string{"dry_run": "1"}, body)
	if err != nil {
		return updateRequestError(err, "")
	}
	check, err := readCPCAnswer(data, "checking the clicks")
	if err != nil {
		return err
	}
	matching, through := *check.Data.Matching, *check.Data.ThroughClickID
	if dryRun {
		if err := renderCPC(data, check); err != nil {
			return err
		}
		output.Success("Dry run: %d click(s) %s match; each would cost $%s. Nothing was written; drop --dry-run to update them (it asks first).",
			matching, cpcSelection(check), check.Data.CPC)
		return nil
	}
	if matching == 0 {
		if err := renderCPC(data, check); err != nil {
			return err
		}
		output.Success("No click %s matches, so there is nothing to update. Widen the days or the filters.", cpcSelection(check))
		return nil
	}
	if !force {
		fmt.Fprintf(os.Stderr, "%d click(s) %s will cost $%s each, whatever they cost before. Clicks recorded after this check are left alone.\n",
			matching, cpcSelection(check), check.Data.CPC)
		ok, err := confirmAction(cmd, "Set the CPC of %d click(s) to $%s?", matching, check.Data.CPC)
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}

	// The update, bounded by what the check counted: it runs only if those
	// clicks still number `matching`, so it never changes a click nobody saw.
	body["expect_clicks"] = matching
	body["through_click_id"] = through
	data, err = c.PostUpdate("clicks/cpc", nil, body)
	if err != nil {
		var apiErr *api.APIError
		if errors.As(err, &apiErr) && apiErr.Status == 409 {
			return withHint(err, "The clicks in this selection changed between the check and the update, so nothing was written. Run the same command again: it counts them again and asks again.")
		}
		return updateRequestError(err, "The update is one transaction: either every counted click has the new CPC or none does. Check with `p202 click update-cpc ... --dry-run`, then run it again; setting the same CPC again changes nothing more.")
	}
	done, err := readCPCAnswer(data, "updating the clicks")
	if err != nil {
		return err
	}
	if done.Data.Updated == nil {
		return updateMalformed("updating the clicks", errors.New("updated is missing"))
	}
	if err := renderCPC(data, done); err != nil {
		return err
	}
	output.Success("Updated: the %d click(s) %s now cost $%s (%d changed; the rest already cost that). Reports show the new cost once the data engine rebuilds those hours (its cron job).",
		matching, cpcSelection(check), done.Data.CPC, *done.Data.Updated)
	return nil
}

func newClickUpdateCPCCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "update-cpc",
		Short: "Set what a set of past clicks cost (the UI's Update CPC), after counting them",
		Long: "Sets the cost (CPC) of the account's clicks between --from 00:00:00 and --to 23:59:59 in\n" +
			"the account's time zone, narrowed by any of the id filters and --method-of-promotion.\n\n" +
			"It counts the clicks first (POST /clicks/cpc?dry_run=1) and says how many, then asks\n" +
			"(--force skips the question; --dry-run stops after the count). The update carries the\n" +
			"count and the highest click id it saw, and the server writes only if those clicks still\n" +
			"number the same — so a click recorded after the count is never changed, and when the\n" +
			"selection moved nothing is written (exit 1; run the command again).\n\n" +
			"Needs a role with access_to_update_section and a key with clicks:write. Cannot be staged.\n" +
			"Reports show the new cost once the data engine rebuilds the hours (its cron job).",
		Example: "  p202 click update-cpc --from 2026-10-01 --to 2026-10-07 --cpc 0.25 --aff-campaign-id 12 --dry-run\n" +
			"  p202 click update-cpc --from 2026-10-07 --to 2026-10-07 --cpc 0.00125 --ppc-account-id 3\n" +
			"  p202 click update-cpc --from 2026-10-01 --to 2026-10-31 --cpc 1.10 --method-of-promotion directlink --force",
		Args: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return validationError("click update-cpc takes no arguments, got %q", args[0]).
					WithHint("Name the clicks with flags: --from, --to, --cpc and any filter (`p202 click update-cpc --help`).")
			}
			return nil
		},
		RunE: runClickUpdateCPC,
	}
	cmd.Flags().String("from", "", "First day, YYYY-MM-DD, in the account's time zone (required)")
	cmd.Flags().String("to", "", "Last day, YYYY-MM-DD, in the account's time zone (required)")
	cmd.Flags().String("cpc", "", "What each click cost, in dollars, up to 99.99999 with five decimals (required)")
	for _, f := range cpcFilterFlags {
		cmd.Flags().String(f.flag, "", fmt.Sprintf("Only this %s's clicks (id from `p202 %s list`; 0 = every %s)", f.what, f.list, f.what))
		emptyHint(cmd, f.flag, fmt.Sprintf("Omit --%s for every %s, or pass an id from `p202 %s list`.", f.flag, f.what, f.list))
	}
	cmd.Flags().String("method-of-promotion", "", "Only direct-link or only landing-page clicks (default: both)")
	enumFlag(cmd, "method-of-promotion", newEnum([]string{"directlink", "landingpage"}))
	emptyHint(cmd, "method-of-promotion", "Omit --method-of-promotion for both, or pass directlink or landingpage.")
	cmd.Flags().Bool("dry-run", false, "Count the clicks and write nothing")
	cmd.Flags().BoolP("force", "f", false, "Update without asking (the count is still checked)")
	return cmd
}

// ─── Subid lists: mark-subids, delete-subids ────────────────────────────

// readSubidLines reads a subid list, one per line, from a file or (for "-")
// from piped stdin. Every line is kept, blank ones included, so the line
// numbers the server answers with are the file's.
func readSubidLines(source string) ([]string, error) {
	var raw []byte
	var err error
	if source == "-" {
		if isTerminal(os.Stdin) {
			return nil, validationError("reading the subids from stdin, but stdin is a terminal").
				WithHint("Pipe the list in (`cat subids.txt | %s -`) or name the file.", strings.TrimSpace(activeCommandPath))
		}
		raw, err = io.ReadAll(os.Stdin)
	} else {
		raw, err = os.ReadFile(source)
	}
	if err != nil {
		return nil, &CLIError{Category: "validation", ExitCode: ExitValidation, Cause: err,
			Message: fmt.Sprintf("cannot read the subids from %s: %v", source, err),
			Hint:    "Name a file holding one subid per line, or - to read them from stdin."}
	}
	text := strings.ReplaceAll(strings.ReplaceAll(string(raw), "\r\n", "\n"), "\r", "\n")
	lines := strings.Split(text, "\n")
	if len(lines) > 0 && lines[len(lines)-1] == "" {
		lines = lines[:len(lines)-1]
	}
	for _, l := range lines {
		if strings.TrimSpace(l) != "" {
			return lines, nil
		}
	}
	return nil, validationError("%s holds no subid", source).
		WithHint("Put one subid per line: the click id from your network's report (`p202 click list` shows click ids).")
}

// subidKey is the click a line names, as the server reads a subid: digits, no
// sign, no leading zero, within 64 bits. Two lines name the same click only
// when their trimmed text is equal, so equal text is how a repeat is found.
func subidKey(line string) (string, int64, bool) {
	s := strings.TrimSpace(line)
	if !positiveIDPattern.MatchString(s) {
		return s, 0, false
	}
	n, err := strconv.ParseInt(s, 10, 64)
	return s, n, err == nil
}

// subidListAnswer is what the subid endpoints answer.
type subidListAnswer struct {
	Data struct {
		Lines       []map[string]interface{} `json:"lines"`
		Conversions *int64                   `json:"conversions"`
	} `json:"data"`
}

// subidRun is a whole list's answer, put together from its parts.
type subidRun struct {
	Lines       []map[string]interface{}
	Conversions int64
}

// sendSubidList sends a list to a subid endpoint in parts of
// updateSubidsPerRequest lines, and puts the answers together with the
// file's line numbers. A subid repeated anywhere in the list is sent once
// and answered duplicate_in_list here, as the server answers a repeat within
// one request, so a repeat in a later part is not processed twice.
//
// On a failure it returns what the parts before it answered, and how many
// lines they covered: their writes stand.
func sendSubidList(c *api.Client, path string, lines []string, dryRun bool) (subidRun, int, error) {
	run := subidRun{}
	send := make([]string, len(lines))
	firstLine := map[string]int{}
	for i, l := range lines {
		key, clickID, ok := subidKey(l)
		if ok {
			if first, seen := firstLine[key]; seen {
				run.Lines = append(run.Lines, map[string]interface{}{
					"line": i + 1, "subid": key, "click_id": clickID, "status": "duplicate_in_list", "first_line": first,
				})
				continue // sent as a blank, which the server skips and does not answer
			}
			firstLine[key] = i + 1
		}
		send[i] = l
	}

	var params map[string]string
	if dryRun {
		params = map[string]string{"dry_run": "1"}
	}
	for start := 0; start < len(send); start += updateSubidsPerRequest {
		end := start + updateSubidsPerRequest
		if end > len(send) {
			end = len(send)
		}
		part := send[start:end]
		blank := true
		for _, l := range part {
			if strings.TrimSpace(l) != "" {
				blank = false
				break
			}
		}
		if blank {
			continue
		}
		data, err := c.PostUpdate(path, params, map[string]interface{}{"subids": part})
		if err != nil {
			return run, start, err
		}
		var answer subidListAnswer
		if err := json.Unmarshal(data, &answer); err != nil || answer.Data.Lines == nil {
			if err == nil {
				err = errors.New("lines is missing")
			}
			return run, start, updateMalformed("sending the subids", err)
		}
		for _, line := range answer.Data.Lines {
			for _, key := range []string{"line", "first_line"} {
				if n, ok := line[key].(float64); ok {
					line[key] = int(n) + start
				}
			}
			run.Lines = append(run.Lines, line)
		}
		if answer.Data.Conversions != nil {
			run.Conversions += *answer.Data.Conversions
		}
	}
	sort.SliceStable(run.Lines, func(i, j int) bool { return lineNumber(run.Lines[i]) < lineNumber(run.Lines[j]) })
	return run, len(send), nil
}

func lineNumber(line map[string]interface{}) int {
	switch n := line["line"].(type) {
	case int:
		return n
	case float64:
		return int(n)
	}
	return 0
}

// countStatuses counts the lines by status: every status named, 0 included,
// and any other one the server answered under its own name.
func countStatuses(lines []map[string]interface{}, statuses []string) map[string]int {
	counts := map[string]int{}
	for _, s := range statuses {
		counts[s] = 0
	}
	for _, line := range lines {
		s, _ := line["status"].(string)
		counts[s]++
	}
	return counts
}

// renderSubidRun prints a list's answer: under --json the API's shape (the
// counts and every line), otherwise one row per line.
func renderSubidRun(run subidRun, dryRun bool, counts map[string]int, withConversions bool) error {
	if jsonOutput {
		out := map[string]interface{}{"dry_run": dryRun, "lines": run.Lines}
		for s, n := range counts {
			out[s] = n
		}
		if withConversions {
			out["conversions"] = run.Conversions
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": out})
		if err != nil {
			return fmt.Errorf("encoding the subid results: %w", err)
		}
		render(encoded)
		return nil
	}
	rows := make([]map[string]interface{}, 0, len(run.Lines))
	for _, line := range run.Lines {
		detail := ""
		if first, ok := line["first_line"]; ok {
			detail = fmt.Sprintf("same click as line %v", first)
		} else if n, ok := line["conversions"].(float64); ok {
			detail = fmt.Sprintf("%d conversion(s)", int(n))
		}
		rows = append(rows, map[string]interface{}{
			"line": line["line"], "subid": line["subid"], "click_id": line["click_id"], "status": line["status"], "detail": detail,
		})
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		return fmt.Errorf("encoding the subid results: %w", err)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = []string{"line", "subid", "click_id", "status", "detail"}
	}
	output.RenderWith(encoded, opts)
	return nil
}

// subidListError is a failed part of a list: what to do next depends on
// whether parts before it had written.
func subidListError(err error, sentLines int, dryRun bool, retrySafe string) error {
	if dryRun {
		return updateRequestError(err, "")
	}
	if sentLines == 0 {
		return updateRequestError(err, retrySafe)
	}
	// Whatever failed, the parts before it wrote: that is the next thing to know.
	return withHint(err, "Lines 1-%d were sent first and what they wrote stands. %s", sentLines, retrySafe)
}

func runConversionMarkSubids(cmd *cobra.Command, args []string) error {
	if err := refuseStagedUpdate(cmd); err != nil {
		return err
	}
	lines, err := readSubidLines(args[0])
	if err != nil {
		return err
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	done := "marked"
	if dryRun {
		done = "would_mark"
	}
	run, sent, err := sendSubidList(c, "conversions/subids", lines, dryRun)
	if err != nil {
		return subidListError(err, sent, dryRun, "Running the same command again is safe: a subid already marked is left as it is, so only the rest are marked.")
	}
	counts := countStatuses(run.Lines, []string{done, "already_converted", "not_found", "not_a_subid", "duplicate_in_list"})
	if err := renderSubidRun(run, dryRun, counts, false); err != nil {
		return err
	}
	verb, tail := "Marked", ""
	if dryRun {
		verb, tail = "Dry run: would mark", " Nothing was written; drop --dry-run to mark them."
	}
	output.Success("%s %d subid(s) converted; %d already converted, %d not found in this account, %d not a subid, %d repeated.%s",
		verb, counts[done], counts["already_converted"], counts["not_found"], counts["not_a_subid"], counts["duplicate_in_list"], tail)
	return nil
}

func runConversionDeleteSubids(cmd *cobra.Command, args []string) error {
	if err := refuseStagedUpdate(cmd); err != nil {
		return err
	}
	lines, err := readSubidLines(args[0])
	if err != nil {
		return err
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	force, _ := cmd.Flags().GetBool("force")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}
	statuses := []string{"would_clear", "not_found", "not_a_subid", "duplicate_in_list"}
	preview, sent, err := sendSubidList(c, "conversions/subids/delete", lines, true)
	if err != nil {
		return subidListError(err, sent, true, "")
	}
	counts := countStatuses(preview.Lines, statuses)
	if dryRun {
		if err := renderSubidRun(preview, true, counts, true); err != nil {
			return err
		}
		output.Success("Dry run: %d subid(s) of this account hold %d conversion(s) that would be cleared; %d not found, %d not a subid, %d repeated. Nothing was written; drop --dry-run to delete them (it asks first).",
			counts["would_clear"], preview.Conversions, counts["not_found"], counts["not_a_subid"], counts["duplicate_in_list"])
		return nil
	}
	if counts["would_clear"] == 0 {
		if err := renderSubidRun(preview, true, counts, true); err != nil {
			return err
		}
		output.Success("None of the subids is a click of this account (%d not found, %d not a subid); nothing to delete.", counts["not_found"], counts["not_a_subid"])
		return nil
	}
	if !force {
		fmt.Fprintf(os.Stderr, "%d subid(s) of this account hold %d conversion(s). Deleting clears them all: the clicks stay, are no longer leads, and the income comes off your reports.\n",
			counts["would_clear"], preview.Conversions)
		ok, err := confirmAction(cmd, "Delete the conversions of %d subid(s)?", counts["would_clear"])
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	run, sent, err := sendSubidList(c, "conversions/subids/delete", lines, false)
	if err != nil {
		return subidListError(err, sent, false, "Running the same command again is safe: a subid already cleared has nothing more to delete.")
	}
	counts = countStatuses(run.Lines, []string{"cleared", "not_found", "not_a_subid", "duplicate_in_list"})
	if err := renderSubidRun(run, false, counts, false); err != nil {
		return err
	}
	output.Success("Cleared the conversions of %d subid(s); %d not found in this account, %d not a subid, %d repeated.",
		counts["cleared"], counts["not_found"], counts["not_a_subid"], counts["duplicate_in_list"])
	return nil
}

func subidFileArgs(name string) cobra.PositionalArgs {
	return func(cmd *cobra.Command, args []string) error {
		if len(args) != 1 {
			return validationError("conversion %s takes one file of subids (or - for stdin), got %d argument(s)", name, len(args)).
				WithHint("Put one subid per line in a file: `p202 conversion %s subids.txt` (or pipe it: `... | p202 conversion %s -`).", name, name)
		}
		return nil
	}
}

func newConversionMarkSubidsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "mark-subids <file|->",
		Short: "Mark clicks converted from a list of subids, one per line (the UI's Update Subids)",
		Long: "Records each subid's click as converted, at its campaign's payout, through the conversion\n" +
			"ledger (source subid_upload) — once per click: a click that is already a lead is answered\n" +
			"already_converted and nothing is added, so running the same list twice is safe.\n\n" +
			"The file holds one subid (a click id) per line; - reads the list from piped stdin. Every\n" +
			"line is answered with its file line number: marked (would_mark with --dry-run),\n" +
			"already_converted, not_found (no click of this account), not_a_subid, or duplicate_in_list.\n" +
			"A long list is sent 1,000 lines a request; when one fails, the lines before it stand and\n" +
			"running the command again marks the rest.\n\n" +
			"Needs a role with access_to_update_section and a key with conversions:write. Cannot be staged.",
		Example: "  p202 conversion mark-subids subids.txt --dry-run\n" +
			"  p202 conversion mark-subids subids.txt\n" +
			"  cut -d, -f2 network-report.csv | p202 conversion mark-subids - --json",
		Args: subidFileArgs("mark-subids"),
		RunE: runConversionMarkSubids,
	}
	cmd.Flags().Bool("dry-run", false, "Say what each line would do and write nothing")
	return cmd
}

func newConversionDeleteSubidsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "delete-subids <file|->",
		Short: "Delete the conversions of a list of subids, one per line (the UI's Delete Subids)",
		Long: "The reverse of mark-subids: every live conversion of each subid's click is cleared through\n" +
			"the ledger (soft-deleted, its revenue voided, the click's value recomputed), so the click\n" +
			"is no longer a lead; the click itself stays. A subid of another account is left alone.\n\n" +
			"It reads the list first (--dry-run stops there), says how many conversions it would clear,\n" +
			"and asks (--force skips). Lines are answered cleared (would_clear), not_found, not_a_subid\n" +
			"or duplicate_in_list, with the file's line numbers. Running it twice is safe.\n\n" +
			"Needs a role with access_to_update_section AND delete_individual_subids, and a key with\n" +
			"conversions:write. Cannot be staged. To clear a whole campaign, use reset-subids.",
		Example: "  p202 conversion delete-subids wrong-subids.txt --dry-run\n" +
			"  p202 conversion delete-subids wrong-subids.txt --force --json",
		Args: subidFileArgs("delete-subids"),
		RunE: runConversionDeleteSubids,
	}
	cmd.Flags().Bool("dry-run", false, "Say what each line would clear and write nothing")
	cmd.Flags().BoolP("force", "f", false, "Delete without asking")
	return cmd
}

// ─── p202 conversion reset-subids ───────────────────────────────────────

type resetAnswer struct {
	Data struct {
		DryRun     bool   `json:"dry_run"`
		Matching   *int64 `json:"matching"`
		Cleared    *int64 `json:"cleared"`
		AffNetwork struct {
			ID   json.Number `json:"id"`
			Name string      `json:"name"`
		} `json:"aff_network"`
		AffCampaign *struct {
			ID   json.Number `json:"id"`
			Name string      `json:"name"`
		} `json:"aff_campaign"`
	} `json:"data"`
}

// renderReset prints a reset's answer: under --json as the API sent it,
// otherwise one flat record naming the category and campaign.
func renderReset(data []byte, a resetAnswer) error {
	if jsonOutput {
		render(data)
		return nil
	}
	row := map[string]interface{}{
		"dry_run":     a.Data.DryRun,
		"aff_network": fmt.Sprintf("%s (%s)", a.Data.AffNetwork.Name, a.Data.AffNetwork.ID.String()),
	}
	row["aff_campaign"] = "every campaign in the category"
	if a.Data.AffCampaign != nil {
		row["aff_campaign"] = fmt.Sprintf("%s (%s)", a.Data.AffCampaign.Name, a.Data.AffCampaign.ID.String())
	}
	if a.Data.Matching != nil {
		row["matching"] = *a.Data.Matching
	}
	if a.Data.Cleared != nil {
		row["cleared"] = *a.Data.Cleared
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": row})
	if err != nil {
		return fmt.Errorf("encoding the reset: %w", err)
	}
	render(encoded)
	return nil
}

func (a resetAnswer) scope() string {
	s := fmt.Sprintf("category %q (%s)", a.Data.AffNetwork.Name, a.Data.AffNetwork.ID.String())
	if a.Data.AffCampaign != nil {
		s = fmt.Sprintf("campaign %q (%s) of %s", a.Data.AffCampaign.Name, a.Data.AffCampaign.ID.String(), s)
	}
	return s
}

func runConversionResetSubids(cmd *cobra.Command, _ []string) error {
	if err := refuseStagedUpdate(cmd); err != nil {
		return err
	}
	body := map[string]interface{}{}
	network, _ := cmd.Flags().GetString("aff-network-id")
	if network == "" {
		return validationError("--aff-network-id is required: the category whose subids to reset").
			WithHint("Find the id with `p202 aff-network list`; add --aff-campaign-id to reset one campaign of it.")
	}
	for _, f := range []struct{ flag, list string }{{"aff-network-id", "aff-network"}, {"aff-campaign-id", "campaign"}} {
		v, _ := cmd.Flags().GetString(f.flag)
		if v == "" {
			continue
		}
		if !positiveIDPattern.MatchString(v) {
			return validationError("--%s must be a positive id, got %q", f.flag, v).WithHint("Find the id with `p202 %s list`.", f.list)
		}
		n, _ := strconv.ParseInt(v, 10, 64)
		body[strings.ReplaceAll(f.flag, "-", "_")] = n
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	force, _ := cmd.Flags().GetBool("force")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}

	data, err := c.PostUpdate("conversions/subids/reset", map[string]string{"dry_run": "1"}, body)
	if err != nil {
		return updateRequestError(err, "")
	}
	var check resetAnswer
	if err := json.Unmarshal(data, &check); err != nil || check.Data.Matching == nil {
		if err == nil {
			err = errors.New("matching is missing")
		}
		return updateMalformed("counting the clicks to reset", err)
	}
	if dryRun || *check.Data.Matching == 0 {
		if err := renderReset(data, check); err != nil {
			return err
		}
		if dryRun {
			output.Success("Dry run: %d converted click(s) of the %s would be cleared. Nothing was written; drop --dry-run to reset them (it asks first).", *check.Data.Matching, check.scope())
		} else {
			output.Success("The %s has no converted click; nothing to reset.", check.scope())
		}
		return nil
	}
	if !force {
		fmt.Fprintf(os.Stderr, "Every conversion of %d click(s) of the %s will be cleared: the clicks stay, are no longer leads, and the income comes off your reports until you upload the subids again.\n",
			*check.Data.Matching, check.scope())
		ok, err := confirmAction(cmd, "Reset the subids of %d click(s)?", *check.Data.Matching)
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	data, err = c.PostUpdate("conversions/subids/reset", nil, body)
	if err != nil {
		return updateRequestError(err, "Running the same command again is safe: it clears what is left.")
	}
	var done resetAnswer
	if err := json.Unmarshal(data, &done); err != nil || done.Data.Cleared == nil {
		if err == nil {
			err = errors.New("cleared is missing")
		}
		return updateMalformed("resetting the subids", err)
	}
	if err := renderReset(data, done); err != nil {
		return err
	}
	output.Success("Reset: %d click(s) of the %s cleared. You can upload the right subids again.", *done.Data.Cleared, done.scope())
	return nil
}

func newConversionResetSubidsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "reset-subids",
		Short: "Clear every conversion of a category or one of its campaigns (the UI's Reset Campaign Subids)",
		Long: "Clears every conversion of the category (--aff-network-id), or of one campaign in it\n" +
			"(--aff-campaign-id), so a report uploaded by mistake can be uploaded again: each converted\n" +
			"click is cleared through the ledger and the data engine rebuilds the hours. Clicks stay.\n\n" +
			"It counts the clicks first (--dry-run stops there) and asks (--force skips). Running it\n" +
			"again is safe: it clears what is left.\n\n" +
			"Needs a role with access_to_update_section and a key with conversions:write. Cannot be staged.",
		Example: "  p202 conversion reset-subids --aff-network-id 3 --dry-run\n" +
			"  p202 conversion reset-subids --aff-network-id 3 --aff-campaign-id 12 --force",
		Args: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return validationError("conversion reset-subids takes no arguments, got %q", args[0]).
					WithHint("Name the category with --aff-network-id (and a campaign with --aff-campaign-id).")
			}
			return nil
		},
		RunE: runConversionResetSubids,
	}
	cmd.Flags().String("aff-network-id", "", "The category whose subids to reset (id from `p202 aff-network list`; required)")
	cmd.Flags().String("aff-campaign-id", "", "Only this campaign of the category (id from `p202 campaign list`; default: every campaign in it)")
	emptyHint(cmd, "aff-network-id", "Pass the category's id from `p202 aff-network list`.")
	emptyHint(cmd, "aff-campaign-id", "Omit --aff-campaign-id to reset the whole category, or pass a campaign id from `p202 campaign list`.")
	cmd.Flags().Bool("dry-run", false, "Count the clicks and write nothing")
	cmd.Flags().BoolP("force", "f", false, "Reset without asking")
	return cmd
}

// ─── p202 conversion upload-revenue ─────────────────────────────────────

type uploadAnswer struct {
	Data struct {
		DryRun      bool   `json:"dry_run"`
		BatchID     *int64 `json:"batch_id"`
		Recorded    *int64 `json:"recorded"`
		WouldRecord *int64 `json:"would_record"`
		Skipped     *int64 `json:"skipped"`
		Columns     struct {
			Subid struct {
				Index  int    `json:"index"`
				Header string `json:"header"`
			} `json:"subid"`
			Amount struct {
				Index  int    `json:"index"`
				Header string `json:"header"`
			} `json:"amount"`
			Guessed []string `json:"guessed"`
		} `json:"columns"`
		Totals []struct {
			ClickID json.Number `json:"click_id"`
			Total   string      `json:"total"`
		} `json:"totals"`
		Lines []map[string]interface{} `json:"lines"`
	} `json:"data"`
}

func (a uploadAnswer) columns() string {
	guessed := ""
	if len(a.Data.Columns.Guessed) > 0 {
		guessed = " (read from the header; name others with --subid-column/--amount-column)"
	}
	return fmt.Sprintf("subid column %q (#%d), commission column %q (#%d)%s",
		a.Data.Columns.Subid.Header, a.Data.Columns.Subid.Index, a.Data.Columns.Amount.Header, a.Data.Columns.Amount.Index, guessed)
}

// total is the sum of the report's per-click totals, exactly.
func (a uploadAnswer) total() string {
	sum := new(big.Rat)
	for _, t := range a.Data.Totals {
		if r, ok := new(big.Rat).SetString(t.Total); ok {
			sum.Add(sum, r)
		}
	}
	return strings.TrimRight(strings.TrimRight(sum.FloatString(5), "0"), ".")
}

func readUploadAnswer(data []byte, what string, dryRun bool) (uploadAnswer, error) {
	var a uploadAnswer
	if err := json.Unmarshal(data, &a); err != nil {
		return a, updateMalformed(what, err)
	}
	if a.Data.Skipped == nil || a.Data.Lines == nil || (dryRun && a.Data.WouldRecord == nil) || (!dryRun && (a.Data.Recorded == nil || a.Data.BatchID == nil)) {
		return a, updateMalformed(what, errors.New("a count or the lines are missing"))
	}
	return a, nil
}

// renderUpload prints an upload's answer: under --json the API's shape,
// otherwise the lines not recorded (with the reason), as the page lists them.
func renderUpload(data []byte, a uploadAnswer) error {
	if jsonOutput {
		render(data)
		return nil
	}
	rows := make([]map[string]interface{}, 0, len(a.Data.Lines))
	for _, l := range a.Data.Lines {
		rows = append(rows, map[string]interface{}{"line": l["line"], "subid": l["subid"], "amount": l["amount"], "status": l["status"], "reason": l["reason"]})
	}
	encoded, err := json.Marshal(map[string]interface{}{"data": rows})
	if err != nil {
		return fmt.Errorf("encoding the report's lines: %w", err)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = []string{"line", "subid", "amount", "status", "reason"}
	}
	output.RenderWith(encoded, opts)
	return nil
}

// uploadColumn is a column flag as the API takes it: a 0-based index when
// the value is a number, otherwise a header name.
func uploadColumn(v string) interface{} {
	if n, err := strconv.Atoi(v); err == nil && n >= 0 && strconv.Itoa(n) == v {
		return n
	}
	return v
}

func runConversionUploadRevenue(cmd *cobra.Command, args []string) error {
	if err := refuseStagedUpdate(cmd); err != nil {
		return err
	}
	raw, err := os.ReadFile(args[0])
	if err != nil {
		return &CLIError{Category: "validation", ExitCode: ExitValidation, Cause: err,
			Message: fmt.Sprintf("cannot read the report %s: %v", args[0], err),
			Hint:    "Name the network's CSV report: `p202 conversion upload-revenue report.csv --dry-run`."}
	}
	if strings.TrimSpace(string(raw)) == "" {
		return validationError("%s is empty", args[0]).WithHint("Export the report from your network as CSV, with a header line.")
	}
	fileName, _ := cmd.Flags().GetString("file-name")
	if fileName == "" {
		fileName = filepath.Base(args[0])
	}
	body := map[string]interface{}{"csv": string(raw), "file_name": fileName}
	for _, f := range []string{"subid-column", "amount-column"} {
		if v, _ := cmd.Flags().GetString(f); v != "" {
			body[strings.ReplaceAll(f, "-", "_")] = uploadColumn(v)
		}
	}
	if encoded, err := json.Marshal(body); err != nil {
		return fmt.Errorf("encoding the report: %w", err)
	} else if len(encoded) > updateUploadMaxBytes {
		return validationError("%s is too large to upload: %d bytes as sent, and the server takes at most %d", args[0], len(encoded), updateUploadMaxBytes).
			WithHint("Upload it through the UI (Update > Upload Revenue Reports), or ask the network for a report of fewer days.")
	}
	dryRun, _ := cmd.Flags().GetBool("dry-run")
	force, _ := cmd.Flags().GetBool("force")
	c, err := api.NewFromConfig()
	if err != nil {
		return err
	}

	data, err := c.PostUpdate("conversions/uploads", map[string]string{"dry_run": "1"}, body)
	if err != nil {
		return updateRequestError(err, "")
	}
	check, err := readUploadAnswer(data, "reading the report", true)
	if err != nil {
		return err
	}
	if dryRun {
		if err := renderUpload(data, check); err != nil {
			return err
		}
		output.Success("Dry run: %d line(s) would be recorded on %d click(s), %s in all; %d skipped (listed). Columns: %s. Nothing was written; drop --dry-run to upload (it asks first).",
			*check.Data.WouldRecord, len(check.Data.Totals), "$"+check.total(), *check.Data.Skipped, check.columns())
		return nil
	}
	if *check.Data.WouldRecord == 0 {
		// Nothing on stdout: a failure prints only its error (the CLI's
		// contract), and --dry-run is how the lines are listed.
		return validationError("none of the lines of %s can be recorded (%d skipped; %s)", args[0], *check.Data.Skipped, check.columns()).
			WithHint("If the subid column is the wrong one (it was %q), name the right one with --subid-column <header or 0-based number>; --dry-run lists every skipped line with its reason.", check.Data.Columns.Subid.Header)
	}
	if !force {
		fmt.Fprintf(os.Stderr, "%d line(s) of %s will be recorded on %d click(s), %s in all, as a new upload batch: each click's value becomes its sum in this report, replacing what earlier uploads and conversions set. %d line(s) are skipped. Columns: %s.\n",
			*check.Data.WouldRecord, fileName, len(check.Data.Totals), "$"+check.total(), *check.Data.Skipped, check.columns())
		ok, err := confirmAction(cmd, "Upload %s?", fileName)
		if err != nil {
			return err
		}
		if !ok {
			fmt.Fprintln(os.Stderr, "Cancelled.")
			return nil
		}
	}
	data, err = c.PostUpdate("conversions/uploads", nil, body)
	if err != nil {
		return updateRequestError(err, "Uploading the same report again is safe: it records every line as a new batch, whose values replace the earlier batch's.")
	}
	done, err := readUploadAnswer(data, "uploading the report", false)
	if err != nil {
		return err
	}
	if err := renderUpload(data, done); err != nil {
		return err
	}
	output.Success("Uploaded %s as batch %d: %d line(s) recorded on %d click(s), %s in all; %d skipped.",
		fileName, *done.Data.BatchID, *done.Data.Recorded, len(done.Data.Totals), "$"+done.total(), *done.Data.Skipped)
	return nil
}

func newConversionUploadRevenueCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "upload-revenue <file.csv>",
		Short: "Record a network's revenue report, a CSV of subid and commission (the UI's Upload Revenue Reports)",
		Long: "Records each line of the report as a conversion of a new upload batch on its subid's\n" +
			"click (source revenue_upload), at the line's commission. A click's lines in one report are\n" +
			"summed, and the newest report replaces what earlier reports and conversions set for that\n" +
			"click — so uploading a corrected report fixes the old one.\n\n" +
			"Columns are read from the header when it names them plainly (subid: sub id, click id,\n" +
			"t202…, aff_sub, sid; commission: commission, payout, revenue, amount, earning, sale,\n" +
			"income); name others with --subid-column/--amount-column (a header, or a 0-based column\n" +
			"number). The report is read on the server first (--dry-run stops there), then you confirm\n" +
			"(--force skips). Lines not recorded are listed with the reason. Unlike `conversion import`,\n" +
			"this is the UI's upload: one batch, values replacing earlier uploads, no transaction ids.\n\n" +
			"Needs a role with access_to_update_section and a key with conversions:write. At most 8 MB.\n" +
			"Cannot be staged.",
		Example: "  p202 conversion upload-revenue march.csv --dry-run\n" +
			"  p202 conversion upload-revenue march.csv --subid-column 'Sub ID 2' --amount-column Commission\n" +
			"  p202 conversion upload-revenue report.csv --subid-column 0 --amount-column 3 --force --json",
		Args: func(cmd *cobra.Command, args []string) error {
			if len(args) != 1 {
				return validationError("conversion upload-revenue takes one CSV file, got %d argument(s)", len(args)).
					WithHint("Name the network's report: `p202 conversion upload-revenue report.csv --dry-run`.")
			}
			return nil
		},
		RunE: runConversionUploadRevenue,
	}
	cmd.Flags().String("subid-column", "", "The subid column: its header, or a 0-based column number (default: read from the header)")
	cmd.Flags().String("amount-column", "", "The commission column: its header, or a 0-based column number (default: read from the header)")
	cmd.Flags().String("file-name", "", "The name the upload is listed under (default: the file's name)")
	emptyHint(cmd, "subid-column", "Omit --subid-column to read it from the header, or name the column (header or 0-based number).")
	emptyHint(cmd, "amount-column", "Omit --amount-column to read it from the header, or name the column (header or 0-based number).")
	emptyHint(cmd, "file-name", "Omit --file-name to list the upload under the file's own name.")
	cmd.Flags().Bool("dry-run", false, "Read the report on the server and write nothing")
	cmd.Flags().BoolP("force", "f", false, "Upload without asking")
	return cmd
}

func init() {
	clickCmd.AddCommand(newClickUpdateCPCCmd())
	conversionCmd.AddCommand(newConversionMarkSubidsCmd(), newConversionDeleteSubidsCmd(), newConversionResetSubidsCmd(), newConversionUploadRevenueCmd())
}
