package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"reflect"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"
)

// mcpWriteToolInput are the arguments a write tool needs before it sends its
// write, beyond its ids.
var mcpWriteToolInput = map[string]map[string]interface{}{
	"campaign_create":         {"aff_campaign_name": "X", "aff_campaign_url": "https://example.com/o", "aff_campaign_payout": "1", "aff_network_id": "1"},
	"campaign_update":         {"aff_campaign_name": "Y"},
	"tracker_create_with_url": {"aff_campaign_id": "1", "ppc_account_id": "1"},
	"tracker_update":          {"click_cpc": "0.5"},
	"landing_page_create":     {"aff_campaign_id": "1", "landing_page_nickname": "L", "landing_page_url": "https://example.com/l"},
	"landing_page_update":     {"landing_page_nickname": "M"},
	"ppc_network_update":      {"ppc_network_name": "O"},
	"ppc_account_update":      {"ppc_account_default": "1"},
	"aff_network_update":      {"aff_network_name": "D"},
	"ppc_network_create":      {"ppc_network_name": "N"},
	"ppc_account_create":      {"ppc_account_name": "A", "ppc_network_id": "1"},
	"aff_network_create":      {"aff_network_name": "C"},
	"text_ad_create":          {"text_ad_name": "T", "text_ad_headline": "H", "text_ad_description": "D", "text_ad_display_url": "example.com", "aff_campaign_id": "1"},
	"text_ad_update":          {"text_ad_headline": "I"},
	"rotator_create":          {"name": "R", "default_url": "https://example.com/d"},
	"rotator_update":          {"name": "S"},
	"click_filter_update":     {"repeat_window": "6h"},
	"click_filter_list_add":   {"address": "203.0.113.0/24", "action": "filter"},
	"rotator_set_rules": {"rules": []interface{}{map[string]interface{}{
		"rule_name": "Mobile",
		"criteria":  []interface{}{map[string]interface{}{"type": "device", "value": "mobile"}},
		"redirects": []interface{}{map[string]interface{}{"redirect_url": "https://example.com/m"}},
	}}},
}

// mcpHostedOptionalReads are reads a hosted tool makes when its key allows
// and answers without when refused: whoami's own user record (whoami.go,
// TestWhoamiAnswersWithoutTheUserRecord).
var mcpHostedOptionalReads = map[string]string{"whoami": "users"}

// stagedDecision is applying or discarding a proposal.
var stagedDecision = regexp.MustCompile(`^/api/v3/staged-changes/[^/]+/(apply|discard)$`)

// phpAreaList reads a `public const NAME = ['a', 'b', ...];` list.
func phpAreaList(t *testing.T, src []byte, name string) map[string]bool {
	t.Helper()
	m := regexp.MustCompile(`(?s)public const ` + name + ` = \[(.*?)\];`).FindSubmatch(src)
	if m == nil {
		t.Fatalf("AiConnect.php has no %s list", name)
	}
	areas := map[string]bool{}
	for _, a := range regexp.MustCompile(`'([a-z-]+)'`).FindAllSubmatch(m[1], -1) {
		areas[string(a[1])] = true
	}
	if len(areas) == 0 {
		t.Fatalf("AiConnect.php's %s list is empty", name)
	}
	return areas
}

// apiArea is the scope area Auth::scopeAreaForPath gives a v3 path, or ""
// for a path outside scope checks.
func apiArea(path string) string {
	rest, ok := strings.CutPrefix(path, "/api/v3/")
	if !ok {
		return ""
	}
	first, _, _ := strings.Cut(rest, "/")
	switch first {
	case "capabilities", "versions", "":
		return ""
	case "changes", "audit":
		return "sync"
	}
	return first
}

