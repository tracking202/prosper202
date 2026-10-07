package cmd

import (
	"strconv"
	"strings"
	"testing"

	"p202/internal/api"
)

// The Administration commands (system_admin.go) and the LTV product
// update/delete and webhook delivery log, against a recording server
// (updateServer, newLtvServer). The contract asserted is the CLI's: what each
// command sends, what it refuses before sending anything, and the exit code
// and hint of each failure.

func TestSystemReadsSendTheirRequest(t *testing.T) {
	for _, c := range []struct {
		args        []string
		path, query string
	}{
		{[]string{"system", "info"}, "/system/info", ""},
		{[]string{"system", "login-log"}, "/system/login-log", ""},
		{[]string{"system", "login-log", "--limit", "200"}, "/system/login-log", "limit=200"},
		{[]string{"system", "metrics"}, "/system/metrics", ""},
		{[]string{"system", "retention", "show"}, "/system/retention", ""},
		{[]string{"system", "isp-lookup", "show"}, "/system/isp-lookup", ""},
		{[]string{"system", "integrations"}, "/system/integrations", ""},
	} {
		t.Run(strings.Join(c.args, " "), func(t *testing.T) {
			seen := updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{}}` })
			if _, _, err := executeCommand(append(c.args, "--json")...); err != nil {
				t.Fatalf("%v: %v", c.args, err)
			}
			if len(*seen) != 1 || (*seen)[0].Method != "GET" || (*seen)[0].Path != c.path || (*seen)[0].Query != c.query {
				t.Errorf("requests = %+v, want GET %s?%s", *seen, c.path, c.query)
			}
		})
	}
}

func TestSystemLoginLogRefusesABadLimitBeforeAnyRequest(t *testing.T) {
	for _, v := range []string{"0", "501", "abc", "05", "-1"} {
		seen := updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":[]}` })
		_, _, err := executeCommand("system", "login-log", "--limit", v)
		assertValidationError(t, err)
		if !strings.Contains(hintFor(err), "--limit 100") || len(*seen) != 0 {
			t.Errorf("--limit %s: hint %q, sent %+v", v, hintFor(err), *seen)
		}
	}
}

