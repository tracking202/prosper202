package api

import (
	"fmt"
	"strings"
	"testing"
)

// scopeRefusalBody is a 1.9.79 install's 403 for a scope the key lacks.
func scopeRefusalBody(scope, keyScope, source, connectAccess string) []byte {
	access := `"connect_access":null,`
	switch {
	case source == KeySourceAPIKey:
		access = ""
	case connectAccess != "":
		access = fmt.Sprintf(`"connect_access":%q,`, connectAccess)
	}
	return []byte(fmt.Sprintf(`{"error":true,"message":"Insufficient API key scope for this operation: requires '%s' (key has: %s). (remedy)","status":403,`+
		`"code":"insufficient_scope","required_scope":%q,"key_scope":%q,"key_source":%q,%s"remedy":"(remedy)"}`,
		scope, keyScope, scope, keyScope, source, access))
}

func TestScopeRefusalReadsTheInstallsFields(t *testing.T) {
	err := parseAPIError(403, scopeRefusalBody("text-ads:write", "reports:read,text-ads:read", KeySourceAIConnection, "write"))
	r, ok := ScopeRefusalOf(fmt.Errorf("creating a text ad: %w", err))
	if !ok {
		t.Fatal("a scope refusal was not read as one")
	}
	want := ScopeRefusal{RequiredScope: "text-ads:write", KeyScope: "reports:read,text-ads:read", KeySource: KeySourceAIConnection, ConnectAccess: "write", ConnectAccessSaid: true}
	if r != want {
		t.Fatalf("read %+v, want %+v", r, want)
	}
	none, _ := ScopeRefusalOf(parseAPIError(403, scopeRefusalBody("users:write", "reports:read", KeySourceAIConnection, "")))
	if !none.ConnectAccessSaid || none.ConnectAccess != "" {
		t.Fatalf("connect_access null read as %+v", none)
	}
}

// An install older than 1.9.79 names the scope only in its message.
func TestScopeRefusalReadsAnOlderInstallsMessage(t *testing.T) {
	err := parseAPIError(403, []byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'campaigns:write' (key has: read,campaigns:stage).","status":403}`))
	r, ok := ScopeRefusalOf(err)
	if !ok || r.RequiredScope != "campaigns:write" || r.KeyScope != "read,campaigns:stage" || r.KeySource != "" || r.ConnectAccessSaid {
		t.Fatalf("read %+v (%v)", r, ok)
	}
	staged := parseAPIError(403, []byte(`{"error":true,"message":"Insufficient API key scope for this operation: the change's area cannot be told, so it requires 'write' for every area (key has: read,stage).","status":403}`))
	if r, ok := ScopeRefusalOf(staged); !ok || r.RequiredScope != "write" || r.KeyScope != "read,stage" {
		t.Fatalf("staged change refusal read %+v (%v)", r, ok)
	}
}

func TestOtherRefusalsAreNotScopeRefusals(t *testing.T) {
	for _, err := range []error{
		parseAPIError(403, []byte(`{"error":true,"message":"Admin access required.","status":403}`)),
		parseAPIError(403, []byte(`{"error":true,"message":"This account's role does not have the 'access_to_setup_section' permission.","status":403}`)),
		// Something in front of the install, in words that are not the install's.
		parseAPIError(403, []byte(`{"message":"This site requires 'sso' sign-in"}`)),
		parseAPIError(401, []byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'campaigns:write'","status":401}`)),
		parseAPIError(422, scopeRefusalBody("campaigns:write", "read", KeySourceAPIKey, "")),
		&RequestError{Kind: "network", Err: fmt.Errorf("requires 'campaigns:write'")},
	} {
		if r, ok := ScopeRefusalOf(err); ok {
			t.Errorf("%v read as a scope refusal: %+v", err, r)
		}
	}
}

func TestScopeRefusalHintFollowsTheKey(t *testing.T) {
	cases := []struct {
		name       string
		err        error
		want, deny []string
	}{
		{"a plain key", parseAPIError(403, scopeRefusalBody("ppc-networks:write", "campaigns:read", KeySourceAPIKey, "")),
			[]string{"`p202 user apikey create <user_id> --scope campaigns:read,ppc-networks:write`", "`p202 config set-key <new key>`", "`p202 whoami`"},
			[]string{"Connect the AI app"}},
		{"a connection", parseAPIError(403, scopeRefusalBody("ppc-networks:write", "ppc-networks:read", KeySourceAIConnection, "write")),
			[]string{`choose "Read and change"`, "'ppc-networks:write'"},
			[]string{"apikey create"}},
		{"a proposing connection", parseAPIError(403, scopeRefusalBody("text-ads:stage", "text-ads:read", KeySourceAIConnection, "propose")),
			[]string{`choose "Read, and propose changes"`},
			[]string{"apikey create"}},
		{"a scope no connection holds", parseAPIError(403, scopeRefusalBody("users:write", "reports:read", KeySourceAIConnection, "")),
			[]string{"No AI connection's key can hold 'users:write'"},
			[]string{"choose \"", "apikey create"}},
		{"a key the install could not read", parseAPIError(403, scopeRefusalBody("ppc-networks:write", "ppc-networks:read", KeySourceUnknown, "write")),
			[]string{`choose "Read and change"`, "--scope ppc-networks:read,ppc-networks:write"},
			nil},
		{"an older install", parseAPIError(403, []byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'clicks:write' (key has: read).","status":403}`)),
			[]string{"--scope read,clicks:write"},
			[]string{"Connect the AI app"}},
		{"an unreadable stored scope", parseAPIError(403, scopeRefusalBody("clicks:read", "!unparseable", KeySourceAPIKey, "")),
			[]string{"--scope clicks:read`"},
			[]string{"!unparseable"}},
	}
	for _, tc := range cases {
		hint := HintFor(tc.err)
		for _, w := range tc.want {
			if !strings.Contains(hint, w) {
				t.Errorf("%s: hint %q lacks %q", tc.name, hint, w)
			}
		}
		for _, d := range tc.deny {
			if strings.Contains(hint, d) {
				t.Errorf("%s: hint %q says %q", tc.name, hint, d)
			}
		}
	}
}

// Without an answer to read the scope from, the class-wide hint stays.
func TestAScope403WithoutItsScopeKeepsTheClassHint(t *testing.T) {
	err := parseAPIError(403, []byte(`{"error":true,"message":"This key's scope is not enough.","status":403}`))
	if hint := HintFor(err); !strings.Contains(hint, "--scope") {
		t.Fatalf("hint %q", hint)
	}
}
