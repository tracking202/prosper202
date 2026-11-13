package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"

	"p202/internal/api"
)

const adConnectionTestKey = "sk-ads-live-Z9q7Xc2Vb8Nm4Lk1"

// The key goes from a file into the request body and nowhere else: not a
// flag, not stdout. What the server answers is what is printed.
func TestAdConnectionCreateSendsTheKeyFromItsFileAndNeverPrintsIt(t *testing.T) {
	var bodies []map[string]interface{}
	var calls []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.Method+" "+r.URL.Path)
		raw, _ := io.ReadAll(r.Body)
		body := map[string]interface{}{}
		_ = json.Unmarshal(raw, &body)
		bodies = append(bodies, body)
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(`{"data":{"connection_id":3,"platform":"openai_ads","account":{"id":"adacct_123","name":"Northwind Outfitters","currency":"USD","timezone":"America/New_York"},"credentials_set":{"advertiser_api_key":true,"conversions_api_key":false}}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	keyFile := filepath.Join(tmp, "key.txt")
	if err := os.WriteFile(keyFile, []byte(adConnectionTestKey+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	out, _, err := executeCommand("ad-connection", "create", "--api-key-file", keyFile, "--ppc-account-id", "4", "--json")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if !reflect.DeepEqual(calls, []string{"POST /api/v3/ad-connections"}) {
		t.Errorf("requests = %v", calls)
	}
	want := map[string]interface{}{"platform": "openai_ads", "api_key": adConnectionTestKey, "ppc_account_id": 4.0}
	if !reflect.DeepEqual(bodies[0], want) {
		t.Errorf("body = %v, want %v", bodies[0], want)
	}
	if strings.Contains(out, adConnectionTestKey) || !strings.Contains(out, "adacct_123") {
		t.Errorf("output must show the account and never the key: %s", out)
	}
}

func TestAdConnectionSetupSendsTheStepsAsked(t *testing.T) {
	var bodies []map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body := map[string]interface{}{}
		_ = json.Unmarshal(raw, &body)
		bodies = append(bodies, body)
		w.Write([]byte(`{"data":{"steps":[{"step":"pixel","outcome":"given","detail":"Using pixel 998877 from Ads Manager."}]}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("ad-connection", "setup", "3", "--skip", "destination", "--event-type", "lead_created",
		"--pixel-id", "998877", "--source-id", "clidsrc_7", "--json"); err != nil {
		t.Fatalf("setup: %v", err)
	}
	want := map[string]interface{}{"skip": []interface{}{"destination"}, "event_types": []interface{}{"lead_created"}, "pixel_id": "998877", "source_id": "clidsrc_7"}
	if !reflect.DeepEqual(bodies[0], want) {
		t.Errorf("body = %v, want %v", bodies[0], want)
	}
}

// Bad input is refused before the server, as a validation error with a next step.
func TestAdConnectionRefusesBadInputBeforeTheServer(t *testing.T) {
	var calls []string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.Method+" "+r.URL.Path)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	empty := filepath.Join(tmp, "empty.txt")
	if err := os.WriteFile(empty, []byte("  \n"), 0o600); err != nil {
		t.Fatal(err)
	}

	for name, args := range map[string][]string{
		"no key file":          {"ad-connection", "create"},
		"an empty key file":    {"ad-connection", "create", "--api-key-file", empty},
		"a missing key file":   {"ad-connection", "create", "--api-key-file", filepath.Join(tmp, "nope.txt")},
		"a bad id":             {"ad-connection", "check", "three"},
		"an unknown step":      {"ad-connection", "setup", "3", "--skip", "pixels"},
		"an unknown event":     {"ad-connection", "setup", "3", "--event-type", "purchase"},
		"a source without id":  {"ad-connection", "setup", "3", "--source-id", "clidsrc_7"},
		"two pixel choices":    {"ad-connection", "setup", "3", "--pixel", "clidsrc_1", "--pixel-id", "9"},
		"nothing to update":    {"ad-connection", "update", "3"},
		"both account choices": {"ad-connection", "update", "3", "--ppc-account-id", "4", "--no-ppc-account"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 || hintFor(err) == "" {
			t.Errorf("%s: err %v, exit %d, hint %q", name, err, exitCodeForError(err), hintFor(err))
		}
	}
	if len(calls) != 0 {
		t.Errorf("bad input reached the server: %v", calls)
	}
}

// `destination test --real` asks for the real event and its read-back; without it the body is empty.
func TestDestinationTestRealAsksForTheReadBack(t *testing.T) {
	var bodies []map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body := map[string]interface{}{}
		_ = json.Unmarshal(raw, &body)
		bodies = append(bodies, body)
		w.Write([]byte(`{"data":{"sent":true,"arrived":true,"event_type":"order_created","pixel_id":"134500901","connection_id":1,"status_code":200,"message":"OpenAI received the test event"}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	out, _, err := executeCommand("destination", "test", "3", "--real", "--json")
	if err != nil {
		t.Fatalf("test --real: %v", err)
	}
	if _, _, err := executeCommand("destination", "test", "3", "--json"); err != nil {
		t.Fatalf("test: %v", err)
	}
	if !reflect.DeepEqual(bodies, []map[string]interface{}{{"real": true, "confirm_counted": true}, {}}) {
		t.Errorf("bodies = %v", bodies)
	}
	if !strings.Contains(out, `"arrived": true`) {
		t.Errorf("the read-back is printed: %s", out)
	}
}

// A command's own hint (where the key is, which command lists ids) is for a
// refusal of what was sent. A dead server, a refused API key or a 500 keeps
// the hint of its class.
func TestAdConnectionHintsAreForRefusedInputOnly(t *testing.T) {
	status := http.StatusOK
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(status)
		w.Write([]byte(`{"error":true,"message":"refused","status":` + strconv.Itoa(status) + `}`))
	}))
	defer srv.Close()
	deadSrv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	dead := deadSrv.URL
	deadSrv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	keyFile := filepath.Join(tmp, "key.txt")
	if err := os.WriteFile(keyFile, []byte(adConnectionTestKey+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	const realHint = "--real needs a ChatGPT Ads destination"
	commands := map[string]struct {
		args []string
		own  string
	}{
		"create":                  {[]string{"ad-connection", "create", "--api-key-file", keyFile}, advertiserKeyWhere},
		"get":                     {[]string{"ad-connection", "get", "3"}, adConnectionListHint},
		"check":                   {[]string{"ad-connection", "check", "3"}, adConnectionListHint},
		"setup":                   {[]string{"ad-connection", "setup", "3"}, adConnectionListHint},
		"update":                  {[]string{"ad-connection", "update", "3", "--api-key-file", keyFile}, adConnectionListHint},
		"destination test --real": {[]string{"destination", "test", "3", "--real"}, realHint},
	}
	for name, command := range commands {
		for _, c := range []struct {
			what   string
			url    string
			status int
			exit   int
			own    bool
		}{
			{"a refused field", srv.URL, http.StatusUnprocessableEntity, ExitValidation, true},
			{"an unknown id", srv.URL, http.StatusNotFound, ExitValidation, true},
			{"a refused API key", srv.URL, http.StatusUnauthorized, ExitAuth, false},
			{"a server failure", srv.URL, http.StatusInternalServerError, ExitServer, false},
			{"a dead server", dead, 0, ExitNetwork, false},
		} {
			status = c.status
			writeTestConfig(t, tmp, c.url, "test-key")
			_, _, err := executeCommand(append(command.args, "--json")...)
			if err == nil {
				t.Fatalf("%s, %s: no error", name, c.what)
			}
			if got := exitCodeForError(err); got != c.exit {
				t.Errorf("%s, %s: exit %d, want %d (%v)", name, c.what, got, c.exit, err)
			}
			hint := hintFor(err)
			if has := strings.Contains(hint, command.own); has != c.own {
				t.Errorf("%s, %s: the command's own hint present = %v, want %v; hint = %q", name, c.what, has, c.own, hint)
			}
			if !c.own && (hint == "" || hint != api.HintFor(err)) {
				t.Errorf("%s, %s: hint = %q, want the one api.HintFor has for the class", name, c.what, hint)
			}
			if c.what == "a dead server" && !strings.Contains(hint, "Check the server URL") {
				t.Errorf("%s, %s: hint = %q, want the network hint", name, c.what, hint)
			}
		}
	}
}

// OpenAI not reached or limiting requests is the server's failure to ask, not
// a word about the key: the class's retry hint, never where the key is.
func TestAdConnectionOpenAiOutageIsNotABadKey(t *testing.T) {
	var status int
	var body string
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if status == http.StatusTooManyRequests || status == http.StatusServiceUnavailable {
			w.Header().Set("Retry-After", "60")
		}
		w.WriteHeader(status)
		w.Write([]byte(body))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	keyFile := filepath.Join(tmp, "key.txt")
	if err := os.WriteFile(keyFile, []byte(adConnectionTestKey+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	upstream := func(code int, reason string) string {
		return `{"error":true,"status":` + strconv.Itoa(code) + `,"message":"OpenAI could not be reached, so the key was not checked and nothing was saved",` +
			`"retry_after_seconds":60,"details":{"upstream":"openai_ads","reason":"` + reason + `"}}`
	}
	commands := map[string][]string{
		"create": {"ad-connection", "create", "--api-key-file", keyFile},
		"update": {"ad-connection", "update", "3", "--api-key-file", keyFile},
		"check":  {"ad-connection", "check", "3"},
		"setup":  {"ad-connection", "setup", "3"},
	}
	for name, args := range commands {
		for _, c := range []struct {
			what   string
			status int
			body   string
			exit   int
			retry  string
		}{
			{"an outage", http.StatusServiceUnavailable, upstream(503, "unreachable"), ExitServer, "Wait a minute and run the same command again"},
			{"an unusable answer", http.StatusBadGateway, upstream(502, "unreadable_answer"), ExitServer, "Wait a minute and run the same command again"},
			{"its rate limit", http.StatusTooManyRequests, upstream(429, "rate_limited"), ExitRateLimited, ""},
		} {
			status, body = c.status, c.body
			_, _, err := executeCommand(append(args, "--json")...)
			if err == nil {
				t.Fatalf("%s, %s: no error", name, c.what)
			}
			if got := exitCodeForError(err); got != c.exit {
				t.Errorf("%s, %s: exit %d, want %d (%v)", name, c.what, got, c.exit, err)
			}
			hint := hintFor(err)
			if strings.Contains(hint, advertiserKeyWhere) || strings.Contains(hint, adConnectionListHint) {
				t.Errorf("%s, %s: a command's own hint on an outage: %q", name, c.what, hint)
			}
			if hint == "" || hint != api.HintFor(err) {
				t.Errorf("%s, %s: hint = %q, want the class's", name, c.what, hint)
			}
			if c.retry != "" && !strings.Contains(hint, c.retry) {
				t.Errorf("%s, %s: hint = %q, want %q", name, c.what, hint, c.retry)
			}
		}
	}
	// A key OpenAI refuses is still the caller's to fix.
	status = http.StatusUnprocessableEntity
	body = `{"error":true,"status":422,"message":"OpenAI refused that key","field_errors":{"api_key":"OpenAI refused that key"}}`
	_, _, err := executeCommand("ad-connection", "create", "--api-key-file", keyFile, "--json")
	if err == nil || exitCodeForError(err) != ExitValidation || !strings.Contains(hintFor(err), advertiserKeyWhere) {
		t.Errorf("a refused key: err %v, exit %d, hint %q", err, exitCodeForError(err), hintFor(err))
	}
}
