package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const fakeKeyFile = `{"type": "service_account", "project_id": "p202-test", "private_key_id": "0123456789abcdef",
 "private_key": "-----BEGIN PRIVATE KEY-----\nSECRETSECRET\n-----END PRIVATE KEY-----\n",
 "client_email": "integrity@p202-test.iam.gserviceaccount.com", "token_uri": "https://oauth2.googleapis.com/token"}`

func writeKeyFile(t *testing.T, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "key.json")
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestAppIntegrityCredentialSetSendsTheKeyFileAsAnObject(t *testing.T) {
	var gotMethod, gotPath string
	var got map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		gotMethod, gotPath = r.Method, r.URL.Path
		raw, _ := io.ReadAll(r.Body)
		if err := json.Unmarshal(raw, &got); err != nil {
			t.Errorf("body is not JSON: %s", raw)
		}
		w.Write([]byte(`{"data":{"registration_id":3,"integrity_mode":"off","credential":{"client_email":"integrity@p202-test.iam.gserviceaccount.com","private_key_id":"0123456789abcdef"}}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, stderr, err := executeCommand("app", "integrity", "credential", "set", "3", "--file", writeKeyFile(t, fakeKeyFile), "--json")
	if err != nil {
		t.Fatalf("credential set: %v", err)
	}
	if gotMethod != "PUT" || !strings.HasSuffix(gotPath, "/apps/3/integrity-credential") {
		t.Errorf("%s %s", gotMethod, gotPath)
	}
	cred, _ := got["credential"].(map[string]interface{})
	if len(got) != 1 || cred["type"] != "service_account" || !strings.Contains(cred["private_key"].(string), "SECRETSECRET") {
		t.Errorf("body = %v: want {\"credential\": <the key file object>}", got)
	}
	if strings.Contains(stdout+stderr, "SECRETSECRET") {
		t.Error("the key reached the terminal")
	}
}

func TestAppIntegrityRefusesBadInputBeforeAnyRequest(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // no server configured: a request would fail differently
	for _, tc := range []struct {
		args []string
		hint string
	}{
		{[]string{"app", "integrity", "credential", "set", "3", "--file", writeKeyFile(t, "not json SECRETSECRET")}, "exactly as Google Cloud downloaded"},
		{[]string{"app", "integrity", "credential", "set", "3", "--file", writeKeyFile(t, `{"type": "authorized_user", "refresh_token": "SECRETSECRET"}`)}, "SERVICE ACCOUNT"},
		{[]string{"app", "integrity", "credential", "set", "3", "--file", filepath.Join(tmp, "missing.json")}, "key file"},
		{[]string{"app", "integrity", "credential", "set", "x", "--file", "k.json"}, "app list --platform android"},
		{[]string{"app", "integrity", "credential", "set", "3", "--file", "k.json", "--staged"}, "without --staged"},
		{[]string{"app", "integrity", "credential", "clear", "3", "--staged", "--force"}, "without --staged"},
		{[]string{"app", "integrity", "status", "0"}, "app list --platform android"},
		{[]string{"app", "update", "3", "--integrity-mode", "strict"}, "credential set"},
		{[]string{"app", "update", "3", "--integrity-mode", "Require"}, "credential set"},
		{[]string{"app", "update", "3", "--integrity-cloud-project-number", "my-project"}, "Project number"},
		{[]string{"app", "create", "--app-key", "com.example.app", "--app-name", "x", "--integrity-mode", "observe"}, "credential set"},
		{[]string{"app", "install", "list", "3", "--integrity-state", "Valid"}, ""},
		{[]string{"app", "install", "list", "3", "--match-state", "integrity_ok"}, ""},
	} {
		_, stderr, err := executeCommand(tc.args...)
		assertValidationError(t, err)
		if exitCodeForError(err) != 1 {
			t.Errorf("%v: exit %d, want 1", tc.args, exitCodeForError(err))
		}
		if tc.hint != "" && !strings.Contains(hintFor(err), tc.hint) {
			t.Errorf("%v: hint %q does not mention %q", tc.args, hintFor(err), tc.hint)
		}
		if strings.Contains(err.Error()+hintFor(err)+stderr, "SECRETSECRET") {
			t.Errorf("%v: the error quoted the key file", tc.args)
		}
	}
}

func TestAppIntegrityStatusClearAndFilters(t *testing.T) {
	var calls []string
	var listQuery string
	clearStatus := 200
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		calls = append(calls, r.Method+" "+r.URL.Path)
		switch {
		case strings.HasSuffix(r.URL.Path, "/installs"):
			listQuery = r.URL.RawQuery
			w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
		case r.Method == "DELETE" && clearStatus == 409:
			w.WriteHeader(409)
			w.Write([]byte(`{"error":true,"status":409,"message":"Play Integrity is require for this app; set integrity_mode to off"}`))
		case r.Method == "DELETE":
			w.Write([]byte(`{"data":{"registration_id":3,"credential":null,"cleared":true,"message":"The Play Integrity credential was deleted."}}`))
		default:
			w.Write([]byte(`{"data":{"registration_id":3,"integrity_mode":"observe"}}`))
		}
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("app", "integrity", "status", "3"); err != nil {
		t.Fatalf("status: %v", err)
	}
	stdout, _, err := executeCommand("app", "integrity", "credential", "clear", "3", "--force", "--json")
	if err != nil {
		t.Fatalf("clear: %v", err)
	}
	if !strings.Contains(stdout, "was deleted") {
		t.Errorf("clear printed %q: a void operation needs its message", stdout)
	}
	clearStatus = 409
	_, _, err = executeCommand("app", "integrity", "credential", "clear", "3", "--force")
	if err == nil || !strings.Contains(hintFor(err), "--integrity-mode off") {
		t.Errorf("409 hint = %q", hintFor(err))
	}
	if _, _, err := executeCommand("app", "install", "list", "3", "--integrity-state", "invalid", "--match-state", "integrity_failed"); err != nil {
		t.Fatalf("list: %v", err)
	}
	if !strings.Contains(listQuery, "integrity_state=invalid") || !strings.Contains(listQuery, "match_state=integrity_failed") {
		t.Errorf("query = %q", listQuery)
	}
	want := []string{"GET /api/v3/apps/3/integrity", "DELETE /api/v3/apps/3/integrity-credential", "DELETE /api/v3/apps/3/integrity-credential", "GET /api/v3/apps/3/installs"}
	var got []string
	for _, c := range calls {
		if !strings.Contains(c, "capabilities") {
			got = append(got, c)
		}
	}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Errorf("calls = %v, want %v", got, want)
	}
}

func TestAppUpdateSendsTheIntegrityFieldsAsTyped(t *testing.T) {
	var got map[string]interface{}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &got)
		w.Write([]byte(`{"data":{"registration_id":3}}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	if _, _, err := executeCommand("app", "update", "3", "--integrity-mode", "require", "--integrity-cloud-project-number", "123456789012"); err != nil {
		t.Fatalf("update: %v", err)
	}
	if got["integrity_mode"] != "require" || got["integrity_cloud_project_number"] != "123456789012" {
		t.Errorf("body = %v", got)
	}
}
