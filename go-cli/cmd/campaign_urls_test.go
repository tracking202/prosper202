package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
)

// campaignFake serves GET /campaigns in pages and records every PUT.
type campaignFake struct {
	mu       sync.Mutex
	endpoint string // list path under /api/v3; "campaigns" when empty
	rows     []map[string]interface{}
	puts     []campaignPut
	gets     []string
	failPut  map[string]bool
	stageAll bool
}

type campaignPut struct {
	ID   string
	Body map[string]interface{}
}

func (f *campaignFake) server(t *testing.T) *httptest.Server {
	t.Helper()
	endpoint := f.endpoint
	if endpoint == "" {
		endpoint = "campaigns"
	}
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/v3/"+endpoint:
			f.gets = append(f.gets, r.URL.RawQuery)
			q := r.URL.Query()
			offset, _ := strconv.Atoi(q.Get("offset"))
			limit, _ := strconv.Atoi(q.Get("limit"))
			if limit == 0 {
				limit = 50
			}
			net := q.Get("filter[aff_network_id]")
			var filtered []map[string]interface{}
			for _, row := range f.rows {
				if net != "" && strconv.Itoa(row["aff_network_id"].(int)) != net {
					continue
				}
				filtered = append(filtered, row)
			}
			page := []map[string]interface{}{}
			for i := offset; i < len(filtered) && i < offset+limit; i++ {
				page = append(page, filtered[i])
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"data":       page,
				"pagination": map[string]interface{}{"total": len(filtered), "limit": limit, "offset": offset},
			})
		case r.Method == http.MethodPut && strings.HasPrefix(r.URL.Path, "/api/v3/campaigns/"):
			id := strings.TrimPrefix(r.URL.Path, "/api/v3/campaigns/")
			raw, _ := io.ReadAll(r.Body)
			var body map[string]interface{}
			_ = json.Unmarshal(raw, &body)
			f.puts = append(f.puts, campaignPut{ID: id, Body: body})
			if f.failPut[id] {
				w.WriteHeader(500)
				_, _ = w.Write([]byte(`{"message":"server error"}`))
				return
			}
			if f.stageAll {
				w.WriteHeader(202)
				_, _ = w.Write([]byte(`{"data":{"change_id":"chg_00000000000000000000000` + id + `","status":"staged","method":"PUT","path":"/campaigns/` + id + `"}}`))
				return
			}
			_, _ = w.Write([]byte(`{"data":{"aff_campaign_id":` + id + `}}`))
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"not found"}`))
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func newCampaignFake() *campaignFake {
	return &campaignFake{rows: []map[string]interface{}{
		{"aff_campaign_id": 279, "aff_campaign_name": "Darkmoon Realm", "aff_network_id": 32,
			"aff_campaign_url": "https://aanicca.G2AFSE.com/click?pid=2753&offer_id=1683&sub1=[[subid]]"},
		{"aff_campaign_id": 60, "aff_campaign_name": "BoxOfAds", "aff_network_id": 7,
			"aff_campaign_url":   "http://promo.boxofads.com/Affpackage?source=t202",
			"aff_campaign_url_2": "http://promo.boxofads.com/alt"},
		{"aff_campaign_id": 281, "aff_campaign_name": "Star Trek Online!", "aff_network_id": 32,
			"aff_campaign_url":   "https://example.com/keep",
			"aff_campaign_url_3": "https://aanicca.g2afse.com/click?offer_id=1692"},
		{"aff_campaign_id": 5, "aff_campaign_name": "Untouched", "aff_network_id": 32,
			"aff_campaign_url": "https://stargateasi.com/?utm_source=x"},
	}}
}

func setupCampaignFake(t *testing.T, f *campaignFake) {
	t.Helper()
	srv := f.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
}

// feedStdin answers the confirmation prompt with answer.
func feedStdin(t *testing.T, answer string) {
	t.Helper()
	p := filepath.Join(t.TempDir(), "stdin")
	if err := os.WriteFile(p, []byte(answer+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	fh, err := os.Open(p)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { fh.Close() })
	withStdin(t, fh)
}

func decodeChanges(t *testing.T, stdout string) []urlChange {
	t.Helper()
	var resp struct {
		Data []urlChange `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	return resp.Data
}

