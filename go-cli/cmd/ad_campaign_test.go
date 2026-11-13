package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strconv"
	"strings"
	"testing"

	"p202/internal/api"
)

func adCampaignServer(t *testing.T) (*[]string, *[]map[string]interface{}) {
	t.Helper()
	calls := []string{}
	bodies := []map[string]interface{}{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.Method+" "+r.URL.RequestURI())
		raw, _ := io.ReadAll(r.Body)
		body := map[string]interface{}{}
		_ = json.Unmarshal(raw, &body)
		bodies = append(bodies, body)
		w.Write([]byte(`{"data":{"dry_run":true,"action":"pause","record":{"id":"cmpn_101","status":"active"}}}`))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &calls, &bodies
}

// Each write goes to its own route, a dry run as ?dry_run=1, with the body asked.
func TestAdCampaignWritesGoWhereTheySayAndDryRunAsks(t *testing.T) {
	calls, bodies := adCampaignServer(t)
	for _, args := range [][]string{
		{"ad-campaign", "pause", "1", "cmpn_101", "--dry-run"},
		{"ad-campaign", "activate", "1", "ad_301", "--level", "ads"},
		{"ad-campaign", "set-template", "1", "adgrp_2", "--level", "ad-groups", "--template", "?a={ad_id}"},
		{"ad-campaign", "attach-events", "1", "cmpn_101", "--event-setting-id", "ces_9"},
		{"ad-campaign", "list", "1", "--level", "ads", "--ad-group-id", "adgrp_2", "--limit", "20"},
		{"ad-campaign", "spend", "1", "--since", "2026-11-01", "--until", "2026-11-10"},
	} {
		if _, _, err := executeCommand(append(args, "--json")...); err != nil {
			t.Fatalf("%v: %v", args, err)
		}
	}
	want := []string{
		"POST /api/v3/ad-connections/1/campaigns/cmpn_101/pause?dry_run=1",
		"POST /api/v3/ad-connections/1/ads/ad_301/activate",
		"POST /api/v3/ad-connections/1/ad-groups/adgrp_2/template",
		"POST /api/v3/ad-connections/1/campaigns/cmpn_101/event-settings",
		"GET /api/v3/ad-connections/1/ads?ad_group_id=adgrp_2&limit=20",
		"GET /api/v3/ad-connections/1/spend?since=2026-11-01&until=2026-11-10",
	}
	if !reflect.DeepEqual(*calls, want) {
		t.Errorf("requests:\n%v\nwant\n%v", *calls, want)
	}
	if !reflect.DeepEqual((*bodies)[2], map[string]interface{}{"template": "a={ad_id}"}) {
		t.Errorf("template body = %v", (*bodies)[2])
	}
	if !reflect.DeepEqual((*bodies)[3], map[string]interface{}{"event_setting_ids": []interface{}{"ces_9"}}) {
		t.Errorf("event settings body = %v", (*bodies)[3])
	}
}

func TestAdCampaignRefusesBadInputBeforeTheServer(t *testing.T) {
	calls, _ := adCampaignServer(t)
	for name, args := range map[string][]string{
		"an id that is not OpenAI's":   {"ad-campaign", "pause", "1", "../ad_account"},
		"a bad connection id":          {"ad-campaign", "list", "one"},
		"an unknown level":             {"ad-campaign", "get", "1", "cmpn_1", "--level", "keywords"},
		"a filter of another level":    {"ad-campaign", "list", "1", "--campaign-id", "cmpn_1"},
		"a template with a space":      {"ad-campaign", "set-template", "1", "cmpn_1", "--template", "a=1 b=2"},
		"an event setting that is not": {"ad-campaign", "attach-events", "1", "cmpn_1", "--event-setting-id", "9"},
		"half a window":                {"ad-campaign", "spend", "1", "--since", "2026-11-01"},
		"a date that is not":           {"ad-campaign", "spend", "1", "--since", "11/01/2026", "--until", "2026-11-10"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%s: err %v, exit %d, hint %q", name, err, exitCodeForError(err), hintFor(err))
		}
	}
	if len(*calls) != 0 {
		t.Errorf("bad input reached the server: %v", *calls)
	}
}

