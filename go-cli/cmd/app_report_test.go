package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

func TestAppReportRefusesAOneSidedFlagWithoutItsPlatform(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // no server configured: a request would fail differently
	for _, tc := range []struct {
		args []string
		says string
		hint string
	}{
		{[]string{"app", "report", "--group-by", "ad-network", "--platform", "all"}, "Apple's postbacks only", "--platform ios"},
		{[]string{"app", "report", "--group-by", "country", "--platform", "android"}, "Apple's postbacks only", "--platform ios"},
		{[]string{"app", "report", "--group-by", "goal"}, "Android installs only", "--platform android"},
		{[]string{"app", "report", "--group-by", "match-state", "--platform", "ios"}, "Android installs only", "--platform android"},
		{[]string{"app", "report", "--signature", "valid", "--platform", "all"}, "--signature filters Apple's postbacks only", "--platform ios"},
		{[]string{"app", "report", "--platform", "android", "--protocol", "skan"}, "--protocol filters Apple's postbacks only", "--platform ios"},
		{[]string{"app", "report", "--match-state", "organic"}, "--match-state filters Android installs only", "--platform android"},
		{[]string{"app", "report", "--platform", "ios", "--trusted", "trusted"}, "--trusted filters Android installs only", "--platform android"},
		{[]string{"app", "report", "--platform", "windows"}, "--platform must be one of", "--platform all"},
		{[]string{"app", "report", "--group-by", "planet"}, "--group-by must be one of", ""},
		{[]string{"app", "report", "--platform", "android", "--match-state", "Organic"}, "--match-state must be one of", ""},
		{[]string{"app", "report", "--platform", "android", "--trusted", "1"}, "--trusted must be one of", ""},
		{[]string{"app", "report", "--platform", "android", "--aff-campaign-id", "x"}, "--aff-campaign-id must be", "p202 campaign list"},
	} {
		_, _, err := executeCommand(tc.args...)
		assertValidationError(t, err)
		if err == nil {
			continue
		}
		if exitCodeForError(err) != 1 {
			t.Errorf("%v: exit code %d, want 1", tc.args, exitCodeForError(err))
		}
		if !strings.Contains(err.Error(), tc.says) {
			t.Errorf("%v: error %q does not say %q", tc.args, err.Error(), tc.says)
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q does not name %q", tc.args, hintFor(err), tc.hint)
		}
	}
}

// Left out, --platform is the API's default, iOS, which is what the report
// meant before Android existed: an iOS grouping and filter pass without it,
// and no platform parameter is sent (the server applies the same default).
func TestAppReportDefaultsToIOSAsTheAPIDoes(t *testing.T) {
	var got url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"group_by":"ad-network","platform":"ios","groups":[],"totals":{"platform":"ios","postbacks":0}},"meta":{"timezone":"UTC"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("app", "report", "--group-by", "ad-network", "--signature", "valid", "--json"); err != nil {
		t.Fatalf("app report without --platform: %v", err)
	}
	if got.Has("platform") {
		t.Errorf("platform = %q, want none sent (the server's default is iOS)", got.Get("platform"))
	}
	if got.Get("group_by") != "ad-network" || got.Get("signature") != "valid" {
		t.Errorf("query = %v, want the iOS grouping and filter", got)
	}
}

func TestAppReportSendsThePlatformAndItsFiltersAndKeepsTheTotals(t *testing.T) {
	var got url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"group_by":"goal","platform":"android","groups":[{"goal_id":4,"goal_name":"Tutorial","installs":2}],` +
			`"totals":{"platform":"android","installs":5,"revenue":9.5}},"meta":{"timezone":"UTC","trusted":"trusted-only"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("app", "report", "--platform", "android", "--group-by", "goal", "--registration-id", "7",
		"--trusted", "unvouched", "--aff-campaign-id", "12", "--json")
	if err != nil {
		t.Fatalf("app report: %v", err)
	}
	for param, want := range map[string]string{"platform": "android", "group_by": "goal", "registration_id": "7", "trusted": "unvouched", "aff_campaign_id": "12"} {
		if got.Get(param) != want {
			t.Errorf("param %s = %q, want %q", param, got.Get(param), want)
		}
	}
	var parsed struct {
		Data []map[string]interface{} `json:"data"`
		Meta map[string]interface{}   `json:"meta"`
	}
	if err := json.Unmarshal([]byte(stdout), &parsed); err != nil {
		t.Fatalf("not the reshaped envelope: %v\n%s", err, stdout)
	}
	if len(parsed.Data) != 1 || parsed.Data[0]["goal_name"] != "Tutorial" {
		t.Errorf("data should be the groups, got %v", parsed.Data)
	}
	totals, ok := parsed.Meta["totals"].(map[string]interface{})
	if !ok || totals["installs"] != float64(5) {
		t.Errorf("meta.totals should carry the report's totals, got %v", parsed.Meta)
	}
	if parsed.Meta["platform"] != "android" || parsed.Meta["group_by"] != "goal" {
		t.Errorf("meta should say the platform and grouping, got %v", parsed.Meta)
	}
}

