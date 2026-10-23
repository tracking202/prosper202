package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"reflect"
	"regexp"
	"strings"
	"sync"
	"testing"

	"github.com/spf13/pflag"
)

// mcpTestChildEnv makes this test binary run the CLI (see TestMain), so the
// server under test is `p202 mcp` as a real process, and each of its tool
// calls a real `p202 mcp call` child.
const mcpTestChildEnv = "P202_TEST_RUN_CLI"

func TestMCPCatalogBuilds(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	if len(cat.Tools) != len(mcpToolSpecs) {
		t.Fatalf("catalogue has %d tools, specs %d", len(cat.Tools), len(mcpToolSpecs))
	}
	valid := regexp.MustCompile(`^[a-z][a-z0-9_]{0,63}$`)
	seen := map[string]bool{}
	for _, tool := range cat.Tools {
		if !valid.MatchString(tool.Name) {
			t.Errorf("tool name %q: want lowercase letters, digits and underscores, at most 64", tool.Name)
		}
		if seen[tool.Name] {
			t.Errorf("tool %s is listed twice", tool.Name)
		}
		seen[tool.Name] = true
		if tool.Title == "" || tool.Annotations.Title == "" {
			t.Errorf("tool %s has no title (the directory refuses a tool without one)", tool.Name)
		}
		if strings.Contains(tool.Description, "--") {
			t.Errorf("tool %s's description still names a CLI flag: %s", tool.Name, tool.Description)
		}
		for name, prop := range tool.InputSchema.Properties {
			if strings.Contains(prop.Description, "--") {
				t.Errorf("tool %s argument %s still names a CLI flag: %s", tool.Name, name, prop.Description)
			}
		}
	}
	read, _ := buildMCPCatalog(rootCmd, true, false)
	for _, tool := range read.Tools {
		if !tool.Annotations.ReadOnlyHint {
			t.Errorf("read-only catalogue lists %s, which writes", tool.Name)
		}
	}
	if len(read.Tools) == 0 || len(read.Tools) == len(cat.Tools) {
		t.Fatalf("read-only catalogue has %d of %d tools", len(read.Tools), len(cat.Tools))
	}
}

// The catalogue is read into the model's context on every connection.
func TestMCPCatalogStaysInBudget(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	body, err := json.Marshal(cat)
	if err != nil {
		t.Fatal(err)
	}
	// Characters, about 22k tokens. Most of it is the report tools' filters;
	// a new tool or flag that crosses this should omit what few asks need.
	// It was 80000 until the rotator writes (rotator_set_rules' rules are a
	// schema of their own), when the list stood within 500 of it.
	const budget = 88000
	if len(body) > budget {
		t.Fatalf("the tool list is %d characters, over its %d budget; trim notes or omit flags", len(body), budget)
	}
}

// A tool's declared positionals must be exactly what its command accepts;
// the command's Args validator is the authority.
func TestMCPToolPositionalsMatchCommands(t *testing.T) {
	for _, s := range mcpToolSpecs {
		c, err := s.command(rootCmd)
		if err != nil {
			t.Error(err)
			continue
		}
		args := make([]string, len(s.Args))
		for i := range args {
			args[i] = "1"
		}
		if c.Args != nil {
			if err := c.Args(c, args); err != nil {
				t.Errorf("%s: `p202 %s` refuses %d positional(s): %v", s.Name, strings.Join(s.Command, " "), len(args), err)
			}
			if err := c.Args(c, append(args, "1")); err == nil {
				t.Errorf("%s: `p202 %s` takes more positionals than the tool declares", s.Name, strings.Join(s.Command, " "))
			}
		} else if len(s.Args) > 0 {
			t.Errorf("%s: `p202 %s` has no Args validator to check its positionals against", s.Name, strings.Join(s.Command, " "))
		}
	}
}