// The hosted MCP server holds each connection's key to the areas in
// AiConnect::READ_AREAS and WRITE_AREAS. Every tool it offers, run in each
// mode, must ask only for those (a GET in a read area, any other write in a
// write area), every area there must be one a tool uses, and a tool it does
// not offer must need an area outside them.
func TestHostedMCPToolsStayInsideTheAIKeyAreas(t *testing.T) {
	src, err := os.ReadFile(repoPath("api", "v3", "Support", "AiConnect.php"))
	if err != nil {
		t.Fatal(err)
	}
	readAreas, writeAreas := phpAreaList(t, src, "READ_AREAS"), phpAreaList(t, src, "WRITE_AREAS")
	usedRead, usedWrite := map[string]bool{}, map[string]bool{}
	for _, s := range mcpToolSpecs {
		for _, staged := range []bool{false, true} {
			if staged && (s.readOnly() || s.NotStaged) {
				continue
			}
			fake := &mcpFakeInstall{}
			srv := fake.server(t)
			tmp := t.TempDir()
			setTestHome(t, tmp)
			writeTestConfig(t, tmp, srv.URL, "test-key-123456")
			input := map[string]interface{}{}
			for _, a := range s.Args {
				input[a.Name] = "1"
				if a.Pattern == mcpChangeID {
					input[a.Name] = "chg_1"
				}
			}
			for name, value := range mcpReadToolRequired[s.Name] {
				input[name] = value
			}
			for name, value := range mcpWriteToolInput[s.Name] {
				input[name] = value
			}
			body, err := json.Marshal(input)
			if err != nil {
				t.Fatal(err)
			}
			args := []string{"mcp", "call", s.Name, "--input", string(body)}
			if staged {
				args = append([]string{"--staged"}, args...)
			}
			_, stderr, err := mcpExecute(t, args...)
			outside, data := false, 0
			for _, hit := range fake.requests() {
				method, uri, _ := strings.Cut(hit, " ")
				path, _, _ := strings.Cut(uri, "?")
				area := apiArea(path)
				if area == "" {
					continue
				}
				data++
				if method != "GET" && stagedDecision.MatchString(path) {
					// The router asks the change's own area of the key
					// (StagedChangesController), not staged-changes.
					continue
				}
				if method == "GET" && mcpHostedOptionalReads[s.Name] == area {
					continue
				}
				if s.NotHosted {
					outside = outside || !readAreas[area]
					continue
				}
				if method == "GET" {
					usedRead[area] = true
					if !readAreas[area] {
						t.Errorf("%s (staged %v) reads %s, outside AiConnect::READ_AREAS: %s", s.Name, staged, area, hit)
					}
				} else {
					usedWrite[area] = true
					if !writeAreas[area] {
						t.Errorf("%s (staged %v) writes %s, outside AiConnect::WRITE_AREAS: %s", s.Name, staged, area, hit)
					}
				}
			}
			if data == 0 && err != nil {
				t.Errorf("%s (staged %v) sent no data request (err %v, stderr %s); give it the arguments it needs", s.Name, staged, err, stderr)
			}
			if s.NotHosted && !staged && !outside {
				t.Errorf("%s is NotHosted but asks only for areas a hosted key reaches; offer it", s.Name)
			}
		}
	}
	for area := range readAreas {
		if !usedRead[area] {
			t.Errorf("AiConnect::READ_AREAS grants %s, which no hosted tool reads", area)
		}
	}
	for area := range writeAreas {
		if !usedWrite[area] {
			t.Errorf("AiConnect::WRITE_AREAS grants %s, which no hosted tool writes", area)
		}
	}
}

func TestMCPCatalogListsWhatTheHostedServerLeavesOut(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	// The click filter and the settings are /users, which no hosted key reaches.
	want := "click_filter_get,click_filter_update,click_filter_test,click_filter_list_add,click_filter_list_remove,system_info,settings_get"
	if strings.Join(cat.HostedExcludes, ",") != want {
		t.Fatalf("hosted_excludes = %q, want %s", cat.HostedExcludes, want)
	}
}

