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
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"

	configpkg "p202/internal/config"
	"p202/internal/syncstate"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
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

func writeTestConfigWithDefaults(t *testing.T, dir, url, apiKey string, defaults map[string]string) {
	t.Helper()
	configDir := filepath.Join(dir, ".p202")
	if err := os.MkdirAll(configDir, 0700); err != nil {
		t.Fatalf("creating config dir: %v", err)
	}
	payload := map[string]interface{}{
		"url":      url,
		"api_key":  apiKey,
		"defaults": defaults,
	}
	data, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("encoding config payload: %v", err)
	}
	if err := os.WriteFile(filepath.Join(configDir, "config.json"), data, 0600); err != nil {
		t.Fatalf("writing config with defaults: %v", err)
	}
}

func writeTestConfigWithProfiles(t *testing.T, dir string, active string, profiles map[string]map[string]interface{}) {
	t.Helper()
	configDir := filepath.Join(dir, ".p202")
	if err := os.MkdirAll(configDir, 0700); err != nil {
		t.Fatalf("creating config dir: %v", err)
	}

	payload := map[string]interface{}{
		"active_profile": active,
		"profiles":       profiles,
	}
	data, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("encoding profile config payload: %v", err)
	}
	if err := os.WriteFile(filepath.Join(configDir, "config.json"), data, 0600); err != nil {
		t.Fatalf("writing profile config: %v", err)
	}
}

func newEntityDataServer(t *testing.T, rowsByEndpoint map[string][]map[string]interface{}) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/api/v3/") {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}

		endpoint := strings.TrimPrefix(r.URL.Path, "/api/v3/")
		writeList := func(rows []map[string]interface{}) {
			offset := 0
			limit := len(rows)
			if raw := r.URL.Query().Get("offset"); raw != "" {
				if parsed, err := strconv.Atoi(raw); err == nil && parsed >= 0 {
					offset = parsed
				}
			}
			if raw := r.URL.Query().Get("limit"); raw != "" {
				if parsed, err := strconv.Atoi(raw); err == nil && parsed > 0 {
					limit = parsed
				}
			}

			start := offset
			if start > len(rows) {
				start = len(rows)
			}
			end := start + limit
			if end > len(rows) {
				end = len(rows)
			}

			page := rows[start:end]
			resp := map[string]interface{}{
				"data": page,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  limit,
					"offset": offset,
				},
			}
			payload, _ := json.Marshal(resp)
			w.WriteHeader(200)
			_, _ = w.Write(payload)
		}

		if rows, ok := rowsByEndpoint[endpoint]; ok {
			writeList(rows)
			return
		}

		if r.Method == http.MethodGet {
			parts := strings.SplitN(endpoint, "/", 3)
			if len(parts) >= 2 && parts[0] != "" && parts[1] != "" {
				baseEndpoint := parts[0]
				targetID := parts[1]
				if rows, ok := rowsByEndpoint[baseEndpoint]; ok {
					for _, row := range rows {
						candidateFields := []string{"id", "public_id"}
						if entityFields, exists := syncEntityIDFields[baseEndpoint]; exists {
							candidateFields = append(candidateFields, entityFields...)
						}
						for _, field := range candidateFields {
							if scalarString(row[field]) == targetID {
								payload, _ := json.Marshal(map[string]interface{}{"data": row})
								w.WriteHeader(200)
								_, _ = w.Write(payload)
								return
							}
						}
					}
					w.WriteHeader(404)
					_, _ = w.Write([]byte(`{"message":"not found"}`))
					return
				}
			}
		}

		if strings.Contains(endpoint, "/") {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}

		writeList([]map[string]interface{}{})
	}))
}

type syncWrite struct {
	Method string
	Path   string
	Body   map[string]interface{}
}

type syncServerCapture struct {
	PostCalls int
	PutCalls  int
	Writes    []syncWrite
}

func newSyncServer(t *testing.T, initialRows map[string][]map[string]interface{}) (*httptest.Server, *syncServerCapture) {
	t.Helper()

	state := map[string][]map[string]interface{}{}
	for endpoint, rows := range initialRows {
		copied := make([]map[string]interface{}, 0, len(rows))
		for _, row := range rows {
			copied = append(copied, cloneInterfaceMap(row))
		}
		state[endpoint] = copied
	}

	capture := &syncServerCapture{}
	idCounters := map[string]int{
		"aff-networks":  1000,
		"ppc-networks":  1000,
		"ppc-accounts":  1000,
		"campaigns":     1000,
		"landing-pages": 1000,
		"text-ads":      1000,
		"trackers":      1000,
	}

	idFieldByEndpoint := map[string]string{
		"aff-networks":  "aff_network_id",
		"ppc-networks":  "ppc_network_id",
		"ppc-accounts":  "ppc_account_id",
		"campaigns":     "aff_campaign_id",
		"landing-pages": "landing_page_id",
		"text-ads":      "text_ad_id",
		"trackers":      "tracker_id",
	}

	parseBody := func(r *http.Request) map[string]interface{} {
		if r.Body == nil {
			return map[string]interface{}{}
		}
		raw, _ := io.ReadAll(r.Body)
		if len(raw) == 0 {
			return map[string]interface{}{}
		}
		body := map[string]interface{}{}
		if err := json.Unmarshal(raw, &body); err != nil {
			return map[string]interface{}{}
		}
		return body
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/api/v3/") {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}

		trimmed := strings.TrimPrefix(r.URL.Path, "/api/v3/")
		parts := strings.Split(strings.Trim(trimmed, "/"), "/")
		if len(parts) == 0 || parts[0] == "" {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
			return
		}
		endpoint := parts[0]
		if _, exists := state[endpoint]; !exists {
			state[endpoint] = []map[string]interface{}{}
		}

		switch r.Method {
		case http.MethodGet:
			rows := state[endpoint]
			offset := 0
			limit := len(rows)
			if raw := r.URL.Query().Get("offset"); raw != "" {
				if parsed, err := strconv.Atoi(raw); err == nil && parsed >= 0 {
					offset = parsed
				}
			}
			if raw := r.URL.Query().Get("limit"); raw != "" {
				if parsed, err := strconv.Atoi(raw); err == nil && parsed > 0 {
					limit = parsed
				}
			}
			start := offset
			if start > len(rows) {
				start = len(rows)
			}
			end := start + limit
			if end > len(rows) {
				end = len(rows)
			}
			page := rows[start:end]
			payload, _ := json.Marshal(map[string]interface{}{
				"data": page,
				"pagination": map[string]interface{}{
					"total":  len(rows),
					"limit":  limit,
					"offset": offset,
				},
			})
			w.WriteHeader(200)
			_, _ = w.Write(payload)
		case http.MethodPost:
			body := parseBody(r)
			capture.PostCalls++
			capture.Writes = append(capture.Writes, syncWrite{Method: r.Method, Path: r.URL.Path, Body: cloneInterfaceMap(body)})

			idCounters[endpoint]++
			idField := idFieldByEndpoint[endpoint]
			if idField != "" {
				if _, exists := body[idField]; !exists {
					body[idField] = idCounters[endpoint]
				}
			}
			state[endpoint] = append(state[endpoint], cloneInterfaceMap(body))
			payload, _ := json.Marshal(map[string]interface{}{"data": body})
			w.WriteHeader(200)
			_, _ = w.Write(payload)
		case http.MethodPut:
			body := parseBody(r)
			capture.PutCalls++
			capture.Writes = append(capture.Writes, syncWrite{Method: r.Method, Path: r.URL.Path, Body: cloneInterfaceMap(body)})
			payload, _ := json.Marshal(map[string]interface{}{"data": body})
			w.WriteHeader(200)
			_, _ = w.Write(payload)
		default:
			w.WriteHeader(405)
			_, _ = w.Write([]byte(`{"message":"method not allowed"}`))
		}
	}))

	return server, capture
}

func cloneInterfaceMap(in map[string]interface{}) map[string]interface{} {
	out := map[string]interface{}{}
	for k, v := range in {
		out[k] = v
	}
	return out
}

func readSavedConfigURLAndKey(t *testing.T, dir string) (string, string) {
	t.Helper()

	raw := readSavedConfigRaw(t, dir)

	urlVal, _ := raw["url"].(string)
	keyVal, _ := raw["api_key"].(string)
	if urlVal != "" || keyVal != "" {
		return urlVal, keyVal
	}

	active := "default"
	if v, ok := raw["active_profile"].(string); ok && strings.TrimSpace(v) != "" {
		active = strings.TrimSpace(v)
	}

	profiles, _ := raw["profiles"].(map[string]interface{})
	if len(profiles) == 0 {
		return "", ""
	}

	selectProfile := func(name string) map[string]interface{} {
		if p, ok := profiles[name].(map[string]interface{}); ok {
			return p
		}
		if p, ok := profiles["default"].(map[string]interface{}); ok {
			return p
		}
		for _, rawProfile := range profiles {
			if p, ok := rawProfile.(map[string]interface{}); ok {
				return p
			}
		}
		return nil
	}

	profile := selectProfile(active)
	if profile == nil {
		return "", ""
	}

	urlVal, _ = profile["url"].(string)
	keyVal, _ = profile["api_key"].(string)
	return urlVal, keyVal
}

func readSavedConfigRaw(t *testing.T, dir string) map[string]interface{} {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(dir, ".p202", "config.json"))
	if err != nil {
		t.Fatalf("reading config: %v", err)
	}
	var raw map[string]interface{}
	if err := json.Unmarshal(data, &raw); err != nil {
		t.Fatalf("parsing config JSON: %v", err)
	}
	return raw
}

func readProfileField(raw map[string]interface{}, profileName, field string) string {
	profiles, _ := raw["profiles"].(map[string]interface{})
	if profiles == nil {
		return ""
	}
	profileObj, _ := profiles[profileName].(map[string]interface{})
	if profileObj == nil {
		return ""
	}
	value, _ := profileObj[field].(string)
	return value
}

func readProfileTags(raw map[string]interface{}, profileName string) []string {
	profiles, _ := raw["profiles"].(map[string]interface{})
	if profiles == nil {
		return nil
	}
	profileObj, _ := profiles[profileName].(map[string]interface{})
	if profileObj == nil {
		return nil
	}
	rawTags, _ := profileObj["tags"].([]interface{})
	out := make([]string, 0, len(rawTags))
	for _, item := range rawTags {
		if tag, ok := item.(string); ok {
			out = append(out, tag)
		}
	}
	sort.Strings(out)
	return out
}

// executeCommand runs rootCmd with the given args and captures stdout/stderr.
// It resets the rootCmd output after execution.
func executeCommand(args ...string) (string, string, error) {
	stdoutBuf := new(bytes.Buffer)
	stderrBuf := new(bytes.Buffer)

	// Reset global and command flag state between test invocations.
	resetAllFlags(rootCmd)
	configpkg.ResetActiveOverride()
	jsonOutput = false
	csvOutput = false
	profileName = ""
	groupName = ""
	_ = rootCmd.PersistentFlags().Set("json", "false")
	_ = rootCmd.PersistentFlags().Set("csv", "false")
	_ = rootCmd.PersistentFlags().Set("profile", "")
	_ = rootCmd.PersistentFlags().Set("group", "")

	rootCmd.SetOut(stdoutBuf)
	rootCmd.SetErr(stderrBuf)
	rootCmd.SetArgs(args)

	// Capture os.Stdout since output.Render and fmt.Printf write to os.Stdout
	oldStdout := os.Stdout
	r, w, _ := os.Pipe()
	os.Stdout = w

	// Capture os.Stderr since output.renderPagination writes directly to os.Stderr
	oldStderr := os.Stderr
	rErr, wErr, _ := os.Pipe()
	os.Stderr = wErr

	err := rootCmd.Execute()

	w.Close()
	os.Stdout = oldStdout
	wErr.Close()
	os.Stderr = oldStderr

	var pipeBuf bytes.Buffer
	io.Copy(&pipeBuf, r)
	r.Close()

	var pipeErrBuf bytes.Buffer
	io.Copy(&pipeErrBuf, rErr)
	rErr.Close()

	// Combine cobra's SetOut buffer with pipe-captured stdout
	combined := stdoutBuf.String() + pipeBuf.String()
	// Combine cobra's SetErr buffer with pipe-captured stderr
	combinedErr := stderrBuf.String() + pipeErrBuf.String()

	return combined, combinedErr, err
}