func TestCampaignListURLContainsSearchesEverySlotAndPage(t *testing.T) {
	f := newCampaignFake()
	// Push the slot-3 match past the first page so paging is exercised.
	for i := 0; i < 120; i++ {
		f.rows = append([]map[string]interface{}{{"aff_campaign_id": 1000 + i, "aff_campaign_name": "filler", "aff_network_id": 1, "aff_campaign_url": "https://filler.example/"}}, f.rows...)
	}
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "list", "--url-contains", "g2afse.COM", "--json")
	if err != nil {
		t.Fatalf("campaign list --url-contains: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	var ids []int
	for _, row := range resp.Data {
		ids = append(ids, int(row["aff_campaign_id"].(float64)))
	}
	if len(ids) != 2 || ids[0] != 279 || ids[1] != 281 {
		t.Fatalf("matched ids = %v, want [279 281] (primary slot, case-insensitive, and slot 3 on page 2)", ids)
	}
	if len(f.gets) < 2 {
		t.Errorf("expected more than one page request, got %v", f.gets)
	}
}

func TestCampaignListURLContainsRejectsPagingFlagsBeforeAnyRequest(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	_, _, err := executeCommand("campaign", "list", "--url-contains", "g2afse", "--limit", "10")
	if err == nil {
		t.Fatal("expected a validation error for --url-contains with --limit")
	}
	if got := exitCodeForError(err); got != ExitValidation {
		t.Errorf("exit code = %d, want %d", got, ExitValidation)
	}
	if !strings.Contains(hintFor(err), "Drop --page/--limit/--offset") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if len(f.gets) != 0 {
		t.Errorf("no campaign request should be made, got %v", f.gets)
	}
}

func TestLandingPageListURLContainsSearchesBothURLFieldsAndEveryPage(t *testing.T) {
	f := &campaignFake{endpoint: "landing-pages"}
	for i := 0; i < 120; i++ {
		f.rows = append(f.rows, map[string]interface{}{"landing_page_id": 1000 + i, "landing_page_url": "https://filler.example/lp"})
	}
	f.rows = append([]map[string]interface{}{
		{"landing_page_id": 7, "landing_page_url": "https://OLD-HOST.example/lp1", "leave_behind_page_url": ""},
		{"landing_page_id": 8, "landing_page_url": "https://keep.example/lp2", "leave_behind_page_url": "https://keep.example/lb"},
	}, f.rows...)
	// Past the first page, matching only on the leave-behind URL.
	f.rows = append(f.rows, map[string]interface{}{"landing_page_id": 9, "landing_page_url": "https://keep.example/lp3", "leave_behind_page_url": "https://old-host.example/lb"})
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("landing-page", "list", "--url-contains", "old-host.EXAMPLE", "--json")
	if err != nil {
		t.Fatalf("landing-page list --url-contains: %v", err)
	}
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	var ids []int
	for _, row := range resp.Data {
		ids = append(ids, int(row["landing_page_id"].(float64)))
	}
	if len(ids) != 2 || ids[0] != 7 || ids[1] != 9 {
		t.Fatalf("matched ids = %v, want [7 9] (landing_page_url case-insensitive, leave_behind_page_url on page 2)", ids)
	}
	if len(f.gets) < 2 {
		t.Errorf("expected more than one page request, got %v", f.gets)
	}
}

func TestReplaceURLDryRunSetTemplateWritesNothing(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse.com",
		"--set", "https://stargateasi.com/?utm_source={slug}&c={id}", "--dry-run", "--json")
	if err != nil {
		t.Fatalf("replace-url --dry-run: %v", err)
	}
	if len(f.puts) != 0 {
		t.Fatalf("dry run wrote: %+v", f.puts)
	}
	got := decodeChanges(t, stdout)
	want := []urlChange{
		{CampaignID: "279", CampaignName: "Darkmoon Realm", Field: "aff_campaign_url",
			OldURL: "https://aanicca.G2AFSE.com/click?pid=2753&offer_id=1683&sub1=[[subid]]",
			NewURL: "https://stargateasi.com/?utm_source=darkmoon-realm&c=279"},
		{CampaignID: "281", CampaignName: "Star Trek Online!", Field: "aff_campaign_url_3",
			OldURL: "https://aanicca.g2afse.com/click?offer_id=1692",
			NewURL: "https://stargateasi.com/?utm_source=star-trek-online&c=281"},
	}
	if len(got) != len(want) {
		t.Fatalf("changes = %+v, want %+v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("change %d = %+v, want %+v", i, got[i], want[i])
		}
	}
}