// A command that confirms, deletes or discards is never offered as a read,
// and a delete is marked destructive.
func TestMCPWriteToolsAreMarked(t *testing.T) {
	deleting := regexp.MustCompile(`^(delete|remove|discard|erase)`)
	for _, s := range mcpToolSpecs {
		c, err := s.command(rootCmd)
		if err != nil {
			t.Fatal(err)
		}
		if s.readOnly() && (c.Flags().Lookup("force") != nil || c.Flags().Lookup("dry-run") != nil) {
			t.Errorf("%s is listed as a read, but `p202 %s` has --force or --dry-run", s.Name, strings.Join(s.Command, " "))
		}
		if deleting.MatchString(c.Name()) && s.Kind != mcpDelete {
			t.Errorf("%s runs `p202 %s` and is not kind delete", s.Name, strings.Join(s.Command, " "))
		}
	}
}

func TestMCPToolArgv(t *testing.T) {
	cases := []struct {
		tool, input string
		want        []string
		wantErr     string
	}{
		{"campaign_list", `{}`, []string{"campaign", "list"}, ""},
		{"campaign_list", ``, []string{"campaign", "list"}, ""},
		{"campaign_list", `{"limit":"5","with_stats":true,"period":"last7"}`, []string{"campaign", "list", "--limit=5", "--period=last7", "--with-stats=true"}, ""},
		{"campaign_list", `{"limit":5}`, []string{"campaign", "list", "--limit=5"}, ""},
		{"campaign_list", `{"with_stats":false}`, []string{"campaign", "list", "--with-stats=false"}, ""},
		{"campaign_list", `{"days":7}`, []string{"campaign", "list", "--days=7"}, ""},
		{"campaign_list", `{"days":1.5}`, nil, "days must be a whole number"},
		{"campaign_list", `{"days":"7"}`, nil, "days must be a number"},
		{"campaign_list", `{"limt":"5"}`, nil, "does not take limt"},
		{"campaign_list", `{"limit":null}`, nil, "limit is null"},
		{"campaign_list", `{"limit":true}`, nil, "limit must be a string"},
		{"campaign_list", `{"url_contains":"--force"}`, []string{"campaign", "list", "--url-contains=--force"}, ""},
		{"campaign_list", `[]`, nil, "must be a JSON object"},
		{"campaign_list", `{} {}`, nil, "must be one JSON object"},
		{"campaign_list", `{"force":true}`, nil, "does not take force"},
		{"campaign_get", `{"id":"12"}`, []string{"campaign", "get", "--", "12"}, ""},
		{"campaign_get", `{"id":12}`, []string{"campaign", "get", "--", "12"}, ""},
		{"campaign_get", `{"id":"-5"}`, nil, "id must be a numeric id"},
		{"campaign_get", `{"id":"1/conversions"}`, nil, "id must be a numeric id"},
		{"campaign_get", `{"id":"../users"}`, nil, "id must be a numeric id"},
		{"campaign_get", `{}`, nil, "campaign_get needs id"},
		{"campaign_get", `{"id":""}`, nil, "id must be a numeric id"},
		{"campaign_get", `{"id":null}`, nil, "id is null; it needs a value"},
		{"campaign_get", `{"id":"1","id":"2"}`, nil, "give id once"},
		{"campaign_get", `{"id":true}`, nil, "id must be a string"},
		{"change_discard", `{"change_id":"chg_1/apply?"}`, nil, "change_id must be a change id"},
		{"change_show", `{"change_id":"chg_abc123"}`, []string{"change", "show", "--", "chg_abc123"}, ""},
		{"report_groups", `{}`, nil, "report_groups needs by"},
		{"campaign_optimize", `{"campaign_id":"5","aff_campaign_id":"9"}`, nil, "does not take aff_campaign_id"},
		{"campaign_delete", `{"id":"7"}`, []string{"campaign", "delete", "--force", "--", "7"}, ""},
		{"campaign_delete", `{"id":"7","dry_run":true}`, []string{"campaign", "delete", "--dry-run=true", "--force", "--", "7"}, ""},
		{"campaign_delete", `{"ids":"7,8"}`, []string{"campaign", "delete", "--ids=7,8", "--force"}, ""},
		{"change_apply", `{"change_id":"chg_abc"}`, []string{"change", "apply", "--force", "--", "chg_abc"}, ""},
		{"report_summary", `{"all_profiles":true}`, nil, "does not take all_profiles"},
		{"click_list", `{"follow":true}`, nil, "does not take follow"},
	}
	for _, tc := range cases {
		spec, ok := findMCPSpec(tc.tool)
		if !ok {
			t.Fatalf("no tool %s", tc.tool)
		}
		got, err := mcpToolArgv(rootCmd, spec, []byte(tc.input))
		if tc.wantErr != "" {
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Errorf("%s %s: want error containing %q, got %v (argv %q)", tc.tool, tc.input, tc.wantErr, err, got)
			} else if exitCodeForError(err) != 1 {
				t.Errorf("%s %s: exit code %d, want 1 (validation)", tc.tool, tc.input, exitCodeForError(err))
			} else if strings.Contains(err.Error(), tc.tool) && !strings.Contains(hintFor(err), tc.tool+" takes: ") {
				t.Errorf("%s %s: hint %q does not list what the tool takes", tc.tool, tc.input, hintFor(err))
			}
			continue
		}
		if err != nil {
			t.Errorf("%s %s: %v", tc.tool, tc.input, err)
			continue
		}
		if !reflect.DeepEqual(got, tc.want) {
			t.Errorf("%s %s:\n got %q\nwant %q", tc.tool, tc.input, got, tc.want)
		}
	}
}