func TestSystemIntegrationsTableListsTheIntegrations(t *testing.T) {
	updateServer(t, func(updateRequest) (int, string) {
		return 200, `{"data":{"base_url":"https://t.example/","integrations":[{"integration":"clickbank","name":"ClickBank","label":"INS URL","url":"https://t.example/tracking202/static/cb202.php","secret_stored":true,"verified":false}]}}`
	})
	stdout, _, err := executeCommand("system", "integrations", "--table", "--wide")
	if err != nil {
		t.Fatalf("system integrations: %v", err)
	}
	if !strings.Contains(stdout, "https://t.example/tracking202/static/cb202.php") || !strings.Contains(stdout, "ClickBank") {
		t.Errorf("the table should list each integration's URL, got:\n%s", stdout)
	}
	updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{"base_url":"x"}}` })
	_, _, err = executeCommand("system", "integrations", "--table")
	if code := exitCodeForError(err); code != ExitServer {
		t.Errorf("an answer without the list: exit %d (%v), want %d", code, err, ExitServer)
	}
}

func retentionResponder(current int) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		if r.Method == "GET" {
			return 200, `{"data":{"auto_delete_days":` + strconv.Itoa(current) + `,"scheduled_deletion":null}}`
		}
		return 200, `{"data":{"previous_auto_delete_days":` + strconv.Itoa(current) + `,"auto_delete_days":30,"scheduled_deletion":null}}`
	}
}

func TestRetentionSetAsksOnlyWhenItWouldDeleteMore(t *testing.T) {
	t.Run("turning deletion on with nobody to answer fails and writes nothing", func(t *testing.T) {
		seen := updateServer(t, retentionResponder(0))
		answerPrompts(t, "")
		_, _, err := executeCommand("system", "retention", "set", "--days", "30")
		assertValidationError(t, err)
		if !strings.Contains(hintFor(err), "--force") || len(*seen) != 1 || (*seen)[0].Method != "GET" {
			t.Errorf("hint %q, requests %+v: want only the read", hintFor(err), *seen)
		}
	})
	t.Run("no cancels", func(t *testing.T) {
		seen := updateServer(t, retentionResponder(90))
		answerPrompts(t, "n\n")
		stdout, stderr, err := executeCommand("system", "retention", "set", "--days", "30")
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Cancelled") || strings.Contains(stdout, "Cancelled") {
			t.Errorf("err %v, requests %d, stderr %q", err, len(*seen), stderr)
		}
		if !strings.Contains(stderr, "90 days of click data") || !strings.Contains(stderr, "cannot be recovered") {
			t.Errorf("the question should say what is kept now and that it is final: %q", stderr)
		}
	})
	t.Run("yes writes the days as a number", func(t *testing.T) {
		seen := updateServer(t, retentionResponder(0))
		answerPrompts(t, "y\n")
		if _, _, err := executeCommand("system", "retention", "set", "--days", "30"); err != nil {
			t.Fatal(err)
		}
		if len(*seen) != 2 || (*seen)[1].Method != "PUT" || (*seen)[1].Path != "/system/retention" || (*seen)[1].Body["auto_delete_days"] != float64(30) {
			t.Errorf("requests = %+v, want the read then PUT {auto_delete_days: 30}", *seen)
		}
	})
	for _, c := range []struct {
		name    string
		current int
		args    []string
	}{
		{"--force skips the question", 0, []string{"--days", "30", "--force"}},
		{"keeping more asks nothing", 30, []string{"--days", "60"}},
		{"turning deletion off asks nothing", 30, []string{"--days", "0"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			seen := updateServer(t, retentionResponder(c.current))
			answerPrompts(t, "")
			if _, _, err := executeCommand(append([]string{"system", "retention", "set"}, c.args...)...); err != nil {
				t.Fatalf("%v: %v", c.args, err)
			}
			if len(*seen) != 2 || (*seen)[1].Method != "PUT" {
				t.Errorf("requests = %+v, want the read and the write", *seen)
			}
		})
	}
}

func TestRetentionSetRefusesBadDaysBeforeAnyRequest(t *testing.T) {
	for _, args := range [][]string{{}, {"--days", "-1"}, {"--days", "36501"}, {"--days", "030"}, {"--days", "thirty"}, {"--days", "1.5"}} {
		seen := updateServer(t, retentionResponder(0))
		_, _, err := executeCommand(append([]string{"system", "retention", "set"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(*seen) != 0 {
			t.Errorf("%v: hint %q, sent %+v", args, hintFor(err), *seen)
		}
	}
}

const deleteBeforePreview = `{"data":{"dry_run":true,"scheduled":false,"before":"2026-01-01","timezone":"America/New_York","cutoff_time":1767243600,"through_click_id":940102,"clicks":1500,"rows":{"202_clicks":1500,"202_clicks_advance":1500,"202_bing":0},"current":null}}`

const deleteBeforeDone = `{"data":{"dry_run":false,"scheduled":true,"before":"2026-01-01","timezone":"America/New_York","cutoff_time":1767243600,"through_click_id":940102,"clicks":1500,"rows":{"202_clicks":1500},"current":{"through_click_id":940102,"before":"2025-12-31","clicks_remaining":1500}}}`

func deleteBeforeResponder(write func() (int, string)) func(updateRequest) (int, string) {
	return func(r updateRequest) (int, string) {
		if r.dryRun() {
			return 200, deleteBeforePreview
		}
		return write()
	}
}

var deleteBeforeArgs = []string{"system", "retention", "delete-before", "--date", "2026-01-01"}

func TestDeleteBeforePreviewsThenSchedulesWhatThePreviewNamed(t *testing.T) {
	seen := updateServer(t, deleteBeforeResponder(func() (int, string) { return 200, deleteBeforeDone }))
	_, stderr, err := executeCommand(append(deleteBeforeArgs, "--force", "--json")...)
	if err != nil {
		t.Fatalf("delete-before: %v\n%s", err, stderr)
	}
	if len(*seen) != 2 {
		t.Fatalf("requests = %+v, want the preview then the write", *seen)
	}
	preview, write := (*seen)[0], (*seen)[1]
	if preview.Method != "POST" || preview.Path != "/system/retention/delete-before" || !preview.dryRun() || preview.Body["before"] != "2026-01-01" {
		t.Errorf("preview = %+v, want POST /system/retention/delete-before?dry_run=1 {before}", preview)
	}
	if _, ok := preview.Body["through_click_id"]; ok {
		t.Errorf("the preview sent through_click_id: %v", preview.Body)
	}
	if write.Query != "" || write.Body["before"] != "2026-01-01" || write.Body["through_click_id"] != float64(940102) {
		t.Errorf("write = %+v, want no query and the preview's through_click_id", write)
	}
	if !strings.Contains(stderr, "Scheduled") || !strings.Contains(stderr, "1500 click(s)") {
		t.Errorf("stderr = %q", stderr)
	}
}

func TestDeleteBeforeAlwaysPreviewsAndAsks(t *testing.T) {
	t.Run("--dry-run sends only the preview", func(t *testing.T) {
		seen := updateServer(t, deleteBeforeResponder(func() (int, string) { return 200, deleteBeforeDone }))
		_, stderr, err := executeCommand(append(deleteBeforeArgs, "--dry-run")...)
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Dry run: 1500 click(s)") || !strings.Contains(stderr, "202_clicks_advance 1500") {
			t.Errorf("err %v, requests %d, stderr %q", err, len(*seen), stderr)
		}
	})
	t.Run("an unanswerable question fails after the preview and schedules nothing", func(t *testing.T) {
		seen := updateServer(t, deleteBeforeResponder(func() (int, string) { return 200, deleteBeforeDone }))
		answerPrompts(t, "")
		_, stderr, err := executeCommand(deleteBeforeArgs...)
		assertValidationError(t, err)
		if hint := hintFor(err); !strings.Contains(hint, "--force") || !strings.Contains(hint, "--dry-run") {
			t.Errorf("hint = %q, want --force and --dry-run", hint)
		}
		if len(*seen) != 1 || !strings.Contains(stderr, "cannot be undone") || !strings.Contains(stderr, "every account") {
			t.Errorf("requests %d, stderr %q: want only the preview, and a warning that says it is final and install-wide", len(*seen), stderr)
		}
	})
	t.Run("no cancels", func(t *testing.T) {
		seen := updateServer(t, deleteBeforeResponder(func() (int, string) { return 200, deleteBeforeDone }))
		answerPrompts(t, "n\n")
		stdout, stderr, err := executeCommand(deleteBeforeArgs...)
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "Cancelled") || strings.Contains(stdout, "Cancelled") {
			t.Errorf("err %v, requests %d, stdout %q, stderr %q", err, len(*seen), stdout, stderr)
		}
	})
	t.Run("nothing before the day writes nothing", func(t *testing.T) {
		seen := updateServer(t, func(updateRequest) (int, string) {
			return 200, strings.Replace(strings.Replace(deleteBeforePreview, `"through_click_id":940102`, `"through_click_id":null`, 1), `"clicks":1500`, `"clicks":0`, 1)
		})
		_, stderr, err := executeCommand(append(deleteBeforeArgs, "--force")...)
		if err != nil || len(*seen) != 1 || !strings.Contains(stderr, "nothing to delete") {
			t.Errorf("err %v, requests %d, stderr %q", err, len(*seen), stderr)
		}
	})
	t.Run("a moved marker says to run it again", func(t *testing.T) {
		updateServer(t, deleteBeforeResponder(func() (int, string) {
			return 409, `{"error":true,"status":409,"message":"through_click_id 940102 is not what this day names now (click 940150). Nothing was scheduled.","details":{"through_click_id":940102,"current_through_click_id":940150}}`
		}))
		_, _, err := executeCommand(append(deleteBeforeArgs, "--force")...)
		if code := exitCodeForError(err); code != ExitValidation || !strings.Contains(hintFor(err), "Run the same command again") {
			t.Errorf("exit %d, hint %q", code, hintFor(err))
		}
	})
	t.Run("a preview without its counts is the server's failure", func(t *testing.T) {
		seen := updateServer(t, func(updateRequest) (int, string) {
			return 200, `{"data":{"before":"2026-01-01","through_click_id":940102}}`
		})
		_, _, err := executeCommand(append(deleteBeforeArgs, "--force")...)
		if code := exitCodeForError(err); code != ExitServer || len(*seen) != 1 {
			t.Errorf("exit %d (%v), requests %d: a missing count must not read as 0 and must not write", code, err, len(*seen))
		}
	})
}

func TestDeleteBeforeRefusesABadDateBeforeAnyRequest(t *testing.T) {
	for _, args := range [][]string{{}, {"--date", "2026-02-30"}, {"--date", "01-01-2026"}, {"--date", "2026-1-1"}, {"--date", "yesterday"}} {
		seen := updateServer(t, deleteBeforeResponder(func() (int, string) { return 200, deleteBeforeDone }))
		_, _, err := executeCommand(append([]string{"system", "retention", "delete-before"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(*seen) != 0 {
			t.Errorf("%v: hint %q, sent %+v", args, hintFor(err), *seen)
		}
	}
}

func TestSystemWritesRefuseStagedBeforeAnyRequest(t *testing.T) {
	t.Cleanup(func() { api.SetStagedMode(false) })
	for _, args := range [][]string{
		{"--staged", "system", "retention", "set", "--days", "30", "--force"},
		{"--staged", "system", "retention", "delete-before", "--date", "2026-01-01", "--force"},
		{"--staged", "system", "retention", "delete-before", "--date", "2026-01-01", "--dry-run"},
		{"--staged", "system", "isp-lookup", "enable"},
		{"--staged", "system", "isp-lookup", "disable"},
	} {
		t.Run(strings.Join(args[2:], " "), func(t *testing.T) {
			seen := updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{}}` })
			_, _, err := executeCommand(args...)
			assertValidationError(t, err)
			if !strings.Contains(err.Error(), "--staged cannot apply") || !strings.Contains(hintFor(err), "Drop --staged") {
				t.Errorf("error %q / hint %q", err, hintFor(err))
			}
			if len(*seen) != 0 {
				t.Errorf("sent %+v under --staged", *seen)
			}
		})
	}
}