// A key that may not read users (one made for a hosted connection) still
// gets whoami's answer, without the user record.
func TestWhoamiAnswersWithoutTheUserRecord(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		case r.URL.Path == "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{},"principal":{"user_id":7,"roles":["admin"],"scopes":["campaigns:read"]}}}`))
		default:
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(`{"error":true,"message":"Insufficient API key scope for this operation: requires 'users:read'"}`))
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	stdout, stderr, err := mcpExecute(t, "mcp", "call", "whoami", "--input", "{}")
	if err != nil {
		t.Fatalf("whoami failed without users:read: %v\nstderr: %s", err, stderr)
	}
	var got struct {
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &got); err != nil {
		t.Fatalf("whoami's answer is not JSON: %v: %s", err, stdout)
	}
	if got.Data["user_id"] != float64(7) {
		t.Fatalf("whoami did not say who: %s", stdout)
	}
	if _, ok := got.Data["user_name_unavailable"]; !ok {
		t.Fatalf("whoami did not say the user record was unavailable: %s", stdout)
	}
}

// Every *_id argument takes an id only: they go into filters and paths
// (report_breakeven's into campaigns/<id>).
func TestMCPIDArgumentsTakeOnlyIDs(t *testing.T) {
	cat, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	for _, tool := range cat.Tools {
		for name, prop := range tool.InputSchema.Properties {
			if strings.HasSuffix(name, "_id") && prop.Pattern == "" {
				t.Errorf("%s: %s has no pattern", tool.Name, name)
			}
		}
	}
	breakeven, _ := findMCPSpec("report_breakeven")
	for _, bad := range []string{"../users", "1/../../users", "%2e%2e", "1 OR 1=1", "", "-1", "1x"} {
		if _, err := mcpToolArgv(rootCmd, breakeven, []byte(`{"aff_campaign_id":`+strconvQuote(bad)+`}`)); err == nil {
			t.Errorf("report_breakeven took aff_campaign_id %q", bad)
		}
	}
	if _, err := mcpToolArgv(rootCmd, breakeven, []byte(`{"aff_campaign_id":"12"}`)); err != nil {
		t.Errorf("report_breakeven refused aff_campaign_id 12: %v", err)
	}
	summary, _ := findMCPSpec("report_summary")
	if _, err := mcpToolArgv(rootCmd, summary, []byte(`{"ppc_network_id":"none"}`)); err != nil {
		t.Errorf("report_summary refused ppc_network_id none: %v", err)
	}
	if _, err := mcpToolArgv(rootCmd, summary, []byte(`{"ppc_network_id":"nonex"}`)); err == nil {
		t.Error("report_summary took ppc_network_id nonex")
	}
}

func strconvQuote(s string) string {
	b, _ := json.Marshal(s)
	return string(b)
}

// The optimize reports put --aff-campaign-id into campaigns/<id>: anything
// but an id is refused before a request is made.
func TestOptimizeReportsRefuseACampaignIDThatIsNotAnID(t *testing.T) {
	for _, args := range [][]string{
		{"report", "breakeven", "--aff-campaign-id", "../users"},
		{"report", "losers", "--aff-campaign-id", "1%2f..%2fusers"},
		{"report", "winners", "--aff-campaign-id", "7x"},
		// Its argument, refused before fetchCampaignPayout's own guard.
		{"campaign", "optimize", "7x"},
		{"campaign", "optimize", "%2e%2e"},
	} {
		fake := &mcpFakeInstall{}
		srv := fake.server(t)
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key-123456")
		_, _, err := executeCommand(args...)
		if err == nil || exitCodeForError(err) != 1 {
			t.Errorf("%v: err %v, want a validation refusal", args, err)
		}
		if hits := fake.requests(); len(hits) != 0 {
			t.Errorf("%v sent %q", args, hits)
		}
	}
}