func resetAllFlags(cmd *cobra.Command) {
	resetFlagSet := func(fs *pflag.FlagSet) {
		fs.VisitAll(func(f *pflag.Flag) {
			_ = fs.Set(f.Name, f.DefValue)
			f.Changed = false
		})
	}

	resetFlagSet(cmd.PersistentFlags())
	resetFlagSet(cmd.Flags())
	for _, c := range cmd.Commands() {
		resetAllFlags(c)
	}
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

	savedURL, _ := readSavedConfigURLAndKey(t, tmp)
	if savedURL != "https://tracker.example.com" {
		t.Errorf("saved URL = %q, want %q", savedURL, "https://tracker.example.com")
	}
}

func TestConfigSetURLTrimsTrailingSlash(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "set-url", "https://tracker.example.com/")
	if err != nil {
		t.Fatalf("config set-url error: %v", err)
	}

	savedURL, _ := readSavedConfigURLAndKey(t, tmp)
	if savedURL != "https://tracker.example.com" {
		t.Errorf("saved URL = %q, should have trailing slash trimmed", savedURL)
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

	_, savedKey := readSavedConfigURLAndKey(t, tmp)
	if savedKey != "abcdefghijklmnop" {
		t.Errorf("saved API key = %q, want %q", savedKey, "abcdefghijklmnop")
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

func TestConfigDefaultCommands(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "set-default", "report.period", "last30")
	if err != nil {
		t.Fatalf("config set-default error: %v", err)
	}

	stdout, _, err := executeCommand("config", "get-default", "report.period")
	if err != nil {
		t.Fatalf("config get-default error: %v", err)
	}
	if !strings.Contains(stdout, "last30") {
		t.Errorf("get-default output should contain value, got:\n%s", stdout)
	}

	_, _, err = executeCommand("config", "unset-default", "report.period")
	if err != nil {
		t.Fatalf("config unset-default error: %v", err)
	}

	_, _, err = executeCommand("config", "get-default", "report.period")
	if err == nil {
		t.Fatal("expected error when getting an unset default")
	}
}

func TestConfigAddProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("config", "add-profile", "prod", "--url", "https://prod.example.com", "--key", "prod-key-123456")
	if err != nil {
		t.Fatalf("config add-profile error: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	if got, _ := raw["active_profile"].(string); got != "prod" {
		t.Fatalf("active_profile = %q, want prod", got)
	}
	if got := readProfileField(raw, "prod", "url"); got != "https://prod.example.com" {
		t.Fatalf("prod profile url = %q", got)
	}
	if got := readProfileField(raw, "prod", "api_key"); got != "prod-key-123456" {
		t.Fatalf("prod profile api_key = %q", got)
	}
}

func TestConfigAddProfileDuplicate(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
	})

	_, _, err := executeCommand("config", "add-profile", "prod", "--url", "https://prod2.example.com", "--key", "prod2-key-123456")
	if err == nil {
		t.Fatal("expected duplicate profile add to fail")
	}
	if !strings.Contains(err.Error(), "already exists") {
		t.Fatalf("expected duplicate error, got: %v", err)
	}
}

func TestConfigUseProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456"},
	})

	_, _, err := executeCommand("config", "use", "staging")
	if err != nil {
		t.Fatalf("config use staging error: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	if got, _ := raw["active_profile"].(string); got != "staging" {
		t.Fatalf("active_profile = %q, want staging", got)
	}
}

func TestConfigListProfiles(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {
			"url":     "https://prod.example.com",
			"api_key": "prod-key-123456",
			"tags":    []string{"env:prod", "region:us"},
		},
		"staging": {
			"url":     "https://staging.example.com",
			"api_key": "staging-key-123456",
			"tags":    []string{"env:staging", "region:us"},
		},
	})

	stdout, _, err := executeCommand("config", "list-profiles", "--json")
	if err != nil {
		t.Fatalf("config list-profiles --json error: %v", err)
	}
	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	dataRows, ok := parsed["data"].([]interface{})
	if !ok || len(dataRows) != 2 {
		t.Fatalf("expected two profile rows, got %#v", parsed["data"])
	}
}

func TestTagProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
	})

	_, _, err := executeCommand("config", "tag-profile", "prod", "ENV:Prod", "region:US")
	if err != nil {
		t.Fatalf("config tag-profile error: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	tags := readProfileTags(raw, "prod")
	if len(tags) != 2 || tags[0] != "env:prod" || tags[1] != "region:us" {
		t.Fatalf("unexpected normalized tags: %#v", tags)
	}
}

func TestUntagProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456", "tags": []string{"env:prod", "region:us"}},
	})

	_, _, err := executeCommand("config", "untag-profile", "prod", "region:us")
	if err != nil {
		t.Fatalf("config untag-profile error: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	tags := readProfileTags(raw, "prod")
	if len(tags) != 1 || tags[0] != "env:prod" {
		t.Fatalf("unexpected tags after untag: %#v", tags)
	}
}

func TestListProfilesFilterByTag(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456", "tags": []string{"env:prod", "region:us"}},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456", "tags": []string{"env:staging", "region:us"}},
	})

	stdout, _, err := executeCommand("config", "list-profiles", "--tag", "env:prod", "--json")
	if err != nil {
		t.Fatalf("config list-profiles --tag error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	rows, _ := parsed["data"].([]interface{})
	if len(rows) != 1 {
		t.Fatalf("expected 1 row from tag filter, got %d", len(rows))
	}
	row, _ := rows[0].(map[string]interface{})
	if row["name"] != "prod" {
		t.Fatalf("filtered profile should be prod, got %#v", row["name"])
	}
}

func TestConfigRenameProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
	})

	_, _, err := executeCommand("config", "rename-profile", "prod", "primary")
	if err != nil {
		t.Fatalf("config rename-profile error: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	if got, _ := raw["active_profile"].(string); got != "primary" {
		t.Fatalf("active_profile = %q, want primary", got)
	}
	if got := readProfileField(raw, "primary", "url"); got != "https://prod.example.com" {
		t.Fatalf("primary profile url = %q", got)
	}
}

func TestConfigRemoveProfileBlocksActive(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod": {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
	})

	_, _, err := executeCommand("config", "remove-profile", "prod", "--force")
	if err == nil {
		t.Fatal("expected active profile removal to fail")
	}
	if !strings.Contains(err.Error(), "cannot remove active profile") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestProfileFlagSelectsCorrectServer(t *testing.T) {
	hits := map[string]int{"prod": 0, "staging": 0}

	prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits["prod"]++
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer prodSrv.Close()

	stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits["staging"]++
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer stageSrv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456"},
		"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456"},
	})

	_, _, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list on active profile failed: %v", err)
	}
	_, _, err = executeCommand("--profile", "staging", "campaign", "list")
	if err != nil {
		t.Fatalf("campaign list with --profile staging failed: %v", err)
	}

	if hits["prod"] != 1 || hits["staging"] != 1 {
		t.Fatalf("server routing mismatch, hits=%v", hits)
	}
}

func TestSetUrlUpdatesResolvedProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456"},
	})

	_, _, err := executeCommand("--profile", "staging", "config", "set-url", "https://staging2.example.com")
	if err != nil {
		t.Fatalf("config set-url with profile override failed: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	if got := readProfileField(raw, "staging", "url"); got != "https://staging2.example.com" {
		t.Fatalf("staging url = %q", got)
	}
	if got := readProfileField(raw, "prod", "url"); got != "https://prod.example.com" {
		t.Fatalf("prod url unexpectedly changed to %q", got)
	}
}

func TestSetKeyUpdatesResolvedProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456"},
	})

	_, _, err := executeCommand("--profile", "staging", "config", "set-key", "staging-key-999999")
	if err != nil {
		t.Fatalf("config set-key with profile override failed: %v", err)
	}

	raw := readSavedConfigRaw(t, tmp)
	if got := readProfileField(raw, "staging", "api_key"); got != "staging-key-999999" {
		t.Fatalf("staging api_key = %q", got)
	}
	if got := readProfileField(raw, "prod", "api_key"); got != "prod-key-123456" {
		t.Fatalf("prod api_key unexpectedly changed to %q", got)
	}
}

func TestDiffShowsOnlyInSource(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 1, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 1, "aff_campaign_name": "Alpha", "aff_network_id": 1},
		},
	})
	defer source.Close()

	target := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 99, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 2, "aff_campaign_name": "Beta", "aff_network_id": 99},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff campaigns error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	data, _ := parsed["data"].(map[string]interface{})
	if got := int(data["only_in_source_count"].(float64)); got != 1 {
		t.Fatalf("only_in_source_count=%d, want 1", got)
	}
	if got := int(data["only_in_target_count"].(float64)); got != 1 {
		t.Fatalf("only_in_target_count=%d, want 1", got)
	}
	if got := int(data["changed_count"].(float64)); got != 0 {
		t.Fatalf("changed_count=%d, want 0", got)
	}
}

func TestDiffShowsChanged(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 1, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{
				"aff_campaign_id":         1,
				"aff_campaign_name":       "Alpha",
				"aff_network_id":          1,
				"aff_campaign_payout":     "10.00",
				"aff_campaign_deleted":    0,
				"aff_campaign_id_public":  "abc",
				"aff_campaign_time":       1700000000,
				"aff_campaign_postback":   "",
				"aff_campaign_postback_2": "",
			},
		},
	})
	defer source.Close()

	target := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 99, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{
				"aff_campaign_id":      2,
				"aff_campaign_name":    "Alpha",
				"aff_network_id":       99,
				"aff_campaign_payout":  "12.00",
				"aff_campaign_deleted": 0,
			},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff campaigns error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	data, _ := parsed["data"].(map[string]interface{})
	if got := int(data["changed_count"].(float64)); got != 1 {
		t.Fatalf("changed_count=%d, want 1", got)
	}
	changedRows, _ := data["changed"].([]interface{})
	if len(changedRows) != 1 {
		t.Fatalf("expected 1 changed row, got %d", len(changedRows))
	}
	changed, _ := changedRows[0].(map[string]interface{})
	fields, _ := changed["changed_fields"].([]interface{})
	found := false
	for _, field := range fields {
		if field.(string) == "aff_campaign_payout" {
			found = true
			break
		}
	}
	if !found {
		t.Fatalf("changed_fields should include aff_campaign_payout, got %#v", fields)
	}
}