func TestReplaceURLWithSubstringRespectsSlotAndSendsOnlyChangedFields(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "replace-url", "--match", "http://promo.boxofads.com",
		"--with", "https://promo.boxofads.com", "--slot", "2", "--force", "--json")
	if err != nil {
		t.Fatalf("replace-url --with --slot 2: %v", err)
	}
	if len(f.puts) != 1 || f.puts[0].ID != "60" {
		t.Fatalf("puts = %+v, want one PUT to campaign 60", f.puts)
	}
	body := f.puts[0].Body
	if len(body) != 1 || body["aff_campaign_url_2"] != "https://promo.boxofads.com/alt" {
		t.Errorf("PUT body = %v, want only aff_campaign_url_2 rewritten (slot 1 excluded by --slot 2)", body)
	}
	got := decodeChanges(t, stdout)
	if len(got) != 1 || got[0].Status != "applied" {
		t.Errorf("result = %+v, want one applied change", got)
	}
}

func TestReplaceURLGroupsSlotsIntoOnePutPerCampaign(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	if _, _, err := executeCommand("campaign", "replace-url", "--match", "boxofads.com", "--with", "example.org", "--force"); err != nil {
		t.Fatalf("replace-url: %v", err)
	}
	if len(f.puts) != 1 {
		t.Fatalf("puts = %+v, want a single PUT carrying both slots", f.puts)
	}
	if f.puts[0].Body["aff_campaign_url"] != "http://promo.example.org/Affpackage?source=t202" ||
		f.puts[0].Body["aff_campaign_url_2"] != "http://promo.example.org/alt" {
		t.Errorf("PUT body = %v", f.puts[0].Body)
	}
}

func TestReplaceURLConfirmationGatesTheWrite(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	feedStdin(t, "n")
	if _, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/"); err != nil {
		t.Fatalf("declined replace-url: %v", err)
	}
	if len(f.puts) != 0 {
		t.Fatalf("answering n must write nothing, got %+v", f.puts)
	}

	feedStdin(t, "y")
	if _, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/"); err != nil {
		t.Fatalf("confirmed replace-url: %v", err)
	}
	if len(f.puts) != 2 {
		t.Fatalf("answering y should update 279 and 281, got %+v", f.puts)
	}
}

func TestReplaceURLIdsAndNetworkNarrowTheScope(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/",
		"--aff-network-id", "32", "--ids", "281", "--dry-run", "--json")
	if err != nil {
		t.Fatalf("replace-url: %v", err)
	}
	for _, q := range f.gets {
		if !strings.Contains(q, "filter%5Baff_network_id%5D=32") {
			t.Errorf("campaign request %q is missing the network filter", q)
		}
	}
	got := decodeChanges(t, stdout)
	if len(got) != 1 || got[0].CampaignID != "281" {
		t.Errorf("changes = %+v, want only campaign 281", got)
	}
}

