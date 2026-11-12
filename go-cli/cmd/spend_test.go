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
	"testing"

	"p202/internal/api"
)

type spendRequest struct {
	Method, Path, Query, Body string
}

// spendServer records each request with its raw body (spend must keep its
// digits) and answers status with body.
func spendServer(t *testing.T, status int, body string) *[]spendRequest {
	t.Helper()
	var seen []spendRequest
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		seen = append(seen, spendRequest{r.Method, r.URL.Path, r.URL.RawQuery, string(data)})
		w.WriteHeader(status)
		w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &seen
}

const spendRowJSON = `{"platform":"openai_ads","account_ref":"acct_northwind","campaign_ref":"cmp_1","ad_group_ref":null,` +
	`"ad_ref":"ad_espresso_grinder","hour_start":1794297600,"impressions":1200,"clicks":31,"spend":18.420001,"currency":"USD"}`

const spendWriteAnswer = `{"data":{"dry_run":false,"received":1,"inserted":1,"replaced":0,"unchanged":0}}`

// canonicalJSON is s as the client encodes it: keys sorted, numbers as written.
func canonicalJSON(t *testing.T, s string) string {
	t.Helper()
	var v interface{}
	if err := decodeOneJSON([]byte(s), &v); err != nil {
		t.Fatal(err)
	}
	out, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return string(out)
}

func writeSpendFile(t *testing.T, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "spend.json")
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestSpendImportPostsTheRowsOfABareList(t *testing.T) {
	seen := spendServer(t, 200, spendWriteAnswer)
	out, _, err := executeCommand("--json", "spend", "import", writeSpendFile(t, "["+spendRowJSON+"]"))
	if err != nil {
		t.Fatalf("spend import: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/spend-ledger") {
		t.Fatalf("requests = %+v, want one POST /spend-ledger", *seen)
	}
	if (*seen)[0].Query != "" {
		t.Errorf("a write should send no query, sent %q", (*seen)[0].Query)
	}
	if want := canonicalJSON(t, `{"rows":[`+spendRowJSON+`]}`); (*seen)[0].Body != want {
		t.Errorf("body = %s\nwant   %s (rows as given, spend with its digits)", (*seen)[0].Body, want)
	}
	if !strings.Contains(out, `"inserted": 1`) {
		t.Errorf("output should carry the counts:\n%s", out)
	}
}

func TestSpendImportReadsTheObjectFormAndDryRuns(t *testing.T) {
	seen := spendServer(t, 200, `{"data":{"dry_run":true,"received":1,"inserted":0,"replaced":1,"unchanged":0}}`)
	_, stderr, err := executeCommand("--table", "spend", "import", "--dry-run", writeSpendFile(t, `{"rows":[`+spendRowJSON+`]}`))
	if err != nil {
		t.Fatalf("spend import --dry-run: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Query != "dry_run=1" {
		t.Fatalf("requests = %+v, want one carrying dry_run=1", *seen)
	}
	if want := canonicalJSON(t, `{"rows":[`+spendRowJSON+`]}`); (*seen)[0].Body != want {
		t.Errorf("body = %s, want %s", (*seen)[0].Body, want)
	}
	if !strings.Contains(stderr, "Dry run: 1 row(s) would be 0 new, 1 replaced") {
		t.Errorf("stderr should say what a write would do:\n%s", stderr)
	}
}

func TestSpendImportReadsStdin(t *testing.T) {
	seen := spendServer(t, 200, spendWriteAnswer)
	f, err := os.Open(writeSpendFile(t, "["+spendRowJSON+"]"))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	withStdin(t, f)
	if _, _, err := executeCommand("--json", "spend", "import", "-"); err != nil {
		t.Fatalf("spend import -: %v", err)
	}
	if len(*seen) != 1 || !strings.Contains((*seen)[0].Body, "ad_espresso_grinder") {
		t.Fatalf("requests = %+v, want one carrying the piped row", *seen)
	}
}

func TestSpendImportRefusesWhatIsCertainlyWrongAndSendsNothing(t *testing.T) {
	many := "[" + strings.TrimSuffix(strings.Repeat(spendRowJSON+",", spendMaxRows+1), ",") + "]"
	cases := []struct {
		name, content, inErr, inHint string
	}{
		{"not JSON", `[{"platform":`, "is not JSON", `{"rows": [...]}`},
		{"two values", `[` + spendRowJSON + `] []`, "more than one JSON value", "one list"},
		{"empty list", `[]`, "no list of spend rows", `{"rows": [...]}`},
		{"no rows", `{"rows": {}}`, "no list of spend rows", `{"rows": [...]}`},
		{"another field", `{"rows": [` + spendRowJSON + `], "dry_run": true}`, `field "dry_run"`, `{"rows": [...]}`},
		{"a row not an object", `[` + spendRowJSON + `, 5]`, "row 2 of", `{"rows": [...]}`},
		{"too many rows", many, fmt.Sprintf("holds %d rows; one import takes at most %d", spendMaxRows+1, spendMaxRows), "Split the rows"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			seen := spendServer(t, 200, spendWriteAnswer)
			_, _, err := executeCommand("spend", "import", writeSpendFile(t, tc.content))
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), tc.inErr) {
				t.Errorf("error = %q, want it to contain %q", err, tc.inErr)
			}
			if !strings.Contains(hintFor(err), tc.inHint) {
				t.Errorf("hint = %q, want it to contain %q", hintFor(err), tc.inHint)
			}
			if len(*seen) != 0 {
				t.Errorf("a refused file should send nothing, sent %+v", *seen)
			}
		})
	}
}

