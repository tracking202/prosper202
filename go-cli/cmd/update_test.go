package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"
)

// updateRequest is one request an Update command sent.
type updateRequest struct {
	Method string
	Path   string
	Query  string
	Body   map[string]interface{}
}

func (r updateRequest) dryRun() bool { return r.Query == "dry_run=1" }

// updateServer answers the Update endpoints with respond and records what was
// sent. The capabilities probe is answered for it (withCapabilities).
func updateServer(t *testing.T, respond func(r updateRequest) (int, string)) *[]updateRequest {
	t.Helper()
	var mu sync.Mutex
	var seen []updateRequest
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		req := updateRequest{Method: r.Method, Path: strings.TrimPrefix(r.URL.Path, "/api/v3"), Query: r.URL.RawQuery}
		if data, _ := io.ReadAll(r.Body); len(data) > 0 {
			if err := json.Unmarshal(data, &req.Body); err != nil {
				t.Errorf("request body is not a JSON object: %s", data)
			}
		}
		mu.Lock()
		seen = append(seen, req)
		mu.Unlock()
		status, body := respond(req)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	home := t.TempDir()
	setTestHome(t, home)
	writeTestConfig(t, home, srv.URL, "test-key")
	return &seen
}

const cpcCheck = `{"data":{"dry_run":true,"matching":3,"through_click_id":940003,"cpc":"0.25000","from":"2026-10-01","to":"2026-10-07","from_time":1,"to_time":2,"timezone":"America/New_York",
  "filters":{"aff_network_id":{"id":0,"name":"Every category"},"aff_campaign_id":{"id":12,"name":"Hosting"},"ppc_network_id":{"id":0,"name":"Every traffic source"},
  "ppc_account_id":{"id":0,"name":"Every account"},"landing_page_id":{"id":0,"name":"Every landing page"},"text_ad_id":{"id":0,"name":"Every text ad"},
  "method_of_promotion":{"value":"directlink","name":"Direct links only"}}}}`

const cpcDone = `{"data":{"dry_run":false,"matching":3,"through_click_id":940003,"updated":2,"cpc":"0.25000","from":"2026-10-01","to":"2026-10-07","from_time":1,"to_time":2,"timezone":"America/New_York","filters":{}}}`

func cpcResponder(write func() (int, string)) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		if r.dryRun() {
			return 200, cpcCheck
		}
		return write()
	}
}

var cpcArgs = []string{"click", "update-cpc", "--from", "2026-10-01", "--to", "2026-10-07", "--cpc", "$0.25",
	"--aff-campaign-id", "12", "--method-of-promotion", "directlink"}

func TestUpdateCPCChecksThenWritesWhatTheCheckCounted(t *testing.T) {
	seen := updateServer(t, cpcResponder(func() (int, string) { return 200, cpcDone }))

	stdout, stderr, err := executeCommand(append(cpcArgs, "--force", "--json")...)
	if err != nil {
		t.Fatalf("update-cpc: %v\n%s", err, stderr)
	}
	if len(*seen) != 2 {
		t.Fatalf("requests = %+v, want the check then the write", *seen)
	}
	check, write := (*seen)[0], (*seen)[1]
	if check.Method != "POST" || check.Path != "/clicks/cpc" || !check.dryRun() {
		t.Errorf("check = %s %s?%s, want POST /clicks/cpc?dry_run=1", check.Method, check.Path, check.Query)
	}
	if write.Path != "/clicks/cpc" || write.Query != "" {
		t.Errorf("write = %s?%s, want POST /clicks/cpc with no query", write.Path, write.Query)
	}
	for _, r := range []updateRequest{check, write} {
		if r.Body["from"] != "2026-10-01" || r.Body["to"] != "2026-10-07" || r.Body["cpc"] != "0.25" {
			t.Errorf("body = %v, want the days and the CPC as strings, $ dropped", r.Body)
		}
		if r.Body["aff_campaign_id"] != float64(12) || r.Body["method_of_promotion"] != "directlink" {
			t.Errorf("body = %v, want the filters (ids as JSON numbers)", r.Body)
		}
		if _, ok := r.Body["ppc_account_id"]; ok {
			t.Errorf("body = %v: an id flag not given must not be sent", r.Body)
		}
	}
	if _, ok := check.Body["expect_clicks"]; ok {
		t.Errorf("the check sent expect_clicks: %v", check.Body)
	}
	if write.Body["expect_clicks"] != float64(3) || write.Body["through_click_id"] != float64(940003) {
		t.Errorf("write = %v, want the check's matching and through_click_id", write.Body)
	}
	if !strings.Contains(stdout, `"updated": 2`) {
		t.Errorf("--json should print the API's answer, got %s", stdout)
	}
	if !strings.Contains(stderr, "now cost $0.25000") || !strings.Contains(stderr, `campaign "Hosting" (12)`) {
		t.Errorf("stderr should say what changed and which clicks: %s", stderr)
	}
}

