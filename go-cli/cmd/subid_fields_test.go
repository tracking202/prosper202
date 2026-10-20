package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"testing"

	"p202/internal/api"
)

const subidParametersPage = "documentation/tools--resources/999-common-network-subid-parameters.md"

// offers puts urls in a campaign's slots, in order.
func offers(urls ...string) []offerURL {
	out := make([]offerURL, 0, len(urls))
	for i, u := range urls {
		out = append(out, offerURL{slot: campaignURLFields[i], url: u})
	}
	return out
}

func subidKind(err error) (subidErrorKind, bool) {
	var se *subidError
	if !errors.As(err, &se) {
		return 0, false
	}
	return se.kind, true
}

// networkLinks is one offer link per network on the parameters page that
// names a field, with [[subid]] where the page says.
var networkLinks = []struct {
	network, template, field string
	ambiguousWithoutTemplate bool
}{
	{"ADCELL", "https://t.adcell.com/p/click?promoId=123&slotId=456&subId=[[subid]]", "subId", false},
	{"AdCombo", "https://ad.example/click?offer=12&subacc=[[subid]]", "subacc", false},
	{"Admitad", "https://ad.admitad.com/g/abc123/?subid=[[subid]]&ulp=https%3A%2F%2Fshop.example%2F", "subid", false},
	{"Affise", "https://track.example/click?pid=12&offer_id=34&sub1=[[subid]]", "sub1", false},
	{"Awin", "https://www.awin1.com/cread.php?awinmid=1234&awinaffid=5678&clickref=[[subid]]&ued=https%3A%2F%2Fshop.example%2F", "clickref", false},
	{"CAKE", "https://cake.example/?a=12&c=34&s2=[[subid]]", "s2", false},
	{"CJ Affiliate", "https://www.anrdoezrs.net/click-1234-5678?sid=[[subid]]", "sid", false},
	{"ClickBank", "https://hop.clickbank.net/?affiliate=me&vendor=them&extclid=[[subid]]", "extclid", false},
	{"ClickDealer", "https://cd.example/?a=12&c=34&s2=[[subid]]", "s2", false},
	{"Commission Factory", "https://t.cfjump.com/12/t/34?UniqueId=[[subid]]", "UniqueId", false},
	{"CPAlead", "https://cpalead.example/view.php?id=12&pub=34&subid=[[subid]]", "subid", false},
	{"Everflow", "https://ef.example/ABC/DEF/?sub1=[[subid]]", "sub1", false},
	{"FlexOffers", "https://track.flexlinkspro.com/g.ashx?foid=12&trid=34&fobs=[[subid]]", "fobs", false},
	// sid is HitPath's campaign: only the campaign URL can say c1 is ours.
	{"HitPath", "https://hp.example/rd/r.php?sid=555&pub=12&c1=[[subid]]", "c1", true},
	{"impact.com", "https://brand.sjv.io/c/12/34/56?subId1=[[subid]]", "subId1", false},
	{"MaxBounty", "https://mb.example/?a=12&c=34&s2=[[subid]]", "s2", false},
	{"Offer18", "https://o18.example/c?o=12&m=34&a=56&aff_sub1=[[subid]]", "aff_sub1", false},
	{"Partnerize", "https://prf.hn/click/camref:1101l3/pubref:[[subid]]/destination:https%3A%2F%2Fshop.example%2F", "/pubref:", false},
	{"Post Affiliate Pro", "https://pap.example/scripts/click.php?a_aid=12&a_bid=34&data1=[[subid]]", "data1", false},
	{"Rakuten Advertising", "https://click.linksynergy.com/deeplink?id=AbC&mid=12&murl=https%3A%2F%2Fshop.example%2F&u1=[[subid]]", "u1", false},
	{"ShareASale (legacy)", "https://shareasale.com/r.cfm?b=12&u=34&m=56&afftrack=[[subid]]", "afftrack", false},
	{"Trackier", "https://trk.example/click?campaign_id=12&pub_id=34&p1=[[subid]]", "p1", false},
	{"Tradedoubler", "https://clk.tradedoubler.com/click?p=12&a=34&g=56&epi=[[subid]]", "epi", false},
	{"TUNE / HasOffers", "https://ho.example/aff_c?offer_id=12&aff_id=34&aff_sub=[[subid]]", "aff_sub", false},
	{"Webgains", "https://track.webgains.com/click.html?wgcampaignid=12&wgprogramid=34&clickref=[[subid]]", "clickref", false},
}