func TestDiffDetectsRotatorRuleChanges(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"rotators": {
			{
				"id":               1,
				"public_id":        100,
				"name":             "R1",
				"default_campaign": 0,
				"default_lp":       0,
				"rules": []interface{}{
					map[string]interface{}{
						"id":        11,
						"rule_name": "US",
						"splittest": 1,
						"status":    1,
						"criteria": []interface{}{
							map[string]interface{}{"id": 1001, "type": "country", "statement": "is", "value": "US"},
						},
						"redirects": []interface{}{
							map[string]interface{}{"id": 2001, "redirect_url": "", "redirect_campaign": 0, "redirect_lp": 0, "weight": 100, "name": "A"},
						},
					},
				},
			},
		},
	})
	defer source.Close()

	target := newEntityDataServer(t, map[string][]map[string]interface{}{
		"rotators": {
			{
				"id":               99,
				"public_id":        100,
				"name":             "R1",
				"default_campaign": 0,
				"default_lp":       0,
				"rules": []interface{}{
					map[string]interface{}{
						"id":        55,
						"rule_name": "US",
						"splittest": 0,
						"status":    1,
						"criteria": []interface{}{
							map[string]interface{}{"id": 3001, "type": "country", "statement": "is", "value": "US"},
						},
						"redirects": []interface{}{
							map[string]interface{}{"id": 4001, "redirect_url": "", "redirect_campaign": 0, "redirect_lp": 0, "weight": 100, "name": "A"},
						},
					},
				},
			},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "rotators", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff rotators error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	data, _ := parsed["data"].(map[string]interface{})
	if got := int(data["changed_count"].(float64)); got != 1 {
		t.Fatalf("changed_count=%d, want 1", got)
	}
	changedRows, _ := data["changed"].([]interface{})
	if len(changedRows) != 1 {
		t.Fatalf("expected 1 changed row, got %d", len(changedRows))
	}
	changed, _ := changedRows[0].(map[string]interface{})
	fields, _ := changed["changed_fields"].([]interface{})
	foundRules := false
	for _, field := range fields {
		if scalarString(field) == "rules" {
			foundRules = true
			break
		}
	}
	if !foundRules {
		t.Fatalf("changed_fields should include rules, got %#v", fields)
	}
}

func TestDiffRotatorIdenticalWithSameRules(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"rotators": {
			{
				"id":               10,
				"public_id":        200,
				"name":             "R2",
				"default_campaign": 0,
				"default_lp":       0,
				"rules": []interface{}{
					map[string]interface{}{
						"id":        501,
						"rule_name": "CA",
						"splittest": 1,
						"status":    1,
						"criteria": []interface{}{
							map[string]interface{}{"id": 7001, "type": "country", "statement": "is", "value": "CA"},
						},
						"redirects": []interface{}{
							map[string]interface{}{"id": 8001, "redirect_url": "https://example.com", "redirect_campaign": 0, "redirect_lp": 0, "weight": 100, "name": "Primary"},
						},
					},
				},
			},
		},
	})
	defer source.Close()

	target := newEntityDataServer(t, map[string][]map[string]interface{}{
		"rotators": {
			{
				"id":               11,
				"public_id":        200,
				"name":             "R2",
				"default_campaign": 0,
				"default_lp":       0,
				"rules": []interface{}{
					map[string]interface{}{
						"id":        900,
						"rule_name": "CA",
						"splittest": 1,
						"status":    1,
						"criteria": []interface{}{
							map[string]interface{}{"id": 9001, "type": "country", "statement": "is", "value": "CA"},
						},
						"redirects": []interface{}{
							map[string]interface{}{"id": 9101, "redirect_url": "https://example.com", "redirect_campaign": 0, "redirect_lp": 0, "weight": 100, "name": "Primary"},
						},
					},
				},
			},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "rotators", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff rotators error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	data, _ := parsed["data"].(map[string]interface{})
	if got := int(data["changed_count"].(float64)); got != 0 {
		t.Fatalf("changed_count=%d, want 0", got)
	}
	if got := int(data["identical_count"].(float64)); got != 1 {
		t.Fatalf("identical_count=%d, want 1", got)
	}
}

func TestDiffAllEntities(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 1, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 1, "aff_campaign_name": "Alpha", "aff_network_id": 1},
		},
	})
	defer source.Close()

	target := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 9, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 8, "aff_campaign_name": "Alpha", "aff_network_id": 9},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "all", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff all error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	if parsed["from"] != "source" || parsed["to"] != "target" {
		t.Fatalf("unexpected from/to fields: %#v", parsed)
	}
	dataObj, ok := parsed["data"].(map[string]interface{})
	if !ok {
		t.Fatalf("diff all data should be object, got %#v", parsed["data"])
	}
	if _, ok := dataObj["campaigns"]; !ok {
		t.Fatalf("diff all response missing campaigns entry")
	}
}

func TestDiffUsesServerPlanWhenCapabilityIsAvailable(t *testing.T) {
	var mu sync.Mutex
	hits := []string{}

	orchestrator := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		hits = append(hits, r.Method+" "+r.URL.Path)
		mu.Unlock()

		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"sync_features":{"sync_plan":true}}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/plan":
			_, _ = w.Write([]byte(`{"data":{"entity":"campaigns","summary":{"changed":1},"data":{"campaigns":{"changed_count":1}}}}`))
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer orchestrator.Close()

	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": orchestrator.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("diff", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("diff command failed: %v", err)
	}
	if !strings.Contains(stdout, `"changed_count": 1`) {
		t.Fatalf("expected server-side diff response, got:\n%s", stdout)
	}

	mu.Lock()
	defer mu.Unlock()
	foundPlan := false
	for _, hit := range hits {
		if hit == "POST /api/v3/sync/plan" {
			foundPlan = true
			break
		}
	}
	if !foundPlan {
		t.Fatalf("expected POST /api/v3/sync/plan call, got %#v", hits)
	}
}

