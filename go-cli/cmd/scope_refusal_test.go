package cmd

import (
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"testing"

	"p202/internal/api"
)

// A 1.9.79 install's 403 for a key without the scope a write needs, as
// Api\V3\Support\ScopeRefusal answers it (ScopeRefusalServedIntegrationTest
// holds the install to it).
const (
	plainKeyRefusal   = `{"error":true,"message":"Insufficient API key scope for this operation: requires 'ppc-networks:write' (key has: campaigns:read). A key's scope cannot be changed: make a key whose scope includes 'ppc-networks:write' (POST /users/{id}/api-keys with a key that holds it, or a full-access key from My Account › Personal Settings › API keys) and use it instead.","status":403,"code":"insufficient_scope","required_scope":"ppc-networks:write","key_scope":"campaigns:read","key_source":"api_key","remedy":"A key's scope cannot be changed: make a key whose scope includes 'ppc-networks:write' (POST /users/{id}/api-keys with a key that holds it, or a full-access key from My Account › Personal Settings › API keys) and use it instead."}`
	connectionRefusal = `{"error":true,"message":"Insufficient API key scope for this operation: requires 'ppc-networks:write' (key has: campaigns:write,ppc-networks:read). This key is an AI app's connection through Tracking202's MCP server. A connection keeps the access it was approved with, and that did not include 'ppc-networks:write'. Connect the AI app again and choose \"Read and change\" on the approval page.","status":403,"code":"insufficient_scope","required_scope":"ppc-networks:write","key_scope":"campaigns:write,ppc-networks:read","key_source":"ai_connection","remedy":"This key is an AI app's connection through Tracking202's MCP server. A connection keeps the access it was approved with, and that did not include 'ppc-networks:write'. Connect the AI app again and choose \"Read and change\" on the approval page.","connect_access":"write"}`
	// An install older than 1.9.79 says it in its message only.
	olderInstallRefusal = `{"error":true,"message":"Insufficient API key scope for this operation: requires 'ppc-networks:write' (key has: campaigns:write,ppc-networks:read).","status":403}`
)

// scopeRefusingInstall reads like mcpFakeInstall and refuses every write
// with body.
func scopeRefusingInstall(t *testing.T, body string) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{"staged_writes":true},"cli":true}}`))
		case r.Method == http.MethodGet:
			_, _ = w.Write([]byte(`{"data":{"ppc_network_id":1,"ppc_network_name":"Google Ads - Main"}}`))
		default:
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(body))
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	return srv
}

// A key made before a write area existed, on that write: exit 2 (auth), and
// a hint naming the scope and the command that makes a key with it.
func TestAKeyWithoutTheScopeIsToldHowToGetIt(t *testing.T) {
	for _, tc := range []struct {
		name, body string
		want       []string
	}{
		{"a plain key", plainKeyRefusal, []string{"`p202 user apikey create <user_id> --scope campaigns:read,ppc-networks:write`", "`p202 config set-key <new key>`"}},
		{"an AI connection's key", connectionRefusal, []string{`Connect the AI app to Prosper202 again and choose "Read and change"`, "'ppc-networks:write'"}},
		{"an older install", olderInstallRefusal, []string{"--scope campaigns:write,ppc-networks:read,ppc-networks:write`"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			scopeRefusingInstall(t, tc.body)
			_, _, err := executeCommand("ppc-network", "update", "1", "--ppc-network-name", "Main")
			if err == nil {
				t.Fatal("the refused update answered no error")
			}
			if code := exitCodeForError(err); code != ExitAuth {
				t.Errorf("exit code %d, want %d (%v)", code, ExitAuth, err)
			}
			hint := hintFor(err)
			for _, w := range tc.want {
				if !strings.Contains(hint, w) {
					t.Errorf("hint %q lacks %q", hint, w)
				}
			}
			env := errorEnvelope(err)["error"].(map[string]interface{})
			if env["http_status"] != 403 || env["category"] != "auth" || env["hint"] != hint {
				t.Errorf("envelope %v", env)
			}
		})
	}
}

// The hosted MCP server's engine runs on the connection's key: whatever the
// install says of the key, and an older install says nothing, the next step
// is approving the connection again.
func TestAHostedCallWithoutTheScopeSaysConnectAgain(t *testing.T) {
	saved := mcpHostedRun
	mcpHostedRun = true
	t.Cleanup(func() { mcpHostedRun = saved })
	for _, tc := range []struct {
		name, body, want string
	}{
		{"a 1.9.79 install", connectionRefusal, `choose "Read and change"`},
		{"an install that took it for a plain key", plainKeyRefusal, "Connect the AI app to Prosper202 again and approve it with the access that includes 'ppc-networks:write'"},
		{"an older install", olderInstallRefusal, "Connect the AI app to Prosper202 again and approve it with the access that includes 'ppc-networks:write'"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			scopeRefusingInstall(t, tc.body)
			_, _, err := mcpExecute(t, "mcp", "call", "ppc_network_update", "--input", `{"id":"1","ppc_network_name":"Main"}`)
			if err == nil {
				t.Fatal("the refused tool call answered no error")
			}
			if code := exitCodeForError(err); code != ExitAuth {
				t.Errorf("exit code %d, want %d (%v)", code, ExitAuth, err)
			}
			hint := hintFor(err)
			if !strings.Contains(hint, tc.want) || strings.Contains(hint, "apikey create") {
				t.Errorf("hint %q, want %q", hint, tc.want)
			}
		})
	}
}

// The hint names each access as the approval page does.
func TestConnectAccessLabelsAreTheApprovalPages(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Support", "AiConnect.php"))
	if err != nil {
		t.Fatal(err)
	}
	block := regexp.MustCompile(`(?s)public const ACCESS_LABELS = \[(.*?)\];`).FindSubmatch(src)
	if block == nil {
		t.Fatal("AiConnect.php has no ACCESS_LABELS")
	}
	labels := regexp.MustCompile(`'([a-z]+)' => '([^']+)'`).FindAllSubmatch(block[1], -1)
	if len(labels) != 3 {
		t.Fatalf("ACCESS_LABELS has %d entries: %s", len(labels), block[1])
	}
	for _, l := range labels {
		r := api.ScopeRefusal{RequiredScope: "campaigns:write", KeySource: api.KeySourceAIConnection, ConnectAccess: string(l[1]), ConnectAccessSaid: true}
		if hint := r.ConnectionHint(); !strings.Contains(hint, `choose "`+string(l[2])+`"`) {
			t.Errorf("access %s: hint %q does not name %q", l[1], hint, l[2])
		}
	}
}