func TestUpdateCPCDryRunSendsOnlyTheCheck(t *testing.T) {
	seen := updateServer(t, cpcResponder(func() (int, string) { return 200, cpcDone }))
	_, stderr, err := executeCommand(append(cpcArgs, "--dry-run", "--json")...)
	if err != nil {
		t.Fatalf("update-cpc --dry-run: %v", err)
	}
	if len(*seen) != 1 || !(*seen)[0].dryRun() {
		t.Fatalf("requests = %+v, want only the check", *seen)
	}
	if !strings.Contains(stderr, "Dry run: 3 click(s)") || !strings.Contains(stderr, "Nothing was written") {
		t.Errorf("stderr = %q", stderr)
	}
}

func TestUpdateCPCAsksBeforeWriting(t *testing.T) {
	t.Run("an unanswerable question fails and writes nothing", func(t *testing.T) {
		seen := updateServer(t, cpcResponder(func() (int, string) { return 200, cpcDone }))
		answerPrompts(t, "")
		_, _, err := executeCommand(cpcArgs...)
		if err == nil {
			t.Fatal("with nobody to answer, the update must fail")
		}
		assertValidationError(t, err)
		if hint := hintFor(err); !strings.Contains(hint, "--force") || !strings.Contains(hint, "--dry-run") {
			t.Errorf("hint = %q, want --force and --dry-run", hint)
		}
		if len(*seen) != 1 {
			t.Errorf("requests = %+v, want only the check", *seen)
		}
	})
	t.Run("no cancels and writes nothing", func(t *testing.T) {
		seen := updateServer(t, cpcResponder(func() (int, string) { return 200, cpcDone }))
		answerPrompts(t, "n\n")
		stdout, stderr, err := executeCommand(cpcArgs...)
		if err != nil {
			t.Fatalf("answering no is not an error: %v", err)
		}
		if len(*seen) != 1 || !strings.Contains(stderr, "Cancelled") || strings.Contains(stdout, "Cancelled") {
			t.Errorf("requests = %d, stdout %q, stderr %q", len(*seen), stdout, stderr)
		}
	})
	t.Run("yes writes", func(t *testing.T) {
		seen := updateServer(t, cpcResponder(func() (int, string) { return 200, cpcDone }))
		answerPrompts(t, "y\n")
		if _, _, err := executeCommand(cpcArgs...); err != nil {
			t.Fatalf("update-cpc: %v", err)
		}
		if len(*seen) != 2 {
			t.Errorf("requests = %+v, want the check and the write", *seen)
		}
	})
}

