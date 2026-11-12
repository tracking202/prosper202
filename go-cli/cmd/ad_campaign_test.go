package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"testing"
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
		"staged":                       {"--staged", "ad-campaign", "pause", "1", "cmpn_1"},
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