func TestIspLookupSendsTheSwitchAndExplainsAMissingDatabase(t *testing.T) {
	seen := updateServer(t, func(updateRequest) (int, string) { return 200, `{"data":{"enabled":false}}` })
	if _, _, err := executeCommand("system", "isp-lookup", "disable"); err != nil {
		t.Fatal(err)
	}
	if len(*seen) != 1 || (*seen)[0].Method != "PUT" || (*seen)[0].Path != "/system/isp-lookup" || (*seen)[0].Body["enabled"] != false {
		t.Errorf("requests = %+v, want PUT /system/isp-lookup {enabled: false}", *seen)
	}
	updateServer(t, func(updateRequest) (int, string) {
		return 422, `{"error":true,"status":422,"message":"The ISP database file is not there, so lookup stays off. Upload GeoIP2-ISP.mmdb (or legacy GeoIPISP.dat) to /srv/geo, then turn it on.","field_errors":{"enabled":"needs GeoIP2-ISP.mmdb or GeoIPISP.dat in /srv/geo"}}`
	})
	_, _, err := executeCommand("system", "isp-lookup", "enable")
	if code := exitCodeForError(err); code != ExitValidation || !strings.Contains(hintFor(err), "Upload GeoIP2-ISP.mmdb") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}
}

func TestSystemRefusalsSayWhatIsMissing(t *testing.T) {
	for _, c := range []struct {
		name, body string
		hint       []string
	}{
		{"not an admin", `{"error":true,"status":403,"message":"Admin access required."}`, []string{"Admin", "Super user", "`p202 whoami`", "`p202 user role assign <user_id> 2`"}},
		{"the page's permission", `{"error":true,"status":403,"message":"This account's role does not have the 'access_to_settings' permission."}`, []string{"permission named above", "`p202 user role list`"}},
		{"a read-only key", `{"error":true,"status":403,"message":"Insufficient API key scope for this operation: requires 'system:write' (key has: read)."}`, []string{"scope"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			updateServer(t, func(updateRequest) (int, string) { return 403, c.body })
			_, _, err := executeCommand("system", "retention", "show")
			if code := exitCodeForError(err); code != ExitAuth {
				t.Errorf("exit %d, want %d", code, ExitAuth)
			}
			for _, want := range c.hint {
				if !strings.Contains(hintFor(err), want) {
					t.Errorf("hint %q lacks %q", hintFor(err), want)
				}
			}
		})
	}
	updateServer(t, func(updateRequest) (int, string) { return 404, `{"error":true,"status":404,"message":"Not found"}` })
	_, _, err := executeCommand("system", "info")
	if !strings.Contains(hintFor(err), "features.administration") {
		t.Errorf("a server without the endpoints: hint %q", hintFor(err))
	}
}

