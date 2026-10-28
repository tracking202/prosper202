package api

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

// The kinds of key an install (1.9.79 and later) names in a scope refusal's
// key_source (Api\V3\Support\ScopeRefusal).
const (
	KeySourceAIConnection = "ai_connection"
	KeySourceAPIKey       = "api_key"
	KeySourceUnknown      = "unknown"
)

// ScopeRefusal is a 403 for a scope the key lacks. A key keeps the scope it
// was made with, so an AI connection approved before a release added to what
// connections may do is refused there until it is approved again. An install
// from 1.9.79 on names the scope in fields; an older one only in its message,
// which every version words "requires '<scope>' (key has: <scopes>)".
type ScopeRefusal struct {
	RequiredScope string
	// KeyScope is the key's own scope, comma-separated; "" when not said.
	KeyScope string
	// KeySource is one of the KeySource constants, or "" from an older install.
	KeySource string
	// ConnectAccess is the approval page's access ("read", "propose",
	// "write") a connection approved again needs, and "" when no
	// connection's key can hold the scope; ConnectAccessSaid is false when
	// the install did not say either.
	ConnectAccess     string
	ConnectAccessSaid bool
}

var (
	scopeRequiresWords = regexp.MustCompile(`requires '([^']+)'`)
	scopeKeyHasWords   = regexp.MustCompile(`\(key has: ([^)]*)\)`)
)

// connectAccessLabels are the approval page's names for each access
// (AiConnect::ACCESS_LABELS; TestConnectAccessLabelsAreTheApprovalPages).
var connectAccessLabels = map[string]string{
	"read":    "Read only",
	"propose": "Read, and propose changes",
	"write":   "Read and change",
}

// ScopeRefusalOf reads err as a scope refusal, from the answer's fields or,
// from an older install, its message.
func ScopeRefusalOf(err error) (ScopeRefusal, bool) {
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Status != 403 {
		return ScopeRefusal{}, false
	}
	field := func(k string) string {
		s, _ := apiErr.Raw[k].(string)
		return strings.TrimSpace(s)
	}
	r := ScopeRefusal{RequiredScope: field("required_scope"), KeyScope: field("key_scope"), KeySource: field("key_source")}
	if r.RequiredScope == "" {
		if !strings.Contains(apiErr.Message, "Insufficient API key scope") {
			return ScopeRefusal{}, false
		}
		m := scopeRequiresWords.FindStringSubmatch(apiErr.Message)
		if m == nil {
			return ScopeRefusal{}, false
		}
		r.RequiredScope = m[1]
	}
	if r.KeyScope == "" {
		if m := scopeKeyHasWords.FindStringSubmatch(apiErr.Message); m != nil {
			r.KeyScope = strings.TrimSpace(m[1])
		}
	}
	if v, ok := apiErr.Raw["connect_access"]; ok {
		if s, isString := v.(string); isString && connectAccessLabels[s] != "" {
			r.ConnectAccess, r.ConnectAccessSaid = s, true
		} else if v == nil {
			r.ConnectAccessSaid = true
		}
	}
	return r, true
}

// Hint is the next step for the key the install named: approving the
// connection again, a new key, or both when it could not tell.
func (r ScopeRefusal) Hint() string {
	switch r.KeySource {
	case KeySourceAIConnection:
		return r.ConnectionHint()
	case KeySourceUnknown:
		return "If this key is an AI app's connection through Tracking202's MCP server: " + r.ConnectionHint() + " Otherwise: " + r.KeyHint()
	}
	return r.KeyHint()
}

// ConnectionHint is the next step for an AI app's connection: approving it
// again, which mints a key with what that access holds today.
func (r ScopeRefusal) ConnectionHint() string {
	if r.ConnectAccessSaid && r.ConnectAccess == "" {
		return fmt.Sprintf("No AI connection's key can hold '%s', so connecting the AI app again would not add it: do this on your install's pages, or with the p202 CLI and an API key that has that scope.", r.RequiredScope)
	}
	if label := connectAccessLabels[r.ConnectAccess]; label != "" {
		return fmt.Sprintf("Connect the AI app to Prosper202 again and choose \"%s\" on your install's approval page: a connection keeps the access it was approved with, and this one's does not include '%s'.", label, r.RequiredScope)
	}
	return fmt.Sprintf("Connect the AI app to Prosper202 again and approve it with the access that includes '%s' (\"Read and change\" makes changes, \"Read, and propose changes\" proposes them): a connection keeps the access it was approved with. If the approval page offers no access that does, this install's version does not let an AI connection do this.", r.RequiredScope)
}

// KeyHint is the next step for any other key: a key's scope cannot be
// changed, so a new key with this one's scope and the missing one.
func (r ScopeRefusal) KeyHint() string {
	return fmt.Sprintf("A key's scope cannot be changed. Make one whose scope includes '%s' with a key that may (a full-access key from My Account › Personal Settings › API keys): `p202 user apikey create <user_id> --scope %s` (`p202 whoami` shows the user id), then use it: `p202 config set-key <new key>`.", r.RequiredScope, r.widenedScope())
}

// widenedScope is the key's scope with the missing token added: the least a
// replacement needs to do what this key does and what it was refused.
func (r ScopeRefusal) widenedScope() string {
	var tokens []string
	seen := map[string]bool{}
	for _, t := range strings.Split(r.KeyScope+","+r.RequiredScope, ",") {
		t = strings.TrimSpace(t)
		// An unreadable stored scope parses to a marker no route matches.
		if t == "" || strings.HasPrefix(t, "!") || seen[t] {
			continue
		}
		seen[t] = true
		tokens = append(tokens, t)
	}
	return strings.Join(tokens, ",")
}