func TestUpdateCPCWithNothingToUpdateWritesNothing(t *testing.T) {
	seen := updateServer(t, func(r updateRequest) (int, string) {
		return 200, strings.Replace(strings.Replace(cpcCheck, `"matching":3`, `"matching":0`, 1), `"through_click_id":940003`, `"through_click_id":0`, 1)
	})
	_, stderr, err := executeCommand(append(cpcArgs, "--force")...)
	if err != nil {
		t.Fatalf("no clicks to update is not an error: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains(stderr, "nothing to update") {
		t.Errorf("requests = %d, stderr %q", len(*seen), stderr)
	}
}

func TestUpdateCPCWhoseCountMovedSaysToRunItAgain(t *testing.T) {
	updateServer(t, cpcResponder(func() (int, string) {
		return 409, `{"error":true,"status":409,"message":"The clicks in this selection changed after they were counted: 3 were confirmed and 4 match now. Nothing was changed.","details":{"expect_clicks":3,"matching":4,"through_click_id":940004}}`
	}))
	_, _, err := executeCommand(append(cpcArgs, "--force")...)
	if err == nil {
		t.Fatal("a moved count must fail")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d", code, ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "nothing was written") || !strings.Contains(hint, "Run the same command again") {
		t.Errorf("hint = %q", hint)
	}
}

func TestUpdateCPCRefusesBadFlagsBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		name string
		args []string
		want string
		hint string
	}{
		{"no from", []string{"--to", "2026-10-07", "--cpc", "1"}, "--from is required: the first day to update, YYYY-MM-DD", "--from 2026-10-01 --to 2026-10-07"},
		{"not a day", []string{"--from", "2026-02-30", "--to", "2026-03-01", "--cpc", "1"}, "--from must be a day", "--from 2026-10-01"},
		{"US order", []string{"--from", "10/01/2026", "--to", "2026-10-07", "--cpc", "1"}, "--from must be a day", "YYYY-MM-DD"},
		{"backwards", []string{"--from", "2026-10-07", "--to", "2026-10-01", "--cpc", "1"}, "is before --from", "Swap them"},
		{"no cpc", []string{"--from", "2026-10-01", "--to", "2026-10-01"}, "--cpc is required", "--cpc 0.25"},
		{"over the column", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "100"}, "at most 99.99999", "five decimals"},
		{"six decimals", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "0.123456"}, "five decimals", "--cpc .00125"},
		{"a word", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "free"}, "--cpc must be dollars", "--cpc 0.25"},
		{"an id that is not one", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "1", "--aff-campaign-id", "12x"}, "--aff-campaign-id must be a campaign id", "p202 campaign list"},
		{"a leading zero", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "1", "--ppc-account-id", "07"}, "--ppc-account-id must be", "p202 ppc-account list"},
		{"an empty filter", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "1", "--aff-campaign-id", ""}, "--aff-campaign-id was given an empty value", "for every campaign"},
		{"an unknown method", []string{"--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "1", "--method-of-promotion", "both"}, "directlink, landingpage", "p202 search"},
		{"an argument", []string{"12", "--from", "2026-10-01", "--to", "2026-10-01", "--cpc", "1"}, "takes no arguments", "--from"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			seen := updateServer(t, func(updateRequest) (int, string) { return 200, cpcCheck })
			_, _, err := executeCommand(append([]string{"click", "update-cpc"}, tc.args...)...)
			if err == nil {
				t.Fatalf("accepted %v", tc.args)
			}
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), tc.want) {
				t.Errorf("message = %q, want %q", err.Error(), tc.want)
			}
			if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
				t.Errorf("hint = %q, want %q", hint, tc.hint)
			}
			if len(*seen) != 0 {
				t.Errorf("sent %+v before refusing", *seen)
			}
		})
	}
}

// writeLines writes a subid list file and returns its path.
func writeLines(t *testing.T, lines ...string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "subids.txt")
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