// ── LTV: product update/delete, webhook deliveries ────────────────────

func TestLtvProductUpdateSendsOnlyTheFieldsGiven(t *testing.T) {
	srv := newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "product", "update", "12", "--name", " Pro plan ", "--price", "49.5"); err != nil {
		t.Fatal(err)
	}
	req := srv.only(t)
	if req.Method != "PATCH" || req.Path != "/ltv/products/12" {
		t.Errorf("request = %s %s", req.Method, req.Path)
	}
	assertJSON(t, req.Body, `{"name":"Pro plan","price":49.5}`)

	srv = newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "product", "update", "12", "--sku", "", "--price", ""); err != nil {
		t.Fatal(err)
	}
	assertJSON(t, srv.only(t).Body, `{"sku":"","price":null}`)

	for _, args := range [][]string{{"12"}, {"12", "--name", "  "}, {"12", "--price", "-1"}, {"12", "--price", "1e3"}, {"abc", "--name", "x"}, {"0", "--name", "x"}} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "product", "update"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(srv.seen()) != 0 {
			t.Errorf("%v: hint %q, sent %d", args, hintFor(err), len(srv.seen()))
		}
	}

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 404, `{"error":true,"status":404,"message":"Product not found"}`
	})
	_, _, err := executeCommand("ltv", "product", "update", "99", "--name", "x")
	if !strings.Contains(hintFor(err), "`p202 ltv products`") {
		t.Errorf("404 hint = %q", hintFor(err))
	}
}

