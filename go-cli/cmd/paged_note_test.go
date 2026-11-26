package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// pagedServer answers GET requests from answers, keyed by the request's offset, and records each query.
func pagedServer(t *testing.T, answers map[string]string) (*[]string, func()) {
	t.Helper()
	var mu sync.Mutex
	seen := []string{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen = append(seen, r.URL.RawQuery)
		mu.Unlock()
		body, ok := answers[r.URL.Query().Get("offset")]
		if !ok {
			t.Errorf("unexpected request %s?%s", r.URL.Path, r.URL.RawQuery)
			body = `{"data":[]}`
		}
		w.WriteHeader(200)
		_, _ = w.Write([]byte(body))
	}))
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &seen, srv.Close
}

func TestPagedMoreRowsNoteSaysWhenRowsRemain(t *testing.T) {
	cases := []struct {
		name, body, want string
	}{
		{"rows remain", `{"data":[{},{}],"truncated":true,"limit":2,"offset":4}`, "Note: more rows remain after rows 5-6; --offset 6 reads the next page."},
		{"under meta", `{"data":[{}],"truncated":true,"meta":{"offset":0,"limit":1}}`, "Note: more rows remain after rows 1-1; --offset 1 reads the next page."},
		{"the last page", `{"data":[{},{}],"truncated":false,"limit":2,"offset":0}`, ""},
		{"a server before truncated", `{"data":[{},{}],"limit":2,"offset":0}`, ""},
		// The table renderer warns under a list page's pagination itself.
		{"a list page", `{"data":[{}],"truncated":true,"pagination":{"total":9,"limit":1,"offset":0}}`, ""},
		{"not JSON", `nope`, ""},
	}
	for _, tc := range cases {
		if got := pagedMoreRowsNote([]byte(tc.body)); got != tc.want {
			t.Errorf("%s: note = %q, want %q", tc.name, got, tc.want)
		}
	}
}

// A list page's warning follows the answer's truncated, not its total: a page of exactly the rows that remain
// says nothing, and one with rows after it says so though the total says otherwise.
func TestListWarningFollowsTruncated(t *testing.T) {
	for _, tc := range []struct {
		name, body string
		warns      bool
	}{
		{"rows remain", `{"data":[{"id":1},{"id":2}],"truncated":true,"pagination":{"total":2,"limit":2,"offset":0}}`, true},
		{"the last page", `{"data":[{"id":1},{"id":2}],"truncated":false,"pagination":{"total":50,"limit":2,"offset":0}}`, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, done := pagedServer(t, map[string]string{"": tc.body})
			defer done()
			_, stderr, err := executeCommand("campaign", "list")
			if err != nil {
				t.Fatalf("campaign list: %v", err)
			}
			warned := strings.Contains(stderr, "Warning: more rows remain after rows 1-2. Use --offset 2 for the next page")
			if warned != tc.warns || strings.Contains(stderr, "Warning: Showing") {
				t.Errorf("stderr = %q, want the truncated warning: %v", stderr, tc.warns)
			}
		})
	}
}

// --all reads on while the answer says rows remain and stops when it says none do, whatever its total says.
func TestAllRowsFollowsTruncated(t *testing.T) {
	seen, done := pagedServer(t, map[string]string{
		"0":   `{"data":[{"aff_campaign_id":1},{"aff_campaign_id":2}],"truncated":true,"pagination":{"total":2,"limit":100,"offset":0}}`,
		"2":   `{"data":[{"aff_campaign_id":3}],"truncated":false,"pagination":{"total":999,"limit":100,"offset":2}}`,
		"100": `{"data":[{"aff_campaign_id":4}],"truncated":false}`,
	})
	defer done()
	stdout, _, err := executeCommand("campaign", "list", "--all", "--json")
	if err != nil {
		t.Fatalf("campaign list --all: %v", err)
	}
	var out struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("not JSON: %v\n%s", err, stdout)
	}
	if len(out.Data) != 3 || len(*seen) != 2 {
		t.Errorf("read %d rows in %d requests (%v), want 3 in 2: the page after a short truncated one starts after its rows", len(out.Data), len(*seen), *seen)
	}
}

func TestAllRowsRefusesAnEmptyPageThatSaysRowsRemain(t *testing.T) {
	_, done := pagedServer(t, map[string]string{"0": `{"data":[],"truncated":true,"pagination":{"total":5,"limit":100,"offset":0}}`})
	defer done()
	_, _, err := executeCommand("campaign", "list", "--all", "--json")
	if err == nil || !strings.Contains(err.Error(), "says rows remain") || hintFor(err) == "" {
		t.Fatalf("err = %v (hint %q), want the contradiction named with a hint", err, hintFor(err))
	}
}

func TestLtvBreakdownSaysWhenRowsRemain(t *testing.T) {
	_, done := pagedServer(t, map[string]string{"10": `{"data":[{"id":1,"name":"a"}],"breakdown":"campaign","truncated":true,"limit":1,"offset":10}`})
	defer done()
	_, stderr, err := executeCommand("ltv", "breakdown", "--limit", "1", "--offset", "10")
	if err != nil {
		t.Fatalf("ltv breakdown: %v", err)
	}
	if !strings.Contains(stderr, "Note: more rows remain after rows 11-11; --offset 11 reads the next page.") {
		t.Errorf("stderr = %q, want the note naming --offset 11", stderr)
	}
	_, stderr, err = executeCommand("ltv", "breakdown", "--limit", "1", "--offset", "10", "--json")
	if err != nil {
		t.Fatalf("ltv breakdown --json: %v", err)
	}
	if strings.Contains(stderr, "Note:") {
		t.Errorf("--json carries truncated itself; stderr = %q", stderr)
	}
}