func TestSubidIsReadFromEachNetworksField(t *testing.T) {
	for _, tc := range networkLinks {
		loc := strings.Replace(tc.template, "[[subid]]", "4821", 1)

		m, err := subidFromCampaign(loc, offers(tc.template))
		if err != nil || m.ID != "4821" || m.Field != tc.field || !m.FromCampaign {
			t.Errorf("%s from its campaign URL: got (%+v, %v), want 4821 from %s", tc.network, m, err, tc.field)
		}

		m, err = subidFromNetworkFields(loc)
		if m.FromCampaign {
			t.Errorf("%s from the network fields says it read a campaign URL", tc.network)
		}
		if tc.ambiguousWithoutTemplate {
			if kind, ok := subidKind(err); !ok || kind != subidAmbiguous {
				t.Errorf("%s from the network fields: got (%+v, %v), want an ambiguity refusal", tc.network, m, err)
			}
			continue
		}
		if err != nil || m.ID != "4821" || !strings.Contains(","+m.Field+",", ","+tc.field+",") {
			t.Errorf("%s from the network fields: got (%+v, %v), want 4821 from %s", tc.network, m, err, tc.field)
		}
	}
}

func TestSubidFromCampaignReadsOnlyTheFieldItNames(t *testing.T) {
	cases := []struct {
		name, loc, field string
		urls             []offerURL
	}{
		{"CJ's sid, HitPath's c1 left alone", "https://www.anrdoezrs.net/click-1?sid=4821&c1=999", "sid",
			offers("https://www.anrdoezrs.net/click-1?sid=[[subid]]&c1=999")},
		{"rotation: the second slot's host", "https://www.awin1.com/cread.php?awinmid=1&awinaffid=2&clickref=4821", "clickref",
			offers("https://a.example/?s=[[subid]]", "https://www.awin1.com/cread.php?awinmid=1&awinaffid=2&clickref=[[subid]]")},
		{"rotation: same field name, other host and path", "https://hp.example/r.php?sid=555&c1=4821", "c1",
			offers("https://www.anrdoezrs.net/click-1?sid=[[subid]]", "https://hp.example/r.php?sid=555&c1=[[subid]]")},
		{"rotation: same path, other host", "https://hp.example/r.php?sid=555&c1=4821", "c1",
			offers("https://cj.example/r.php?sid=[[subid]]", "https://hp.example/r.php?sid=555&c1=[[subid]]")},
		{"rotation: same host, other path", "https://n.example/b?sid=555&c1=4821", "c1",
			offers("https://n.example/a?sid=[[subid]]", "https://n.example/b?sid=555&c1=[[subid]]")},
		{"the bare field beats the destination", "https://www.awin1.com/cread.php?awinmid=1&ued=https%3A%2F%2Fs.example%2F%3Fref%3D4821&clickref=4821", "clickref",
			offers("https://www.awin1.com/cread.php?awinmid=1&ued=https%3A%2F%2Fs.example%2F%3Fref%3D[[subid]]&clickref=[[subid]]")},
		{"a bare path segment", "https://go.example/r/4821", "path segment 2",
			offers("https://go.example/r/[[subid]]")},
		{"the token in any case", "https://hp.example/r.php?sid=555&c1=4821", "c1",
			offers("https://hp.example/r.php?sid=555&c1=[[SubID]]")},
	}
	for _, tc := range cases {
		m, err := subidFromCampaign(tc.loc, tc.urls)
		if err != nil || m.ID != "4821" || m.Field != tc.field {
			t.Errorf("%s: got (%+v, %v), want 4821 from %s", tc.name, m, err, tc.field)
		}
	}
}

