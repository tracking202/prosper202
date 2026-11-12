package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
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
	if !reflect.DeepEqual(bodies, []map[string]interface{}{{"real": true}, {}}) {
		t.Errorf("bodies = %v", bodies)
	}
	if !strings.Contains(out, `"arrived": true`) {
		t.Errorf("the read-back is printed: %s", out)
	}
}