func TestReplaceURLPartialFailureReportsEachOutcome(t *testing.T) {
	f := newCampaignFake()
	f.failPut = map[string]bool{"281": true}
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--force", "--json")
	if err == nil {
		t.Fatal("expected a partial-failure error")
	}
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", got, ExitPartialFailure)
	}
	if !strings.Contains(hintFor(err), "re-run the same command") {
		t.Errorf("hint = %q, want the retry step", hintFor(err))
	}
	status := map[string]string{}
	for _, ch := range decodeChanges(t, stdout) {
		status[ch.CampaignID] = ch.Status
	}
	if status["279"] != "applied" || status["281"] != "failed" {
		t.Errorf("statuses = %v, want 279 applied and 281 failed", status)
	}
}

func TestReplaceURLStagedRecordsProposalsWithoutPrompting(t *testing.T) {
	f := newCampaignFake()
	f.stageAll = true
	setupCampaignFake(t, f)

	// No --force and nothing on stdin: staged writes are proposals, so no prompt.
	stdout, _, err := executeCommand("--staged", "campaign", "replace-url", "--match", "g2afse", "--set", "https://new.example/", "--json")
	if err != nil {
		t.Fatalf("staged replace-url: %v", err)
	}
	if len(f.puts) != 2 {
		t.Fatalf("puts = %+v, want 2 staged PUTs", f.puts)
	}
	for _, ch := range decodeChanges(t, stdout) {
		if ch.Status != "staged" || !strings.HasPrefix(ch.ChangeID, "chg_") {
			t.Errorf("change %+v, want status staged with a chg_ id", ch)
		}
	}
}

func TestReplaceURLNoMatchIsNotAnError(t *testing.T) {
	f := newCampaignFake()
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "replace-url", "--match", "nowhere.invalid", "--set", "https://new.example/", "--force", "--json")
	if err != nil {
		t.Fatalf("replace-url with no matches: %v", err)
	}
	if len(f.puts) != 0 || len(decodeChanges(t, stdout)) != 0 {
		t.Errorf("expected no writes and an empty data array, got puts=%+v stdout=%s", f.puts, stdout)
	}
}

func TestReplaceURLValidatesFlagsBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		name string
		args []string
		hint string
	}{
		{"missing match", []string{"--set", "https://x.example/"}, "--match old-network.com"},
		{"neither with nor set", []string{"--match", "a"}, "--with https://new.example.com"},
		{"both with and set", []string{"--match", "a", "--with", "b", "--set", "https://x.example/"}, "--with https://new.example.com"},
		{"relative set", []string{"--match", "a", "--set", "stargateasi.com/?x={slug}"}, "need a scheme and host"},
		{"bad slot", []string{"--match", "a", "--with", "b", "--slot", "6"}, "Slot 1 is the primary offer URL"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := newCampaignFake()
			setupCampaignFake(t, f)
			_, _, err := executeCommand(append([]string{"campaign", "replace-url"}, tc.args...)...)
			if err == nil {
				t.Fatal("expected a validation error")
			}
			if got := exitCodeForError(err); got != ExitValidation {
				t.Errorf("exit code = %d, want %d", got, ExitValidation)
			}
			if !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", hintFor(err), tc.hint)
			}
			if len(f.gets)+len(f.puts) != 0 {
				t.Errorf("validation must precede any request; gets=%v puts=%+v", f.gets, f.puts)
			}
		})
	}
}

func TestSlugify(t *testing.T) {
	for in, want := range map[string]string{
		"Star Trek Online":                  "star-trek-online",
		"Revioly CPI - US Desktop *CHROME*": "revioly-cpi-us-desktop-chrome",
		"  Start A Career Today! ":          "start-a-career-today",
		"***":                               "",
	} {
		if got := slugify(in); got != want {
			t.Errorf("slugify(%q) = %q, want %q", in, got, want)
		}
	}
	if got := expandURLTemplate("https://x/?s={slug}", "7", "***"); got != "https://x/?s=campaign-7" {
		t.Errorf("empty slug fallback = %q", got)
	}
}