func TestSubidFromCampaignNeverGuesses(t *testing.T) {
	cases := []struct {
		name, loc string
		urls      []offerURL
		kind      subidErrorKind
	}{
		{"no [[subid]] in the offer URL, HitPath's sid in the redirect", "https://hp.example/rd/r.php?sid=555&pub=12",
			offers("https://hp.example/rd/r.php?sid=555&pub=12"), subidNoToken},
		{"[[subid]] only in the fragment", "https://hp.example/r.php?sid=555#c1=4821",
			offers("https://hp.example/r.php?sid=555#c1=[[subid]]"), subidNoToken},
		{"the redirect went elsewhere (cloaking)", "https://cloak.example/go.php?sid=4821",
			offers("https://www.anrdoezrs.net/click-1?sid=[[subid]]"), subidMissing},
		{"the field is not in the redirect", "https://www.anrdoezrs.net/click-1?pid=4821",
			offers("https://www.anrdoezrs.net/click-1?sid=[[subid]]"), subidMissing},
		{"text after the id", "https://n.example/?sid=4821-id",
			offers("https://n.example/?sid=[[subid]]-id"), subidNotBare},
		{"text before the id in a path field", "https://prf.hn/click/camref:1/pubref:x4821/destination:https%3A%2F%2F",
			offers("https://prf.hn/click/camref:1/pubref:x[[subid]]/destination:https%3A%2F%2F"), subidNotBare},
		{"only in the destination", "https://www.awin1.com/cread.php?ued=https%3A%2F%2Fs.example%2F%3Fr%3D4821",
			offers("https://www.awin1.com/cread.php?ued=https%3A%2F%2Fs.example%2F%3Fr%3D[[subid]]"), subidNotBare},
		{"no click recorded", "https://www.awin1.com/cread.php?awinmid=1&clickref=p202",
			offers("https://www.awin1.com/cread.php?awinmid=1&clickref=[[subid]]"), subidNoClick},
		{"empty redirect", "", offers("https://n.example/?s=[[subid]]"), subidMissing},
	}
	for _, tc := range cases {
		m, err := subidFromCampaign(tc.loc, tc.urls)
		if kind, ok := subidKind(err); !ok || kind != tc.kind {
			t.Errorf("%s: got (%+v, %v), want refusal kind %d", tc.name, m, err, tc.kind)
		}
	}
}

func TestSubidFromNetworkFieldsMustAgree(t *testing.T) {
	if m, err := subidFromNetworkFields("https://n.example/?s=4821&sub1=4821"); err != nil || m.ID != "4821" || m.Field != "s,sub1" {
		t.Errorf("agreeing fields: got (%+v, %v)", m, err)
	}
	if _, err := subidFromNetworkFields("https://n.example/?s=4821&sub1=4822"); err == nil {
		t.Error("disagreeing fields were not refused")
	} else if kind, _ := subidKind(err); kind != subidAmbiguous {
		t.Errorf("disagreeing fields: %v", err)
	}
	if _, err := subidFromNetworkFields("https://n.example/?clickref=p202"); err == nil {
		t.Error("p202 was not refused")
	} else if kind, _ := subidKind(err); kind != subidNoClick {
		t.Errorf("p202: %v", err)
	}
	for _, loc := range []string{
		"https://n.example/?offer=12&aff_id=34",
		"https://n.example/?s=0123",
		"https://n.example/?s=12abc",
		"https://n.example/?S=4821",
		"",
	} {
		if kind, ok := subidKind(func() error { _, err := subidFromNetworkFields(loc); return err }()); !ok || kind != subidMissing {
			t.Errorf("%q: want no subid", loc)
		}
	}
}

func TestSubidErrorsAreCategorized(t *testing.T) {
	_, notBare := subidFromCampaign("https://n.example/?sid=4821-id", offers("https://a.example/?s=[[subid]]", "https://n.example/?sid=[[subid]]-id"))
	if got := api.ErrorCategory(notBare); got != "validation" || exitCodeForError(notBare) != ExitValidation {
		t.Errorf("not bare: category %q exit %d, want validation/1", got, exitCodeForError(notBare))
	}
	if hint := subidHint(notBare, trackerOffers{campaignID: "3", decides: true}); !strings.Contains(hint, "campaign update 3 --aff-campaign-url-2 ") {
		t.Errorf("not-bare hint %q does not name slot 2's flag", hint)
	}
	_, noClick := subidFromCampaign("https://n.example/?s=p202", offers("https://n.example/?s=[[subid]]"))
	if got := api.ErrorCategory(noClick); got != "server" || exitCodeForError(noClick) != ExitServer {
		t.Errorf("no click: category %q exit %d, want server/4", got, exitCodeForError(noClick))
	}
	if hint := subidHint(noClick, trackerOffers{decides: true}); !strings.Contains(hint, "p202 system health") {
		t.Errorf("no-click hint %q does not send to system health", hint)
	}
}