// subidResponder answers a subid endpoint the way the server does: one line
// per non-blank item, numbered by its position in the list sent, with status
// (dry run, or write) for a subid and not_a_subid otherwise.
func subidResponder(dryStatus, writeStatus string) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		items, _ := r.Body["subids"].([]interface{})
		var lines []map[string]interface{}
		conversions := 0
		for i, it := range items {
			s := strings.TrimSpace(fmt.Sprint(it))
			if s == "" {
				continue
			}
			line := map[string]interface{}{"line": i + 1, "subid": s, "click_id": nil, "status": "not_a_subid"}
			if key, id, ok := subidKey(s); ok && key == s {
				line["click_id"] = id
				line["status"] = writeStatus
				if r.dryRun() {
					line["status"] = dryStatus
					if dryStatus == "would_clear" {
						line["conversions"] = 2
						conversions += 2
					}
				}
			}
			lines = append(lines, line)
		}
		out := map[string]interface{}{"dry_run": r.dryRun(), "lines": lines}
		if r.dryRun() && dryStatus == "would_clear" {
			out["conversions"] = conversions
		}
		data, _ := json.Marshal(map[string]interface{}{"data": out})
		return 200, string(data)
	}
}

func TestMarkSubidsSendsTheFileAndAnswersWithItsLineNumbers(t *testing.T) {
	seen := updateServer(t, subidResponder("would_mark", "marked"))
	path := writeLines(t, "940001", "", " 940002 ", "abc", "940001")

	stdout, stderr, err := executeCommand("conversion", "mark-subids", path, "--json")
	if err != nil {
		t.Fatalf("mark-subids: %v\n%s", err, stderr)
	}
	if len(*seen) != 1 || (*seen)[0].Path != "/conversions/subids" || (*seen)[0].Query != "" {
		t.Fatalf("requests = %+v, want one POST /conversions/subids", *seen)
	}
	sent, _ := (*seen)[0].Body["subids"].([]interface{})
	want := []interface{}{"940001", "", " 940002 ", "abc", ""}
	if fmt.Sprint(sent) != fmt.Sprint(want) {
		t.Errorf("subids = %q, want every line kept in place, the repeat sent blank: %q", sent, want)
	}
	var out struct {
		Data struct {
			Marked     int                      `json:"marked"`
			NotASubid  int                      `json:"not_a_subid"`
			Duplicates int                      `json:"duplicate_in_list"`
			Lines      []map[string]interface{} `json:"lines"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("--json output is not JSON: %v\n%s", err, stdout)
	}
	if out.Data.Marked != 2 || out.Data.NotASubid != 1 || out.Data.Duplicates != 1 {
		t.Errorf("counts = %+v", out.Data)
	}
	var numbers []string
	for _, l := range out.Data.Lines {
		numbers = append(numbers, fmt.Sprintf("%v:%v", l["line"], l["status"]))
	}
	if got := strings.Join(numbers, " "); got != "1:marked 3:marked 4:not_a_subid 5:duplicate_in_list" {
		t.Errorf("lines = %s, want the file's line numbers", got)
	}
	if first := out.Data.Lines[3]["first_line"]; first != float64(1) {
		t.Errorf("the repeat names line %v, want 1", first)
	}
}

func TestMarkSubidsSendsALongListInParts(t *testing.T) {
	seen := updateServer(t, subidResponder("would_mark", "marked"))
	lines := make([]string, 2500)
	for i := range lines {
		lines[i] = fmt.Sprint(950001 + i)
	}
	stdout, _, err := executeCommand("conversion", "mark-subids", writeLines(t, lines...), "--json")
	if err != nil {
		t.Fatalf("mark-subids: %v", err)
	}
	if len(*seen) != 3 {
		t.Fatalf("sent %d requests, want 3 parts of at most %d lines", len(*seen), updateSubidsPerRequest)
	}
	for i, size := range []int{1000, 1000, 500} {
		if got := len((*seen)[i].Body["subids"].([]interface{})); got != size {
			t.Errorf("part %d carried %d lines, want %d", i+1, got, size)
		}
	}
	var out struct {
		Data struct {
			Marked int                      `json:"marked"`
			Lines  []map[string]interface{} `json:"lines"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatal(err)
	}
	last := out.Data.Lines[len(out.Data.Lines)-1]
	if out.Data.Marked != 2500 || last["line"] != float64(2500) || last["subid"] != "952500" {
		t.Errorf("marked %d, last line %v, want 2500 and line 2500 = 952500", out.Data.Marked, last)
	}
}

func TestMarkSubidsFailingPartSaysWhatTheEarlierPartsWrote(t *testing.T) {
	calls := 0
	respond := subidResponder("would_mark", "marked")
	updateServer(t, func(r updateRequest) (int, string) {
		calls++
		if calls == 2 {
			return 500, `{"error":true,"status":500,"message":"Marking stopped at line 13 (subid 951012): 12 subids were marked before it and stay marked. Sending the same list again is safe."}`
		}
		return respond(r)
	})
	lines := make([]string, 1500)
	for i := range lines {
		lines[i] = fmt.Sprint(950001 + i)
	}
	_, _, err := executeCommand("conversion", "mark-subids", writeLines(t, lines...))
	if err == nil {
		t.Fatal("a failed part must fail the command")
	}
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("exit code = %d, want %d", code, ExitServer)
	}
	if !strings.Contains(err.Error(), "12 subids were marked") {
		t.Errorf("message = %q, want the server's account of the part", err.Error())
	}
	if hint := hintFor(err); !strings.Contains(hint, "Lines 1-1000 were sent first") || !strings.Contains(hint, "again is safe") {
		t.Errorf("hint = %q", hint)
	}
}

