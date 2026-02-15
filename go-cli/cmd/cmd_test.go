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