func TestSpendImportTakesExactlyTheMostRows(t *testing.T) {
	seen := spendServer(t, 200, spendWriteAnswer)
	rows := "[" + strings.TrimSuffix(strings.Repeat(spendRowJSON+",", spendMaxRows), ",") + "]"
	if _, _, err := executeCommand("--json", "spend", "import", writeSpendFile(t, rows)); err != nil {
		t.Fatalf("%d rows should be sent: %v", spendMaxRows, err)
	}
	if len(*seen) != 1 {
		t.Fatalf("requests = %d, want 1", len(*seen))
	}
}

func TestSpendImportRefusesAMissingFileAndAMissingArgument(t *testing.T) {
	seen := spendServer(t, 200, spendWriteAnswer)
	_, _, err := executeCommand("spend", "import", filepath.Join(t.TempDir(), "absent.json"))
	assertValidationError(t, err)
	if !strings.Contains(hintFor(err), "- to read them from stdin") {
		t.Errorf("hint = %q", hintFor(err))
	}
	_, _, err = executeCommand("spend", "import")
	assertValidationError(t, err)
	if !strings.Contains(hintFor(err), "p202 spend import spend.json") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if len(*seen) != 0 {
		t.Errorf("sent %+v", *seen)
	}
}

func TestSpendImportFromATerminalFailsInsteadOfBlocking(t *testing.T) {
	devNull, err := os.Open(os.DevNull)
	if err != nil {
		t.Skipf("cannot open %s: %v", os.DevNull, err)
	}
	defer devNull.Close()
	if !isTerminal(devNull) {
		t.Skipf("%s is not a character device here", os.DevNull)
	}
	withStdin(t, devNull)
	seen := spendServer(t, 200, spendWriteAnswer)
	_, _, err = executeCommand("spend", "import", "-")
	assertValidationError(t, err)
	if !strings.Contains(hintFor(err), "| p202 spend import -") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if len(*seen) != 0 {
		t.Errorf("sent %+v", *seen)
	}
}

func TestSpendImportRefusesStaged(t *testing.T) {
	seen := spendServer(t, 200, spendWriteAnswer)
	_, _, err := executeCommand("--staged", "spend", "import", writeSpendFile(t, "["+spendRowJSON+"]"))
	assertValidationError(t, err)
	if !strings.Contains(err.Error(), "--staged") || !strings.Contains(hintFor(err), "--dry-run") {
		t.Errorf("error = %q, hint = %q", err, hintFor(err))
	}
	if len(*seen) != 0 {
		t.Errorf("a staged import should send nothing, sent %+v", *seen)
	}
}

func TestSpendImportServerRefusalsSayWhatToDoNext(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		code   int
		hint   string
		inErr  string
	}{
		{"403", 403, `{"error":true,"message":"Forbidden"}`, ExitAuth, "Update section (access_to_update_section) and the spend-ledger:write scope", ""},
		{"422 rows", 422, `{"error":true,"message":"Validation failed","field_errors":{"rows.3.ad_ref":"is required"}}`, ExitValidation,
			"rows.0 is the first", "rows.3.ad_ref: is required"},
		{"404", 404, `{"error":true,"message":"Not found"}`, -1, "1.9.80 or later", ""},
		{"500", 500, `{"error":true,"message":"Internal"}`, ExitServer, "run the same import again", ""},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			spendServer(t, tc.status, tc.body)
			_, _, err := executeCommand("spend", "import", writeSpendFile(t, "["+spendRowJSON+"]"))
			if err == nil {
				t.Fatalf("HTTP %d should be an error", tc.status)
			}
			if tc.code >= 0 && exitCodeForError(err) != tc.code {
				t.Errorf("exit code = %d, want %d", exitCodeForError(err), tc.code)
			}
			if !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", hintFor(err), tc.hint)
			}
			if tc.inErr != "" && !strings.Contains(err.Error(), tc.inErr) {
				t.Errorf("error = %q, want the server's field error %q", err, tc.inErr)
			}
		})
	}
}

