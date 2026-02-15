package cmd

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

// setTestHome sets HOME (or USERPROFILE on Windows) via t.Setenv so it
// is automatically restored after the test.
func setTestHome(t *testing.T, dir string) {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Setenv("USERPROFILE", dir)
	} else {
		t.Setenv("HOME", dir)
	}
}

// writeTestConfig creates a p202 config file under dir/.p202/config.json
// pointing at the given URL and API key.
func writeTestConfig(t *testing.T, dir, url, apiKey string) {
	t.Helper()
	configDir := filepath.Join(dir, ".p202")
	if err := os.MkdirAll(configDir, 0700); err != nil {
		t.Fatalf("creating config dir: %v", err)
	}
	cfg := fmt.Sprintf(`{"url": %q, "api_key": %q}`, url, apiKey)
	if err := os.WriteFile(filepath.Join(configDir, "config.json"), []byte(cfg), 0600); err != nil {
		t.Fatalf("writing config: %v", err)
	}
}

// executeCommand runs rootCmd with the given args and captures stdout/stderr.
// It resets the rootCmd output after execution.
func executeCommand(args ...string) (string, string, error) {
	stdoutBuf := new(bytes.Buffer)
	stderrBuf := new(bytes.Buffer)

	rootCmd.SetOut(stdoutBuf)
	rootCmd.SetErr(stderrBuf)
	rootCmd.SetArgs(args)

	// Capture os.Stdout since output.Render and fmt.Printf write to os.Stdout
	oldStdout := os.Stdout
	r, w, _ := os.Pipe()
	os.Stdout = w

	err := rootCmd.Execute()

	w.Close()
	os.Stdout = oldStdout

	var pipeBuf bytes.Buffer
	io.Copy(&pipeBuf, r)
	r.Close()

	// Combine cobra's SetOut buffer with pipe-captured stdout
	combined := stdoutBuf.String() + pipeBuf.String()

	return combined, stderrBuf.String(), err
}

func TestConfigSetURL(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	stdout, _, err := executeCommand("config", "set-url", "https://tracker.example.com")
	if err != nil {
		t.Fatalf("config set-url error: %v", err)
	}

	if !strings.Contains(stdout, "https://tracker.example.com") {
		t.Errorf("output should contain URL, got:\n%s", stdout)
	}

	// Verify config file was written
	data, err := os.ReadFile(filepath.Join(tmp, ".p202", "config.json"))
	if err != nil {
		t.Fatalf("config file not created: %v", err)
	}
	var cfg map[string]string
	if err := json.Unmarshal(data, &cfg); err != nil {
		t.Fatalf("config file is not valid JSON: %v", err)
	}
	if cfg["url"] != "https://tracker.example.com" {
		t.Errorf("saved URL = %q, want %q", cfg["url"], "https://tracker.example.com")
	}
}

func TestConfigSetURLTrimsTrailingSlash(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "set-url", "https://tracker.example.com/")
	if err != nil {
		t.Fatalf("config set-url error: %v", err)
	}

	data, err := os.ReadFile(filepath.Join(tmp, ".p202", "config.json"))
	if err != nil {
		t.Fatal(err)
	}
	var cfg map[string]string
	json.Unmarshal(data, &cfg)
	if cfg["url"] != "https://tracker.example.com" {
		t.Errorf("saved URL = %q, should have trailing slash trimmed", cfg["url"])
	}
}

func TestConfigSetURLRejectsInvalidURL(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "set-url", "not-a-url")
	if err == nil {
		t.Fatal("expected error for invalid URL")
	}
	if !strings.Contains(err.Error(), "http") {
		t.Errorf("error = %q, expected URL validation message", err.Error())
	}
}

func TestConfigSetKey(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	stdout, _, err := executeCommand("config", "set-key", "abcdefghijklmnop")
	if err != nil {
		t.Fatalf("config set-key error: %v", err)
	}

	// Should show masked key in output
	if !strings.Contains(stdout, "abcd...mnop") {
		t.Errorf("output should contain masked key, got:\n%s", stdout)
	}

	// Verify full key was saved
	data, err := os.ReadFile(filepath.Join(tmp, ".p202", "config.json"))
	if err != nil {
		t.Fatal(err)
	}
	var cfg map[string]string
	json.Unmarshal(data, &cfg)
	if cfg["api_key"] != "abcdefghijklmnop" {
		t.Errorf("saved API key = %q, want %q", cfg["api_key"], "abcdefghijklmnop")
	}
}

