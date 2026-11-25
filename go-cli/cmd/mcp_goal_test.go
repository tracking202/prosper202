package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"reflect"
	"regexp"
	"slices"
	"sort"
	"strings"
	"sync"
	"testing"
)

// goalFunnelStep is one goal of a 21-goal setup funnel: the shapes a product's
// sign-up, trial and payment funnel takes (a step after another, a window
// from the click, each repeat, a value from the event's revenue).
type goalFunnelStep struct {
	key, name, event, repeat string
	revenue                  bool
	after                    []string
	withinDays               int
}

var goalSetupFunnel = []goalFunnelStep{
	{key: "signup", name: "Sign-up", event: "signup", repeat: "once"},
	{key: "card", name: "Card added", event: "card_added", repeat: "once", after: []string{"signup"}},
	{key: "trial", name: "Trial started", event: "trial_start", repeat: "once", after: []string{"signup"}},
	{key: "activated", name: "Activated", event: "install", repeat: "once", withinDays: 14},
	{key: "licence", name: "Licence active", event: "licence_active", repeat: "once"},
	{key: "connected", name: "Connected", event: "connected", repeat: "once"},
	{key: "key", name: "Key created", event: "key_created", repeat: "once"},
	{key: "paired", name: "Paired", event: "paired", repeat: "once"},
	{key: "purchase", name: "First payment", event: "purchase", repeat: "once", revenue: true},
	{key: "converted", name: "Trial converted", event: "purchase", repeat: "once", after: []string{"trial"}, withinDays: 30},
	{key: "renewal", name: "Renewal", event: "renewal", repeat: "each", revenue: true},
	{key: "upgrade", name: "Upgrade", event: "upgrade", repeat: "each", revenue: true},
	{key: "one-time", name: "One-time charge", event: "one_time", repeat: "each", revenue: true},
	{key: "failed", name: "Payment failed", event: "payment_failed", repeat: "each"},
	{key: "trial-cancel", name: "Trial cancelled", event: "trial_cancel", repeat: "once"},
	{key: "cancel", name: "Cancelled", event: "cancel", repeat: "once"},
	{key: "cta", name: "Site: portal click", event: "cta_click", repeat: "once"},
	{key: "github", name: "Site: GitHub click", event: "github_click", repeat: "each"},
	{key: "copy", name: "Site: copied a command", event: "docs_copy", repeat: "once"},
	{key: "pricing", name: "Site: saw pricing", event: "pricing_view", repeat: "once"},
	{key: "download", name: "Site: download", event: "download", repeat: "once"},
}

// definition is the step's whole definition, its after steps named by the
// goal ids their creates answered.
func (s goalFunnelStep) definition(ids map[string]int) map[string]interface{} {
	after := []interface{}{}
	for _, k := range s.after {
		after = append(after, ids[k])
	}
	var within interface{}
	if s.withinDays > 0 {
		within = map[string]interface{}{"days": s.withinDays, "from": "click"}
	}
	value := map[string]interface{}{"type": "none"}
	if s.revenue {
		value = map[string]interface{}{"type": "from_property", "prop": "$revenue"}
	}
	return map[string]interface{}{"name": s.name, "trigger": map[string]interface{}{"event": s.event},
		"after": after, "within": within, "repeat": map[string]interface{}{"mode": s.repeat}, "value": value}
}

