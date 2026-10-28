package cmd

import (
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"regexp"
	"strings"
	"sync"
	"testing"
)

// The tokens the CLI offers are the ones the server writes into a link
// (Prosper202\Click\TrackingLinkVariables::BUILT_IN), in its order: a token
// only one side knows is either refused by the server or never offered.
func TestLinkTokensMatchTheServer(t *testing.T) {
	src, err := os.ReadFile(repoPath("202-config", "Click", "TrackingLinkVariables.php"))
	if err != nil {
		t.Fatal(err)
	}
	block := regexp.MustCompile(`(?s)const BUILT_IN = \[(.*?)\];`).FindSubmatch(src)
	if block == nil {
		t.Fatal("BUILT_IN not found in TrackingLinkVariables.php")
	}
	var server []string
	for _, m := range regexp.MustCompile(`'([a-z0-9_]+)'`).FindAllSubmatch(block[1], -1) {
		server = append(server, string(m[1]))
	}
	if strings.Join(server, ",") != strings.Join(linkTokens, ",") {
		t.Errorf("linkTokens = %v, server's BUILT_IN = %v", linkTokens, server)
	}
}

type linkTokenRequests struct {
	mu      sync.Mutex
	queries map[string]url.Values // path -> query
	posts   int
}

func linkTokenServer(t *testing.T) (*httptest.Server, *linkTokenRequests) {
	t.Helper()
	seen := &linkTokenRequests{queries: map[string]url.Values{}}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		seen.mu.Lock()
		defer seen.mu.Unlock()
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v3/trackers":
			seen.posts++
			w.WriteHeader(201)
			w.Write([]byte(`{"data":{"tracker_id":56,"aff_campaign_id":1}}`))
		case r.Method == "GET" && r.URL.Path == "/api/v3/trackers":
			w.Write([]byte(`{"data":[{"tracker_id":56,"aff_campaign_id":1}]}`))
		case r.Method == "GET" && strings.HasSuffix(r.URL.Path, "/url"):
			seen.queries[r.URL.Path] = r.URL.Query()
			w.Write([]byte(`{"data":{"tracker_id":56,"direct_url":"https://trk.example/dl.php?t202id=9"}}`))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv, seen
}

func TestLinkTokenFlagsReachTheURLRequest(t *testing.T) {
	commands := map[string][]string{
		"get-url":         {"tracker", "get-url", "56"},
		"create-with-url": {"tracker", "create-with-url", "--aff-campaign-id=1"},
		"bulk-urls":       {"tracker", "bulk-urls"},
	}
	for name, args := range commands {
		t.Run(name, func(t *testing.T) {
			_, seen := linkTokenServer(t)
			args := append(append([]string{}, args...), "--t202kw={keyword}", "--c1", " fb ", "--utm-source=[src]")
			if _, _, err := executeCommand(args...); err != nil {
				t.Fatalf("%s: %v", name, err)
			}
			q, ok := seen.queries["/api/v3/trackers/56/url"]
			if !ok {
				t.Fatalf("no URL request; saw %v", seen.queries)
			}
			want := url.Values{"t202kw": {"{keyword}"}, "c1": {"fb"}, "utm_source": {"[src]"}}
			if q.Encode() != want.Encode() {
				t.Errorf("query = %v, want %v (trimmed as the server trims, and only the flags given)", q, want)
			}
		})
	}
}

func TestALinkTokenThatCannotGoInALinkSendsNothing(t *testing.T) {
	for _, bad := range []string{"a&b", "a#b", "a?b", "a b", "a\tb", "\x00", strings.Repeat("x", 256)} {
		for _, args := range [][]string{
			{"tracker", "get-url", "56"},
			{"tracker", "create-with-url", "--aff-campaign-id=1"},
			{"tracker", "bulk-urls"},
		} {
			_, seen := linkTokenServer(t)
			_, _, err := executeCommand(append(append([]string{}, args...), "--c3="+bad)...)
			if err == nil {
				t.Fatalf("%s --c3=%q: accepted", args[1], bad)
			}
			if code := exitCodeForError(err); code != ExitValidation {
				t.Errorf("%s --c3=%q: exit %d, want %d", args[1], bad, code, ExitValidation)
			}
			if seen.posts != 0 || len(seen.queries) != 0 {
				t.Errorf("%s --c3=%q: sent %d creates and %d URL requests, want none", args[1], bad, seen.posts, len(seen.queries))
			}
		}
	}
}

// When the link cannot be fetched after the create, the tracker exists: the
// error has to say so, or the obvious retry makes a second tracker.
func TestCreateWithURLSaysTheTrackerExistsWhenTheLinkFails(t *testing.T) {
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" {
			w.WriteHeader(201)
			w.Write([]byte(`{"data":{"tracker_id":56}}`))
			return
		}
		w.WriteHeader(500)
		w.Write([]byte(`{"message":"boom"}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	_, _, err := executeCommand("tracker", "create-with-url", "--aff-campaign-id=1")
	if err == nil {
		t.Fatal("a failed link fetch reported success")
	}
	hint := hintFor(err)
	if !strings.Contains(hint, "tracker_id 56") || !strings.Contains(hint, "p202 tracker get-url 56") {
		t.Errorf("hint = %q, want it to name the created tracker and get-url", hint)
	}
}

// The verify commands add their test keyword to the link the API returns;
// a landing page's link can end in the page's #fragment, and a parameter
// after it is never sent.
func TestWithLinkParamGoesBeforeTheFragment(t *testing.T) {
	cases := map[string]string{
		"https://t.example/dl.php?t202id=5&t202kw=":     "https://t.example/dl.php?t202id=5&t202kw=&t202kw=test",
		"https://lp.example/a?x=1&t202id=5&t202kw=#top": "https://lp.example/a?x=1&t202id=5&t202kw=&t202kw=test#top",
		"https://lp.example/a":                          "https://lp.example/a?t202kw=test",
		"https://lp.example/a#top":                      "https://lp.example/a?t202kw=test#top",
	}
	for link, want := range cases {
		if got := appendTrackingKW(link); got != want {
			t.Errorf("appendTrackingKW(%q) = %q, want %q", link, got, want)
		}
	}
}