// docFields reads the parameters page's table: each row's network and the
// fields its Field and Also columns name, ranges (`sub2`–`sub8`) expanded;
// notes holds what the Notes column names the same way.
func docFields(t *testing.T) (rows map[string][]string, notes map[string]bool) {
	t.Helper()
	data, err := os.ReadFile(repoPath("documentation", "tools--resources", "999-common-network-subid-parameters.md"))
	if err != nil {
		t.Fatalf("reading %s: %v", subidParametersPage, err)
	}
	tick := regexp.MustCompile("`([^`]+)`")
	rng := regexp.MustCompile("`([A-Za-z_]+?)([0-9]+)`–`([A-Za-z_]+?)([0-9]+)`")
	names := func(cell string) []string {
		var out []string
		for _, m := range rng.FindAllStringSubmatch(cell, -1) {
			if m[1] != m[3] {
				t.Fatalf("range %s does not keep one name", m[0])
			}
			lo, _ := strconv.Atoi(m[2])
			hi, _ := strconv.Atoi(m[4])
			for i := lo; i <= hi; i++ {
				out = append(out, m[1]+strconv.Itoa(i))
			}
			cell = strings.Replace(cell, m[0], "", 1)
		}
		for _, m := range tick.FindAllStringSubmatch(cell, -1) {
			out = append(out, strings.TrimSuffix(m[1], "="))
		}
		return out
	}
	rows = map[string][]string{}
	notes = map[string]bool{}
	for i, line := range strings.Split(string(data), "\n") {
		if !strings.HasPrefix(line, "|") {
			continue
		}
		cols := strings.Split(line, "|")
		if len(cols) != 6 {
			t.Fatalf("%s:%d has %d columns, want 4: a row this cannot read would be skipped", subidParametersPage, i+1, len(cols)-2)
		}
		network := strings.TrimSpace(cols[1])
		if network == "Network or platform" || strings.HasPrefix(network, "---") {
			continue
		}
		rows[network] = append(names(cols[2]), names(cols[3])...)
		for _, n := range names(cols[4]) {
			notes[n] = true
		}
	}
	if len(rows) < 29 {
		t.Fatalf("read %d rows from %s, want the page's 29", len(rows), subidParametersPage)
	}
	return rows, notes
}

func TestNetworkSubidFieldsMatchTheParametersPage(t *testing.T) {
	// Named on the page but not a place for [[subid]].
	notSubid := map[string]string{"tag": "Amazon's tag is the account's tracking ID"}
	// Read here though no Field or Also column names them; each but s must be
	// in a row's Notes.
	extra := map[string]string{
		"s":     "Prosper202's own example offers use ?s=[[subid]]",
		"tid":   "ClickBank's older field",
		"sub9":  "Everflow's optional sub6–sub10",
		"sub10": "Everflow's optional sub6–sub10",
	}

	known := map[string]bool{}
	for _, f := range networkSubIDQueryFields {
		if known[f] {
			t.Errorf("%s is listed twice in networkSubIDQueryFields", f)
		}
		known[f] = true
	}
	for _, f := range networkSubIDPathFields {
		known["/"+f] = true
	}

	examples := map[string]bool{}
	for _, tc := range networkLinks {
		examples[tc.network] = true
	}

	rows, notes := docFields(t)
	onPage := map[string]bool{}
	for network, fields := range rows {
		named := false
		for _, f := range fields {
			onPage[f] = true
			if _, skip := notSubid[f]; skip {
				continue
			}
			named = true
			if !known[f] {
				t.Errorf("%s's %s is on the parameters page but not in networkSubIDQueryFields/PathFields", network, f)
			}
		}
		if named && !examples[network] {
			t.Errorf("%s names a SubID field on the page but has no networkLinks example", network)
		}
		if !named && examples[network] {
			t.Errorf("%s names no SubID field on the page but has a networkLinks example", network)
		}
	}
	for f := range known {
		if onPage[f] {
			continue
		}
		if extra[f] == "" {
			t.Errorf("%s is read as a SubID field but the parameters page does not name it", f)
		} else if f != "s" && !notes[f] {
			t.Errorf("%s is read for %s, which no Notes cell on the parameters page names any more", f, extra[f])
		}
	}
}