func TestMCPTextNamesToolsAndArguments(t *testing.T) {
	got := mcpText("Filter by ID (from `p202 aff-network list`, or `campaign list`); with --with-stats, see `p202 shell` or `breakdown device_type`")
	want := "Filter by ID (from aff_network_list, or campaign_list); with with_stats, see `p202 shell` or `breakdown device_type`"
	if got != want {
		t.Fatalf("got  %q\nwant %q", got, want)
	}
}

// mcpFakeInstall answers every GET with an empty list and records every
// request, so a test can see what a tool sent.
type mcpFakeInstall struct {
	mu   sync.Mutex
	hits []string
}

func (f *mcpFakeInstall) server(t *testing.T) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.hits = append(f.hits, r.Method+" "+r.URL.RequestURI())
		f.mu.Unlock()
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{"staged_writes":true,"delete_dry_run":true},"cli":true,"principal":{"user_id":1,"roles":["Super user"],"scopes":["*"]}}}`))
		case r.Method == http.MethodGet:
			_, _ = w.Write([]byte(`{"data":[],"pagination":{"total":0,"limit":50,"offset":0}}`))
		case r.Method == http.MethodDelete:
			w.WriteHeader(http.StatusNoContent)
		default:
			w.WriteHeader(http.StatusCreated)
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":9,"aff_campaign_name":"X"}}`))
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func (f *mcpFakeInstall) requests() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.hits...)
}

func mcpExecute(t *testing.T, args ...string) (string, string, error) {
	t.Helper()
	t.Cleanup(func() { mcpToolRun = false })
	return executeCommand(args...)
}

// mcpReadToolRequired are the arguments a read tool needs before it sends a
// request, beyond its ids.
var mcpReadToolRequired = map[string]map[string]string{
	"report_groups":    {"by": "campaign"},
	"report_crosstab":  {"rows": "country", "cols": "ppc_account"},
	"report_breakeven": {"aff_campaign_id": "1"},
	"forecast":         {"metric": "clicks"},
}

