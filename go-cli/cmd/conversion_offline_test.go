package cmd

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"p202/internal/api"
)

const offlineGoogleCSV = "Google Click ID,GBRAID,WBRAID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID,Ad User Data,Ad Personalization\r\n" +
	"EAIaIQ_1,,,Sale,2026-10-27T02:22:48-04:00,12.5,USD,P202-1,Granted,\r\n" +
	",0AAAA_2,,Sale,2026-10-27T02:22:47-04:00,3,USD,P202-2,Granted,\r\n"

const offlineSummaryJSON = `{"data":{"format":"microsoft","days":7,"time_from":1,"time_to":2,"timezone":"UTC","currency":"USD",
 "conversions":5,"rows":2,"left_out":{"not_counted":0,"same_ip":2,"no_click_id":1,"over_row_limit":0},"left_out_total":3,
 "reasons":{"same_ip":"Nothing named its click; it was guessed from the visitor's IP address.","no_click_id":"Its click came with no click id from this ad platform."}}}`

func TestOfflineExportSendsTheChoicesAndWritesTheFile(t *testing.T) {
	_, seen := goalServer(t, 200, offlineGoogleCSV)
	path := filepath.Join(t.TempDir(), "google.csv")
	_, stderr, err := executeCommand("conversion", "offline-export", "--format", "google", "--days", "30",
		"--aff-campaign-id", "3", "--conversion-name", "Sale", "--ad-user-data", "granted", "--ad-personalization", "denied",
		"--output", path)
	if err != nil {
		t.Fatalf("conversion offline-export: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/conversions/offline-export") {
		t.Fatalf("requests = %+v, want one GET /conversions/offline-export", *seen)
	}
	for _, want := range []string{"format=google", "days=30", "campaign_id=3", "conversion_name=Sale", "ad_user_data=granted", "ad_personalization=denied"} {
		if !strings.Contains((*seen)[0].Query, want) {
			t.Errorf("query %q should carry %s", (*seen)[0].Query, want)
		}
	}
	written, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading --output: %v", err)
	}
	if string(written) != offlineGoogleCSV {
		t.Errorf("--output holds %q, want the server's file byte for byte", written)
	}
	if !strings.Contains(stderr, "2 conversions written to") || !strings.Contains(stderr, "--summary") {
		t.Errorf("the success line should count the file's conversions and name --summary:\n%s", stderr)
	}
}

func TestOfflineExportWritesTheFileToStdoutWhenNoOutputIsNamed(t *testing.T) {
	goalServer(t, 200, offlineGoogleCSV)
	out, _, err := executeCommand("conversion", "offline-export", "--format", "google")
	if err != nil {
		t.Fatalf("conversion offline-export: %v", err)
	}
	if out != offlineGoogleCSV {
		t.Errorf("stdout = %q, want the file", out)
	}
}

func TestOfflineExportRowsSkipTheTemplatesParametersLine(t *testing.T) {
	uploads := "Parameters:TimeZone=UTC,,\r\nGoogle Click ID,Conversion Name,Conversion Time\r\nA,B,C\r\n"
	if got := offlineExportRows([]byte(uploads), "google_uploads"); got != 1 {
		t.Errorf("google_uploads rows = %d, want 1", got)
	}
	if got := offlineExportRows([]byte(offlineGoogleCSV), "google"); got != 2 {
		t.Errorf("google rows = %d, want 2", got)
	}
	if got := offlineExportRows([]byte("Parameters:TimeZone=+0000\r\nheader\r\n"), "microsoft"); got != 0 {
		t.Errorf("an empty microsoft file has %d rows, want 0", got)
	}
}