// simulateFake answers the API and tracking endpoints conversion simulate calls.
type simulateFake struct {
	tracker        string // trackers/7's data; default: campaign 3, no rotator
	campaignStatus int
	campaign       map[string]interface{}
	location       string

	mu       sync.Mutex
	postback []string
}

func (f *simulateFake) server(t *testing.T) {
	t.Helper()
	var base string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v3/trackers/7/url":
			fmt.Fprintf(w, `{"data":{"direct_url":%q}}`, base+"/tracking202/redirect/dl.php?t202id=abc")
		case "/api/v3/trackers/7":
			tr := f.tracker
			if tr == "" {
				tr = `{"tracker_id":7,"aff_campaign_id":3,"rotator_id":0,"landing_page_id":0}`
			}
			fmt.Fprintf(w, `{"data":%s}`, tr)
		case "/api/v3/campaigns/3":
			if f.campaignStatus != 0 {
				w.WriteHeader(f.campaignStatus)
				fmt.Fprint(w, `{"message":"Insufficient scope"}`)
				return
			}
			body, _ := json.Marshal(map[string]interface{}{"data": f.campaign})
			w.Write(body)
		case "/tracking202/redirect/dl.php":
			w.Header().Set("Location", f.location)
			w.WriteHeader(http.StatusFound)
		case "/tracking202/static/gpb.php":
			f.mu.Lock()
			f.postback = append(f.postback, r.URL.Query().Get("subid"))
			f.mu.Unlock()
			fmt.Fprint(w, `{"status":"ok"}`)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	base = srv.URL
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
}

func simulateRow(t *testing.T, stdout string) map[string]interface{} {
	t.Helper()
	var resp struct {
		Data []map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil || len(resp.Data) != 1 {
		t.Fatalf("stdout %q: %v", stdout, err)
	}
	return resp.Data[0]
}

func TestConversionSimulatePostsTheCampaignsSubidField(t *testing.T) {
	f := &simulateFake{
		campaign: map[string]interface{}{"aff_campaign_url": "https://hp.example/rd/r.php?sid=555&pub=12&c1=[[subid]]", "aff_campaign_rotate": 0},
		location: "https://hp.example/rd/r.php?sid=555&pub=12&c1=4821",
	}
	f.server(t)

	stdout, stderr, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json")
	if err != nil {
		t.Fatalf("simulate: %v\nstderr: %s", err, stderr)
	}
	if len(f.postback) != 1 || f.postback[0] != "4821" {
		t.Fatalf("postback subids %v, want [4821] (c1, not HitPath's sid 555)", f.postback)
	}
	if row := simulateRow(t, stdout); row["subid_field"] != "c1" || row["subid_source"] != "campaign URL" || row["result"] != "PASS" {
		t.Errorf("row %v, want c1 from the campaign URL and PASS", row)
	}
}

func TestConversionSimulateReadsAwinClickref(t *testing.T) {
	f := &simulateFake{
		campaign: map[string]interface{}{"aff_campaign_url": "https://www.awin1.com/cread.php?awinmid=1234&awinaffid=5678&clickref=[[subid]]&ued=https%3A%2F%2Fshop.example%2F"},
		location: "https://www.awin1.com/cread.php?awinmid=1234&awinaffid=5678&clickref=4821&ued=https%3A%2F%2Fshop.example%2F",
	}
	f.server(t)

	if _, stderr, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json"); err != nil {
		t.Fatalf("simulate: %v\nstderr: %s", err, stderr)
	}
	if len(f.postback) != 1 || f.postback[0] != "4821" {
		t.Fatalf("postback subids %v, want [4821]", f.postback)
	}
}