func TestSubidListsAreReadStrictly(t *testing.T) {
	setTestHome(t, t.TempDir())
	cases := []struct {
		name string
		args []string
		want string
		hint string
	}{
		{"no file", []string{"conversion", "mark-subids"}, "takes one file of subids", "subids.txt"},
		{"a missing file", []string{"conversion", "delete-subids", filepath.Join(t.TempDir(), "nope.txt")}, "cannot read the subids", "one subid per line"},
		{"only blank lines", []string{"conversion", "mark-subids", writeLines(t, "", "  ")}, "holds no subid", "one subid per line"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, _, err := executeCommand(tc.args...)
			if err == nil {
				t.Fatalf("accepted %v", tc.args)
			}
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), tc.want) || !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("error %q / hint %q, want %q / %q", err.Error(), hintFor(err), tc.want, tc.hint)
			}
		})
	}
}

func TestDeleteSubidsPreviewsAsksAndDeletes(t *testing.T) {
	path := writeLines(t, "940001", "940002", "x")
	t.Run("--force previews, then deletes", func(t *testing.T) {
		seen := updateServer(t, subidResponder("would_clear", "cleared"))
		_, stderr, err := executeCommand("conversion", "delete-subids", path, "--force", "--json")
		if err != nil {
			t.Fatalf("delete-subids: %v", err)
		}
		if len(*seen) != 2 || !(*seen)[0].dryRun() || (*seen)[1].dryRun() || (*seen)[1].Path != "/conversions/subids/delete" {
			t.Fatalf("requests = %+v, want the dry run then the delete", *seen)
		}
		if !strings.Contains(stderr, "Cleared the conversions of 2 subid(s)") {
			t.Errorf("stderr = %q", stderr)
		}
	})
	t.Run("--dry-run sends only the preview, with the conversions it would clear", func(t *testing.T) {
		seen := updateServer(t, subidResponder("would_clear", "cleared"))
		stdout, _, err := executeCommand("conversion", "delete-subids", path, "--dry-run", "--json")
		if err != nil {
			t.Fatalf("delete-subids --dry-run: %v", err)
		}
		if len(*seen) != 1 || !strings.Contains(stdout, `"conversions": 4`) || !strings.Contains(stdout, `"would_clear": 2`) {
			t.Errorf("requests %d, stdout %s", len(*seen), stdout)
		}
	})
	t.Run("nobody to answer: fails after the preview, deletes nothing", func(t *testing.T) {
		seen := updateServer(t, subidResponder("would_clear", "cleared"))
		answerPrompts(t, "")
		_, _, err := executeCommand("conversion", "delete-subids", path)
		if err == nil {
			t.Fatal("an unanswered delete must fail")
		}
		if len(*seen) != 1 {
			t.Errorf("requests = %+v, want only the preview", *seen)
		}
	})
}