// --read-only holds the client itself for the length of the call: the
// install sees the mode on while the tool runs, and it is off afterwards.
func TestMCPReadOnlyCallHoldsTheClient(t *testing.T) {
	var during []bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		during = append(during, api.ReadOnlyMode())
		switch r.URL.Path {
		case "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
		default:
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	if _, stderr, err := mcpExecute(t, "mcp", "call", "campaign_list", "--input", "{}", "--read-only"); err != nil {
		t.Fatalf("campaign_list --read-only: %v\n%s", err, stderr)
	}
	if len(during) == 0 {
		t.Fatal("the call reached the install with nothing")
	}
	for i, on := range during {
		if !on {
			t.Errorf("request %d went out with read-only mode off", i)
		}
	}
	if api.ReadOnlyMode() {
		t.Error("read-only mode is still on after the call")
	}
}

// mcpPagingInstall answers every list with one row a page, of three, so a
// command that reads every page asks for a second one (offset past 0).
type mcpPagingInstall struct {
	mu   sync.Mutex
	hits []*http.Request
}

func (f *mcpPagingInstall) server(t *testing.T) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.hits = append(f.hits, r.Clone(r.Context()))
		f.mu.Unlock()
		switch r.URL.Path {
		case "/api/versions":
			_, _ = w.Write([]byte(`{"data":{"preferred":"v3","supported":["v3"]}}`))
			return
		case "/api/v3/capabilities":
			_, _ = w.Write([]byte(`{"data":{"features":{},"cli":true,"principal":{"user_id":1,"roles":["Super user"],"scopes":["*"]}}}`))
			return
		}
		offset, _ := strconv.Atoi(r.URL.Query().Get("offset"))
		_, _ = fmt.Fprintf(w, `{"data":[{"id":%d}],"pagination":{"total":3,"limit":1,"offset":%d}}`, offset+1, offset)
	}))
	t.Cleanup(srv.Close)
	return srv
}

// secondPage reports whether a data request asked for a page past the first.
func (f *mcpPagingInstall) secondPage() (bool, int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	data := 0
	for _, r := range f.hits {
		if apiArea(r.URL.Path) == "" {
			continue
		}
		data++
		if n, err := strconv.Atoi(r.URL.Query().Get("offset")); err == nil && n > 0 {
			return true, data
		}
	}
	return false, data
}

func withHostedRun(t *testing.T, hosted bool) {
	t.Helper()
	was := mcpHostedRun
	mcpHostedRun = hosted
	t.Cleanup(func() { mcpHostedRun = was })
}

// mcpHostedOmitAll are the tools whose all reads every page.
var mcpHostedOmitAll = []string{"campaign_list", "tracker_list", "landing_page_list", "ppc_network_list", "ppc_account_list", "aff_network_list", "text_ad_list", "rotator_list", "click_list", "conversion_list"}

// HostedOmit holds all exactly where all reads every page: each tool that
// takes all, run natively with all: true against an install with more than
// one page, asks for a second page if and only if its spec omits all on the
// hosted server (change_list's all is every user's changes, one request).
// Only all: the other flags that read every page stay (mcpEveryPage).
func TestHostedOmitsAllWhereAllReadsEveryPage(t *testing.T) {
	withHostedRun(t, false)
	taking := 0
	for _, s := range mcpToolSpecs {
		c, err := s.command(rootCmd)
		if err != nil {
			t.Fatal(err)
		}
		params, _, err := s.params(c)
		if err != nil {
			t.Fatal(err)
		}
		takesAll := false
		for _, p := range params {
			takesAll = takesAll || (p.flag == "all")
		}
		if !takesAll {
			if slices.Contains(s.HostedOmit, "all") {
				t.Errorf("%s omits all on the hosted server but does not take it", s.Name)
			}
			continue
		}
		taking++
		fake := &mcpPagingInstall{}
		srv := fake.server(t)
		tmp := t.TempDir()
		setTestHome(t, tmp)
		writeTestConfig(t, tmp, srv.URL, "test-key-123456")
		input := map[string]interface{}{"all": true}
		for name, value := range mcpReadToolRequired[s.Name] {
			input[name] = value
		}
		body, err := json.Marshal(input)
		if err != nil {
			t.Fatal(err)
		}
		if _, stderr, err := mcpExecute(t, "mcp", "call", s.Name, "--input", string(body), "--read-only"); err != nil {
			t.Errorf("%s with all: true: %v\n%s", s.Name, err, stderr)
			continue
		}
		paged, data := fake.secondPage()
		if data == 0 {
			t.Errorf("%s with all: true sent no data request", s.Name)
			continue
		}
		omitted := slices.Contains(s.HostedOmit, "all")
		if paged && !omitted {
			t.Errorf("%s reads every page with all: true; add HostedOmit: mcpEveryPage", s.Name)
		}
		if !paged && omitted {
			t.Errorf("%s omits all on the hosted server, but its all reads one page; offer it", s.Name)
		}
	}
	if taking < 11 {
		t.Fatalf("only %d tools take all; want the ten lists and change_list at least", taking)
	}
}