func TestConversionSimulateRefusesAnOfferURLWithoutSubid(t *testing.T) {
	f := &simulateFake{
		campaign: map[string]interface{}{"aff_campaign_url": "https://hp.example/rd/r.php?sid=555&pub=12"},
		location: "https://hp.example/rd/r.php?sid=555&pub=12",
	}
	f.server(t)

	_, _, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json")
	if err == nil {
		t.Fatal("simulate posted HitPath's sid for an offer URL with no [[subid]]")
	}
	if len(f.postback) != 0 {
		t.Errorf("postback fired with %v", f.postback)
	}
	if hint := hintFor(err); !strings.Contains(hint, "campaign update 3 --aff-campaign-url ") {
		t.Errorf("hint %q does not name the update", hint)
	}
}

func TestConversionSimulateUsesOnlySlotOneWithoutRotation(t *testing.T) {
	f := &simulateFake{
		campaign: map[string]interface{}{
			"aff_campaign_url":    "https://www.anrdoezrs.net/click-1?sid=[[subid]]",
			"aff_campaign_url_2":  "https://hp.example/r.php?sid=555&c1=[[subid]]",
			"aff_campaign_rotate": 0,
		},
		location: "https://hp.example/r.php?sid=555&c1=4821",
	}
	f.server(t)

	if _, _, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json"); err == nil {
		t.Fatal("simulate read slot 2 of a campaign that does not rotate")
	}
	if len(f.postback) != 0 {
		t.Errorf("postback fired with %v", f.postback)
	}

	f.campaign["aff_campaign_rotate"] = 1
	f.server(t)
	if _, stderr, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json"); err != nil {
		t.Fatalf("rotating campaign: %v\nstderr: %s", err, stderr)
	}
	if len(f.postback) != 1 || f.postback[0] != "4821" {
		t.Fatalf("postback subids %v, want [4821]", f.postback)
	}
}

func TestConversionSimulateRedirectorDoesNotTrustItsCampaign(t *testing.T) {
	f := &simulateFake{
		tracker:  `{"tracker_id":7,"aff_campaign_id":3,"rotator_id":9,"landing_page_id":0}`,
		campaign: map[string]interface{}{"aff_campaign_url": "https://hp.example/r.php?sid=[[subid]]"},
		location: "https://hp.example/r.php?sid=555&c1=4821",
	}
	f.server(t)

	_, _, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json")
	if err == nil {
		t.Fatal("simulate read the redirector tracker's campaign as its destination")
	}
	if len(f.postback) != 0 {
		t.Errorf("postback fired with %v", f.postback)
	}
	if hint := hintFor(err); !strings.Contains(hint, "destination campaign") {
		t.Errorf("hint %q does not say to simulate the destination campaign", hint)
	}
}

func TestConversionSimulateSaysWhenTheCampaignCannotBeRead(t *testing.T) {
	f := &simulateFake{
		campaignStatus: http.StatusForbidden,
		location:       "https://hp.example/rd/r.php?sid=555&pub=12&c1=4821",
	}
	f.server(t)

	_, _, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json")
	if err == nil {
		t.Fatal("simulate guessed between sid=555 and c1=4821")
	}
	if len(f.postback) != 0 {
		t.Errorf("postback fired with %v", f.postback)
	}
	if got := api.ErrorCategory(err); got != "validation" || exitCodeForError(err) != ExitValidation {
		t.Errorf("category %q exit %d, want validation/1", got, exitCodeForError(err))
	}
	if hint := hintFor(err); !strings.Contains(hint, "could not be read") {
		t.Errorf("hint %q does not say the campaign URL could not be read", hint)
	}

	f.location = "https://www.awin1.com/cread.php?awinmid=1&clickref=4821"
	f.server(t)
	stdout, stderr, err := executeCommand("conversion", "simulate", "--tracker", "7", "--json")
	if err != nil {
		t.Fatalf("one network field: %v\nstderr: %s", err, stderr)
	}
	if row := simulateRow(t, stdout); !strings.Contains(fmt.Sprint(row["subid_source"]), "campaign 3 could not be read") {
		t.Errorf("source %v does not say why the network fields were read", row["subid_source"])
	}
}
