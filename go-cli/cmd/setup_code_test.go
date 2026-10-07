package cmd

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

// setupServer answers the Setup endpoints with fixed bodies and records every
// request the command makes (capabilities and version probes aside).
type setupRequest struct {
	Method string
	Path   string
	Query  url.Values
	Body   map[string]interface{}
}

func newSetupServer(t *testing.T, features string, answer func(r *http.Request) (int, string)) (*httptest.Server, *[]setupRequest) {
	t.Helper()
	var seen []setupRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/capabilities") {
			_, _ = w.Write([]byte(`{"data":{"features":{` + features + `}}}`))
			return
		}
		if strings.HasSuffix(r.URL.Path, "/versions") {
			_, _ = w.Write([]byte(`{"data":{"current":"v3","supported":["v3"]}}`))
			return
		}
		req := setupRequest{Method: r.Method, Path: r.URL.Path, Query: r.URL.Query()}
		if body, _ := io.ReadAll(r.Body); len(body) > 0 {
			_ = json.Unmarshal(body, &req.Body)
		}
		seen = append(seen, req)
		status, body := answer(r)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key")
	return srv, &seen
}

const setupFeatures = `"setup_section":true,"delete_dry_run":true,"staged_writes":true,"create_idempotency":true`

const simpleCodeAnswer = `{"data":{"landing_page_id":12,"landing_page_id_public":6122,"landing_page_type":"simple",
"landing_page_nickname":"Spring","landing_page_url":"https://lp.example/spring","base_url":"//t.example/",
"loader":"<script>load(\"//t.example/tracking202/static/landing.php?lpip=6122\")</script>",
"aff_campaign_id":3,"outbound_link":"//t.example/tracking202/redirect/go.php?lpip=6122",
"outbound_php":"<?php header('location: //t.example/tracking202/redirect/lp.php?lpip=6122'); ?>",
"outbound_javascript":"<html>window.location='//t.example/tracking202/redirect/lp.php?lpip=6122'</html>",
"segments":{"t202Country":"Visitor's Country","t202City":"Visitor's City","t202kw":"Value passed in t202kw"}}}`

func TestLandingPageCodePrintsThePagesSnippets(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, simpleCodeAnswer })

	stdout, _, err := executeCommand("landing-page", "code", "12")
	if err != nil {
		t.Fatalf("landing-page code: %v", err)
	}
	if len(*seen) != 1 || (*seen)[0].Path != "/api/v3/landing-pages/12/code" || len((*seen)[0].Query) != 0 {
		t.Fatalf("requests = %+v, want one GET /landing-pages/12/code with no query", *seen)
	}
	for _, want := range []string{
		`Landing page 12 "Spring" (simple): https://lp.example/spring`,
		`landing.php?lpip=6122`,
		"Option 1: outbound redirect link",
		"//t.example/tracking202/redirect/go.php?lpip=6122",
		"Option 2: outbound PHP redirect",
		"Option 3: outbound JavaScript redirect",
	} {
		if !strings.Contains(stdout, want) {
			t.Errorf("output lacks %q:\n%s", want, stdout)
		}
	}
	// The page's order, not the alphabet's: the visitor's country comes first.
	if c, k := strings.Index(stdout, "t202Country"), strings.Index(stdout, "t202kw"); c < 0 || k < 0 || strings.Index(stdout[c+1:], "t202City")+c+1 > k {
		t.Errorf("segments are not in the order the server sent them:\n%s", stdout)
	}

	stdout, _, err = executeCommand("landing-page", "code", "12", "--json")
	if err != nil {
		t.Fatalf("landing-page code --json: %v", err)
	}
	var got map[string]map[string]interface{}
	if err := json.Unmarshal([]byte(stdout), &got); err != nil {
		t.Fatalf("--json output is not JSON: %v\n%s", err, stdout)
	}
	if got["data"]["outbound_php"] != "<?php header('location: //t.example/tracking202/redirect/lp.php?lpip=6122'); ?>" {
		t.Errorf("--json does not carry the snippets as sent: %v", got["data"])
	}
}

func TestLandingPageCodeSendsTheOffersInOrder(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		return 200, `{"data":{"landing_page_id":14,"landing_page_type":"advanced","loader":"<script></script>","offers":[]}}`
	})
	if _, _, err := executeCommand("landing-page", "code", "14", "--offer", "rotator:2", "--offer", "campaign:3,campaign:5", "--json"); err != nil {
		t.Fatalf("landing-page code with offers: %v", err)
	}
	if got := (*seen)[0].Query.Get("offers"); got != "rotator:2,campaign:3,campaign:5" {
		t.Errorf("offers = %q, want the flags' order", got)
	}
}