func TestConfigSetKeyRejectsShortValue(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "set-key", "short")
	if err == nil {
		t.Fatal("expected error for short API key")
	}
	if !strings.Contains(err.Error(), "at least 8 characters") {
		t.Errorf("error = %q, expected API key length validation message", err.Error())
	}
}

func TestConfigShow(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "longapikey12345678")

	stdout, _, err := executeCommand("config", "show")
	if err != nil {
		t.Fatalf("config show error: %v", err)
	}

	if !strings.Contains(stdout, "https://tracker.example.com") {
		t.Errorf("should show URL, got:\n%s", stdout)
	}
	// API key should be masked
	if !strings.Contains(stdout, "long...5678") {
		t.Errorf("should show masked key, got:\n%s", stdout)
	}
}

func TestConfigShowJSON(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "longapikey12345678")

	stdout, _, err := executeCommand("config", "show", "--json")
	if err != nil {
		t.Fatalf("config show --json error: %v", err)
	}

	// Output should be valid JSON
	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Errorf("output is not valid JSON: %v\noutput:\n%s", err, stdout)
	}
}

func TestCampaignList(t *testing.T) {
	var gotPath, gotMethod string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		w.WriteHeader(200)
		w.Write([]byte(`[{"id":1,"aff_campaign_name":"Test Campaign"}]`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	if gotMethod != "GET" {
		t.Errorf("method = %q, want GET", gotMethod)
	}
	if gotPath != "/api/v3/campaigns" {
		t.Errorf("path = %q, want /api/v3/campaigns", gotPath)
	}
	if !strings.Contains(stdout, "Test Campaign") {
		t.Errorf("output should contain campaign name, got:\n%s", stdout)
	}
}

func TestCampaignGet(t *testing.T) {
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"id":1,"aff_campaign_name":"Campaign One"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("campaign", "get", "1")
	if err != nil {
		t.Fatalf("campaign get 1 error: %v", err)
	}

	if gotPath != "/api/v3/campaigns/1" {
		t.Errorf("path = %q, want /api/v3/campaigns/1", gotPath)
	}
	if !strings.Contains(stdout, "Campaign One") {
		t.Errorf("output should contain campaign data, got:\n%s", stdout)
	}
}

func TestCampaignCreatePassesExtendedFields(t *testing.T) {
	var gotPath, gotMethod string
	var gotBody map[string]string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		bodyBytes, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(bodyBytes, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"id":1}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand(
		"campaign", "create",
		"--aff_campaign_name=Campaign A",
		"--aff_campaign_url=https://offer.example.com",
		"--aff_campaign_url_2=https://offer2.example.com",
		"--aff_campaign_currency=USD",
		"--aff_campaign_foreign_payout=12.34",
		"--aff_campaign_cloaking=1",
		"--aff_campaign_rotate=1",
	)
	if err != nil {
		t.Fatalf("campaign create error: %v", err)
	}

	if gotMethod != "POST" {
		t.Errorf("method = %q, want POST", gotMethod)
	}
	if gotPath != "/api/v3/campaigns" {
		t.Errorf("path = %q, want /api/v3/campaigns", gotPath)
	}
	for _, key := range []string{
		"aff_campaign_url_2",
		"aff_campaign_currency",
		"aff_campaign_foreign_payout",
		"aff_campaign_cloaking",
		"aff_campaign_rotate",
	} {
		if _, ok := gotBody[key]; !ok {
			t.Errorf("request body missing %q", key)
		}
	}
}

func TestLandingPageCreateRequiresAffCampaignID(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "test-key")

	_, _, err := executeCommand("landing-page", "create", "--landing_page_url=https://lp.example.com")
	if err == nil {
		t.Fatal("expected required flag error")
	}
	if !strings.Contains(err.Error(), "required flag --aff_campaign_id is missing") {
		t.Errorf("error = %q, expected missing aff_campaign_id", err.Error())
	}
}

func TestTrackerCreateUsesClickFields(t *testing.T) {
	var gotPath, gotMethod string
	var gotBody map[string]string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		bodyBytes, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(bodyBytes, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"id":1}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand(
		"tracker", "create",
		"--aff_campaign_id=44",
		"--ppc_account_id=55",
		"--text_ad_id=11",
		"--rotator_id=22",
		"--click_cpc=0.65",
		"--click_cpa=4.5",
		"--click_cloaking=1",
	)
	if err != nil {
		t.Fatalf("tracker create error: %v", err)
	}

	if gotMethod != "POST" {
		t.Errorf("method = %q, want POST", gotMethod)
	}
	if gotPath != "/api/v3/trackers" {
		t.Errorf("path = %q, want /api/v3/trackers", gotPath)
	}
	for _, key := range []string{"click_cpc", "click_cpa", "click_cloaking", "text_ad_id", "rotator_id"} {
		if _, ok := gotBody[key]; !ok {
			t.Errorf("request body missing %q", key)
		}
	}
	if _, ok := gotBody["tracker_cpc"]; ok {
		t.Errorf("request body should not include legacy field tracker_cpc")
	}
	if _, ok := gotBody["tracker_name"]; ok {
		t.Errorf("request body should not include unsupported field tracker_name")
	}
}

func TestTextAdCreateUsesDescriptionField(t *testing.T) {
	var gotBody map[string]string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		bodyBytes, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(bodyBytes, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"id":1}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand(
		"text-ad", "create",
		"--text_ad_name=Ad Name",
		"--text_ad_description=Ad description",
		"--aff_campaign_id=9",
		"--landing_page_id=10",
		"--text_ad_type=1",
	)
	if err != nil {
		t.Fatalf("text-ad create error: %v", err)
	}

	if gotBody["text_ad_description"] != "Ad description" {
		t.Errorf("text_ad_description = %q, want %q", gotBody["text_ad_description"], "Ad description")
	}
	if _, ok := gotBody["text_ad_body"]; ok {
		t.Errorf("request body should not include legacy field text_ad_body")
	}
	for _, key := range []string{"aff_campaign_id", "landing_page_id", "text_ad_type"} {
		if _, ok := gotBody[key]; !ok {
			t.Errorf("request body missing %q", key)
		}
	}
}

func TestTrackerGetURL(t *testing.T) {
	var gotPath, gotMethod string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"tracker_id":56,"direct_url":"https://trk.example.com/tracking202/redirect/go.php?t202id=123"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("tracker", "get-url", "56")
	if err != nil {
		t.Fatalf("tracker get-url error: %v", err)
	}

	if gotMethod != "GET" {
		t.Errorf("method = %q, want GET", gotMethod)
	}
	if gotPath != "/api/v3/trackers/56/url" {
		t.Errorf("path = %q, want /api/v3/trackers/56/url", gotPath)
	}
	if !strings.Contains(stdout, "direct_url") {
		t.Errorf("output should contain direct_url, got:\n%s", stdout)
	}
}

func TestSystemHealth(t *testing.T) {
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"status":"healthy","database":"ok","php_version":"8.3.0"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("system", "health")
	if err != nil {
		t.Fatalf("system health error: %v", err)
	}

	if gotPath != "/api/v3/system/health" {
		t.Errorf("path = %q, want /api/v3/system/health", gotPath)
	}
	if !strings.Contains(stdout, "healthy") {
		t.Errorf("output should contain health status, got:\n%s", stdout)
	}
}

func TestReportBreakdownPassesQueryParams(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"meta":{"total":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("report", "breakdown",
		"--breakdown=country",
		"--sort=total_clicks",
		"--sort_dir=ASC",
		"--limit=25",
		"--period=last7")
	if err != nil {
		t.Fatalf("report breakdown error: %v", err)
	}

	expectations := map[string]string{
		"breakdown": "country",
		"sort":      "total_clicks",
		"sort_dir":  "ASC",
		"limit":     "25",
		"period":    "last7",
	}
	for key, want := range expectations {
		got := gotParams.Get(key)
		if got != want {
			t.Errorf("query param %q = %q, want %q", key, got, want)
		}
	}
}

func TestReportDaypart(t *testing.T) {
	var gotPath string
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"hour_of_day":12,"total_clicks":10,"total_click_throughs":8,"total_leads":2,"total_income":15.5,"total_cost":6.2,"total_net":9.3,"epc":1.55,"avg_cpc":0.62,"conv_rate":25,"roi":150,"cpa":3.1}],"timezone":"UTC"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("report", "daypart", "--period=last30")
	if err != nil {
		t.Fatalf("report daypart error: %v", err)
	}

	if gotPath != "/api/v3/reports/daypart" {
		t.Errorf("path = %q, want /api/v3/reports/daypart", gotPath)
	}
	if gotParams.Get("period") != "last30" {
		t.Errorf("period = %q, want %q", gotParams.Get("period"), "last30")
	}
	if !strings.Contains(stdout, "12") {
		t.Errorf("output should contain hour_of_day value, got:\n%s", stdout)
	}
}

