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

func TestAppInstallListMapsFlagsToTheRegistrationsInstalls(t *testing.T) {
	var gotPath string
	var gotParams url.Values
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("app", "install", "list", "3", "--match-state", "bad_token", "--trusted", "refuted", "--test", "0", "--click-id", "42"); err != nil {
		t.Fatalf("app install list: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/apps/3/installs") {
		t.Errorf("path = %q, want .../apps/3/installs", gotPath)
	}
	for param, want := range map[string]string{"match_state": "bad_token", "trusted": "refuted", "test": "0", "click_id": "42"} {
		if got := gotParams.Get(param); got != want {
			t.Errorf("param %s = %q, want %q", param, got, want)
		}
	}
}

func TestAppInstallRefusesBadArgumentsBeforeAnyRequest(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // no server configured: a request would fail differently
	for _, args := range [][]string{
		{"app", "install", "list", "x"},
		{"app", "install", "list", "0"},
		{"app", "install", "list", "3", "--match-state", "Attributed"},
		{"app", "install", "list", "3", "--trusted", "1"},
		{"app", "install", "list", "3", "--test", "yes"},
		{"app", "install", "list", "3", "--click-id", "-4"},
		{"app", "install", "list", "3", "--time-from", "yesterday"},
		{"app", "install", "get", "3", "8D7C4A52-9F0E-4B1D-A7C3-2E5F60718293"},
		{"app", "install", "token", "3"},
		{"app", "install", "token", "3", "--click", "1.5"},
		{"app", "install", "simulate", "3", "--click", "9", "--install-uuid", "nope"},
		{"app", "install", "simulate", "3", "--click", "9", "--delay", "-1"},
		{"app", "create", "--app-key", "com.example.app", "--app-name", "x", "--attribution-window-days", "0"},
		{"app", "create", "--app-key", "com.example.app", "--app-name", "x", "--attribution-window-days", "07"},
		{"app", "create", "--app-key", "com.example.app", "--app-name", "x", "--trust-client-revenue", "yes"},
		{"goal", "reevaluate", "12", "--subject-type", "visitor"},
	} {
		_, _, err := executeCommand(args...)
		assertValidationError(t, err)
		if hintFor(err) == "" {
			t.Errorf("%v: no hint", args)
		}
	}
}

func TestAppInstallSimulateIsRefusedUnderStaged(t *testing.T) {
	requests := 0
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		requests++
		w.WriteHeader(500)
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("app", "install", "simulate", "3", "--click", "9", "--staged")
	assertValidationError(t, err)
	if !strings.Contains(hintFor(err), "without --staged") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if requests != 0 {
		t.Errorf("the public intake cannot record a proposal, so nothing may be sent; %d requests were", requests)
	}
}

func TestAppInstallSimulatePostsWhatTheSdkPostsWithTheAppToken(t *testing.T) {
	var posted map[string]interface{}
	var postedToken, postedPath string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == "GET" && strings.HasSuffix(r.URL.Path, "/apps/3"):
			w.Write([]byte(`{"data":{"registration_id":3,"platform":"android","app_key":"com.example.summit","app_token":"` + strings.Repeat("a", 64) + `"}}`))
		case r.Method == "GET" && strings.HasSuffix(r.URL.Path, "/apps/3/install-token"):
			if r.URL.Query().Get("click_id") != "9" {
				t.Errorf("install-token click_id = %q", r.URL.Query().Get("click_id"))
			}
			w.Write([]byte(`{"data":{"click_id":9,"click_time":1727200000,"install_token":"9.AAAAAAAAAAAAAAAA","referrer":"p202=9.AAAAAAAAAAAAAAAA"}}`))
		case r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/apps/installs"):
			postedPath = r.URL.Path
			postedToken = r.Header.Get("X-P202-App-Token")
			raw, _ := io.ReadAll(r.Body)
			if err := json.Unmarshal(raw, &posted); err != nil {
				t.Errorf("body is not JSON: %s", raw)
			}
			w.Write([]byte(`{"data":{"install_uuid":"x","match":"attributed","reason":"Attributed to click 9.","trusted":1,"test":false,"duplicate":false}}`))
		default:
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
			w.WriteHeader(404)
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("app", "install", "simulate", "3", "--click", "9", "--install-uuid", "8d7c4a52-9f0e-4b1d-a7c3-2e5f60718293", "--json")
	if err != nil {
		t.Fatalf("simulate: %v", err)
	}
	if !strings.HasSuffix(postedPath, "/apps/installs") || postedToken != strings.Repeat("a", 64) {
		t.Errorf("posted to %q with token %q", postedPath, postedToken)
	}
	ref, _ := posted["referrer"].(map[string]interface{})
	if posted["install_uuid"] != "8d7c4a52-9f0e-4b1d-a7c3-2e5f60718293" || posted["app_key"] != "com.example.summit" || posted["store"] != "google_play" {
		t.Errorf("body = %v", posted)
	}
	if ref["install_referrer"] != "p202=9.AAAAAAAAAAAAAAAA" || ref["status"] != "ok" {
		t.Errorf("referrer = %v", ref)
	}
	// JSON numbers, after the click: the server refuses a timestamp sent as a string.
	if ref["referrer_click_timestamp_server_seconds"] != float64(1727200005) || ref["install_begin_timestamp_server_seconds"] != float64(1727200035) {
		t.Errorf("timestamps = %v", ref)
	}
	if !strings.Contains(stdout, `"attributed"`) {
		t.Errorf("stdout = %s", stdout)
	}
}

func TestNewUUIDIsTheLowerCaseV4TheIntakeAccepts(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		u, err := newUUID()
		if err != nil {
			t.Fatal(err)
		}
		if !installUUID.MatchString(u) || u[14] != '4' || !strings.ContainsRune("89ab", rune(u[19])) {
			t.Fatalf("%q is not a canonical v4 UUID", u)
		}
		if seen[u] {
			t.Fatalf("duplicate %q", u)
		}
		seen[u] = true
	}
}
