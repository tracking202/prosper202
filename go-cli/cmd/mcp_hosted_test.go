package cmd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strings"
	"testing"

	"p202/internal/api"
)

// mcpWriteToolInput are the arguments a write tool needs before it sends its
// write, beyond its ids.
var mcpWriteToolInput = map[string]map[string]string{
	"campaign_create":         {"aff_campaign_name": "X", "aff_campaign_url": "https://example.com/o", "aff_campaign_payout": "1", "aff_network_id": "1"},
	"campaign_update":         {"aff_campaign_name": "Y"},
	"tracker_create_with_url": {"aff_campaign_id": "1", "ppc_account_id": "1"},
	"tracker_update":          {"click_cpc": "0.5"},
	"landing_page_create":     {"aff_campaign_id": "1", "landing_page_nickname": "L", "landing_page_url": "https://example.com/l"},
	"landing_page_update":     {"landing_page_nickname": "M"},
	"ppc_network_create":      {"ppc_network_name": "N"},
	"ppc_account_create":      {"ppc_account_name": "A", "ppc_network_id": "1"},
	"aff_network_create":      {"aff_network_name": "C"},
	"text_ad_create":          {"text_ad_name": "T", "text_ad_headline": "H", "text_ad_description": "D", "text_ad_display_url": "example.com", "aff_campaign_id": "1"},
	"text_ad_update":          {"text_ad_headline": "I"},
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
	if strings.Join(cat.HostedExcludes, ",") != "system_info" {
		t.Fatalf("hosted_excludes = %q, want [system_info]", cat.HostedExcludes)
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
		// Its only guard is fetchCampaignPayout's own.
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