func TestReportDaypartPassesSortParams(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"timezone":"UTC"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("report", "daypart", "--sort=roi", "--sort_dir=DESC")
	if err != nil {
		t.Fatalf("report daypart sort error: %v", err)
	}

	if gotParams.Get("sort") != "roi" {
		t.Errorf("sort = %q, want %q", gotParams.Get("sort"), "roi")
	}
	if gotParams.Get("sort_dir") != "DESC" {
		t.Errorf("sort_dir = %q, want %q", gotParams.Get("sort_dir"), "DESC")
	}
}

func TestReportDaypartPassesFilterParams(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"timezone":"UTC"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("report", "daypart", "--period=last7", "--country_id=223")
	if err != nil {
		t.Fatalf("report daypart filter error: %v", err)
	}

	if gotParams.Get("period") != "last7" {
		t.Errorf("period = %q, want %q", gotParams.Get("period"), "last7")
	}
	if gotParams.Get("country_id") != "223" {
		t.Errorf("country_id = %q, want %q", gotParams.Get("country_id"), "223")
	}
}

func TestJSONFlagOutputsRawJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`[{"id":1,"name":"test"}]`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "campaign", "list")
	if err != nil {
		t.Fatalf("--json campaign list error: %v", err)
	}

	// In JSON mode, output should be valid JSON (pretty-printed)
	trimmed := strings.TrimSpace(stdout)
	var parsed interface{}
	if err := json.Unmarshal([]byte(trimmed), &parsed); err != nil {
		t.Errorf("--json output should be valid JSON: %v\noutput:\n%s", err, stdout)
	}

	// Should contain indentation (pretty-printed)
	if !strings.Contains(stdout, "  ") {
		t.Errorf("--json output should be pretty-printed, got:\n%s", stdout)
	}
}

