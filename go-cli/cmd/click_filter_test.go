package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"sync"
	"testing"
)

// clickFilterFake is an install that records each request and its body.
type clickFilterFake struct {
	mu     sync.Mutex
	hits   []string
	bodies map[string]string
}

func (f *clickFilterFake) server(t *testing.T) *httptest.Server {
	f.bodies = map[string]string{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		hit := r.Method + " " + r.URL.RequestURI()
		f.mu.Lock()
		f.hits = append(f.hits, hit)
		f.bodies[hit] = string(body)
		f.mu.Unlock()
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{"staged_writes":true,"delete_dry_run":true},"principal":{"user_id":4,"roles":["Admin"],"scopes":["*"]}}}`))
		case r.Method == http.MethodDelete:
			w.WriteHeader(http.StatusNoContent)
		default:
			_, _ = w.Write([]byte(`{"data":{"sign_in_addresses":true,"crawler_ranges":true,"repeat_visits":true,"repeat_window":"24h",` +
				`"entries":[{"entry_id":3,"address":"203.0.113.0/24","action":"filter","note":"farm","created_at":1}]}}`))
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

// data are the requests past the version and capabilities probes.
func (f *clickFilterFake) data() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []string
	for _, h := range f.hits {
		if !strings.HasPrefix(h, "GET /api/versions") && !strings.HasPrefix(h, "GET /api/v3/capabilities") {
			out = append(out, h)
		}
	}
	return out
}

func clickFilterRun(t *testing.T, args ...string) (*clickFilterFake, string, error) {
	t.Helper()
	fake := &clickFilterFake{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	stdout, _, err := executeCommand(args...)
	return fake, stdout, err
}

func TestClickFilterActsOnTheKeysOwnAccountUnlessTold(t *testing.T) {
	fake, _, err := clickFilterRun(t, "click-filter", "get", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(fake.data(), " | "); got != "GET /api/v3/users/4/click-filter" {
		t.Fatalf("requests = %s, want the principal's own click filter", got)
	}
	fake, _, err = clickFilterRun(t, "click-filter", "get", "--user-id", "7", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(fake.data(), " | "); got != "GET /api/v3/users/7/click-filter" {
		t.Fatalf("requests = %s, want user 7's", got)
	}
}

func TestClickFilterSetSendsOnlyWhatIsGiven(t *testing.T) {
	fake, _, err := clickFilterRun(t, "click-filter", "set", "--repeat-window", "6h", "--sign-in-addresses", "0", "--json")
	if err != nil {
		t.Fatal(err)
	}
	hit := "PUT /api/v3/users/4/click-filter"
	if got := strings.Join(fake.data(), " | "); got != hit {
		t.Fatalf("requests = %s", got)
	}
	var body map[string]interface{}
	if err := json.Unmarshal([]byte(fake.bodies[hit]), &body); err != nil {
		t.Fatal(err)
	}
	if len(body) != 2 || body["repeat_window"] != "6h" || body["sign_in_addresses"] != false {
		t.Fatalf("body = %v, want repeat_window 6h and sign_in_addresses false only", body)
	}
}

func TestClickFilterRefusesBadInputBeforeAnyRequest(t *testing.T) {
	for name, args := range map[string][]string{
		"set with nothing":     {"click-filter", "set"},
		"a window not offered": {"click-filter", "set", "--repeat-window", "2h"},
		"a switch not 0 or 1":  {"click-filter", "set", "--crawler-ranges", "yes"},
		"add without action":   {"click-filter", "list", "add", "203.0.113.7"},
		"add a word":           {"click-filter", "list", "add", "office", "--action", "count"},
		"an unknown action":    {"click-filter", "list", "add", "203.0.113.7", "--action", "allow"},
		"test a block":         {"click-filter", "test", "203.0.113.0/24"},
		"test a word":          {"click-filter", "test", "localhost"},
		"a user id not an id":  {"click-filter", "get", "--user-id", "7x"},
		"update with nothing":  {"click-filter", "list", "update", "3"},
	} {
		fake, _, err := clickFilterRun(t, append(args, "--json")...)
		if err == nil {
			t.Errorf("%s: accepted", name)
			continue
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%s: exit %d, want %d (validation): %v", name, code, ExitValidation, err)
		}
		if got := fake.data(); len(got) != 0 {
			t.Errorf("%s: sent %v before refusing", name, got)
		}
	}
}

func TestClickFilterListAddAndRemove(t *testing.T) {
	fake, _, err := clickFilterRun(t, "click-filter", "list", "add", "203.0.113.0/24", "--action", "filter", "--note", "farm", "--json")
	if err != nil {
		t.Fatal(err)
	}
	hit := "POST /api/v3/users/4/click-filter/entries"
	var body map[string]interface{}
	if err := json.Unmarshal([]byte(fake.bodies[hit]), &body); err != nil {
		t.Fatalf("no %s: %v (%v)", hit, err, fake.data())
	}
	if body["address"] != "203.0.113.0/24" || body["action"] != "filter" || body["note"] != "farm" {
		t.Fatalf("body = %v", body)
	}

	fake, _, err = clickFilterRun(t, "click-filter", "list", "remove", "3", "--force", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(fake.data(), " | "); got != "DELETE /api/v3/users/4/click-filter/entries/3" {
		t.Fatalf("requests = %s", got)
	}

	fake, _, err = clickFilterRun(t, "click-filter", "list", "remove", "3", "--dry-run", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(fake.data(), " | "); got != "DELETE /api/v3/users/4/click-filter/entries/3?dry_run=1" {
		t.Fatalf("requests = %s, want the preview only", got)
	}
}

func TestClickFilterListShowsTheEntries(t *testing.T) {
	_, stdout, err := clickFilterRun(t, "click-filter", "list", "--json")
	if err != nil {
		t.Fatal(err)
	}
	var answer struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &answer); err != nil {
		t.Fatalf("%v: %s", err, stdout)
	}
	if len(answer.Data) != 1 || answer.Data[0]["address"] != "203.0.113.0/24" {
		t.Fatalf("entries = %v", answer.Data)
	}
}

func TestClickFilterTestIsARead(t *testing.T) {
	fake, _, err := clickFilterRun(t, "click-filter", "test", "2001:db8::7", "--json")
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(fake.data(), " | "); got != "GET /api/v3/users/4/click-filter/test?ip=2001%3Adb8%3A%3A7" {
		t.Fatalf("requests = %s", got)
	}
}

// The windows, actions and reasons the CLI offers are the server's.
func TestClickFilterValuesAreTheServers(t *testing.T) {
	read := func(path string) string {
		src, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		return string(src)
	}
	block := func(src, pattern, what string) string {
		m := regexp.MustCompile(pattern).FindStringSubmatch(src)
		if m == nil {
			t.Fatalf("cannot read %s: the scan is blind, not the list empty", what)
		}
		return m[1]
	}
	settings := read(repoPath("202-config", "Click", "Filter", "ClickFilterSettings.php"))
	var windows []string
	for _, m := range regexp.MustCompile(`'(\w+)' => \d+`).FindAllStringSubmatch(block(settings, `const WINDOWS = \[([^\]]*)\]`, "WINDOWS"), -1) {
		windows = append(windows, m[1])
	}
	if strings.Join(windows, ",") != strings.Join(clickFilterWindows, ",") {
		t.Errorf("--repeat-window offers %v, the server takes %v", clickFilterWindows, windows)
	}
	entry := read(repoPath("202-config", "Click", "Filter", "ClickFilterEntry.php"))
	if got := block(entry, `public const COUNT = '(\w+)'`, "COUNT") + "," + block(entry, `public const FILTER = '(\w+)'`, "FILTER"); got != strings.Join(clickFilterActions, ",") {
		t.Errorf("--action offers %v, the server takes %s", clickFilterActions, got)
	}
	verdict := read(repoPath("202-config", "Click", "Filter", "ClickFilterVerdict.php"))
	reasons := map[string]string{}
	for _, m := range regexp.MustCompile(`public const (\w+) = '(\w+)';`).FindAllStringSubmatch(verdict, -1) {
		reasons[m[1]] = m[2]
	}
	controller := read(repoPath("api", "v3", "Controllers", "ClicksController.php"))
	var served []string
	for _, line := range strings.Split(block(controller, `(?s)public const FILTER_REASONS = \[(.*?)\];`, "FILTER_REASONS"), "\n") {
		line = strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(line), ","))
		if line == "" {
			continue
		}
		if m := regexp.MustCompile(`ClickFilterVerdict::(\w+)$`).FindStringSubmatch(line); m != nil {
			served = append(served, reasons[m[1]])
		} else if m := regexp.MustCompile(`^'(\w+)'$`).FindStringSubmatch(line); m != nil {
			served = append(served, m[1])
		} else {
			t.Fatalf("cannot read this FILTER_REASONS line: %q", line)
		}
	}
	if strings.Join(served, ",") != strings.Join(clickFilterReasons, ",") {
		t.Errorf("click list --filter-reason offers %v, the server takes %v", clickFilterReasons, served)
	}
}