const spendListAnswer = `{"data":[{"period":"2026-11-11","platform":"openai_ads","account_ref":"acct_northwind","campaign_ref":"cmp_1",
 "ad_group_ref":null,"ad_ref":"ad_espresso_grinder","impressions":1200,"platform_clicks":31,"spend":18.42,"currency":"USD",
 "matched_clicks":29,"unallocated_spend":0,"updated_at":1794301200}],"interval":"day","timezone":"America/New_York",
 "currency":"USD","reaches":1794301200,"truncated":true,"limit":1}`

func TestSpendListSendsTheWindowAndFilters(t *testing.T) {
	seen := spendServer(t, 200, spendListAnswer)
	out, stderr, err := executeCommand("--table", "spend", "list", "--period", "yesterday", "--time-from", "2026-11-01",
		"--time-to", "2026-11-11", "--interval", "hour", "--platform", "openai_ads", "--limit", "1")
	if err != nil {
		t.Fatalf("spend list: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "GET" || !strings.HasSuffix((*seen)[0].Path, "/spend-ledger") {
		t.Fatalf("requests = %+v, want one GET /spend-ledger", *seen)
	}
	for _, want := range []string{"period=yesterday", "time_from=2026-11-01", "time_to=2026-11-11", "interval=hour", "platform=openai_ads", "limit=1"} {
		if !strings.Contains((*seen)[0].Query, want) {
			t.Errorf("query %q should carry %s", (*seen)[0].Query, want)
		}
	}
	if !strings.Contains(out, "ad_espresso_grinder") {
		t.Errorf("output should show the row:\n%s", out)
	}
	if !strings.Contains(stderr, "More rows than --limit 1") {
		t.Errorf("a truncated answer should say so:\n%s", stderr)
	}
}

func TestSpendListAcceptsTheSnakeCaseWindowAliases(t *testing.T) {
	seen := spendServer(t, 200, spendListAnswer)
	if _, _, err := executeCommand("--json", "spend", "list", "--time_from", "1794297600"); err != nil { // the alias of --time-from
		t.Fatal(err)
	}
	if len(*seen) != 1 || (*seen)[0].Query != "time_from=1794297600" {
		t.Fatalf("requests = %+v", *seen)
	}
}

func TestSpendListRefusesBadFlagsBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		args          []string
		inErr, inHint string
	}{
		{[]string{"--limit", "0"}, "1 to 5000", "newest 500"},
		{[]string{"--limit", "5001"}, "1 to 5000", "newest 500"},
		{[]string{"--limit", "12abc"}, "1 to 5000", "newest 500"},
		{[]string{"--interval", "week"}, "day, hour", ""},
		{[]string{"--period", "lastweek"}, "yesterday", ""},
	}
	for _, tc := range cases {
		seen := spendServer(t, 200, spendListAnswer)
		_, _, err := executeCommand(append([]string{"spend", "list"}, tc.args...)...)
		assertValidationError(t, err)
		if !strings.Contains(err.Error(), tc.inErr) {
			t.Errorf("%v: error = %q, want %q", tc.args, err, tc.inErr)
		}
		if !strings.Contains(hintFor(err), tc.inHint) {
			t.Errorf("%v: hint = %q, want %q", tc.args, hintFor(err), tc.inHint)
		}
		if len(*seen) != 0 {
			t.Errorf("%v: sent %+v", tc.args, *seen)
		}
	}
}

func TestSpendListRefusalsSayWhatToDoNext(t *testing.T) {
	spendServer(t, 403, `{"error":true,"message":"Forbidden"}`)
	_, _, err := executeCommand("spend", "list")
	if err == nil || !strings.Contains(hintFor(err), "spend-ledger:read") {
		t.Errorf("403 hint = %q (err %v)", hintFor(err), err)
	}
	spendServer(t, 422, `{"error":true,"message":"Validation failed","field_errors":{"time_from":"is not a time"}}`)
	_, _, err = executeCommand("spend", "list", "--time-from", "soon")
	var apiErr *api.APIError
	if !asAPIError(err, &apiErr) || apiErr.FieldErrors["time_from"] != "is not a time" || exitCodeForError(err) != ExitValidation {
		t.Errorf("a 422 should pass the field error through, got %v", err)
	}
}

func TestSpendImportAgainstADeadURLNamesTheConfigAndTheSafeRetry(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:1", "test-key")
	_, _, err := executeCommand("spend", "import", writeSpendFile(t, "["+spendRowJSON+"]"))
	if err == nil || exitCodeForError(err) != ExitNetwork {
		t.Fatalf("a dead URL should be a network error (exit %d), got %v", ExitNetwork, err)
	}
	for _, want := range []string{"p202 config test", "run the same import again"} {
		if !strings.Contains(hintFor(err), want) {
			t.Errorf("hint = %q, want it to name %q", hintFor(err), want)
		}
	}
}
