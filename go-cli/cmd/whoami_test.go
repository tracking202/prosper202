package cmd

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// identityServer answers health before authentication, as the API does, and
// everything else only for the key "good".
func identityServer(t *testing.T, capabilities string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/versions"):
			w.Write([]byte(`{"data":{"current":"v3","supported":["v3"]}}`))
		case strings.HasSuffix(r.URL.Path, "/system/health"):
			w.Write([]byte(`{"data":{"status":"healthy"}}`))
		case r.Header.Get("Authorization") != "Bearer good":
			w.WriteHeader(401)
			w.Write([]byte(`{"message":"Invalid API key."}`))
		case strings.HasSuffix(r.URL.Path, "/capabilities"):
			w.Write([]byte(capabilities))
		case strings.HasSuffix(r.URL.Path, "/users/7"):
			w.Write([]byte(`{"data":{"user_id":7,"user_name":"ops","user_email":"ops@example.com"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

const capabilitiesWithPrincipal = `{"data":{"features":{},"principal":{"user_id":7,"roles":["admin"],"scopes":["read","campaigns:write"]}}}`

// The health check is answered before authentication, so `config test`
// passed with any key. It now asks with the key, and a refused one is an
// auth error.
func TestConfigTestFailsForAKeyTheServerRefuses(t *testing.T) {
	srv := identityServer(t, capabilitiesWithPrincipal)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "not-the-key")
	_, _, err := executeCommand("config", "test")
	if err == nil {
		t.Fatal("config test passed with a key the server refuses")
	}
	if code := exitCodeForError(err); code != ExitAuth {
		t.Errorf("exit %d, want %d (auth)", code, ExitAuth)
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 config set-key") {
		t.Errorf("hint = %q, want it to name config set-key", hint)
	}
}

func TestConfigTestAndWhoamiNameTheKeysUser(t *testing.T) {
	srv := identityServer(t, capabilitiesWithPrincipal)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "good")

	stdout, _, err := executeCommand("config", "test", "--json")
	if err != nil {
		t.Fatalf("config test: %v", err)
	}
	if !strings.Contains(stdout, `"user_id": 7`) || !strings.Contains(stdout, `"campaigns:write"`) {
		t.Errorf("config test output lacks the principal:\n%s", stdout)
	}

	stdout, _, err = executeCommand("whoami", "--json")
	if err != nil {
		t.Fatalf("whoami: %v", err)
	}
	for _, want := range []string{`"user_id": 7`, `"user_name": "ops"`, `"admin"`, `"read"`, `"url": "` + srv.URL} {
		if !strings.Contains(stdout, want) {
			t.Errorf("whoami lacks %s:\n%s", want, stdout)
		}
	}
}

// A server from before the principal: the key authenticated, so config test
// passes; whoami cannot answer and says why rather than guessing.
func TestAServerWithoutThePrincipal(t *testing.T) {
	srv := identityServer(t, `{"data":{"features":{}}}`)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "good")
	if _, _, err := executeCommand("config", "test"); err != nil {
		t.Errorf("config test failed for a key the server accepted: %v", err)
	}
	_, _, err := executeCommand("whoami")
	if err == nil || !strings.Contains(hintFor(err), "predates") {
		t.Errorf("whoami: err = %v, hint %q", err, hintFor(err))
	}
}