func TestSyncUsesServerJobsWhenCapabilityIsAvailable(t *testing.T) {
	var workerRuns int
	var jobGets int

	orchestrator := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"sync_features":{"async_jobs":true}}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/jobs":
			_, _ = w.Write([]byte(`{"data":{"job_id":"abc123","status":"queued"}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/sync/worker/run":
			workerRuns++
			_, _ = w.Write([]byte(`{"data":{"processed":1}}`))
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/sync/jobs/abc123":
			jobGets++
			_, _ = w.Write([]byte(`{"data":{"job_id":"abc123","status":"succeeded","results":{"campaigns":{"synced":2}}}}`))
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer orchestrator.Close()

	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": orchestrator.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync command failed: %v", err)
	}
	if !strings.Contains(stdout, `"job_id": "abc123"`) {
		t.Fatalf("expected server job response, got:\n%s", stdout)
	}
	if !strings.Contains(stdout, `"status": "succeeded"`) {
		t.Fatalf("expected terminal polled job status, got:\n%s", stdout)
	}
	if workerRuns == 0 {
		t.Fatalf("expected sync worker endpoint to be invoked")
	}
	if jobGets == 0 {
		t.Fatalf("expected job poll endpoint to be invoked")
	}
}

func TestSyncDryRunMakesNoPosts(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--dry-run", "--json")
	if err != nil {
		t.Fatalf("sync dry-run error: %v", err)
	}
	if capture.PostCalls != 0 || capture.PutCalls != 0 {
		t.Fatalf("dry-run should not write, post=%d put=%d", capture.PostCalls, capture.PutCalls)
	}
	if !strings.Contains(stdout, `"dry_run": true`) {
		t.Fatalf("dry-run output should include dry_run=true:\n%s", stdout)
	}
}

func TestSyncCreatesWithRemappedFKs(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 20, "aff_campaign_name": "Camp A", "aff_network_id": 10},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 101, "aff_network_name": "Net A"},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync campaigns error: %v", err)
	}

	if capture.PostCalls != 1 {
		t.Fatalf("expected 1 post call, got %d", capture.PostCalls)
	}
	if len(capture.Writes) == 0 {
		t.Fatal("expected captured writes")
	}
	lastWrite := capture.Writes[len(capture.Writes)-1]
	if lastWrite.Path != "/api/v3/campaigns" {
		t.Fatalf("expected campaign post path, got %s", lastWrite.Path)
	}
	if got := scalarString(lastWrite.Body["aff_network_id"]); got != "101" {
		t.Fatalf("expected remapped aff_network_id=101, got %q", got)
	}
}

func TestSyncTrackersRemapAllForeignKeysIncludingRotator(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
		"ppc-networks": {
			{"ppc_network_id": 20, "ppc_network_name": "PPC Net"},
		},
		"ppc-accounts": {
			{"ppc_account_id": 30, "ppc_account_name": "Account A", "ppc_network_id": 20},
		},
		"campaigns": {
			{"aff_campaign_id": 40, "aff_campaign_name": "Camp A", "aff_network_id": 10},
		},
		"landing-pages": {
			{"landing_page_id": 50, "landing_page_url": "https://lp.example/a", "aff_campaign_id": 40},
		},
		"text-ads": {
			{"text_ad_id": 60, "text_ad_name": "Ad A", "aff_campaign_id": 40, "landing_page_id": 50},
		},
		"rotators": {
			{"id": 70, "public_id": "rot-a", "default_campaign": 40, "default_lp": 50},
		},
		"trackers": {
			{
				"tracker_id":      80,
				"aff_campaign_id": 40,
				"ppc_account_id":  30,
				"landing_page_id": 50,
				"text_ad_id":      60,
				"rotator_id":      70,
				"click_cpc":       "1.20",
				"click_cpa":       "0",
				"click_cloaking":  "0",
			},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 101, "aff_network_name": "Net A"},
		},
		"ppc-networks": {
			{"ppc_network_id": 201, "ppc_network_name": "PPC Net"},
		},
		"ppc-accounts": {
			{"ppc_account_id": 301, "ppc_account_name": "Account A", "ppc_network_id": 201},
		},
		"campaigns": {
			{"aff_campaign_id": 401, "aff_campaign_name": "Camp A", "aff_network_id": 101},
		},
		"landing-pages": {
			{"landing_page_id": 501, "landing_page_url": "https://lp.example/a", "aff_campaign_id": 401},
		},
		"text-ads": {
			{"text_ad_id": 601, "text_ad_name": "Ad A", "aff_campaign_id": 401, "landing_page_id": 501},
		},
		"rotators": {
			{"id": 701, "public_id": "rot-a", "default_campaign": 401, "default_lp": 501},
		},
		"trackers": {},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "trackers", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync trackers error: %v", err)
	}

	if capture.PostCalls != 1 {
		t.Fatalf("expected one tracker create write, got post=%d", capture.PostCalls)
	}

	lastWrite := capture.Writes[len(capture.Writes)-1]
	if lastWrite.Path != "/api/v3/trackers" {
		t.Fatalf("expected tracker post path, got %s", lastWrite.Path)
	}
	if got := scalarString(lastWrite.Body["aff_campaign_id"]); got != "401" {
		t.Fatalf("expected remapped aff_campaign_id=401, got %q", got)
	}
	if got := scalarString(lastWrite.Body["ppc_account_id"]); got != "301" {
		t.Fatalf("expected remapped ppc_account_id=301, got %q", got)
	}
	if got := scalarString(lastWrite.Body["landing_page_id"]); got != "501" {
		t.Fatalf("expected remapped landing_page_id=501, got %q", got)
	}
	if got := scalarString(lastWrite.Body["text_ad_id"]); got != "601" {
		t.Fatalf("expected remapped text_ad_id=601, got %q", got)
	}
	if got := scalarString(lastWrite.Body["rotator_id"]); got != "701" {
		t.Fatalf("expected remapped rotator_id=701, got %q", got)
	}
}

func TestSyncTrackersIsIdempotentOnSecondRun(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
		"ppc-networks": {
			{"ppc_network_id": 20, "ppc_network_name": "PPC Net"},
		},
		"ppc-accounts": {
			{"ppc_account_id": 30, "ppc_account_name": "Account A", "ppc_network_id": 20},
		},
		"campaigns": {
			{"aff_campaign_id": 40, "aff_campaign_name": "Camp A", "aff_network_id": 10},
		},
		"landing-pages": {
			{"landing_page_id": 50, "landing_page_url": "https://lp.example/a", "aff_campaign_id": 40},
		},
		"text-ads": {
			{"text_ad_id": 60, "text_ad_name": "Ad A", "aff_campaign_id": 40, "landing_page_id": 50},
		},
		"rotators": {
			{"id": 70, "public_id": "rot-a", "default_campaign": 40, "default_lp": 50},
		},
		"trackers": {
			{
				"tracker_id":      80,
				"aff_campaign_id": 40,
				"ppc_account_id":  30,
				"landing_page_id": 50,
				"text_ad_id":      60,
				"rotator_id":      70,
				"click_cpc":       "1.20",
				"click_cpa":       "0",
				"click_cloaking":  "0",
			},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 101, "aff_network_name": "Net A"},
		},
		"ppc-networks": {
			{"ppc_network_id": 201, "ppc_network_name": "PPC Net"},
		},
		"ppc-accounts": {
			{"ppc_account_id": 301, "ppc_account_name": "Account A", "ppc_network_id": 201},
		},
		"campaigns": {
			{"aff_campaign_id": 401, "aff_campaign_name": "Camp A", "aff_network_id": 101},
		},
		"landing-pages": {
			{"landing_page_id": 501, "landing_page_url": "https://lp.example/a", "aff_campaign_id": 401},
		},
		"text-ads": {
			{"text_ad_id": 601, "text_ad_name": "Ad A", "aff_campaign_id": 401, "landing_page_id": 501},
		},
		"rotators": {
			{"id": 701, "public_id": "rot-a", "default_campaign": 401, "default_lp": 501},
		},
		"trackers": {},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "trackers", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync trackers error: %v", err)
	}
	postBefore := capture.PostCalls
	putBefore := capture.PutCalls

	stdout, _, err := executeCommand("sync", "trackers", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("second sync trackers error: %v", err)
	}
	if capture.PostCalls != postBefore || capture.PutCalls != putBefore {
		t.Fatalf("expected idempotent second sync with no writes, post %d->%d put %d->%d", postBefore, capture.PostCalls, putBefore, capture.PutCalls)
	}
	if !strings.Contains(stdout, `"skipped": 1`) {
		t.Fatalf("expected skipped count on second tracker sync:\n%s", stdout)
	}
}

func TestSyncSkipsExistingByName(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 20, "aff_campaign_name": "Camp A", "aff_network_id": 10, "aff_campaign_payout": "10.00"},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 101, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 201, "aff_campaign_name": "Camp A", "aff_network_id": 101, "aff_campaign_payout": "10.00"},
		},
	})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync campaigns error: %v", err)
	}

	if capture.PostCalls != 0 || capture.PutCalls != 0 {
		t.Fatalf("expected skip with zero writes, post=%d put=%d", capture.PostCalls, capture.PutCalls)
	}
	if !strings.Contains(stdout, `"skipped": 1`) {
		t.Fatalf("expected skipped count in output:\n%s", stdout)
	}
}

func TestSyncDependencyOrder(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
		"campaigns": {
			{"aff_campaign_id": 20, "aff_campaign_name": "Camp A", "aff_network_id": 10},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "all", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync all error: %v", err)
	}

	if len(capture.Writes) < 2 {
		t.Fatalf("expected at least 2 writes, got %d", len(capture.Writes))
	}
	firstPath := capture.Writes[0].Path
	secondPath := capture.Writes[1].Path
	if firstPath != "/api/v3/aff-networks" || secondPath != "/api/v3/campaigns" {
		t.Fatalf("unexpected dependency order: first=%s second=%s", firstPath, secondPath)
	}
}

func TestSyncUnresolvableFK(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"campaigns": {
			{"aff_campaign_id": 20, "aff_campaign_name": "Camp A", "aff_network_id": 999},
		},
	})
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	stdout, _, err := executeCommand("sync", "campaigns", "--from", "source", "--to", "target", "--skip-errors", "--json")
	if err != nil {
		t.Fatalf("sync campaigns with --skip-errors should not fail hard: %v", err)
	}
	if capture.PostCalls != 0 {
		t.Fatalf("expected no posts for unresolvable FK, got %d", capture.PostCalls)
	}
	if !strings.Contains(stdout, `"failed": 1`) {
		t.Fatalf("expected failed count in output:\n%s", stdout)
	}
}

func TestSyncWritesManifest(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	})
	defer source.Close()

	target, _ := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync aff-networks error: %v", err)
	}

	manifestPath := syncstate.ManifestPath("source", "target")
	data, err := os.ReadFile(manifestPath)
	if err != nil {
		t.Fatalf("expected manifest file at %s: %v", manifestPath, err)
	}
	var manifest map[string]interface{}
	if err := json.Unmarshal(data, &manifest); err != nil {
		t.Fatalf("manifest JSON parse error: %v", err)
	}
	if manifest["last_sync"] == "" {
		t.Fatalf("manifest should include last_sync")
	}
	mappings, ok := manifest["mappings"].(map[string]interface{})
	if !ok || len(mappings) == 0 {
		t.Fatalf("manifest should include mappings: %#v", manifest["mappings"])
	}
}

func TestReSyncSkipsUnchanged(t *testing.T) {
	sourceRows := map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	}
	source := newEntityDataServer(t, sourceRows)
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync error: %v", err)
	}

	postBefore := capture.PostCalls
	putBefore := capture.PutCalls
	stdout, _, err := executeCommand("re-sync", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("re-sync error: %v", err)
	}
	if capture.PostCalls != postBefore || capture.PutCalls != putBefore {
		t.Fatalf("expected unchanged re-sync to do no writes, post %d->%d put %d->%d", postBefore, capture.PostCalls, putBefore, capture.PutCalls)
	}
	if !strings.Contains(stdout, `"skipped": 1`) {
		t.Fatalf("expected skipped count for unchanged re-sync:\n%s", stdout)
	}
}

func TestReSyncUpdatesChanged(t *testing.T) {
	sourceRows := map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	}
	source := newEntityDataServer(t, sourceRows)
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync error: %v", err)
	}

	// Mutate source record while keeping the same source ID.
	sourceRows["aff-networks"][0]["aff_network_name"] = "Net A Updated"

	putBefore := capture.PutCalls
	_, _, err = executeCommand("re-sync", "--from", "source", "--to", "target", "--force-update", "--json")
	if err != nil {
		t.Fatalf("re-sync --force-update error: %v", err)
	}
	if capture.PutCalls <= putBefore {
		t.Fatalf("expected re-sync change to trigger PUT, put calls before=%d after=%d", putBefore, capture.PutCalls)
	}
}

func TestReSyncCreatesNew(t *testing.T) {
	sourceRows := map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	}
	source := newEntityDataServer(t, sourceRows)
	defer source.Close()

	target, capture := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync error: %v", err)
	}

	sourceRows["aff-networks"] = append(sourceRows["aff-networks"], map[string]interface{}{
		"aff_network_id":   11,
		"aff_network_name": "Net B",
	})

	postBefore := capture.PostCalls
	_, _, err = executeCommand("re-sync", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("re-sync create new error: %v", err)
	}
	if capture.PostCalls <= postBefore {
		t.Fatalf("expected re-sync to POST new rows, post calls before=%d after=%d", postBefore, capture.PostCalls)
	}
}

func TestSyncStatus(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	})
	defer source.Close()

	target, _ := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync error: %v", err)
	}

	stdout, _, err := executeCommand("sync", "status", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync status error: %v", err)
	}
	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid status JSON: %v\n%s", err, stdout)
	}
	if parsed["last_sync"] == "" {
		t.Fatalf("sync status should include last_sync: %#v", parsed)
	}
	dataObj, ok := parsed["data"].(map[string]interface{})
	if !ok {
		t.Fatalf("sync status should include data object: %#v", parsed["data"])
	}
	if _, ok := dataObj["aff-networks"]; !ok {
		t.Fatalf("sync status should include aff-networks summary")
	}
}

func TestSyncHistory(t *testing.T) {
	source := newEntityDataServer(t, map[string][]map[string]interface{}{
		"aff-networks": {
			{"aff_network_id": 10, "aff_network_name": "Net A"},
		},
	})
	defer source.Close()

	target, _ := newSyncServer(t, map[string][]map[string]interface{}{})
	defer target.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "source", map[string]map[string]interface{}{
		"source": {"url": source.URL, "api_key": "source-key-123456"},
		"target": {"url": target.URL, "api_key": "target-key-123456"},
	})

	_, _, err := executeCommand("sync", "aff-networks", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("initial sync error: %v", err)
	}

	stdout, _, err := executeCommand("sync", "history", "--from", "source", "--to", "target", "--json")
	if err != nil {
		t.Fatalf("sync history error: %v", err)
	}
	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid history JSON: %v\n%s", err, stdout)
	}
	rows, _ := parsed["data"].([]interface{})
	if len(rows) == 0 {
		t.Fatalf("sync history should contain at least one entry")
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

func TestReportSummaryUsesConfigDefaultPeriod(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"total_clicks":10}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithDefaults(t, tmp, srv.URL, "test-key", map[string]string{
		"report.period": "last30",
	})

	_, _, err := executeCommand("report", "summary")
	if err != nil {
		t.Fatalf("report summary with default period error: %v", err)
	}

	if got := gotParams.Get("period"); got != "last30" {
		t.Errorf("period = %q, want %q", got, "last30")
	}
}

func TestCampaignListFriendlyFilterMapsToAPIFilter(t *testing.T) {
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

	_, _, err := executeCommand("campaign", "list", "--aff_network_id=5")
	if err != nil {
		t.Fatalf("campaign list with filter error: %v", err)
	}

	if got := gotParams.Get("filter[aff_network_id]"); got != "5" {
		t.Errorf("filter[aff_network_id] = %q, want %q", got, "5")
	}
}

func TestCampaignListLegacyFilterAliasStillWorks(t *testing.T) {
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

	_, _, err := executeCommand("campaign", "list", "--filter[aff_network_id]=6")
	if err != nil {
		t.Fatalf("campaign list with legacy alias error: %v", err)
	}

	if got := gotParams.Get("filter[aff_network_id]"); got != "6" {
		t.Errorf("filter[aff_network_id] = %q, want %q", got, "6")
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

func TestTrackerCreateUsesCrudDefaultAffCampaignID(t *testing.T) {
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
	writeTestConfigWithDefaults(t, tmp, srv.URL, "test-key", map[string]string{
		"crud.aff_campaign_id": "77",
	})

	_, _, err := executeCommand("tracker", "create")
	if err != nil {
		t.Fatalf("tracker create with defaults error: %v", err)
	}

	if gotBody["aff_campaign_id"] != "77" {
		t.Errorf("aff_campaign_id = %q, want %q", gotBody["aff_campaign_id"], "77")
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

func TestTrackerListFriendlyFiltersMapToAPIFilters(t *testing.T) {
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

	_, _, err := executeCommand(
		"tracker", "list",
		"--aff_campaign_id=10",
		"--ppc_account_id=20",
		"--landing_page_id=30",
	)
	if err != nil {
		t.Fatalf("tracker list with filters error: %v", err)
	}

	expectations := map[string]string{
		"filter[aff_campaign_id]": "10",
		"filter[ppc_account_id]":  "20",
		"filter[landing_page_id]": "30",
	}
	for key, want := range expectations {
		if got := gotParams.Get(key); got != want {
			t.Errorf("%s = %q, want %q", key, got, want)
		}
	}
}

func TestTrackerListUsesCrudDefaultFilter(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithDefaults(t, tmp, srv.URL, "test-key", map[string]string{
		"crud.aff_campaign_id": "99",
	})

	_, _, err := executeCommand("tracker", "list")
	if err != nil {
		t.Fatalf("tracker list with defaults error: %v", err)
	}

	if got := gotParams.Get("filter[aff_campaign_id]"); got != "99" {
		t.Errorf("filter[aff_campaign_id] = %q, want %q", got, "99")
	}
}

func TestLandingPageListFriendlyFilterMapsToAPIFilter(t *testing.T) {
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

	_, _, err := executeCommand("landing-page", "list", "--aff_campaign_id=15")
	if err != nil {
		t.Fatalf("landing-page list with filter error: %v", err)
	}

	if got := gotParams.Get("filter[aff_campaign_id]"); got != "15" {
		t.Errorf("filter[aff_campaign_id] = %q, want %q", got, "15")
	}
}

func TestTextAdListFriendlyFilterMapsToAPIFilter(t *testing.T) {
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

	_, _, err := executeCommand("text-ad", "list", "--aff_campaign_id=25")
	if err != nil {
		t.Fatalf("text-ad list with filter error: %v", err)
	}

	if got := gotParams.Get("filter[aff_campaign_id]"); got != "25" {
		t.Errorf("filter[aff_campaign_id] = %q, want %q", got, "25")
	}
}

func TestPpcAccountListFriendlyFilterMapsToAPIFilter(t *testing.T) {
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

	_, _, err := executeCommand("ppc-account", "list", "--ppc_network_id=35")
	if err != nil {
		t.Fatalf("ppc-account list with filter error: %v", err)
	}

	if got := gotParams.Get("filter[ppc_network_id]"); got != "35" {
		t.Errorf("filter[ppc_network_id] = %q, want %q", got, "35")
	}
}

func TestCampaignClone(t *testing.T) {
	var postedBody map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "GET" && r.URL.Path == "/api/v3/campaigns/9":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_name":"Base Campaign","aff_campaign_url":"https://offer.example","aff_network_id":4}}`))
		case r.Method == "POST" && r.URL.Path == "/api/v3/campaigns":
			bodyBytes, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(bodyBytes, &postedBody)
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":10,"aff_campaign_name":"Base Campaign (Clone)"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "clone", "9")
	if err != nil {
		t.Fatalf("campaign clone error: %v", err)
	}

	if postedBody["aff_campaign_name"] != "Base Campaign (Clone)" {
		t.Errorf("aff_campaign_name = %#v, want %q", postedBody["aff_campaign_name"], "Base Campaign (Clone)")
	}
	if postedBody["aff_campaign_url"] != "https://offer.example" {
		t.Errorf("aff_campaign_url = %#v, want %q", postedBody["aff_campaign_url"], "https://offer.example")
	}
	if _, exists := postedBody["aff_campaign_id"]; exists {
		t.Errorf("clone payload should not include aff_campaign_id")
	}
}

