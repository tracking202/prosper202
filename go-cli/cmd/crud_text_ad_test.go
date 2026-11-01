package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

var textAdCopy = []string{"--text-ad-name", "N", "--text-ad-headline", "H", "--text-ad-description", "D", "--text-ad-display-url", "example.com"}

// textAdServer answers GET text-ads/5 with stored (nil: 404) and any write
// with 201, recording every request but the handshake.
func textAdServer(t *testing.T, stored map[string]interface{}) *[]goalRequest {
	t.Helper()
	var seen []goalRequest
	srv := httptest.NewServer(withCapabilities(func(w http.ResponseWriter, r *http.Request) {
		req := goalRequest{Method: r.Method, Path: r.URL.Path}
		if data, _ := io.ReadAll(r.Body); len(data) > 0 {
			if err := json.Unmarshal(data, &req.Body); err != nil {
				t.Errorf("request body is not a JSON object: %s", data)
			}
		}
		seen = append(seen, req)
		if r.Method == http.MethodGet {
			if stored == nil {
				w.WriteHeader(404)
				_, _ = w.Write([]byte(`{"error":true,"message":"Not found"}`))
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"data": stored})
			return
		}
		w.WriteHeader(201)
		_, _ = w.Write([]byte(`{"data":{"text_ad_id":5}}`))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return &seen
}

// Setup › Text Ads asks for the campaign of a campaign's ad and the page of
// an advanced landing page's ad. The API takes 0 for both, and Get Links
// offers an ad only on its campaign's or page's links, so an ad sent
// without its link was created where nothing could use it. Refused before
// any request, with no config at all.
func TestTextAdCreateNeedsTheLinkItsTypeNames(t *testing.T) {
	setTestHome(t, t.TempDir())
	for _, tc := range []struct {
		flags      []string
		want, hint string
	}{
		{nil, "needs --aff-campaign-id", "p202 campaign list"},
		{[]string{"--aff-campaign-id", "0"}, "needs --aff-campaign-id", "p202 campaign list"},
		{[]string{"--aff-campaign-id", " 00"}, "needs --aff-campaign-id", "p202 campaign list"},
		{[]string{"--text-ad-type", "0", "--landing-page-id", "7"}, "needs --aff-campaign-id", "p202 campaign list"},
		{[]string{"--text-ad-type", "1"}, "needs --landing-page-id", "p202 landing-page list"},
		{[]string{"--text-ad-type", "1", "--aff-campaign-id", "3"}, "needs --landing-page-id", "p202 landing-page list"},
		{[]string{"--text-ad-type", "1", "--landing-page-id", "0"}, "needs --landing-page-id", "p202 landing-page list"},
	} {
		args := append(append([]string{"text-ad", "create"}, textAdCopy...), tc.flags...)
		_, _, err := executeCommand(args...)
		if err == nil || !strings.Contains(err.Error(), tc.want) || exitCodeForError(err) != 1 {
			t.Errorf("%v: err = %v (exit %d), want a validation error saying %q", tc.flags, err, exitCodeForError(err), tc.want)
			continue
		}
		if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
			t.Errorf("%v: hint = %q, want it to name %s", tc.flags, hint, tc.hint)
		}
	}
	_, _, err := executeCommand(append(append([]string{"text-ad", "create"}, textAdCopy...), "--text-ad-type", "2", "--aff-campaign-id", "3")...)
	if err == nil || !strings.Contains(err.Error(), "--text-ad-type") || exitCodeForError(err) != 1 {
		t.Errorf("--text-ad-type 2: err = %v, want the type refused by name", err)
	}
}

func TestTextAdCreateSendsTheAdWithItsLink(t *testing.T) {
	for _, tc := range []struct {
		flags []string
		want  map[string]interface{}
	}{
		{[]string{"--aff-campaign-id", "3"}, map[string]interface{}{"aff_campaign_id": "3"}},
		{[]string{"--text-ad-type", "1", "--landing-page-id", "7"}, map[string]interface{}{"text_ad_type": "1", "landing_page_id": "7"}},
	} {
		seen := textAdServer(t, nil)
		args := append(append([]string{"text-ad", "create"}, textAdCopy...), tc.flags...)
		if _, _, err := executeCommand(args...); err != nil {
			t.Fatalf("%v: %v", tc.flags, err)
		}
		if len(*seen) != 1 || (*seen)[0].Method != "POST" || !strings.HasSuffix((*seen)[0].Path, "/text-ads") {
			t.Fatalf("%v: requests = %+v, want one POST /text-ads", tc.flags, *seen)
		}
		for k, v := range tc.want {
			if got := (*seen)[0].Body[k]; got != v {
				t.Errorf("%v: body %s = %v, want %v", tc.flags, k, got, v)
			}
		}
		if (*seen)[0].Body["text_ad_headline"] != "H" {
			t.Errorf("%v: body = %v", tc.flags, (*seen)[0].Body)
		}
	}
}

// An update is held to the same rule over the ad as it will be: the body's
// fields over the stored ad's, which is read only when the body sets the
// type or a link. An edit of the copy alone is sent as it is.
func TestTextAdUpdateChecksTheLinkAgainstTheStoredAd(t *testing.T) {
	campaignAd := map[string]interface{}{"text_ad_id": 5, "text_ad_type": 0, "aff_campaign_id": 3, "landing_page_id": 0}
	for _, tc := range []struct {
		name    string
		stored  map[string]interface{}
		flags   []string
		methods string // the requests made, in order
		want    string // a refusal; "" for a PUT
	}{
		{"copy only", campaignAd, []string{"--text-ad-headline", "New"}, "PUT", ""},
		{"type and its link", campaignAd, []string{"--text-ad-type", "1", "--landing-page-id", "7"}, "PUT", ""},
		{"another campaign", campaignAd, []string{"--aff-campaign-id", "4"}, "GET PUT", ""},
		{"a page on a campaign's ad", campaignAd, []string{"--landing-page-id", "7"}, "GET PUT", ""},
		{"type without its link", campaignAd, []string{"--text-ad-type", "1"}, "GET", "needs --landing-page-id"},
		{"campaign taken away", campaignAd, []string{"--aff-campaign-id", "0"}, "GET", "needs --aff-campaign-id"},
		{"page taken from an advanced page's ad", map[string]interface{}{"text_ad_type": "1", "aff_campaign_id": "0", "landing_page_id": "7"}, []string{"--landing-page-id", "0"}, "GET", "needs --landing-page-id"},
		{"back to a campaign's ad with no campaign", map[string]interface{}{"text_ad_type": 1, "aff_campaign_id": 0, "landing_page_id": 7}, []string{"--text-ad-type", "0"}, "GET", "needs --aff-campaign-id"},
		{"an answer without the type", map[string]interface{}{"aff_campaign_id": 3}, []string{"--aff-campaign-id", "4"}, "GET", "has no text_ad_type"},
		{"no such ad", nil, []string{"--aff-campaign-id", "4"}, "GET", "404"},
	} {
		seen := textAdServer(t, tc.stored)
		_, _, err := executeCommand(append([]string{"text-ad", "update", "5"}, tc.flags...)...)
		var methods []string
		for _, r := range *seen {
			methods = append(methods, r.Method)
		}
		if got := strings.Join(methods, " "); got != tc.methods {
			t.Errorf("%s: requests %q, want %q", tc.name, got, tc.methods)
		}
		if tc.want == "" {
			if err != nil {
				t.Errorf("%s: %v", tc.name, err)
			}
			continue
		}
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%s: err = %v, want %q", tc.name, err, tc.want)
		}
	}
}