// A hosted run refuses an omitted argument, naming it and how to page, before
// anything is sent; a native run takes it (TestHostedOmitsAllWhereAllReadsEveryPage).
func TestHostedRunRefusesWhatItOmits(t *testing.T) {
	withHostedRun(t, true)
	checked := 0
	for _, s := range mcpToolSpecs {
		for _, f := range s.HostedOmit {
			prop := strings.ReplaceAll(f, "-", "_")
			for _, value := range []string{"true", "false"} {
				fake := &mcpFakeInstall{}
				srv := fake.server(t)
				tmp := t.TempDir()
				setTestHome(t, tmp)
				writeTestConfig(t, tmp, srv.URL, "test-key-123456")
				_, _, err := mcpExecute(t, "mcp", "call", s.Name, "--input", `{"`+prop+`":`+value+`}`, "--read-only")
				if err == nil {
					t.Errorf("hosted %s took %s: %s", s.Name, prop, value)
					continue
				}
				checked++
				if code := exitCodeForError(err); code != 1 {
					t.Errorf("hosted %s %s: exit %d, want 1 (validation)", s.Name, prop, code)
				}
				if want := s.Name + " does not take " + prop + " on the hosted server"; !strings.Contains(err.Error(), want) {
					t.Errorf("hosted %s: error %q does not say %q", s.Name, err.Error(), want)
				}
				hint := hintFor(err)
				if !strings.Contains(hint, "limit and offset") || !strings.Contains(hint, s.Name+" takes: ") {
					t.Errorf("hosted %s: hint %q does not say how to page and what the tool takes", s.Name, hint)
				}
				if _, list, _ := strings.Cut(hint, s.Name+" takes: "); slices.Contains(strings.Split(strings.TrimSuffix(list, "."), ", "), prop) {
					t.Errorf("hosted %s: hint lists %s among what the tool takes: %q", s.Name, prop, hint)
				}
				if hits := fake.requests(); len(hits) != 0 {
					t.Errorf("hosted %s with %s sent %q", s.Name, prop, hits)
				}
			}
		}
	}
	if checked < 20 {
		t.Fatalf("checked %d refusals; want both values on each of the ten lists", checked)
	}
}

// A hosted run's tool list leaves the omitted arguments out and nothing else;
// a native one keeps them. Both name them in hosted_omits.
func TestHostedSchemaLeavesOutWhatItOmits(t *testing.T) {
	withHostedRun(t, false)
	native, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	mcpHostedRun = true
	hosted, err := buildMCPCatalog(rootCmd, false, false)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(native.HostedOmits, hosted.HostedOmits) {
		t.Errorf("hosted_omits differ: native %v, hosted %v", native.HostedOmits, hosted.HostedOmits)
	}
	byName := map[string]mcpTool{}
	for _, tool := range hosted.Tools {
		byName[tool.Name] = tool
	}
	for _, n := range native.Tools {
		h, ok := byName[n.Name]
		if !ok {
			t.Errorf("the hosted run does not list %s", n.Name)
			continue
		}
		want := map[string]mcpProperty{}
		for name, prop := range n.InputSchema.Properties {
			want[name] = prop
		}
		for _, prop := range native.HostedOmits[n.Name] {
			if _, ok := n.InputSchema.Properties[prop]; !ok {
				t.Errorf("native %s does not take %s", n.Name, prop)
			}
			delete(want, prop)
		}
		if !reflect.DeepEqual(h.InputSchema.Properties, want) {
			t.Errorf("hosted %s takes %v; want the native arguments minus %v", n.Name, mcpPropertyNames(h.InputSchema.Properties), native.HostedOmits[n.Name])
		}
	}
}

