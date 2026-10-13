package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

const groupsBody = `{"data":[
 {"breakdown":"campaign","id":7,"name":"Alpha","total_clicks":"4","children":[
   {"breakdown":"keyword","id":2,"name":"shoes","total_clicks":"3"},
   {"breakdown":"keyword","id":null,"name":null,"total_clicks":"1"}]},
 {"breakdown":"campaign","id":8,"name":"Beta","total_clicks":"1","children":[]}],
 "totals":{"total_clicks":"5"},"by":["campaign","keyword"]}`

func TestReportGroupsSendsTheLevelsAndFlattensTheTree(t *testing.T) {
	var got map[string]string
	var path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		got = map[string]string{}
		for k := range r.URL.Query() {
			got[k] = r.URL.Query().Get(k)
		}
		_, _ = w.Write([]byte(groupsBody))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	stdout, _, err := executeCommand("report", "groups", "--by", "offer, keyword", "--period", "last7", "--show", "real", "--sort", "clicks", "--ndjson")
	if err != nil {
		t.Fatalf("report groups: %v", err)
	}
	if path != "/api/v3/reports/groups" {
		t.Errorf("path = %s", path)
	}
	for k, v := range map[string]string{"by": "campaign,keyword", "period": "last7", "show": "real", "sort": "total_clicks"} {
		if got[k] != v {
			t.Errorf("query %s = %q, want %q (sent %v)", k, got[k], v, got)
		}
	}
	var groups []string
	for _, line := range strings.Split(strings.TrimSpace(stdout), "\n") {
		var row struct {
			Group string `json:"group"`
			Level int    `json:"level"`
		}
		if err := json.Unmarshal([]byte(line), &row); err != nil {
			t.Fatalf("not one JSON object per line: %q", line)
		}
		groups = append(groups, row.Group)
	}
	want := []string{"Alpha", "› shoes", "› [no keyword]", "Beta", "Total"}
	if strings.Join(groups, "|") != strings.Join(want, "|") {
		t.Errorf("rows %q, want %q (a parent before its children, the none child named)", groups, want)
	}

	stdout, _, err = executeCommand("report", "groups", "--by", "campaign,keyword", "--json")
	if err != nil {
		t.Fatalf("report groups --json: %v", err)
	}
	if !strings.Contains(stdout, `"children"`) {
		t.Errorf("--json must answer the tree as served, got %s", stdout)
	}
}

func TestReportGroupsRefusesBadLevelsBeforeAnyRequest(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp) // nothing configured: refused before the client is built
	for _, by := range []string{"", "campaign,nope", "campaign,offer", "campaign,keyword,country,city,region"} {
		args := []string{"report", "groups"}
		if by != "" {
			args = append(args, "--by", by)
		}
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != ExitValidation || strings.Contains(err.Error(), "no URL configured") {
			t.Errorf("--by %q: want a validation refusal before any request, got %v", by, err)
		}
	}
}

func TestReportGroupsOnAServerWithoutItSaysSo(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"error":true,"message":"Not found","status":404}`))
	}))
	defer srv.Close()
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	_, _, err := executeCommand("report", "groups", "--by", "campaign")
	if err == nil || !strings.Contains(hintFor(err), "no GET /reports/groups") {
		t.Errorf("a 404 must say the server predates the report: %v / %q", err, hintFor(err))
	}
}