func TestLtvProductDeletePreviewsAndExplainsARefusal(t *testing.T) {
	srv := newLtvServer(t, nil)
	if _, _, err := executeCommand("ltv", "product", "delete", "12", "--dry-run"); err != nil {
		t.Fatal(err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/products/12" || req.RawQuery != "dry_run=1" {
		t.Errorf("preview = %+v", req)
	}

	srv = newLtvServer(t, func(ltvRequest) (int, string) { return 204, "" })
	if _, _, err := executeCommand("ltv", "product", "delete", "12", "--force"); err != nil {
		t.Fatal(err)
	}
	if req := srv.only(t); req.Method != "DELETE" || req.Path != "/ltv/products/12" || req.RawQuery != "" {
		t.Errorf("delete = %+v", req)
	}

	newLtvServer(t, func(ltvRequest) (int, string) {
		return 409, `{"error":true,"status":409,"message":"This product appears on 2 order line item(s) and cannot be deleted.","details":{"line_items":2}}`
	})
	_, _, err := executeCommand("ltv", "product", "delete", "12", "--force")
	if code := exitCodeForError(err); code != ExitValidation || !strings.Contains(hintFor(err), "ltv product update") {
		t.Errorf("exit %d, hint %q", code, hintFor(err))
	}
}

func TestLtvWebhookDeliveriesReadsTheLog(t *testing.T) {
	srv := newLtvServer(t, func(ltvRequest) (int, string) { return 200, `{"data":[],"meta":{}}` })
	if _, _, err := executeCommand("ltv", "webhooks", "deliveries", "3", "--limit", "10", "--status", "failed"); err != nil {
		t.Fatal(err)
	}
	req := srv.only(t)
	q := queryOf(t, req.RawQuery)
	if req.Method != "GET" || req.Path != "/ltv/webhooks/3/deliveries" || q.Get("limit") != "10" || q.Get("status") != "failed" {
		t.Errorf("request = %+v", req)
	}
	for _, args := range [][]string{{"3", "--limit", "101"}, {"3", "--limit", "0"}, {"3", "--status", "lost"}, {"x"}} {
		srv = newLtvServer(t, nil)
		_, _, err := executeCommand(append([]string{"ltv", "webhooks", "deliveries"}, args...)...)
		assertValidationError(t, err)
		if hintFor(err) == "" || len(srv.seen()) != 0 {
			t.Errorf("%v: hint %q, sent %d", args, hintFor(err), len(srv.seen()))
		}
	}
	newLtvServer(t, func(ltvRequest) (int, string) {
		return 404, `{"error":true,"status":404,"message":"Webhook not found"}`
	})
	_, _, err := executeCommand("ltv", "webhooks", "deliveries", "9")
	if !strings.Contains(hintFor(err), "`p202 ltv webhooks list`") {
		t.Errorf("404 hint = %q", hintFor(err))
	}
}