func mcpPropertyNames(props map[string]mcpProperty) []string {
	var names []string
	for name := range props {
		names = append(names, name)
	}
	sort.Strings(names)
	return names
}

// The relay reads hosted_omits from the native `p202 mcp tools`: the ten
// lists that read every page with all, and {} (never null) when none.
func TestMCPCatalogListsHostedOmits(t *testing.T) {
	read := func() map[string]json.RawMessage {
		t.Helper()
		cat, err := buildMCPCatalog(rootCmd, false, false)
		if err != nil {
			t.Fatal(err)
		}
		body, err := json.Marshal(cat)
		if err != nil {
			t.Fatal(err)
		}
		var raw map[string]json.RawMessage
		if err := json.Unmarshal(body, &raw); err != nil {
			t.Fatal(err)
		}
		return raw
	}
	withHostedRun(t, false)
	var got map[string][]string
	if err := json.Unmarshal(read()["hosted_omits"], &got); err != nil {
		t.Fatal(err)
	}
	want := map[string][]string{}
	for _, name := range mcpHostedOmitAll {
		want[name] = []string{"all"}
	}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("hosted_omits = %v, want %v", got, want)
	}
	saved := mcpToolSpecs
	t.Cleanup(func() { mcpToolSpecs = saved })
	var none []mcpToolSpec
	for _, s := range saved {
		if len(s.HostedOmit) == 0 {
			none = append(none, s)
		}
	}
	mcpToolSpecs = none
	if empty := string(read()["hosted_omits"]); empty != "{}" {
		t.Errorf("hosted_omits with nothing omitted = %s, want {}", empty)
	}
}

// HostedOmit names an optional flag the tool takes, or the catalogue does not
// build: a name it does not take would omit nothing and refuse nothing.
func TestHostedOmitNamesAnOptionalFlag(t *testing.T) {
	list, _ := findMCPSpec("campaign_list")
	c, err := list.command(rootCmd)
	if err != nil {
		t.Fatal(err)
	}
	for _, bad := range []mcpToolSpec{
		{Name: list.Name, Command: list.Command, Kind: mcpRead, HostedOmit: []string{"al"}},
		{Name: list.Name, Command: list.Command, Kind: mcpRead, Omit: []string{"all"}, HostedOmit: []string{"all"}},
		{Name: list.Name, Command: list.Command, Kind: mcpRead, Required: []string{"all"}, HostedOmit: []string{"all"}},
	} {
		if _, _, err := bad.params(c); err == nil || !strings.Contains(err.Error(), "HostedOmit names --") {
			t.Errorf("HostedOmit %v (Omit %v, Required %v): err %v", bad.HostedOmit, bad.Omit, bad.Required, err)
		}
	}
	// --force is the approval's to give (mcpOmittedFlags), never an argument.
	del, _ := findMCPSpec("campaign_delete")
	dc, err := del.command(rootCmd)
	if err != nil {
		t.Fatal(err)
	}
	bad := mcpToolSpec{Name: del.Name, Command: del.Command, Kind: mcpDelete, Args: del.Args, HostedOmit: []string{"force"}}
	if _, _, err := bad.params(dc); err == nil || !strings.Contains(err.Error(), "HostedOmit names --force") {
		t.Errorf("HostedOmit [force] on campaign_delete: err %v", err)
	}
}