// OpenAI not reached, its rate limit and an answer that cannot be used keep the
// class's "wait and run it again"; a list hint is for a 404 and the key hint
// for a 422 naming api_key.
func TestAdCampaignHintsAreScopedToTheAnswer(t *testing.T) {
	status, body := 0, ""
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		w.Write([]byte(body))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	upstream := func(code int, reason string) string {
		return `{"error":true,"status":` + strconv.Itoa(code) + `,"message":"Could not pause cmpn_101: no answer from OpenAI.",` +
			`"retry_after_seconds":60,"details":{"upstream":"openai_ads","reason":"` + reason + `"}}`
	}
	commands := [][]string{
		{"ad-campaign", "list", "3"},
		{"ad-campaign", "get", "3", "cmpn_101"},
		{"ad-campaign", "pause", "3", "cmpn_101"},
		{"ad-campaign", "attach-events", "3", "cmpn_101"},
		{"ad-campaign", "spend", "3"},
	}
	for _, args := range commands {
		for _, c := range []struct {
			status int
			body   string
			exit   int
		}{
			{http.StatusServiceUnavailable, upstream(503, "unreachable"), ExitServer},
			{http.StatusBadGateway, upstream(502, "unreadable_answer"), ExitServer},
			{http.StatusTooManyRequests, upstream(429, "rate_limited"), ExitRateLimited},
		} {
			status, body = c.status, c.body
			_, _, err := executeCommand(append(args, "--json")...)
			if err == nil || exitCodeForError(err) != c.exit {
				t.Errorf("%v, %d: err %v, exit %d, want %d", args, c.status, err, exitCodeForError(err), c.exit)
				continue
			}
			hint := hintFor(err)
			write := args[1] == "pause" || args[1] == "attach-events"
			if write && c.status != http.StatusTooManyRequests {
				// A write may have landed: read it before sending it again.
				if !strings.Contains(hint, "p202 ad-campaign get 3 cmpn_101") || !strings.Contains(hint, "may have been applied") {
					t.Errorf("%v, %d: hint %q does not say it may have been applied", args, c.status, hint)
				}
				continue
			}
			if hint == "" || hint != api.HintFor(err) {
				t.Errorf("%v, %d: hint %q, want the class's %q", args, c.status, hint, api.HintFor(err))
			}
			if strings.Contains(hint, adCampaignListHint) || strings.Contains(hint, adConnectionListHint) || strings.Contains(hint, advertiserKeyWhere) {
				t.Errorf("%v, %d: a command's own hint on OpenAI's failure: %q", args, c.status, hint)
			}
		}
		status = http.StatusNotFound
		body = `{"error":true,"status":404,"message":"Could not read cmpn_101: OpenAI answered 404."}`
		if _, _, err := executeCommand(append(args, "--json")...); err == nil || !strings.Contains(hintFor(err), "p202 ad-c") {
			t.Errorf("%v, 404: err %v, hint %q", args, err, hintFor(err))
		}
		if args[1] == "pause" {
			status = http.StatusConflict
			body = `{"error":true,"status":409,"message":"Could not activate cmpn_101: OpenAI answered 400: campaign is archived.","details":{"upstream":"openai_ads","reason":"refused"}}`
			_, _, err := executeCommand(append(args, "--json")...)
			if err == nil || !strings.Contains(hintFor(err), "OpenAI refused it") || !strings.Contains(hintFor(err), "same answer") || strings.Contains(hintFor(err), "Fix the field") {
				t.Errorf("%v, OpenAI's refusal: err %v, hint %q", args, err, hintFor(err))
			}
		}
		status = http.StatusUnprocessableEntity
		body = `{"error":true,"status":422,"message":"OpenAI refused the stored key","field_errors":{"api_key":"OpenAI refused the stored key"}}`
		_, _, err := executeCommand(append(args, "--json")...)
		if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), "p202 ad-connection update 3 --api-key-file") {
			t.Errorf("%v, refused key: err %v, exit %d, hint %q", args, err, exitCodeForError(err), hintFor(err))
		}
	}
}

// The ad-campaign tools take OpenAI's own ids where the commands do: a
// parent to narrow a list by (cmpn_, adgrp_), the object to change (ad_ too),
// and the next page's cursor; Prosper202's numeric ids are refused.
func TestMCPAdCampaignToolsTakeChatGptAdsIds(t *testing.T) {
	tools, err := buildMCPDeferredTools(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	byName := map[string]mcpTool{}
	for _, tool := range tools {
		byName[tool.Name] = tool
	}
	list := byName["ad_campaign_list"].InputSchema
	for _, prop := range []string{"campaign_id", "ad_group_id"} {
		if list.Properties[prop].Pattern != adObjectIDPattern.String() {
			t.Errorf("ad_campaign_list %s pattern %q, want %q", prop, list.Properties[prop].Pattern, adObjectIDPattern.String())
		}
	}
	if _, ok := list.Properties["after"]; !ok {
		t.Errorf("ad_campaign_list takes no after, so it cannot page: %v", list.Properties)
	}
	if byName["ad_campaign_pause"].InputSchema.Properties["id"].Pattern != adObjectIDPattern.String() {
		t.Errorf("ad_campaign_pause id pattern %q", byName["ad_campaign_pause"].InputSchema.Properties["id"].Pattern)
	}

	calls, _ := adCampaignServer(t)
	for _, input := range []string{
		`{"connection_id":"1","level":"ad-groups","campaign_id":"cmpn_101"}`,
		`{"connection_id":"1","level":"ads","ad_group_id":"adgrp_201","after":"ad_301"}`,
	} {
		if _, stderr, err := mcpExecute(t, "mcp", "call", "ad_campaign_list", "--input", input); err != nil {
			t.Errorf("%s: %v %s", input, err, stderr)
		}
	}
	if _, _, err := mcpExecute(t, "mcp", "call", "ad_campaign_pause", "--input", `{"connection_id":"1","id":"ad_301","level":"ads","dry_run":true}`); err != nil {
		t.Errorf("pause ad_301: %v", err)
	}
	want := []string{
		"GET /api/v3/ad-connections/1/ad-groups?campaign_id=cmpn_101",
		"GET /api/v3/ad-connections/1/ads?ad_group_id=adgrp_201&after=ad_301",
		"POST /api/v3/ad-connections/1/ads/ad_301/pause?dry_run=1",
	}
	if strings.Join(*calls, "|") != strings.Join(want, "|") {
		t.Errorf("requests %v, want %v", *calls, want)
	}
	if _, _, err := mcpExecute(t, "mcp", "call", "ad_campaign_list", "--input", `{"connection_id":"1","level":"ad-groups","campaign_id":"101"}`); err == nil {
		t.Errorf("a numeric campaign_id was taken")
	}
}