func TestOfflineExportSummaryShowsEachReasonAndTheTotals(t *testing.T) {
	_, seen := goalServer(t, 200, offlineSummaryJSON)
	out, _, err := executeCommand("--table", "--wide", "conversion", "offline-export", "--format", "microsoft", "--days", "7", "--summary")
	if err != nil {
		t.Fatalf("conversion offline-export --summary: %v", err)
	}
	if len(*seen) != 1 || !strings.HasSuffix((*seen)[0].Path, "/conversions/offline-export/summary") {
		t.Fatalf("requests = %+v, want one GET /conversions/offline-export/summary", *seen)
	}
	for _, want := range []string{"2 of 5 conversions from the last 7 days are in the microsoft file", "same_ip", "no_click_id", "guessed from the visitor's IP address"} {
		if !strings.Contains(out, want) {
			t.Errorf("output should contain %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "over_row_limit") {
		t.Errorf("a reason that left nothing out is not listed:\n%s", out)
	}

	out, _, err = executeCommand("--json", "conversion", "offline-export", "--format", "microsoft", "--summary")
	if err != nil {
		t.Fatalf("--json --summary: %v", err)
	}
	if !strings.Contains(out, `"left_out_total": 3`) {
		t.Errorf("--json passes the API's answer through:\n%s", out)
	}
}

func TestOfflineExportRefusesBadChoicesBeforeAnyRequest(t *testing.T) {
	setTestHome(t, t.TempDir())
	cases := []struct {
		args []string
		want string
		hint string
	}{
		{[]string{"conversion", "offline-export"}, "--format is required", "--format google"},
		{[]string{"conversion", "offline-export", "--format", "bing"}, "--format must be one of: google, google_uploads, microsoft", "microsoft"},
		{[]string{"conversion", "offline-export", "--format", "google", "--days", "0"}, "--days must be a whole number from 1 to 90", "90 days"},
		{[]string{"conversion", "offline-export", "--format", "google", "--days", "91"}, "--days must be a whole number from 1 to 90", ""},
		{[]string{"conversion", "offline-export", "--format", "google", "--days", "7d"}, "--days must be a whole number from 1 to 90", ""},
		{[]string{"conversion", "offline-export", "--format", "google", "--aff-campaign-id", "x"}, "--aff-campaign-id must be a positive integer", "p202 campaign list"},
		{[]string{"conversion", "offline-export", "--format", "microsoft", "--ad-user-data", "granted"}, "--ad-user-data is a Google column", "--format google"},
		{[]string{"conversion", "offline-export", "--format", "google", "--ad-personalization", "yes"}, "--ad-personalization must be one of: granted, denied", ""},
		{[]string{"conversion", "offline-export", "--format", "google", "--summary", "--output", "x.csv"}, "--summary prints the counts", "Drop --summary"},
		{[]string{"conversion", "offline-export", "--format", "google", "--conversion-name", ""}, "--conversion-name", "campaign's name"},
	}
	for _, tc := range cases {
		_, _, err := executeCommand(tc.args...)
		if err == nil {
			t.Errorf("%v: expected an error", tc.args)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%v: exit code %d, want %d (validation): %v", tc.args, code, ExitValidation, err)
		}
		if !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%v: error %q should mention %q", tc.args, err, tc.want)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q should mention %q", tc.args, hintFor(err), tc.hint)
		}
	}
}

func TestOfflineExportNamesTheNextStepForTheServersRefusals(t *testing.T) {
	forbidden := offlineExportError(&api.APIError{Status: 403, Message: "This account's role does not have the 'access_to_campaign_data' permission."})
	if !strings.Contains(hintFor(forbidden), "access to campaign data") {
		t.Errorf("403 hint = %q", hintFor(forbidden))
	}
	if code := exitCodeForError(forbidden); code != ExitAuth {
		t.Errorf("403 exit code = %d, want %d", code, ExitAuth)
	}
	named := offlineExportError(&api.APIError{Status: 422, FieldErrors: map[string]string{"conversion_name": "A conversion name cannot start with =, +, - or @"}})
	if !strings.Contains(hintFor(named), "exactly as spelled") {
		t.Errorf("422 hint = %q", hintFor(named))
	}
	other := &api.APIError{Status: 422, FieldErrors: map[string]string{"days": "A whole number"}}
	if got, want := hintFor(offlineExportError(other)), hintFor(other); got != want {
		t.Errorf("another refusal keeps the class-wide hint: got %q, want %q", got, want)
	}
}

// A file over the 64 MB the client downloads is refused whole, and the next
// step names this command's own ways to a smaller file, not --time-from and
// --time-to, which it does not take.
func TestOfflineExportTooLargeNamesItsOwnWayToASmallerFile(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/conversions/offline-export") {
			w.WriteHeader(404)
			return
		}
		w.Header().Set("Content-Type", "text/csv; charset=utf-8")
		chunk := bytes.Repeat([]byte("x"), 1<<20)
		for i := 0; i < 64; i++ {
			if _, err := w.Write(chunk); err != nil {
				return
			}
		}
		_, _ = w.Write([]byte("x"))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out := filepath.Join(tmp, "google.csv")
	_, _, err := executeCommand("conversion", "offline-export", "--format", "google", "--output", out)
	if err == nil {
		t.Fatal("a file over the cap was accepted")
	}
	if code := exitCodeForError(err); code != ExitValidation {
		t.Errorf("exit code = %d, want %d (the file is too big, nothing failed)", code, ExitValidation)
	}
	hint := hintFor(err)
	for _, want := range []string{"--days", "--aff-campaign-id", "p202 campaign list", "fetch URL"} {
		if !strings.Contains(hint, want) {
			t.Errorf("hint %q should name %s", hint, want)
		}
	}
	for _, foreign := range []string{"--time-from", "--time-to", "--group-by"} {
		if strings.Contains(hint, foreign) {
			t.Errorf("hint %q names %s, which this command does not take", hint, foreign)
		}
	}
	if _, statErr := os.Stat(out); !os.IsNotExist(statErr) {
		t.Errorf("a refused download left a file behind")
	}
}