func TestResetSubidsChecksAndResets(t *testing.T) {
	answer := func(r updateRequest) (int, string) {
		if r.dryRun() {
			return 200, `{"data":{"dry_run":true,"matching":7,"aff_network":{"id":3,"name":"Hosting"},"aff_campaign":{"id":12,"name":"Hosting offer"}}}`
		}
		return 200, `{"data":{"dry_run":false,"cleared":7,"aff_network":{"id":3,"name":"Hosting"},"aff_campaign":{"id":12,"name":"Hosting offer"}}}`
	}
	seen := updateServer(t, answer)
	_, stderr, err := executeCommand("conversion", "reset-subids", "--aff-network-id", "3", "--aff-campaign-id", "12", "--force", "--json")
	if err != nil {
		t.Fatalf("reset-subids: %v", err)
	}
	if len(*seen) != 2 || (*seen)[1].Path != "/conversions/subids/reset" {
		t.Fatalf("requests = %+v", *seen)
	}
	if b := (*seen)[1].Body; b["aff_network_id"] != float64(3) || b["aff_campaign_id"] != float64(12) {
		t.Errorf("body = %v, want the ids as JSON numbers", b)
	}
	if !strings.Contains(stderr, `Reset: 7 click(s) of the campaign "Hosting offer" (12) of category "Hosting" (3)`) {
		t.Errorf("stderr = %q", stderr)
	}

	setTestHome(t, t.TempDir())
	_, _, err = executeCommand("conversion", "reset-subids", "--aff-campaign-id", "12")
	if err == nil || !strings.Contains(err.Error(), "--aff-network-id is required") || !strings.Contains(hintFor(err), "p202 aff-network list") {
		t.Errorf("without the category: %v / %q", err, hintFor(err))
	}
}