// Every read tool, run with placeholder arguments, asks the install for data
// and sends no write.
func TestMCPReadToolsSendNoWrites(t *testing.T) {
	for _, s := range mcpToolSpecs {
		if !s.readOnly() {
			continue
		}
		fake := &mcpFakeInstall{}
		srv := fake.server(t)
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key-123456")
		input := map[string]string{}
		for _, a := range s.Args {
			input[a.Name] = "1"
			if a.Pattern == mcpChangeID {
				input[a.Name] = "chg_1"
			}
		}
		for name, value := range mcpReadToolRequired[s.Name] {
			input[name] = value
		}
		body, err := json.Marshal(input)
		if err != nil {
			t.Fatal(err)
		}
		_, stderr, err := mcpExecute(t, "mcp", "call", s.Name, "--input", string(body))
		data := 0
		for _, hit := range fake.requests() {
			if !strings.HasPrefix(hit, "GET ") {
				t.Errorf("%s is a read tool and sent %s", s.Name, hit)
			}
			if !strings.HasPrefix(hit, "GET /api/versions") && !strings.HasPrefix(hit, "GET /api/v3/capabilities") {
				data++
			}
		}
		// A tool that fails before asking for any data has proved nothing
		// about what it sends.
		if data == 0 && err != nil {
			t.Errorf("%s sent no data request (err %v, stderr %s); give it the arguments it needs in mcpReadToolRequired", s.Name, err, stderr)
		}
	}
}

func TestMCPCallCarriesProfileAndStaged(t *testing.T) {
	first, second := &mcpFakeInstall{}, &mcpFakeInstall{}
	firstSrv, secondSrv := first.server(t), second.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithProfiles(t, tmp, "first", map[string]map[string]interface{}{
		"first":  {"url": firstSrv.URL, "api_key": "first-key-123456"},
		"second": {"url": secondSrv.URL, "api_key": "second-key-123456"},
	})
	stdout, stderr, err := mcpExecute(t, "--profile", "second", "--staged", "mcp", "call", "campaign_create",
		"--input", `{"aff_campaign_name":"X","aff_campaign_url":"https://example.com/o","aff_campaign_payout":"1","aff_network_id":"1"}`)
	if err != nil {
		t.Fatalf("mcp call: %v\nstderr: %s", err, stderr)
	}
	if len(first.requests()) != 0 {
		t.Fatalf("the active profile's install was called: %q", first.requests())
	}
	var post string
	for _, hit := range second.requests() {
		if strings.HasPrefix(hit, "POST ") {
			post = hit
		}
	}
	if !strings.Contains(post, "staged=1") {
		t.Fatalf("the write was not staged: %q", second.requests())
	}
	if strings.Contains(strings.TrimSpace(stdout), "\n") || !json.Valid([]byte(stdout)) {
		t.Fatalf("stdout is not one line of JSON: %q", stdout)
	}
}

func TestMCPCallIsCompactJSONWhateverTheConfigSays(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfigWithDefaults(t, tmp, srv.URL, "test-key-123456", map[string]string{"output.format": "table"})
	t.Setenv(outputEnvVar, "csv")
	stdout, stderr, err := mcpExecute(t, "mcp", "call", "campaign_list")
	if err != nil {
		t.Fatalf("mcp call: %v\nstderr: %s", err, stderr)
	}
	if strings.TrimSpace(stdout) != `{"data":[],"pagination":{"limit":50,"offset":0,"total":0}}` {
		t.Fatalf("stdout = %q", stdout)
	}
}

func TestMCPCallDeleteNeedsNoTerminal(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	_, stderr, err := mcpExecute(t, "mcp", "call", "campaign_delete", "--input", `{"id":"7"}`)
	if err != nil {
		t.Fatalf("mcp call: %v\nstderr: %s", err, stderr)
	}
	found := false
	for _, hit := range fake.requests() {
		found = found || strings.HasPrefix(hit, "DELETE /api/v3/campaigns/7")
	}
	if !found {
		t.Fatalf("no DELETE sent: %q", fake.requests())
	}
}