func TestLandingPageCodeRefusesAMalformedOfferBeforeAnyRequest(t *testing.T) {
	for _, offer := range []string{"lp:3", "campaign:0", "campaign:", "campaign:1e3", "Campaign:3", "campaign: 3"} {
		_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, simpleCodeAnswer })
		_, _, err := executeCommand("landing-page", "code", "14", "--offer", offer)
		if err == nil {
			t.Fatalf("--offer %q was accepted", offer)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("--offer %q: exit %d, want %d", offer, code, ExitValidation)
		}
		if hint := hintFor(err); !strings.Contains(hint, "p202 campaign list") || !strings.Contains(hint, "p202 rotator list") {
			t.Errorf("--offer %q: hint %q does not name the lists to read ids from", offer, hint)
		}
		if len(*seen) != 0 {
			t.Errorf("--offer %q: %d requests were made before the refusal", offer, len(*seen))
		}
	}
}

func TestLandingPageCodeTriesThePublicIdTheCodeCarries(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) {
		switch {
		case r.URL.Path == "/api/v3/landing-pages/6122/code":
			return 404, `{"error":true,"message":"Landing page 6122 not found","status":404}`
		case r.URL.Path == "/api/v3/landing-pages" && r.URL.Query().Get("filter[landing_page_id_public]") == "6122":
			return 200, `{"data":[{"landing_page_id":12,"landing_page_id_public":6122}]}`
		case r.URL.Path == "/api/v3/landing-pages/12/code":
			return 200, simpleCodeAnswer
		}
		return 500, `{"error":true,"message":"unexpected"}`
	})
	_, stderr, err := executeCommand("landing-page", "code", "6122")
	if err != nil {
		t.Fatalf("landing-page code by public id: %v", err)
	}
	if last := (*seen)[len(*seen)-1]; last.Path != "/api/v3/landing-pages/12/code" {
		t.Errorf("last request = %s, want the internal id's code", last.Path)
	}
	if !strings.Contains(stderr, "No landing page has id 6122; showing landing page 12") {
		t.Errorf("the switch to the public id is not said on stderr:\n%s", stderr)
	}
}

func TestSetupErrorsNameTheNextStep(t *testing.T) {
	cases := []struct {
		name     string
		features string
		status   int
		body     string
		args     []string
		exit     int
		hint     string
	}{
		{"an old server", `"delete_dry_run":true`, 404, `{"error":true,"message":"Not found","status":404}`,
			[]string{"landing-page", "code", "12"}, ExitValidation, "no Setup API (capabilities features.setup_section)"},
		{"an old server's conversion route", `"delete_dry_run":true`, 404, `{"error":true,"message":"Conversion not found","status":404}`,
			[]string{"conversion", "postback-url"}, ExitValidation, "Setup > Postback / Pixel"},
		{"a page that is not yours", setupFeatures, 404, `{"error":true,"message":"Landing page 12 not found","status":404}`,
			[]string{"landing-page", "code", "12"}, ExitValidation, "`p202 landing-page list`"},
		{"a pixel of another account", setupFeatures, 404, `{"error":true,"message":"Pixel 9 not found on traffic source account 4","status":404}`,
			[]string{"ppc-account", "pixel", "update", "4", "9", "--code", "https://x.example/"}, ExitValidation, "`p202 ppc-account pixel list 4`"},
		{"an account that is not yours", setupFeatures, 404, `{"error":true,"message":"Traffic source account 99 not found","status":404}`,
			[]string{"ppc-account", "pixel", "list", "99"}, ExitValidation, "`p202 ppc-account list`"},
		{"a variable of another source", setupFeatures, 404, `{"error":true,"message":"Variable 7 not found on traffic source 3","status":404}`,
			[]string{"ppc-network", "variable", "delete", "3", "7", "--force"}, ExitValidation, "`p202 ppc-network variable list 3`"},
		{"a role without the Setup section", setupFeatures, 403, `{"error":true,"message":"This account's role does not have the 'access_to_setup_section' permission.","status":403}`,
			[]string{"ppc-account", "pixel", "list", "3"}, ExitAuth, "access_to_setup_section (Super user, Admin or Campaign manager)"},
		{"a role without the variables dialog", setupFeatures, 403, `{"error":true,"message":"This account's role does not have the 'remove_traffic_source' permission.","status":403}`,
			[]string{"ppc-network", "variable", "create", "3", "--name", "Ad", "--parameter", "adid", "--placeholder", "{ad_id}"}, ExitAuth, "remove_traffic_source (Super user or Admin)"},
		{"an advanced page with no offer", setupFeatures, 422, `{"error":true,"message":"Please select an affiliate campaign or rotator","status":422,"field_errors":{"offers":"An advanced landing page links out to one or more offers."}}`,
			[]string{"landing-page", "code", "14"}, ExitValidation, "--offer campaign:<id>"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			newSetupServer(t, tc.features, func(r *http.Request) (int, string) { return tc.status, tc.body })
			stdout, _, err := executeCommand(append(tc.args, "--json")...)
			if err == nil {
				t.Fatal("expected an error")
			}
			if code := exitCodeForError(err); code != tc.exit {
				t.Errorf("exit %d, want %d", code, tc.exit)
			}
			if hint := hintFor(err); !strings.Contains(hint, tc.hint) {
				t.Errorf("hint %q lacks %q", hint, tc.hint)
			}
			if strings.TrimSpace(stdout) != "" {
				t.Errorf("stdout must stay empty on failure, got %q", stdout)
			}
		})
	}
}

