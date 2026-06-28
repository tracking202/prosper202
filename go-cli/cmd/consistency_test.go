package cmd

import (
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

// report breakdown should accept the same friendly flags as the analytics
// shorthand: --group-by (alias of --breakdown), --sort-dir (alias of --sort_dir),
// and dimension aliases such as lp -> landing_page.

func TestReportBreakdownAcceptsGroupByAlias(t *testing.T) {
	var gotParams url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("report", "breakdown", "--group-by", "ppc_account"); err != nil {
		t.Fatalf("report breakdown --group-by error: %v", err)
	}
	if got := gotParams.Get("breakdown"); got != "ppc_account" {
		t.Errorf("breakdown = %q, want %q", got, "ppc_account")
	}
}

func TestReportBreakdownDimensionAliasLp(t *testing.T) {
	var gotParams url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("report", "breakdown", "-b", "lp"); err != nil {
		t.Fatalf("report breakdown -b lp error: %v", err)
	}
	if got := gotParams.Get("breakdown"); got != "landing_page" {
		t.Errorf("breakdown = %q, want %q (lp alias)", got, "landing_page")
	}
}

func TestReportBreakdownAcceptsSortDirAlias(t *testing.T) {
	var gotParams url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("report", "breakdown", "-b", "country", "--sort", "roi", "--sort-dir", "DESC"); err != nil {
		t.Fatalf("report breakdown --sort-dir error: %v", err)
	}
	if got := gotParams.Get("sort_dir"); got != "DESC" {
		t.Errorf("sort_dir = %q, want %q", got, "DESC")
	}
}

func TestReportBreakdownCanonicalFlagsStillWork(t *testing.T) {
	var gotParams url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotParams = r.URL.Query()
		w.WriteHeader(200)
		w.Write([]byte(`{"data":[]}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("report", "breakdown", "--breakdown", "device", "--sort_dir", "ASC"); err != nil {
		t.Fatalf("report breakdown canonical flags error: %v", err)
	}
	if got := gotParams.Get("breakdown"); got != "device" {
		t.Errorf("breakdown = %q, want %q", got, "device")
	}
	if got := gotParams.Get("sort_dir"); got != "ASC" {
		t.Errorf("sort_dir = %q, want %q", got, "ASC")
	}
}

// rotator rule-update should accept the rule id via --rule_id as an alternative
// to the second positional arg, matching the flag-flexible style of rule-delete.

func TestRotatorRuleUpdateAcceptsRuleIdFlag(t *testing.T) {
	var gotPath, gotMethod string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotMethod = r.Method
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("rotator", "rule-update", "4", "--rule_id", "15", "--status", "0"); err != nil {
		t.Fatalf("rule-update --rule_id error: %v", err)
	}
	if gotMethod != http.MethodPut {
		t.Errorf("method = %q, want PUT", gotMethod)
	}
	if !strings.HasSuffix(gotPath, "/rotators/4/rules/15") {
		t.Errorf("path = %q, want suffix %q", gotPath, "/rotators/4/rules/15")
	}
}

func TestRotatorRuleUpdatePositionalStillWorks(t *testing.T) {
	var gotPath string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(200)
		w.Write([]byte(`{"data":{}}`))
	}))
	defer srv.Close()

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")

	if _, _, err := executeCommand("rotator", "rule-update", "4", "15", "--status", "1"); err != nil {
		t.Fatalf("rule-update positional error: %v", err)
	}
	if !strings.HasSuffix(gotPath, "/rotators/4/rules/15") {
		t.Errorf("path = %q, want suffix %q", gotPath, "/rotators/4/rules/15")
	}
}