func TestCampaignCloneNameOverride(t *testing.T) {
	var postedBody map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "GET" && r.URL.Path == "/api/v3/campaigns/9":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_name":"Base Campaign","aff_campaign_url":"https://offer.example"}}`))
		case r.Method == "POST" && r.URL.Path == "/api/v3/campaigns":
			bodyBytes, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(bodyBytes, &postedBody)
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":10,"aff_campaign_name":"Custom Clone"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "clone", "9", "--name=Custom Clone")
	if err != nil {
		t.Fatalf("campaign clone with name override error: %v", err)
	}

	if postedBody["aff_campaign_name"] != "Custom Clone" {
		t.Errorf("aff_campaign_name = %#v, want %q", postedBody["aff_campaign_name"], "Custom Clone")
	}
}

func TestTrackerCreateWithURL(t *testing.T) {
	var gotCreateBody map[string]interface{}
	var gotURLPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/trackers":
			bodyBytes, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(bodyBytes, &gotCreateBody)
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"tracker_id":56,"aff_campaign_id":1}}`))
		case r.Method == "GET" && r.URL.Path == "/api/v3/trackers/56/url":
			gotURLPath = r.URL.Path
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"tracker_id":56,"direct_url":"https://trk.example/56"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("tracker", "create-with-url", "--aff_campaign_id=1")
	if err != nil {
		t.Fatalf("tracker create-with-url error: %v", err)
	}

	if gotCreateBody["aff_campaign_id"] != "1" {
		t.Errorf("aff_campaign_id = %#v, want %q", gotCreateBody["aff_campaign_id"], "1")
	}
	if gotURLPath != "/api/v3/trackers/56/url" {
		t.Errorf("tracker URL path = %q, want %q", gotURLPath, "/api/v3/trackers/56/url")
	}
	if !strings.Contains(stdout, "direct_url") {
		t.Errorf("output should contain direct_url, got:\n%s", stdout)
	}
}

func TestTrackerBulkURLs(t *testing.T) {
	var listQuery url.Values
	urlCalls := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "GET" && r.URL.Path == "/api/v3/trackers":
			listQuery = r.URL.Query()
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"tracker_id":1,"aff_campaign_id":10},{"tracker_id":2,"aff_campaign_id":10}]}`))
		case r.Method == "GET" && r.URL.Path == "/api/v3/trackers/1/url":
			urlCalls++
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"tracker_id":1,"direct_url":"https://trk.example/1"}}`))
		case r.Method == "GET" && r.URL.Path == "/api/v3/trackers/2/url":
			urlCalls++
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"tracker_id":2,"direct_url":"https://trk.example/2"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("tracker", "bulk-urls", "--aff_campaign_id=10", "--concurrency=2")
	if err != nil {
		t.Fatalf("tracker bulk-urls error: %v", err)
	}

	if got := listQuery.Get("filter[aff_campaign_id]"); got != "10" {
		t.Errorf("filter[aff_campaign_id] = %q, want %q", got, "10")
	}
	if urlCalls != 2 {
		t.Errorf("urlCalls = %d, want 2", urlCalls)
	}
	if !strings.Contains(stdout, "https://trk.example/1") || !strings.Contains(stdout, "https://trk.example/2") {
		t.Errorf("output should contain both tracker URLs, got:\n%s", stdout)
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

func TestCSVFlagOutputsCSV(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`[{"id":1,"aff_campaign_name":"Test Campaign"}]`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--csv", "campaign", "list")
	if err != nil {
		t.Fatalf("--csv campaign list error: %v", err)
	}

	trimmed := strings.TrimSpace(stdout)
	if !strings.Contains(trimmed, "id,aff_campaign_name") {
		t.Errorf("CSV output missing header, got:\n%s", stdout)
	}
	if !strings.Contains(trimmed, "1,Test Campaign") {
		t.Errorf("CSV output missing row, got:\n%s", stdout)
	}
}

func TestJSONAndCSVMutuallyExclusive(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("--json", "--csv", "campaign", "list")
	if err == nil {
		t.Fatal("expected error when both --json and --csv are set")
	}
	if !strings.Contains(err.Error(), "cannot be used together") {
		t.Errorf("error = %q, expected mutual exclusion message", err.Error())
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

func TestDashboardDefaultsToTodayPeriod(t *testing.T) {
	var gotPath string
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"total_clicks":42,"total_leads":3}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("dashboard")
	if err != nil {
		t.Fatalf("dashboard error: %v", err)
	}

	if gotPath != "/api/v3/reports/summary" {
		t.Errorf("path = %q, want /api/v3/reports/summary", gotPath)
	}
	if gotParams.Get("period") != "today" {
		t.Errorf("period = %q, want %q", gotParams.Get("period"), "today")
	}
	if !strings.Contains(stdout, "42") {
		t.Errorf("output should contain summary values, got:\n%s", stdout)
	}
}

func TestDashboardPassesExplicitFilters(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"total_clicks":1}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("dashboard", "--period=last7", "--aff_campaign_id=7", "--country_id=223")
	if err != nil {
		t.Fatalf("dashboard with filters error: %v", err)
	}

	expectations := map[string]string{
		"period":          "last7",
		"aff_campaign_id": "7",
		"country_id":      "223",
	}
	for key, want := range expectations {
		if got := gotParams.Get(key); got != want {
			t.Errorf("query param %q = %q, want %q", key, got, want)
		}
	}
}

func TestUnifiedReportAggregatesAcrossProfiles(t *testing.T) {
	prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/reports/summary" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":10,"total_leads":2,"total_net":50}}`))
	}))
	defer prodSrv.Close()

	stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/reports/summary" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":5,"total_leads":1,"total_net":5}}`))
	}))
	defer stageSrv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456"},
		"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456"},
	})

	stdout, _, err := executeCommand("report", "summary", "--profiles", "prod,staging", "--json")
	if err != nil {
		t.Fatalf("multi-profile report summary error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	aggregated, ok := parsed["aggregated"].(map[string]interface{})
	if !ok {
		t.Fatalf("aggregated section missing: %#v", parsed)
	}
	if got := int(aggregated["total_clicks"].(float64)); got != 15 {
		t.Fatalf("aggregated total_clicks=%d, want 15", got)
	}
	if got := int(aggregated["total_leads"].(float64)); got != 3 {
		t.Fatalf("aggregated total_leads=%d, want 3", got)
	}
}

func TestUnifiedReportPartialError(t *testing.T) {
	prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":7,"total_leads":2}}`))
	}))
	defer prodSrv.Close()

	stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"staging unavailable"}`))
	}))
	defer stageSrv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456"},
		"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456"},
	})

	stdout, _, err := executeCommand("report", "summary", "--profiles", "prod,staging", "--json")
	if err != nil {
		t.Fatalf("multi-profile partial error should still succeed: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	errorsOut, ok := parsed["errors"].([]interface{})
	if !ok || len(errorsOut) != 1 {
		t.Fatalf("expected one profile error entry, got %#v", parsed["errors"])
	}
	aggregated, _ := parsed["aggregated"].(map[string]interface{})
	if got := int(aggregated["total_clicks"].(float64)); got != 7 {
		t.Fatalf("aggregated total_clicks=%d, want 7", got)
	}
}

func TestDashboardAllProfilesAggregates(t *testing.T) {
	prodHits := 0
	stageHits := 0

	prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		prodHits++
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":4,"total_leads":1}}`))
	}))
	defer prodSrv.Close()

	stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		stageHits++
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":6,"total_leads":2}}`))
	}))
	defer stageSrv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456"},
		"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456"},
	})

	stdout, _, err := executeCommand("dashboard", "--all-profiles", "--json")
	if err != nil {
		t.Fatalf("dashboard --all-profiles error: %v", err)
	}
	if prodHits == 0 || stageHits == 0 {
		t.Fatalf("expected both profile endpoints to be called, prodHits=%d stageHits=%d", prodHits, stageHits)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	aggregated, _ := parsed["aggregated"].(map[string]interface{})
	if got := int(aggregated["total_clicks"].(float64)); got != 10 {
		t.Fatalf("aggregated total_clicks=%d, want 10", got)
	}
}