func TestAppReportWithNoPlatformAsksForBoth(t *testing.T) {
	var got url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"group_by":"day","platform":"all","groups":[],"totals":{"ios":{},"android":{},"combined":{"installs":0}}},"meta":{}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	if _, _, err := executeCommand("app", "report", "--registration-id", "3", "--time-from", "0"); err != nil {
		t.Fatalf("app report: %v", err)
	}
	if _, sent := got["platform"]; sent {
		t.Errorf("no --platform must send no platform (the server's default is both), got %q", got.Get("platform"))
	}
	if got.Get("registration_id") != "3" || got.Get("time_from") != "0" {
		t.Errorf("the shared filters go with both platforms, got %v", got)
	}
}

func TestAppLinkReadsAndAppliesThroughTheCampaign(t *testing.T) {
	var puts []map[string]interface{}
	var putPath string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && strings.HasSuffix(r.URL.Path, "/apps/7/store-link"):
			if r.URL.Query().Get("campaign_id") != "12" {
				t.Errorf("campaign_id = %q", r.URL.Query().Get("campaign_id"))
			}
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"registration_id":7,"platform":"android","store_link":"https://play.google.com/store/apps/details?id=a.b&referrer=p202%3D[[p202_install_token]]",` +
				`"campaign":{"aff_campaign_id":12,"ready":false,"needs":["x"],"apply":{"aff_campaign_url":"https://play.google.com/store/apps/details?id=a.b&referrer=p202%3D[[p202_install_token]]","app_registration_id":7}}}}`))
		case r.Method == http.MethodPut:
			putPath = r.URL.Path
			body, _ := io.ReadAll(r.Body)
			var decoded map[string]interface{}
			_ = json.Unmarshal(body, &decoded)
			puts = append(puts, decoded)
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":12,"app_registration_id":7}}`))
		default:
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
			w.WriteHeader(500)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("app", "link", "7", "--campaign-id", "12"); err != nil {
		t.Fatalf("app link: %v", err)
	}
	if len(puts) != 0 {
		t.Fatalf("without --apply nothing is written, got %v", puts)
	}
	if _, _, err := executeCommand("app", "link", "7", "--campaign-id", "12", "--apply"); err != nil {
		t.Fatalf("app link --apply: %v", err)
	}
	if len(puts) != 1 || !strings.HasSuffix(putPath, "/campaigns/12") {
		t.Fatalf("--apply is one PUT /campaigns/12, got %d to %q", len(puts), putPath)
	}
	if puts[0]["app_registration_id"] != "7" || !strings.Contains(puts[0]["aff_campaign_url"].(string), "[[p202_install_token]]") {
		t.Errorf("the PUT carries what the server said to apply, got %v", puts[0])
	}
}

func TestAppLinkAndNotificationsRefuseBadArgumentsBeforeAnyRequest(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	for _, args := range [][]string{
		{"app", "link", "x"},
		{"app", "link", "7", "--apply"},
		{"app", "link", "7", "--campaign-id", "0"},
		{"app", "notifications", "--status", "done"},
		{"app", "notifications", "--kind", "reach"},
		{"app", "notifications", "--registration-id", "-1"},
		{"app", "notifications", "--time-from", "yesterday"},
	} {
		_, _, err := executeCommand(args...)
		assertValidationError(t, err)
	}
	_, _, err := executeCommand("app", "link", "7", "--apply")
	if !strings.Contains(hintFor(err), "p202 campaign list") {
		t.Errorf("--apply without a campaign should say where campaign ids come from, got %q", hintFor(err))
	}
}

func TestAppNotificationsSendsItsFilters(t *testing.T) {
	var got url.Values
	var path string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.Query()
		path = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0},"meta":{"summary":{"pending":0,"sent":0,"failed":0,"cancelled":0,"suppressed":0}}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	if _, _, err := executeCommand("app", "notifications", "--registration-id", "7", "--status", "failed", "--kind", "reached"); err != nil {
		t.Fatalf("app notifications: %v", err)
	}
	if !strings.HasSuffix(path, "/apps/notifications") {
		t.Errorf("path = %q", path)
	}
	for param, want := range map[string]string{"registration_id": "7", "status": "failed", "kind": "reached"} {
		if got.Get(param) != want {
			t.Errorf("param %s = %q, want %q", param, got.Get(param), want)
		}
	}
}