func TestMCPCallRefusals(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	for _, tc := range []struct {
		args []string
		want string
	}{
		{[]string{"mcp", "call", "campaign_delete", "--read-only", "--input", `{"id":"7"}`}, "this server is read-only"},
		{[]string{"mcp", "call", "no_such_tool"}, `there is no MCP tool "no_such_tool"`},
		{[]string{"mcp", "call", "campaign_list", "--input", `{"bogus":1}`}, "does not take bogus"},
		{[]string{"--staged", "mcp", "call", "change_apply", "--input", `{"change_id":"chg_1"}`}, "only proposes changes"},
		{[]string{"--staged", "mcp", "call", "change_discard", "--input", `{"change_id":"chg_1"}`}, "only proposes changes"},
	} {
		_, _, err := mcpExecute(t, tc.args...)
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%q: want %q, got %v", tc.args, tc.want, err)
		}
		if exitCodeForError(err) != 1 {
			t.Errorf("%q: exit %d, want 1", tc.args, exitCodeForError(err))
		}
	}
	if len(fake.requests()) != 0 {
		t.Fatalf("a refused call reached the install: %q", fake.requests())
	}
}

// A server that only proposes changes cannot apply or discard them: a model
// that could stage a write and then apply it would make --staged mean
// nothing.
func TestMCPStagedCatalogLeavesOutApplying(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, true)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range cat.Tools {
		if tool.Name == "change_apply" || tool.Name == "change_discard" {
			t.Errorf("a staged server lists %s", tool.Name)
		}
	}
	if !strings.Contains(cat.Instructions, "only proposes changes") {
		t.Errorf("a staged server's instructions do not say it only proposes: %q", cat.Instructions)
	}
	full, _ := buildMCPCatalog(rootCmd, false, false)
	if strings.Join(full.StagedExcludes, ",") != "change_apply,change_discard" {
		t.Errorf("staged_excludes = %q", full.StagedExcludes)
	}
}

// No tool argument may be a root flag in disguise: a command's own flag
// named like one would be listed, and the model could set it.
func TestMCPToolsTakeNoRootFlag(t *testing.T) {
	root := map[string]bool{"help": true, "version": true}
	rootCmd.PersistentFlags().VisitAll(func(f *pflag.Flag) { root[strings.ReplaceAll(f.Name, "-", "_")] = true })
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range cat.Tools {
		for name := range tool.InputSchema.Properties {
			if root[name] {
				t.Errorf("%s takes %s, a root flag", tool.Name, name)
			}
		}
	}
}

func TestMCPCallReadsItsArgumentsFromStdin(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	t.Setenv(outputEnvVar, "xml") // not an output format; mcp ignores it
	withStdinText(t, `{"limit":"7"}`)
	if _, stderr, err := mcpExecute(t, "mcp", "call", "campaign_list", "--input", "-"); err != nil {
		t.Fatalf("mcp call: %v\n%s", err, stderr)
	}
	if !mcpHit(fake.requests(), "GET /api/v3/campaigns?limit=7") {
		t.Fatalf("requests: %q", fake.requests())
	}
	withStdinText(t, `{"url_contains":"`+strings.Repeat("x", mcpMaxInputBytes)+`"}`)
	_, _, err := mcpExecute(t, "mcp", "call", "campaign_list", "--input", "-")
	if err == nil || !strings.Contains(err.Error(), "over 262144 bytes") || exitCodeForError(err) != 1 {
		t.Fatalf("an oversized input answered %v", err)
	}
}

func withStdinText(t *testing.T, text string) {
	t.Helper()
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		_, _ = w.WriteString(text)
		_ = w.Close()
	}()
	old := os.Stdin
	os.Stdin = r
	t.Cleanup(func() {
		os.Stdin = old
		_ = r.Close()
	})
}

func mcpHit(hits []string, want string) bool {
	for _, h := range hits {
		if h == want {
			return true
		}
	}
	return false
}