func TestUploadRevenueSendsTheReportAndItsColumns(t *testing.T) {
	csv := filepath.Join(t.TempDir(), "march.csv")
	if err := os.WriteFile(csv, []byte("Sub ID,Order,Commission\n940001,a,$1.50\n940001,b,2.25\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	preview := `{"data":{"dry_run":true,"batch_id":null,"would_record":2,"skipped":0,"columns":{"subid":{"index":0,"header":"Sub ID"},"amount":{"index":2,"header":"Commission"},"guessed":[]},"skipped_reasons":[],"clicks":1,"total":"3.75000","totals":[{"click_id":940001,"total":"3.75000"}],"totals_unlisted":0,"lines":[{"line":1,"subid":"Sub ID","amount":"Commission","status":"header","reason":"read as the header row (not a subid)"}],"lines_unlisted":0}}`
	done := `{"data":{"dry_run":false,"batch_id":7,"recorded":2,"skipped":0,"columns":{"subid":{"index":0,"header":"Sub ID"},"amount":{"index":2,"header":"Commission"},"guessed":[]},"skipped_reasons":[],"clicks":1,"total":"3.75000","totals":[{"click_id":940001,"total":"3.75000"}],"totals_unlisted":0,"lines":[],"lines_unlisted":0}}`
	seen := updateServer(t, func(r updateRequest) (int, string) {
		if r.dryRun() {
			return 200, preview
		}
		return 200, done
	})
	_, stderr, err := executeCommand("conversion", "upload-revenue", csv, "--subid-column", "Sub ID", "--amount-column", "2", "--force")
	if err != nil {
		t.Fatalf("upload-revenue: %v\n%s", err, stderr)
	}
	if len(*seen) != 2 || (*seen)[1].Path != "/conversions/uploads" || (*seen)[1].dryRun() {
		t.Fatalf("requests = %+v, want the dry run then the upload", *seen)
	}
	b := (*seen)[1].Body
	if b["csv"] != "Sub ID,Order,Commission\n940001,a,$1.50\n940001,b,2.25\n" || b["file_name"] != "march.csv" {
		t.Errorf("body = %v, want the report as written and its file name", b)
	}
	if b["subid_column"] != "Sub ID" || b["amount_column"] != float64(2) {
		t.Errorf("columns = %v / %v, want a header name and a 0-based number", b["subid_column"], b["amount_column"])
	}
	if !strings.Contains(stderr, "Uploaded march.csv as batch 7: 2 line(s) recorded on 1 click(s), $3.75 in all") {
		t.Errorf("stderr = %q", stderr)
	}
}

func TestUploadRevenueThatWouldRecordNothingIsRefusedWithoutWriting(t *testing.T) {
	csv := filepath.Join(t.TempDir(), "wrong.csv")
	if err := os.WriteFile(csv, []byte("Order,Sub ID\nabc,1\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	seen := updateServer(t, func(updateRequest) (int, string) {
		return 200, `{"data":{"dry_run":true,"batch_id":null,"would_record":0,"skipped":1,"columns":{"subid":{"index":0,"header":"Order"},"amount":{"index":1,"header":"Sub ID"},"guessed":[]},"skipped_reasons":[{"reason":"not a subid (a click id is a whole number)","lines":1}],"clicks":0,"total":"0.00000","totals":[],"totals_unlisted":0,"lines":[{"line":2,"subid":"abc","amount":"1","status":"skipped","reason":"not a subid (a click id is a whole number)"}],"lines_unlisted":0}}`
	})
	stdout, _, err := executeCommand("conversion", "upload-revenue", csv, "--subid-column", "0", "--amount-column", "1", "--force", "--json")
	if err == nil {
		t.Fatal("a report with nothing to record must not be uploaded")
	}
	assertValidationError(t, err)
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("a failure must leave stdout empty, got %q", stdout)
	}
	if hint := hintFor(err); !strings.Contains(hint, `it was "Order"`) || !strings.Contains(hint, "--subid-column") {
		t.Errorf("hint = %q", hint)
	}
	if !strings.Contains(err.Error(), "by reason: not a subid (a click id is a whole number) (1)") {
		t.Errorf("error = %q, want the skipped lines counted by reason", err)
	}
	if len(*seen) != 1 {
		t.Errorf("requests = %+v, want only the dry run", *seen)
	}
}

// An answer lists the first lines not recorded and the first clicks' totals,
// and counts the rest: the summary takes the click count and the total from
// the answer's exact fields, never from the lists, and says what was not
// listed and why.
func TestUploadRevenueSummaryReadsTheExactCountsNotTheListedOnes(t *testing.T) {
	csv := filepath.Join(t.TempDir(), "big.csv")
	if err := os.WriteFile(csv, []byte("subid,payout\n1,2\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	preview := `{"data":{"dry_run":true,"batch_id":null,"would_record":1500,"skipped":3000,"columns":{"subid":{"index":0,"header":"subid"},"amount":{"index":1,"header":"payout"},"guessed":[]},` +
		`"skipped_reasons":[{"reason":"no click with this subid in your account","lines":2999},{"reason":"the commission is not a number","lines":1}],` +
		`"clicks":1500,"total":"18510.00000","totals":[{"click_id":1,"total":"12.34000"}],"totals_unlisted":1499,` +
		`"lines":[{"line":2,"subid":"9","amount":"1","status":"skipped","reason":"no click with this subid in your account"}],"lines_unlisted":2999}}`
	updateServer(t, func(updateRequest) (int, string) { return 200, preview })
	_, stderr, err := executeCommand("conversion", "upload-revenue", csv, "--dry-run")
	if err != nil {
		t.Fatalf("upload-revenue --dry-run: %v\n%s", err, stderr)
	}
	for _, want := range []string{
		"1500 line(s) would be recorded on 1500 click(s), $18510 in all",
		"3000 skipped (listing 1 of the 3000 lines not recorded; by reason: no click with this subid in your account (2999); the commission is not a number (1))",
	} {
		if !strings.Contains(stderr, want) {
			t.Errorf("stderr = %q, want %q", stderr, want)
		}
	}
}

func TestUploadRevenueRefusesAnAnswerWithoutTheExactCounts(t *testing.T) {
	csv := filepath.Join(t.TempDir(), "r.csv")
	if err := os.WriteFile(csv, []byte("subid,payout\n1,2\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	// The answer as it was before it carried clicks and total: summing the
	// listed totals would understate a report with more clicks than listed.
	updateServer(t, func(updateRequest) (int, string) {
		return 200, `{"data":{"dry_run":true,"batch_id":null,"would_record":1,"skipped":0,"columns":{"subid":{"index":0,"header":"subid"},"amount":{"index":1,"header":"payout"},"guessed":[]},"totals":[{"click_id":1,"total":"2.00000"}],"lines":[]}}`
	})
	_, _, err := executeCommand("conversion", "upload-revenue", csv, "--dry-run")
	if err == nil || !strings.Contains(err.Error(), "the click count or the count of lines not listed is missing") {
		t.Fatalf("err = %v, want the answer refused as malformed", err)
	}
}

func TestUpdateCommandsRefuseStagedBeforeAnyRequest(t *testing.T) {
	t.Cleanup(func() { api.SetStagedMode(false) })
	csv := filepath.Join(t.TempDir(), "r.csv")
	if err := os.WriteFile(csv, []byte("subid,payout\n1,2\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	list := writeLines(t, "940001")
	for _, args := range [][]string{
		append([]string{"--staged"}, cpcArgs...),
		{"--staged", "conversion", "mark-subids", list},
		{"--staged", "conversion", "delete-subids", list, "--force"},
		{"--staged", "conversion", "reset-subids", "--aff-network-id", "3", "--force"},
		{"--staged", "conversion", "upload-revenue", csv, "--force"},
	} {
		t.Run(args[2]+" "+args[3], func(t *testing.T) {
			seen := updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{}}` })
			_, _, err := executeCommand(args...)
			if err == nil {
				t.Fatalf("%v ran under --staged", args)
			}
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), "--staged cannot apply") || !strings.Contains(hintFor(err), "Drop --staged") {
				t.Errorf("error %q / hint %q", err.Error(), hintFor(err))
			}
			if len(*seen) != 0 {
				t.Errorf("sent %+v under --staged", *seen)
			}
		})
	}
}

func TestUpdateErrorsAreCategorizedAndSayWhatToDo(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		exit   int
		hint   string
	}{
		{"a role without the permission", 403, `{"error":true,"status":403,"message":"This account's role does not have the 'access_to_update_section' permission."}`, ExitAuth, "p202 user role assign"},
		{"a key without the scope", 403, `{"error":true,"status":403,"message":"Insufficient API key scope for this operation: requires 'clicks:write' (key has: read)."}`, ExitAuth, "--scope"},
		{"a server without the Update API", 404, `{"error":true,"status":404,"message":"Not found"}`, ExitValidation, "no Update API"},
		{"a proxy page", 200, `<html>bad gateway</html>`, ExitServer, "p202 system health"},
		{"an answer without its count", 200, `{"data":{"dry_run":true}}`, ExitServer, "p202 system health"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			updateServer(t, func(updateRequest) (int, string) { return tc.status, tc.body })
			_, _, err := executeCommand(append(cpcArgs, "--dry-run")...)
			if err == nil {
				t.Fatal("want an error")
			}
			if code := exitCodeForError(err); code != tc.exit {
				t.Errorf("exit code = %d, want %d (%v)", code, tc.exit, err)
			}
			if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
				t.Errorf("hint = %q, want %q", hint, tc.hint)
			}
		})
	}
}