const postbackAnswer = `{"data":{"scheme":"https","base_url":"https://t.example/tracking202/static/","amount":"{payout}","subid":"{aff_sub}","campaign_id":3,
"simple":{"pixel":"<img src=\"https://t.example/tracking202/static/gpx.php?amount={payout}&subid={aff_sub}\" />","postback_url":"https://t.example/tracking202/static/gpb.php?amount={payout}&subid={aff_sub}"},
"advanced":{"pixel":"<img src=\"https://t.example/tracking202/static/gpx.php?amount={payout}&cid=3&subid={aff_sub}\" />","postback_url":"https://t.example/tracking202/static/gpb.php?amount={payout}&cid=3&subid={aff_sub}"},
"universal":{"javascript":"<script>var vars202={}</script>","iframe":"<iframe src=\"https://t.example/tracking202/static/upx.php?amount={payout}&subid={aff_sub}\"></iframe>"}}}`

func TestPostbackURLPrintsTheURLAloneFromTheServer(t *testing.T) {
	_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, postbackAnswer })

	stdout, stderr, err := executeCommand("conversion", "postback-url", "--subid", "{aff_sub}", "--amount", "{payout}")
	if err != nil {
		t.Fatalf("postback-url: %v", err)
	}
	if strings.TrimSpace(stdout) != "https://t.example/tracking202/static/gpb.php?amount={payout}&subid={aff_sub}" {
		t.Errorf("stdout = %q, want the simple postback URL alone", stdout)
	}
	if !strings.Contains(stderr, "give this to your network") {
		t.Errorf("the guidance belongs on stderr: %q", stderr)
	}
	q := (*seen)[0].Query
	if (*seen)[0].Path != "/api/v3/conversions/postback-code" || q.Get("subid") != "{aff_sub}" || q.Get("amount") != "{payout}" || q.Has("campaign_id") {
		t.Errorf("request = %s %v", (*seen)[0].Path, q)
	}

	stdout, _, err = executeCommand("conversion", "postback-url", "--campaign", "3")
	if err != nil {
		t.Fatalf("postback-url --campaign: %v", err)
	}
	if !strings.Contains(stdout, "&cid=3&") {
		t.Errorf("--campaign prints the advanced postback, got %q", stdout)
	}
	if got := (*seen)[1].Query.Get("campaign_id"); got != "3" {
		t.Errorf("campaign_id = %q, want 3", got)
	}
}

func TestPixelPrintsTheChosenForm(t *testing.T) {
	newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, postbackAnswer })
	for args, want := range map[string]string{
		"":                                  `gpx.php?amount={payout}&subid={aff_sub}`,
		"--type advanced --campaign 3":      `gpx.php?amount={payout}&cid=3&subid={aff_sub}`,
		"--type universal":                  `<script>var vars202={}</script>`,
		"--type universal --iframe":         `<iframe src=`,
		"--campaign 3":                      `&cid=3&`,
		"--type advanced --scheme https":    `&cid=3&`,
		"--type simple --subid {aff_sub}":   `gpx.php?amount={payout}&subid={aff_sub}`,
		"--type universal --amount 12.50":   `<script>`,
		"--type advanced --amount {payout}": `cid=3`,
	} {
		stdout, _, err := executeCommand(append([]string{"conversion", "pixel"}, strings.Fields(args)...)...)
		if err != nil {
			t.Fatalf("pixel %s: %v", args, err)
		}
		if !strings.Contains(stdout, want) || strings.Count(strings.TrimSpace(stdout), "\n") != 0 {
			t.Errorf("pixel %s: stdout %q, want one line containing %q", args, stdout, want)
		}
	}
}

func TestPostbackChoicesThatCannotApplyAreRefusedBeforeAnyRequest(t *testing.T) {
	cases := map[string]string{
		"conversion postback-url --type universal":           "conversion pixel --type universal",
		"conversion postback-url --type simple --campaign 3": "--type advanced",
		"conversion pixel --iframe":                          "--type universal --iframe",
		"conversion pixel --type advanced --campaign x":      "p202 campaign list",
		"conversion pixel --scheme ftp":                      "",
	}
	for args, hint := range cases {
		_, seen := newSetupServer(t, setupFeatures, func(r *http.Request) (int, string) { return 200, postbackAnswer })
		_, _, err := executeCommand(strings.Fields(args)...)
		if err == nil {
			t.Fatalf("%s was accepted", args)
		}
		if code := exitCodeForError(err); code != ExitValidation {
			t.Errorf("%s: exit %d, want %d", args, code, ExitValidation)
		}
		if hint != "" && !strings.Contains(hintFor(err), hint) {
			t.Errorf("%s: hint %q lacks %q", args, hintFor(err), hint)
		}
		if len(*seen) != 0 {
			t.Errorf("%s: %d requests before the refusal", args, len(*seen))
		}
	}
}