// A funnel of 21 goals, each its whole definition, is made through
// goal_create alone: each create sends the owner and the definition as they
// were given (after naming the goal ids earlier answers gave, within null as
// null), and each answer carries the goal_id the next step's after needs.
func TestMCPGoalCreateMakesASetupFunnel(t *testing.T) {
	if len(goalSetupFunnel) != 21 {
		t.Fatalf("the funnel has %d goals, want 21", len(goalSetupFunnel))
	}
	var mu sync.Mutex
	var bodies []map[string]interface{}
	next := 900
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{"staged_writes":true},"cli":true,"principal":{"user_id":1,"roles":["Super user"],"scopes":["*"]}}}`))
		case r.Method == http.MethodPost && r.URL.Path == "/api/v3/goals":
			raw, _ := io.ReadAll(r.Body)
			var body map[string]interface{}
			if err := json.Unmarshal(raw, &body); err != nil {
				t.Errorf("POST /goals body %s: %v", raw, err)
			}
			mu.Lock()
			bodies = append(bodies, body)
			next++
			id := next
			mu.Unlock()
			w.WriteHeader(http.StatusCreated)
			_, _ = fmt.Fprintf(w, `{"data":{"goal_id":%d,"scope":"campaign","scope_id":316,"current_version":1}}`, id)
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL.RequestURI())
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")

	ids := map[string]int{}
	for i, step := range goalSetupFunnel {
		def := step.definition(ids)
		input, err := json.Marshal(map[string]interface{}{"campaign_id": "316", "definition": def})
		if err != nil {
			t.Fatal(err)
		}
		stdout, stderr, err := mcpExecute(t, "mcp", "call", "goal_create", "--input", string(input))
		if err != nil {
			t.Fatalf("goal %d (%s): %v %s", i+1, step.name, err, stderr)
		}
		var answer struct {
			Data struct {
				GoalID int `json:"goal_id"`
			} `json:"data"`
		}
		if err := json.Unmarshal([]byte(stdout), &answer); err != nil || answer.Data.GoalID == 0 {
			t.Fatalf("goal %d (%s) answered %s (%v)", i+1, step.name, stdout, err)
		}
		ids[step.key] = answer.Data.GoalID

		mu.Lock()
		got := bodies[len(bodies)-1]
		mu.Unlock()
		want := map[string]interface{}{"scope": "campaign", "scope_id": float64(316), "definition": roundTrip(t, def)}
		if !reflect.DeepEqual(got, want) {
			t.Errorf("goal %d (%s) sent %v, want %v", i+1, step.name, got, want)
		}
	}
	if len(bodies) != 21 {
		t.Fatalf("sent %d creates, want 21", len(bodies))
	}
	// The steps after another name the ids its create answered.
	if got := bodies[9]["definition"].(map[string]interface{})["after"]; !reflect.DeepEqual(got, []interface{}{float64(ids["trial"])}) {
		t.Errorf("Trial converted's after is %v, want [%d]", got, ids["trial"])
	}
}

// roundTrip is v as the install decodes it.
func roundTrip(t *testing.T, v interface{}) interface{} {
	t.Helper()
	raw, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	var out interface{}
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatal(err)
	}
	return out
}

// goalParserKeys reads GoalDefinition's unknownKeys lists, by path ("" is
// the definition; a predicate's path is computed, so it is read as "where").
func goalParserKeys(t *testing.T) map[string][]string {
	t.Helper()
	src, err := os.ReadFile(repoPath("202-config", "Goals", "GoalDefinition.php"))
	if err != nil {
		t.Fatal(err)
	}
	out := map[string][]string{}
	calls := regexp.MustCompile(`self::unknownKeys\(\$\w+, \[([^\]]*)\], (?:'([^']*)'|\$path)`).FindAllSubmatch(src, -1)
	for _, m := range calls {
		path := string(m[2])
		if !strings.HasPrefix(string(m[0]), "self::unknownKeys($p,") && strings.HasSuffix(string(m[0]), "$path") {
			t.Fatalf("an unknownKeys call with a computed path the test cannot place: %s", m[0])
		}
		if strings.HasSuffix(string(m[0]), "$path") {
			path = "where"
		}
		for _, k := range phpStringList(string(m[1])) {
			if !slices.Contains(out[path], k) {
				out[path] = append(out[path], k)
			}
		}
	}
	if len(calls) < 10 {
		t.Fatalf("read %d unknownKeys calls in GoalDefinition.php; the parser makes 11", len(calls))
	}
	return out
}

// The definition schema goal_create and goal_update take names each key the
// parser accepts, at each path, and no other: a model that follows it is
// never refused for a key, and is shown every part a goal has.
func TestGoalDefinitionSchemaIsTheParsers(t *testing.T) {
	schema := goalDefinitionSchema()
	parts := map[string]*mcpProperty{"": &schema}
	for _, path := range []string{"trigger", "threshold", "within", "repeat", "value"} {
		p := schema.Properties[path]
		parts[path] = &p
	}
	sum := schema.Properties["threshold"].Properties["sum"]
	parts["threshold.sum"] = &sum
	parts["where"] = schema.Properties["trigger"].Properties["where"].Items
	keys := goalParserKeys(t)
	for path, want := range keys {
		part, ok := parts[path]
		if !ok || part == nil {
			t.Errorf("the parser reads keys at %q, which the schema has no part for", path)
			continue
		}
		var named []string
		for k := range part.Properties {
			named = append(named, k)
		}
		sort.Strings(named)
		sort.Strings(want)
		if strings.Join(named, ",") != strings.Join(want, ",") {
			t.Errorf("the schema names %v at %q, the parser %v", named, path, want)
		}
	}
	if len(keys) != len(parts) {
		t.Errorf("the schema has parts %d, the parser reads %d paths (%v)", len(parts), len(keys), keys)
	}
	src, err := os.ReadFile(repoPath("202-config", "Goals", "GoalDefinition.php"))
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`public const OPS = \[([^\]]*)\];`).FindSubmatch(src)
	if m == nil || strings.Join(phpStringList(string(m[1])), ",") != strings.Join(parts["where"].Properties["op"].Enum, ",") {
		t.Errorf("the schema's ops %v, the parser's OPS %q", parts["where"].Properties["op"].Enum, m)
	}
}