func TestAPIErrorDisplaysMessage(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(404)
		w.Write([]byte(`{"message":"Campaign not found"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "get", "999")
	if err == nil {
		t.Fatal("expected error for 404 response")
	}

	errMsg := err.Error()
	if !strings.Contains(errMsg, "Campaign not found") {
		t.Errorf("error should contain API message, got: %q", errMsg)
	}
}

func TestCampaignListWithPagination(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"meta":{"total":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "list", "--page=3", "--limit=20")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	if gotParams.Get("page") != "3" {
		t.Errorf("page = %q, want %q", gotParams.Get("page"), "3")
	}
	if gotParams.Get("limit") != "20" {
		t.Errorf("limit = %q, want %q", gotParams.Get("limit"), "20")
	}
}

func TestSystemVersion(t *testing.T) {
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"version":"4.0.0","php":"8.3.0"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("system", "version")
	if err != nil {
		t.Fatalf("system version error: %v", err)
	}

	if gotPath != "/api/v3/system/version" {
		t.Errorf("path = %q, want /api/v3/system/version", gotPath)
	}
	if !strings.Contains(stdout, "4.0.0") {
		t.Errorf("output should contain version, got:\n%s", stdout)
	}
}

func TestCampaignListSendsAuthHeader(t *testing.T) {
	var gotAuth string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth = r.Header.Get("Authorization")
		w.WriteHeader(200)
		w.Write([]byte(`[]`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "my-secret-api-key")

	_, _, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	if gotAuth != "Bearer my-secret-api-key" {
		t.Errorf("Authorization = %q, want %q", gotAuth, "Bearer my-secret-api-key")
	}
}

func TestMissingConfigErrorsGracefully(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	// No config file written — should fail on Validate()

	_, _, err := executeCommand("campaign", "list")
	if err == nil {
		t.Fatal("expected error when config is missing")
	}

	errMsg := err.Error()
	if !strings.Contains(errMsg, "no URL configured") {
		t.Errorf("error should mention missing URL, got: %q", errMsg)
	}
}

func TestReportSummary(t *testing.T) {
	var gotPath string
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"total_clicks":100,"total_leads":5}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("report", "summary", "--period=today")
	if err != nil {
		t.Fatalf("report summary error: %v", err)
	}

	if gotPath != "/api/v3/reports/summary" {
		t.Errorf("path = %q, want /api/v3/reports/summary", gotPath)
	}
	if gotParams.Get("period") != "today" {
		t.Errorf("period param = %q, want %q", gotParams.Get("period"), "today")
	}
	if !strings.Contains(stdout, "100") {
		t.Errorf("output should contain total_clicks value, got:\n%s", stdout)
	}
}

func TestClickList(t *testing.T) {
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"click_id":"abc123"}],"meta":{"total":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("click", "list")
	if err != nil {
		t.Fatalf("click list error: %v", err)
	}

	if gotPath != "/api/v3/clicks" {
		t.Errorf("path = %q, want /api/v3/clicks", gotPath)
	}
	if !strings.Contains(stdout, "abc123") {
		t.Errorf("output should contain click ID, got:\n%s", stdout)
	}
}

func TestClickListPageCalculatesOffset(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"click_id":"abc123"}],"meta":{"total":1}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("click", "list", "--page=2", "--limit=25")
	if err != nil {
		t.Fatalf("click list with page error: %v", err)
	}

	if gotParams.Get("limit") != "25" {
		t.Errorf("limit = %q, want %q", gotParams.Get("limit"), "25")
	}
	if gotParams.Get("offset") != "25" {
		t.Errorf("offset = %q, want %q", gotParams.Get("offset"), "25")
	}
}

func TestConversionCreateSupportsLegacyAliases(t *testing.T) {
	var gotPath, gotMethod string
	var gotBody map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		bodyBytes, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(bodyBytes, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"conv_id":1,"click_id":12345}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("conversion", "create", "--click_id_public=12345", "--conversion_payout=1.25")
	if err != nil {
		t.Fatalf("conversion create alias flags error: %v", err)
	}

	if gotMethod != "POST" {
		t.Errorf("method = %q, want POST", gotMethod)
	}
	if gotPath != "/api/v3/conversions" {
		t.Errorf("path = %q, want /api/v3/conversions", gotPath)
	}
	if gotBody["click_id"] != float64(12345) {
		t.Errorf("click_id = %#v, want %v", gotBody["click_id"], float64(12345))
	}
	if gotBody["payout"] != "1.25" {
		t.Errorf("payout = %#v, want %q", gotBody["payout"], "1.25")
	}
	if _, ok := gotBody["click_id_public"]; ok {
		t.Errorf("request body should not include click_id_public alias")
	}
	if _, ok := gotBody["conversion_payout"]; ok {
		t.Errorf("request body should not include conversion_payout alias")
	}
}

func TestAPI422ErrorShowsFieldErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(422)
		w.Write([]byte(`{"message":"Validation failed","field_errors":{"name":"is required"}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "create", "--aff_campaign_name=test", "--aff_campaign_url=http://example.com")
	if err == nil {
		t.Fatal("expected error for 422 response")
	}

	errMsg := err.Error()
	if !strings.Contains(errMsg, "Validation failed") {
		t.Errorf("error should contain validation message, got: %q", errMsg)
	}
}

func TestAPI500Error(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`Internal Server Error`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("system", "health")
	if err == nil {
		t.Fatal("expected error for 500 response")
	}

	errMsg := err.Error()
	if !strings.Contains(errMsg, "500") {
		t.Errorf("error should contain status code, got: %q", errMsg)
	}
}