func TestResolveGroupViaReportSummary(t *testing.T) {
	prodSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":8}}`))
	}))
	defer prodSrv.Close()

	stageSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"total_clicks":2}}`))
	}))
	defer stageSrv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": prodSrv.URL, "api_key": "prod-key-123456", "tags": []string{"env:prod"}},
		"staging": {"url": stageSrv.URL, "api_key": "staging-key-123456", "tags": []string{"env:staging"}},
	})

	stdout, _, err := executeCommand("report", "summary", "--group", "env:prod", "--json")
	if err != nil {
		t.Fatalf("report summary --group error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	aggregated, _ := parsed["aggregated"].(map[string]interface{})
	if got := int(aggregated["total_clicks"].(float64)); got != 8 {
		t.Fatalf("group-resolved aggregated total_clicks=%d, want 8", got)
	}
}

func TestExecRunsAgainstAllProfiles(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456"},
	})

	originalRunner := execProfileRunner
	t.Cleanup(func() { execProfileRunner = originalRunner })

	var mu sync.Mutex
	calls := make([]execCall, 0)
	execProfileRunner = func(call execCall) execResult {
		mu.Lock()
		calls = append(calls, call)
		mu.Unlock()
		return execResult{Profile: call.Profile, ExitCode: 0, Stdout: fmt.Sprintf("ok-%s\n", call.Profile)}
	}

	stdout, _, err := executeCommand("exec", "--all-profiles", "--", "campaign", "list")
	if err != nil {
		t.Fatalf("exec --all-profiles error: %v", err)
	}

	if !strings.Contains(stdout, "=== prod ===") || !strings.Contains(stdout, "=== staging ===") {
		t.Fatalf("expected profile sections in output:\n%s", stdout)
	}
	mu.Lock()
	callCount := len(calls)
	mu.Unlock()
	if callCount != 2 {
		t.Fatalf("expected 2 exec calls, got %d", callCount)
	}
}

func TestExecRunsAgainstGroup(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456", "tags": []string{"env:prod"}},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456", "tags": []string{"env:staging"}},
	})

	originalRunner := execProfileRunner
	t.Cleanup(func() { execProfileRunner = originalRunner })

	var gotProfiles []string
	var mu sync.Mutex
	execProfileRunner = func(call execCall) execResult {
		mu.Lock()
		gotProfiles = append(gotProfiles, call.Profile)
		mu.Unlock()
		return execResult{Profile: call.Profile, ExitCode: 0, Stdout: "ok\n"}
	}

	_, _, err := executeCommand("exec", "--group", "env:prod", "--", "report", "summary")
	if err != nil {
		t.Fatalf("exec --group error: %v", err)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(gotProfiles) != 1 || gotProfiles[0] != "prod" {
		t.Fatalf("expected exec to target only prod group, got %#v", gotProfiles)
	}
}

func TestExecCapturesErrors(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "prod", map[string]map[string]interface{}{
		"prod":    {"url": "https://prod.example.com", "api_key": "prod-key-123456"},
		"staging": {"url": "https://staging.example.com", "api_key": "staging-key-123456"},
	})

	originalRunner := execProfileRunner
	t.Cleanup(func() { execProfileRunner = originalRunner })

	execProfileRunner = func(call execCall) execResult {
		if call.Profile == "staging" {
			return execResult{
				Profile:  call.Profile,
				ExitCode: 2,
				Stdout:   `{"message":"failed"}`,
				Err:      fmt.Errorf("staging failed"),
			}
		}
		return execResult{
			Profile:  call.Profile,
			ExitCode: 0,
			Stdout:   `{"data":{"ok":true}}`,
		}
	}

	stdout, _, err := executeCommand("--json", "exec", "--profiles", "prod,staging", "--", "campaign", "list")
	if err == nil {
		t.Fatal("expected exec to return error when one profile fails")
	}

	var parsed map[string]interface{}
	if parseErr := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); parseErr != nil {
		t.Fatalf("exec output should be valid JSON: %v\n%s", parseErr, stdout)
	}
	errorsObj, ok := parsed["errors"].(map[string]interface{})
	if !ok {
		t.Fatalf("exec JSON should include errors object: %#v", parsed)
	}
	if _, exists := errorsObj["staging"]; !exists {
		t.Fatalf("expected staging error in output: %#v", errorsObj)
	}
}

func TestExportCampaignsPaginated(t *testing.T) {
	offsets := make([]string, 0)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "GET" || r.URL.Path != "/api/v3/campaigns" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}

		offset := r.URL.Query().Get("offset")
		offsets = append(offsets, offset)

		resp := map[string]interface{}{"data": []map[string]interface{}{}}
		if offset == "0" {
			rows := make([]map[string]interface{}, 0, 100)
			for i := 1; i <= 100; i++ {
				rows = append(rows, map[string]interface{}{"id": i})
			}
			resp["data"] = rows
		} else if offset == "100" {
			resp["data"] = []map[string]interface{}{{"id": 101}}
		}

		data, _ := json.Marshal(resp)
		w.WriteHeader(200)
		w.Write(data)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "export", "campaigns")
	if err != nil {
		t.Fatalf("export campaigns error: %v", err)
	}

	if len(offsets) != 2 || offsets[0] != "0" || offsets[1] != "100" {
		t.Errorf("offsets = %#v, want [\"0\", \"100\"]", offsets)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("export output not valid JSON: %v\n%s", err, stdout)
	}
	dataArr, _ := parsed["data"].([]interface{})
	if len(dataArr) != 101 {
		t.Errorf("exported row count = %d, want 101", len(dataArr))
	}
}

func TestExportWritesOutputFile(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" && r.URL.Path == "/api/v3/campaigns" {
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"id":1,"aff_campaign_name":"A"}]}`))
			return
		}
		w.WriteHeader(404)
		w.Write([]byte(`{"message":"not found"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	outFile := filepath.Join(tmp, "campaigns-export.json")
	_, _, err := executeCommand("export", "campaigns", "--output", outFile)
	if err != nil {
		t.Fatalf("export campaigns --output error: %v", err)
	}

	raw, err := os.ReadFile(outFile)
	if err != nil {
		t.Fatalf("reading export file: %v", err)
	}
	if !strings.Contains(string(raw), "\"entity\": \"campaigns\"") {
		t.Errorf("export file missing entity marker:\n%s", string(raw))
	}
}

func TestImportCampaignsDryRunSkipsPosts(t *testing.T) {
	postCalls := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" && r.URL.Path == "/api/v3/campaigns" {
			postCalls++
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":1}}`))
			return
		}
		w.WriteHeader(404)
		w.Write([]byte(`{"message":"not found"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	inFile := filepath.Join(tmp, "campaigns-import.json")
	if err := os.WriteFile(inFile, []byte(`[{"aff_campaign_name":"A","aff_campaign_url":"https://offer.example"}]`), 0600); err != nil {
		t.Fatalf("writing import file: %v", err)
	}

	stdout, _, err := executeCommand("--json", "import", "campaigns", inFile, "--dry-run")
	if err != nil {
		t.Fatalf("import campaigns --dry-run error: %v", err)
	}
	if postCalls != 0 {
		t.Errorf("postCalls = %d, want 0 for dry-run", postCalls)
	}
	if !strings.Contains(stdout, "\"dry_run\": true") {
		t.Errorf("dry-run output missing flag:\n%s", stdout)
	}
}

func TestImportCampaignsStripsImmutableFields(t *testing.T) {
	var postedBody map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" && r.URL.Path == "/api/v3/campaigns" {
			bodyBytes, _ := io.ReadAll(r.Body)
			_ = json.Unmarshal(bodyBytes, &postedBody)
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"aff_campaign_id":10}}`))
			return
		}
		w.WriteHeader(404)
		w.Write([]byte(`{"message":"not found"}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	inFile := filepath.Join(tmp, "campaigns-import.json")
	if err := os.WriteFile(inFile, []byte(`[{"aff_campaign_id":9,"aff_campaign_name":"A","aff_campaign_url":"https://offer.example"}]`), 0600); err != nil {
		t.Fatalf("writing import file: %v", err)
	}

	_, _, err := executeCommand("import", "campaigns", inFile)
	if err != nil {
		t.Fatalf("import campaigns error: %v", err)
	}

	if _, exists := postedBody["aff_campaign_id"]; exists {
		t.Errorf("posted body should not include immutable aff_campaign_id")
	}
	if postedBody["aff_campaign_name"] != "A" {
		t.Errorf("aff_campaign_name = %#v, want %q", postedBody["aff_campaign_name"], "A")
	}
}

func TestUserAPIKeyRotateDeletesOldKeyByDefault(t *testing.T) {
	var createPath, deletePath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/users/7/api-keys":
			createPath = r.URL.Path
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"api_key":"new-key-abcdef1234"}}`))
		case r.Method == "DELETE" && r.URL.Path == "/api/v3/users/7/api-keys/old-key-12345678":
			deletePath = r.URL.Path
			w.WriteHeader(200)
			w.Write([]byte(`{"ok":true}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "old-key-12345678")

	stdout, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--force")
	if err != nil {
		t.Fatalf("user apikey rotate error: %v", err)
	}

	if createPath != "/api/v3/users/7/api-keys" {
		t.Errorf("createPath = %q, want /api/v3/users/7/api-keys", createPath)
	}
	if deletePath != "/api/v3/users/7/api-keys/old-key-12345678" {
		t.Errorf("deletePath = %q, want /api/v3/users/7/api-keys/old-key-12345678", deletePath)
	}
	if !strings.Contains(stdout, "new-key-abcdef1234") {
		t.Errorf("output should contain new key, got:\n%s", stdout)
	}
}

func TestUserAPIKeyRotateKeepOldSkipsDelete(t *testing.T) {
	deleted := false

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/users/7/api-keys":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"api_key":"new-key-abcdef1234"}}`))
		case r.Method == "DELETE":
			deleted = true
			w.WriteHeader(200)
			w.Write([]byte(`{"ok":true}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "old-key-12345678")

	_, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--keep-old")
	if err != nil {
		t.Fatalf("user apikey rotate --keep-old error: %v", err)
	}

	if deleted {
		t.Errorf("old key should not be deleted when --keep-old is set")
	}
}

func TestUserAPIKeyRotateUpdateConfig(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/users/7/api-keys":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":{"api_key":"new-key-abcdef1234"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "old-key-12345678")

	_, _, err := executeCommand("user", "apikey", "rotate", "7", "old-key-12345678", "--keep-old", "--update-config")
	if err != nil {
		t.Fatalf("user apikey rotate --update-config error: %v", err)
	}

	_, savedKey := readSavedConfigURLAndKey(t, tmp)
	if savedKey != "new-key-abcdef1234" {
		t.Errorf("saved API key = %q, want %q", savedKey, "new-key-abcdef1234")
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

func TestRotatorRuleUpdateCommand(t *testing.T) {
	var gotPath, gotMethod string
	var gotBody map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		bodyBytes, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(bodyBytes, &gotBody)
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{"id":3,"rules":[{"id":11,"rule_name":"US Premium v2"}]}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand(
		"rotator", "rule-update", "3", "11",
		"--rule_name=US Premium v2",
		"--status=1",
		"--criteria_json=[{\"type\":\"country\",\"statement\":\"is\",\"value\":\"US\"}]",
	)
	if err != nil {
		t.Fatalf("rotator rule-update error: %v", err)
	}

	if gotMethod != "PUT" {
		t.Fatalf("method = %q, want PUT", gotMethod)
	}
	if gotPath != "/api/v3/rotators/3/rules/11" {
		t.Fatalf("path = %q, want /api/v3/rotators/3/rules/11", gotPath)
	}
	if gotBody["rule_name"] != "US Premium v2" {
		t.Fatalf("rule_name = %#v, want %q", gotBody["rule_name"], "US Premium v2")
	}
	if gotBody["status"] != "1" {
		t.Fatalf("status = %#v, want %q", gotBody["status"], "1")
	}
	if _, ok := gotBody["criteria"]; !ok {
		t.Fatalf("criteria payload missing in body: %#v", gotBody)
	}
	if !strings.Contains(stdout, "US Premium v2") {
		t.Fatalf("expected response output, got:\n%s", stdout)
	}
}

func TestRotatorRuleUpdateRequiresAtLeastOneField(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "test-key")

	_, _, err := executeCommand("rotator", "rule-update", "3", "11")
	if err == nil {
		t.Fatal("expected error for empty rule-update payload")
	}
	if !strings.Contains(err.Error(), "no fields specified") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestAnalyticsCommandAliasMapping(t *testing.T) {
	var gotParams url.Values
	var gotPath string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":10,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand(
		"analytics",
		"--group-by=lp",
		"--period=last7",
		"--days=30",
		"--sort=conversions",
		"--limit=10",
	)
	if err != nil {
		t.Fatalf("analytics command error: %v", err)
	}

	if gotPath != "/api/v3/reports/breakdown" {
		t.Fatalf("path = %q, want /api/v3/reports/breakdown", gotPath)
	}
	if got := gotParams.Get("breakdown"); got != "landing_page" {
		t.Fatalf("breakdown = %q, want landing_page", got)
	}
	if got := gotParams.Get("sort"); got != "total_leads" {
		t.Fatalf("sort = %q, want total_leads", got)
	}
	if got := gotParams.Get("sort_dir"); got != "DESC" {
		t.Fatalf("sort_dir = %q, want DESC", got)
	}
	if got := gotParams.Get("period"); got != "last7" {
		t.Fatalf("period = %q, want last7", got)
	}
	if gotParams.Get("time_from") != "" || gotParams.Get("time_to") != "" {
		t.Fatalf("time_from/time_to should be empty when --period is provided, got: %#v", gotParams)
	}
}

func TestAnalyticsCommandCanBeDisabledByFeatureFlag(t *testing.T) {
	t.Setenv("CLI_ENABLE_ANALYTICS_SHORTHAND", "0")

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.example.com", "test-key")

	_, _, err := executeCommand("analytics", "--group-by=campaign")
	if err == nil {
		t.Fatal("expected analytics command to be disabled")
	}
	if !strings.Contains(err.Error(), "analytics shorthand is disabled") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestTrackerListResolveNamesAddsResolvedFields(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v3/trackers":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"tracker_id":10,"aff_campaign_id":4,"ppc_account_id":0,"landing_page_id":0,"text_ad_id":0,"rotator_id":0}],"pagination":{"total":1,"limit":50,"offset":0}}`))
		case "/api/v3/campaigns":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"aff_campaign_id":4,"aff_campaign_name":"Paz"}],"pagination":{"total":1,"limit":100,"offset":0}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "tracker", "list", "--resolve-names")
	if err != nil {
		t.Fatalf("tracker list --resolve-names error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}

	data, _ := parsed["data"].([]interface{})
	if len(data) != 1 {
		t.Fatalf("expected one tracker row, got %d", len(data))
	}
	row, _ := data[0].(map[string]interface{})
	if got := scalarString(row["campaign_name"]); got != "Paz" {
		t.Fatalf("campaign_name = %q, want Paz", got)
	}
	if got := scalarString(row["aff_campaign_id"]); got != "4" {
		t.Fatalf("aff_campaign_id should remain present, got %q", got)
	}
}

func TestTrackerListResolveNamesCanBeDisabledByFeatureFlag(t *testing.T) {
	t.Setenv("CLI_ENABLE_RESOLVE_NAMES", "0")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("tracker", "list", "--resolve-names")
	if err == nil {
		t.Fatal("expected --resolve-names feature gate error")
	}
	if !strings.Contains(err.Error(), "--resolve-names is disabled") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestTrackerListAllFetchesAllPages(t *testing.T) {
	var offsets []string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/trackers" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}

		offset := r.URL.Query().Get("offset")
		limit := r.URL.Query().Get("limit")
		offsets = append(offsets, offset+"|"+limit)

		off, _ := strconv.Atoi(offset)
		lim, _ := strconv.Atoi(limit)
		total := 205
		if lim <= 0 {
			lim = 100
		}
		end := off + lim
		if end > total {
			end = total
		}

		capHint := end - off
		if capHint < 0 {
			capHint = 0
		}
		rows := make([]map[string]interface{}, 0, capHint)
		for i := off; i < end; i++ {
			rows = append(rows, map[string]interface{}{"tracker_id": i + 1})
		}
		payload, _ := json.Marshal(map[string]interface{}{
			"data": rows,
			"pagination": map[string]interface{}{
				"total":  total,
				"limit":  lim,
				"offset": off,
			},
		})
		w.WriteHeader(200)
		w.Write(payload)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "tracker", "list", "--all")
	if err != nil {
		t.Fatalf("tracker list --all error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	rows, _ := parsed["data"].([]interface{})
	if len(rows) != 205 {
		t.Fatalf("rows length = %d, want 205", len(rows))
	}
	if len(offsets) != 3 {
		t.Fatalf("expected 3 paged calls, got %d (%#v)", len(offsets), offsets)
	}
}

func TestTrackerListAllDetectsPaginationStall(t *testing.T) {
	requests := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/trackers" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		requests++

		rows := make([]map[string]interface{}, 0, 100)
		for i := 0; i < 100; i++ {
			rows = append(rows, map[string]interface{}{"tracker_id": i + 1})
		}
		payload, _ := json.Marshal(map[string]interface{}{
			"data": rows,
			"pagination": map[string]interface{}{
				"total":  250,
				"limit":  100,
				"offset": 0,
			},
		})
		w.WriteHeader(200)
		w.Write(payload)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("--json", "tracker", "list", "--all")
	if err == nil {
		t.Fatal("expected pagination stall error")
	}
	if !strings.Contains(err.Error(), "pagination stalled") {
		t.Fatalf("unexpected error: %v", err)
	}
	if requests < 2 {
		t.Fatalf("expected at least 2 requests before stall detection, got %d", requests)
	}
}

func TestTrackerListAllRejectsDuplicatePageSequence(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/trackers" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}

		offset := r.URL.Query().Get("offset")
		limit := 100
		total := 250

		start := 0
		if offset == "200" {
			start = 200
		}
		end := start + limit
		if end > total {
			end = total
		}

		rows := make([]map[string]interface{}, 0, end-start)
		for i := start; i < end; i++ {
			rows = append(rows, map[string]interface{}{"tracker_id": i + 1})
		}

		reportedOffset := start
		if offset == "100" {
			reportedOffset = 0
		}

		payload, _ := json.Marshal(map[string]interface{}{
			"data": rows,
			"pagination": map[string]interface{}{
				"total":  total,
				"limit":  limit,
				"offset": reportedOffset,
			},
		})
		w.WriteHeader(200)
		w.Write(payload)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("--json", "tracker", "list", "--all")
	if err == nil {
		t.Fatal("expected duplicate-page protection error")
	}
	if !strings.Contains(err.Error(), "pagination stalled") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestTrackerListResolveNamesFallsBackWhenLookupFails(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v3/trackers":
			w.WriteHeader(200)
			w.Write([]byte(`{"data":[{"tracker_id":10,"aff_campaign_id":4,"ppc_account_id":0,"landing_page_id":0,"text_ad_id":0,"rotator_id":0}],"pagination":{"total":1,"limit":50,"offset":0}}`))
		case "/api/v3/campaigns":
			w.WriteHeader(500)
			w.Write([]byte(`{"message":"lookup failed"}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, stderr, err := executeCommand("--json", "tracker", "list", "--resolve-names")
	if err != nil {
		t.Fatalf("tracker list --resolve-names should gracefully fallback, got error: %v", err)
	}
	if !strings.Contains(stderr, "resolve names lookup failed for campaigns") {
		t.Fatalf("expected fallback warning in stderr, got: %q", stderr)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	data, _ := parsed["data"].([]interface{})
	if len(data) != 1 {
		t.Fatalf("expected one tracker row, got %d", len(data))
	}
	row, _ := data[0].(map[string]interface{})
	if got := scalarString(row["campaign_name"]); got != "id:4" {
		t.Fatalf("campaign_name = %q, want id:4 fallback", got)
	}
	if got := scalarString(row["aff_campaign_id"]); got != "4" {
		t.Fatalf("aff_campaign_id should remain present, got %q", got)
	}
}

func TestConversionDeleteBulkIdsReturnsErrorOnPartialFailure(t *testing.T) {
	var deletePaths []string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "DELETE" || !strings.HasPrefix(r.URL.Path, "/api/v3/conversions/") {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		deletePaths = append(deletePaths, r.URL.Path)
		if strings.HasSuffix(r.URL.Path, "/2") {
			w.WriteHeader(500)
			w.Write([]byte(`{"message":"boom"}`))
			return
		}
		w.WriteHeader(204)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("conversion", "delete", "--ids=1,2", "--force")
	if err == nil {
		t.Fatal("expected partial failure error for bulk delete")
	}
	if !strings.Contains(stdout, "Deleted 1 of 2 conversions.") {
		t.Fatalf("unexpected summary output:\n%s", stdout)
	}
	if len(deletePaths) != 2 {
		t.Fatalf("expected 2 delete calls, got %d", len(deletePaths))
	}
}

func TestRotatorRuleDeleteBulkIds(t *testing.T) {
	var deletePaths []string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "DELETE" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		deletePaths = append(deletePaths, r.URL.Path)
		w.WriteHeader(204)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("rotator", "rule-delete", "5", "--ids=10,11", "--force")
	if err != nil {
		t.Fatalf("rotator rule-delete --ids error: %v", err)
	}
	if len(deletePaths) != 2 {
		t.Fatalf("expected 2 delete calls, got %d", len(deletePaths))
	}
	if deletePaths[0] != "/api/v3/rotators/5/rules/10" || deletePaths[1] != "/api/v3/rotators/5/rules/11" {
		t.Fatalf("unexpected delete paths: %#v", deletePaths)
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

// --- Regression/Snapshot Tests ---

func TestCampaignListJSONOutputShape(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"aff_campaign_id":1,"aff_campaign_name":"Camp A"}],"pagination":{"total":1,"limit":25,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "campaign", "list")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}

	// Verify required top-level keys exist
	if _, ok := parsed["data"]; !ok {
		t.Error("JSON output missing 'data' key")
	}
	if _, ok := parsed["pagination"]; !ok {
		t.Error("JSON output missing 'pagination' key")
	}

	// Verify pagination shape
	pg, _ := parsed["pagination"].(map[string]interface{})
	for _, key := range []string{"total", "limit", "offset"} {
		if _, ok := pg[key]; !ok {
			t.Errorf("pagination missing '%s' key", key)
		}
	}

	// Verify data is an array
	data, ok := parsed["data"].([]interface{})
	if !ok {
		t.Fatal("data should be an array")
	}
	if len(data) != 1 {
		t.Fatalf("data length = %d, want 1", len(data))
	}
}

func TestRotatorListJSONOutputShape(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"id":5,"name":"US Rotator"}],"pagination":{"total":1,"limit":25,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "rotator", "list")
	if err != nil {
		t.Fatalf("rotator list error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	if _, ok := parsed["data"]; !ok {
		t.Error("JSON output missing 'data' key")
	}
	if _, ok := parsed["pagination"]; !ok {
		t.Error("JSON output missing 'pagination' key")
	}
}

func TestExitCodeClassification(t *testing.T) {
	tests := []struct {
		name     string
		err      error
		wantCode int
	}{
		{
			name:     "nil error",
			err:      nil,
			wantCode: ExitOK,
		},
		{
			name:     "validation error",
			err:      validationError("bad input"),
			wantCode: ExitValidation,
		},
		{
			name:     "partial failure error",
			err:      partialFailureError("2 of 3 failed"),
			wantCode: ExitPartialFailure,
		},
		{
			name:     "generic fmt.Errorf",
			err:      fmt.Errorf("something went wrong"),
			wantCode: ExitValidation, // fallback
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := exitCodeForError(tt.err)
			if got != tt.wantCode {
				t.Errorf("exitCodeForError() = %d, want %d", got, tt.wantCode)
			}
		})
	}
}

func TestCLIErrorCategoryName(t *testing.T) {
	tests := []struct {
		name    string
		err     *CLIError
		wantCat string
	}{
		{
			name:    "validation",
			err:     validationError("bad input"),
			wantCat: "validation",
		},
		{
			name:    "partial_failure",
			err:     partialFailureError("some failed"),
			wantCat: "partial_failure",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := tt.err.CategoryName(); got != tt.wantCat {
				t.Errorf("CategoryName() = %q, want %q", got, tt.wantCat)
			}
		})
	}
}

func TestPaginationWarningAppearsInTableMode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"id":1},{"id":2}],"pagination":{"total":50,"limit":2,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, stderr, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	if !strings.Contains(stderr, "Warning: Showing 2 of 50 results") {
		t.Errorf("expected truncation warning in stderr, got: %q", stderr)
	}
}

func TestPaginationWarningAbsentInJSONMode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"id":1},{"id":2}],"pagination":{"total":50,"limit":2,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, stderr, err := executeCommand("--json", "campaign", "list")
	if err != nil {
		t.Fatalf("campaign list --json error: %v", err)
	}

	if strings.Contains(stderr, "Warning") {
		t.Errorf("expected no warning in JSON mode, got: %q", stderr)
	}
}

func TestPaginationNoWarningWhenAllShown(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"id":1}],"pagination":{"total":1,"limit":25,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, stderr, err := executeCommand("campaign", "list")
	if err != nil {
		t.Fatalf("campaign list error: %v", err)
	}

	if strings.Contains(stderr, "Warning") {
		t.Errorf("should not warn when all results shown, got: %q", stderr)
	}
}

func TestAnalyticsGroupByRequired(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("analytics")
	if err == nil {
		t.Fatal("expected error when --group-by is missing")
	}
	if !strings.Contains(err.Error(), "--group-by is required") {
		t.Errorf("unexpected error: %q", err.Error())
	}
}

func TestAnalyticsInvalidGroupByRejects(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("analytics", "--group-by=nonexistent")
	if err == nil {
		t.Fatal("expected error for invalid group-by")
	}
	if !strings.Contains(err.Error(), "unsupported --group-by") {
		t.Errorf("unexpected error: %q", err.Error())
	}
}

func TestAnalyticsInvalidSortRejects(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("analytics", "--group-by=campaign", "--sort=bogus")
	if err == nil {
		t.Fatal("expected error for invalid sort")
	}
	if !strings.Contains(err.Error(), "unsupported --sort") {
		t.Errorf("unexpected error: %q", err.Error())
	}
}

func TestBulkDeletePartialFailureExitCode(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "DELETE" && strings.HasSuffix(r.URL.Path, "/2") {
			w.WriteHeader(500)
			w.Write([]byte(`{"message":"server error"}`))
			return
		}
		w.WriteHeader(204)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("campaign", "delete", "--ids=1,2,3", "--force")
	if err == nil {
		t.Fatal("expected error for partial failure")
	}

	cliErr, ok := err.(*CLIError)
	if !ok {
		t.Fatalf("error type = %T, want *CLIError", err)
	}
	if cliErr.ExitCode != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d (partial failure)", cliErr.ExitCode, ExitPartialFailure)
	}
	if cliErr.Category != "partial_failure" {
		t.Errorf("category = %q, want %q", cliErr.Category, "partial_failure")
	}
}

func TestConversionListJSONOutputShape(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"id":10,"click_id":5,"payout":"1.50"}],"pagination":{"total":1,"limit":25,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "conversion", "list")
	if err != nil {
		t.Fatalf("conversion list error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	if _, ok := parsed["data"]; !ok {
		t.Error("JSON output missing 'data' key")
	}
	if _, ok := parsed["pagination"]; !ok {
		t.Error("JSON output missing 'pagination' key")
	}
}

func TestClickListJSONOutputShape(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[{"click_id":1,"click_ip":"1.2.3.4"}],"pagination":{"total":1,"limit":25,"offset":0}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("--json", "click", "list")
	if err != nil {
		t.Fatalf("click list error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON output: %v\n%s", err, stdout)
	}
	if _, ok := parsed["data"]; !ok {
		t.Error("JSON output missing 'data' key")
	}
}

func TestFeatureFlagParsing(t *testing.T) {
	tests := []struct {
		envVal       string
		defaultValue bool
		want         bool
	}{
		{"", true, true},       // absent -> default
		{"1", false, true},     // "1" -> true
		{"true", false, true},  // "true" -> true
		{"0", true, false},     // "0" -> false
		{"false", true, false}, // "false" -> false
		{"off", true, false},   // "off" -> false
		{"on", false, true},    // "on" -> true
		{"junk", true, true},   // unrecognized -> default
		{"junk", false, false}, // unrecognized -> default
	}

	for _, tt := range tests {
		t.Run(fmt.Sprintf("val=%q_default=%v", tt.envVal, tt.defaultValue), func(t *testing.T) {
			t.Setenv("TEST_FEATURE_FLAG_XYZ", tt.envVal)
			got := envFlagEnabled("TEST_FEATURE_FLAG_XYZ", tt.defaultValue)
			if got != tt.want {
				t.Errorf("envFlagEnabled(%q, %v) = %v, want %v", tt.envVal, tt.defaultValue, got, tt.want)
			}
		})
	}

	// absent env var case
	t.Run("absent_default_true", func(t *testing.T) {
		got := envFlagEnabled("TOTALLY_ABSENT_FLAG_FOR_TEST_12345", true)
		if !got {
			t.Error("absent flag should return default value true")
		}
	})
	t.Run("absent_default_false", func(t *testing.T) {
		got := envFlagEnabled("TOTALLY_ABSENT_FLAG_FOR_TEST_12345", false)
		if got {
			t.Error("absent flag should return default value false")
		}
	})
}

func TestParseIDListDeduplication(t *testing.T) {
	tests := []struct {
		input   string
		want    []string
		wantErr bool
	}{
		{"1,2,3", []string{"1", "2", "3"}, false},
		{"1, 2, 3", []string{"1", "2", "3"}, false},
		{"1,1,2,2,3", []string{"1", "2", "3"}, false},
		{" , , ", nil, false},
		{"5", []string{"5"}, false},
		{"1,,2,,3", []string{"1", "2", "3"}, false},
		{"abc,def", nil, true},
		{"1,abc,3", nil, true},
		{"1.5,2", nil, true},
	}

	for _, tt := range tests {
		t.Run(tt.input, func(t *testing.T) {
			got, err := parseIDList(tt.input)
			if tt.wantErr {
				if err == nil {
					t.Errorf("parseIDList(%q) expected error, got nil", tt.input)
				}
				return
			}
			if err != nil {
				t.Fatalf("parseIDList(%q) unexpected error: %v", tt.input, err)
			}
			if tt.want == nil {
				if len(got) != 0 {
					t.Errorf("parseIDList(%q) = %v, want empty", tt.input, got)
				}
				return
			}
			if len(got) != len(tt.want) {
				t.Fatalf("parseIDList(%q) = %v (len %d), want %v (len %d)", tt.input, got, len(got), tt.want, len(tt.want))
			}
			for i := range got {
				if got[i] != tt.want[i] {
					t.Errorf("parseIDList(%q)[%d] = %q, want %q", tt.input, i, got[i], tt.want[i])
				}
			}
		})
	}
}

func TestRotatorDeleteBulkPartialFailure(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "DELETE" && strings.HasSuffix(r.URL.Path, "/2") {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
			return
		}
		w.WriteHeader(204)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("rotator", "delete", "--ids=1,2,3", "--force")
	if err == nil {
		t.Fatal("expected error for partial failure")
	}

	cliErr, ok := err.(*CLIError)
	if !ok {
		t.Fatalf("error type = %T, want *CLIError", err)
	}
	if cliErr.ExitCode != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", cliErr.ExitCode, ExitPartialFailure)
	}
}

func TestAnalyticsPeriodPrecedenceOverDays(t *testing.T) {
	var gotParams url.Values

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("analytics", "--group-by=campaign", "--period=last30", "--days=7")
	if err != nil {
		t.Fatalf("analytics error: %v", err)
	}

	if got := gotParams.Get("period"); got != "last30" {
		t.Errorf("period = %q, want last30", got)
	}
	// When --period is provided, --days should be ignored (no time_from/time_to)
	if got := gotParams.Get("time_from"); got != "" {
		t.Errorf("time_from should be empty when --period is set, got %q", got)
	}
}

func TestListAllWinsOverLimit(t *testing.T) {
	callCount := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		// Return a small dataset to end pagination
		rows := []map[string]interface{}{{"id": callCount}}
		payload, _ := json.Marshal(map[string]interface{}{
			"data": rows,
			"pagination": map[string]interface{}{
				"total":  1,
				"limit":  100,
				"offset": 0,
			},
		})
		w.WriteHeader(200)
		w.Write(payload)
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	// --all should override --limit
	stdout, _, err := executeCommand("--json", "campaign", "list", "--all", "--limit=1")
	if err != nil {
		t.Fatalf("list --all --limit error: %v", err)
	}

	var parsed map[string]interface{}
	if err := json.Unmarshal([]byte(strings.TrimSpace(stdout)), &parsed); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	data, _ := parsed["data"].([]interface{})
	if len(data) != 1 {
		t.Fatalf("data length = %d, want 1", len(data))
	}
}

func TestUIFriendlyAliasesHitSameEndpoints(t *testing.T) {
	tests := []struct {
		alias        string
		canonical    string
		subcommand   string
		wantEndpoint string
	}{
		{"traffic-source", "ppc-account", "list", "/api/v3/ppc-accounts"},
		{"traffic-network", "ppc-network", "list", "/api/v3/ppc-networks"},
		{"category", "aff-network", "list", "/api/v3/aff-networks"},
		{"redirector", "rotator", "list", "/api/v3/rotators"},
	}

	for _, tt := range tests {
		t.Run(tt.alias, func(t *testing.T) {
			var gotPath string
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				gotPath = r.URL.Path
				w.WriteHeader(200)
				w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":25,"offset":0}}`))
			}))
			defer srv.Close()

			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key")

			_, _, err := executeCommand("--json", tt.alias, tt.subcommand)
			if err != nil {
				t.Fatalf("%s %s error: %v", tt.alias, tt.subcommand, err)
			}
			if gotPath != tt.wantEndpoint {
				t.Errorf("alias %q hit %q, want %q", tt.alias, gotPath, tt.wantEndpoint)
			}
		})
	}
}
