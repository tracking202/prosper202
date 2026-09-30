package cmd

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
)

// fakeResolver answers from a fixed table; anything else is NXDOMAIN.
type fakeResolver struct {
	mu      sync.Mutex
	hosts   map[string][]string
	lookups map[string]int
}

func (r *fakeResolver) lookup(_ context.Context, host string) ([]string, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.lookups == nil {
		r.lookups = map[string]int{}
	}
	r.lookups[host]++
	if addrs, ok := r.hosts[host]; ok {
		return addrs, nil
	}
	return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
}

func (r *fakeResolver) count(host string) int {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.lookups[host]
}

// loopbackDial connects for real, but only to 127.0.0.1: tests never leave the machine.
func loopbackDial(t *testing.T) func(ctx context.Context, network, addr string) (net.Conn, error) {
	return func(ctx context.Context, network, addr string) (net.Conn, error) {
		if host, _, _ := net.SplitHostPort(addr); host != "127.0.0.1" {
			t.Errorf("dial to %s: tests must stay on loopback", addr)
			return nil, errors.New("non-loopback dial refused by the test")
		}
		var d net.Dialer
		return d.DialContext(ctx, network, addr)
	}
}

func withURLProbe(t *testing.T, p urlProbe) {
	t.Helper()
	old := checkURLsNet
	checkURLsNet = p
	t.Cleanup(func() { checkURLsNet = old })
}

// offerServer is a local offer host that counts connections and HTTP requests.
type offerServer struct {
	port     string
	pool     *x509.CertPool
	conns    atomic.Int32
	mu       sync.Mutex
	requests []string // "METHOD /path?query"
}

func (o *offerServer) requestLog() []string {
	o.mu.Lock()
	defer o.mu.Unlock()
	return append([]string(nil), o.requests...)
}

func newOfferServer(t *testing.T, useTLS bool, handler http.HandlerFunc) *offerServer {
	t.Helper()
	o := &offerServer{}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		o.mu.Lock()
		o.requests = append(o.requests, r.Method+" "+r.URL.RequestURI())
		o.mu.Unlock()
		if handler != nil {
			handler(w, r)
		}
	}))
	srv.Config.ErrorLog = log.New(io.Discard, "", 0) // rejected handshakes are expected
	srv.Config.ConnState = func(_ net.Conn, s http.ConnState) {
		if s == http.StateNew {
			o.conns.Add(1)
		}
	}
	if useTLS {
		srv.StartTLS()
		o.pool = x509.NewCertPool()
		o.pool.AddCert(srv.Certificate())
	} else {
		srv.Start()
	}
	t.Cleanup(srv.Close)
	_, o.port, _ = net.SplitHostPort(srv.Listener.Addr().String())
	return o
}

// closedPort returns a loopback port nothing listens on.
func closedPort(t *testing.T) string {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	_, port, _ := net.SplitHostPort(l.Addr().String())
	l.Close()
	return port
}

func campaignRow(id, network int, name string, urls ...string) map[string]interface{} {
	row := map[string]interface{}{"aff_campaign_id": id, "aff_campaign_name": name, "aff_network_id": network}
	for i, u := range urls {
		if u != "" {
			row[campaignURLFields[i]] = u
		}
	}
	return row
}

func decodeChecks(t *testing.T, stdout string) []urlCheck {
	t.Helper()
	var resp struct {
		Data []urlCheck `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	return resp.Data
}

func checksByKey(checks []urlCheck) map[string]urlCheck {
	out := map[string]urlCheck{}
	for _, ch := range checks {
		out[ch.CampaignID+"/"+ch.Field] = ch
	}
	return out
}

func TestCheckURLsOKDedupsHostsAndSendsNoHTTPRequest(t *testing.T) {
	tlsSrv := newOfferServer(t, true, nil)
	plainSrv := newOfferServer(t, false, nil)
	res := &fakeResolver{hosts: map[string][]string{"example.com": {"127.0.0.1"}, "plain.test": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t), rootCAs: tlsSrv.pool})

	f := &campaignFake{rows: []map[string]interface{}{
		campaignRow(1, 7, "Live A", "https://example.com:"+tlsSrv.port+"/click?sub1=[[subid]]&c1=[[c1]]"),
		campaignRow(2, 7, "Live B", "https://EXAMPLE.com:"+tlsSrv.port+"/[[c2]]/other?s=[[subid]]",
			"http://plain.test:"+plainSrv.port+"/go?s=[[subid]]"),
	}}
	setupCampaignFake(t, f)

	stdout, stderr, err := executeCommand("campaign", "check-urls", "--json")
	if err != nil {
		t.Fatalf("check-urls: %v", err)
	}
	got := checksByKey(decodeChecks(t, stdout))
	if len(got) != 3 {
		t.Fatalf("rows = %+v, want 3", got)
	}
	host := "example.com:" + tlsSrv.port
	for _, k := range []string{"1/aff_campaign_url", "2/aff_campaign_url"} {
		if got[k].Status != urlCheckOK || got[k].Host != host || !strings.Contains(got[k].Detail, "TLS ok") {
			t.Errorf("%s = %+v, want ok on %s with a TLS ok detail", k, got[k], host)
		}
	}
	if ch := got["2/aff_campaign_url_2"]; ch.Status != urlCheckOK || ch.Host != "plain.test:"+plainSrv.port {
		t.Errorf("http slot = %+v, want ok on plain.test", ch)
	}
	if n := res.count("example.com"); n != 1 {
		t.Errorf("example.com resolved %d times, want once for two campaigns", n)
	}
	if n := tlsSrv.conns.Load(); n != 1 {
		t.Errorf("TLS server saw %d connections, want 1 (one check per host)", n)
	}
	if reqs := append(tlsSrv.requestLog(), plainSrv.requestLog()...); len(reqs) != 0 {
		t.Errorf("without --http no HTTP request may be sent, got %v", reqs)
	}
	if !strings.Contains(stderr, "Checked 2 host(s) for 3 offer URL(s), without sending any HTTP request: ok 3.") {
		t.Errorf("stderr summary = %q", stderr)
	}
}

func TestCheckURLsDefaultRootsRejectAnUntrustedCertificate(t *testing.T) {
	tlsSrv := newOfferServer(t, true, nil)
	res := &fakeResolver{hosts: map[string][]string{"example.com": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t)}) // nil rootCAs: the system pool

	f := &campaignFake{rows: []map[string]interface{}{
		campaignRow(1, 7, "Self-signed", "https://example.com:"+tlsSrv.port+"/x"),
	}}
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "check-urls", "--json")
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Fatalf("exit code = %d (%v), want %d", got, err, ExitPartialFailure)
	}
	checks := decodeChecks(t, stdout)
	if len(checks) != 1 || checks[0].Status != urlCheckTLSFailed || !strings.HasPrefix(checks[0].Detail, "unknown authority: ") {
		t.Errorf("checks = %+v, want tls_failed with an unknown authority detail", checks)
	}
	if reqs := tlsSrv.requestLog(); len(reqs) != 0 {
		t.Errorf("a rejected handshake must not be followed by a request, got %v", reqs)
	}
}

func TestCheckURLsReportsEachFailureStatus(t *testing.T) {
	tlsSrv := newOfferServer(t, true, nil)
	res := &fakeResolver{hosts: map[string][]string{"refused.test": {"127.0.0.1"}, "wrong.test": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t), rootCAs: tlsSrv.pool})

	f := &campaignFake{rows: []map[string]interface{}{
		campaignRow(3, 7, "Dead DNS", "https://gone.test/click?s=[[subid]]"),
		campaignRow(4, 7, "Refused", "http://refused.test:"+closedPort(t)+"/x"),
		campaignRow(5, 7, "Wrong cert", "https://wrong.test:"+tlsSrv.port+"/x"),
		campaignRow(6, 7, "Broken", "not a url", "https://[[c1]].example.com/x", "ftp://example.com/file", "https://exa mple.com/"),
	}}
	setupCampaignFake(t, f)

	stdout, stderr, err := executeCommand("campaign", "check-urls", "--json")
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Fatalf("exit code = %d (%v), want %d", got, err, ExitPartialFailure)
	}
	if !strings.Contains(err.Error(), "7 of 7 offer URL(s)") || !strings.Contains(hintFor(err), "p202 campaign replace-url --match <host>") {
		t.Errorf("error = %q, hint = %q", err, hintFor(err))
	}
	got := checksByKey(decodeChecks(t, stdout))
	want := map[string]struct{ status, detail string }{
		"3/aff_campaign_url":   {urlCheckDNSFailed, "no such host: gone.test"},
		"4/aff_campaign_url":   {urlCheckConnectFailed, "refused"},
		"5/aff_campaign_url":   {urlCheckTLSFailed, "hostname mismatch: "},
		"6/aff_campaign_url":   {urlCheckInvalid, "no scheme"},
		"6/aff_campaign_url_2": {urlCheckInvalid, "click token"},
		"6/aff_campaign_url_3": {urlCheckInvalid, `scheme "ftp"`},
		"6/aff_campaign_url_4": {urlCheckInvalid, "does not parse: invalid character"},
	}
	if len(got) != len(want) {
		t.Fatalf("rows = %+v, want %d", got, len(want))
	}
	for k, w := range want {
		if got[k].Status != w.status || !strings.Contains(got[k].Detail, w.detail) {
			t.Errorf("%s = {%s %q}, want {%s ...%q...}", k, got[k].Status, got[k].Detail, w.status, w.detail)
		}
	}
	if reqs := tlsSrv.requestLog(); len(reqs) != 0 {
		t.Errorf("no HTTP request may be sent, got %v", reqs)
	}
	if !strings.Contains(stderr, "invalid_url 4, dns_failed 1, connect_failed 1, tls_failed 1") {
		t.Errorf("stderr summary = %q", stderr)
	}
}

func TestCheckURLsTimeoutWhenTheHostNeverAnswers(t *testing.T) {
	res := &fakeResolver{hosts: map[string][]string{"slow.test": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: func(ctx context.Context, _, _ string) (net.Conn, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}})
	f := &campaignFake{rows: []map[string]interface{}{campaignRow(8, 7, "Slow", "https://slow.test/x")}}
	setupCampaignFake(t, f)

	stdout, _, err := executeCommand("campaign", "check-urls", "--timeout", "50ms", "--json")
	if got := exitCodeForError(err); got != ExitPartialFailure {
		t.Fatalf("exit code = %d (%v), want %d", got, err, ExitPartialFailure)
	}
	checks := decodeChecks(t, stdout)
	if len(checks) != 1 || checks[0].Status != urlCheckTimeout || !strings.Contains(checks[0].Detail, "within 50ms") {
		t.Errorf("checks = %+v, want timeout within 50ms", checks)
	}
}

func TestCheckURLsScopeFlagsNarrowTheRows(t *testing.T) {
	res := &fakeResolver{}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t)})
	rows := []map[string]interface{}{
		campaignRow(10, 7, "Ten", "https://keep.test/a", "https://keep.test/b", "https://other.test/c"),
		campaignRow(11, 7, "Eleven", "https://keep.test/d"),
		campaignRow(12, 9, "Twelve", "https://keep.test/e"),
	}
	cases := []struct {
		name string
		args []string
		want []string
	}{
		{"url-contains per slot, case-insensitive", []string{"--url-contains", "KEEP.test"}, []string{"10/aff_campaign_url", "10/aff_campaign_url_2", "11/aff_campaign_url", "12/aff_campaign_url"}},
		{"slot", []string{"--slot", "2,3"}, []string{"10/aff_campaign_url_2", "10/aff_campaign_url_3"}},
		{"ids", []string{"--ids", "11,12"}, []string{"11/aff_campaign_url", "12/aff_campaign_url"}},
		{"network", []string{"--aff-network-id", "9"}, []string{"12/aff_campaign_url"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := &campaignFake{rows: rows}
			setupCampaignFake(t, f)
			stdout, _, _ := executeCommand(append([]string{"campaign", "check-urls", "--json"}, tc.args...)...)
			var got []string
			for _, ch := range decodeChecks(t, stdout) {
				got = append(got, ch.CampaignID+"/"+ch.Field)
			}
			if strings.Join(got, " ") != strings.Join(tc.want, " ") {
				t.Errorf("rows = %v, want %v", got, tc.want)
			}
			if tc.name == "network" {
				for _, q := range f.gets {
					if !strings.Contains(q, "filter%5Baff_network_id%5D=9") {
						t.Errorf("campaign request %q is missing the network filter", q)
					}
				}
			}
		})
	}
}

func TestCheckURLsHTTPDeclinedSendsNothing(t *testing.T) {
	tlsSrv := newOfferServer(t, true, nil)
	res := &fakeResolver{hosts: map[string][]string{"example.com": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t), rootCAs: tlsSrv.pool})
	f := &campaignFake{rows: []map[string]interface{}{campaignRow(1, 7, "Live", "https://example.com:"+tlsSrv.port+"/click")}}
	setupCampaignFake(t, f)

	feedStdin(t, "n")
	stdout, stderr, err := executeCommand("campaign", "check-urls", "--http")
	if err != nil {
		t.Fatalf("declined check-urls --http: %v", err)
	}
	if !strings.Contains(stderr, "may record each one as a click") {
		t.Errorf("stderr = %q, want the click warning", stderr)
	}
	if reqs := tlsSrv.requestLog(); len(reqs) != 0 || tlsSrv.conns.Load() != 0 {
		t.Errorf("declining must send nothing: requests %v, connections %d", reqs, tlsSrv.conns.Load())
	}
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("declined run printed rows: %q", stdout)
	}
}

func TestCheckURLsHTTPConfirmedReportsStatusOncePerURL(t *testing.T) {
	tlsSrv := newOfferServer(t, true, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Location", "https://advertiser.test/landing")
		w.WriteHeader(http.StatusFound)
	})
	res := &fakeResolver{hosts: map[string][]string{"example.com": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t), rootCAs: tlsSrv.pool})
	shared := "https://example.com:" + tlsSrv.port + "/click?sub1=[[subid]]"
	f := &campaignFake{rows: []map[string]interface{}{
		campaignRow(1, 7, "A", shared),
		campaignRow(2, 7, "B", shared, "https://example.com:"+tlsSrv.port+"/other"),
	}}
	setupCampaignFake(t, f)

	feedStdin(t, "y")
	stdout, stderr, err := executeCommand("campaign", "check-urls", "--http", "--json")
	if err != nil {
		t.Fatalf("check-urls --http: %v", err)
	}
	reqs := tlsSrv.requestLog()
	if len(reqs) != 2 || !contains(reqs, "HEAD /click?sub1=p202check") || !contains(reqs, "HEAD /other") {
		t.Errorf("requests = %v, want one HEAD per unique URL with tokens filled", reqs)
	}
	for _, ch := range decodeChecks(t, stdout) {
		if ch.Status != urlCheckOK || ch.HTTPStatus != http.StatusFound || ch.Location != "https://advertiser.test/landing" {
			t.Errorf("row %+v, want ok with http_status 302 and the Location (redirect not followed)", ch)
		}
	}
	if !strings.Contains(stderr, "then sent 2 HTTP request(s)") || !strings.Contains(stderr, "HTTP status: 302 x3") {
		t.Errorf("stderr summary = %q", stderr)
	}
}

func TestCheckURLsHTTPFallsBackToGETOnlyOn405(t *testing.T) {
	tlsSrv := newOfferServer(t, true, func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/no-head" && r.Method == http.MethodHead:
			w.WriteHeader(http.StatusMethodNotAllowed)
		case r.URL.Path == "/gone":
			w.WriteHeader(http.StatusNotFound)
		}
	})
	res := &fakeResolver{hosts: map[string][]string{"example.com": {"127.0.0.1"}}}
	withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t), rootCAs: tlsSrv.pool})
	base := "https://example.com:" + tlsSrv.port
	f := &campaignFake{rows: []map[string]interface{}{
		campaignRow(1, 7, "No HEAD", base+"/no-head"),
		campaignRow(2, 7, "Gone", base+"/gone"),
	}}
	setupCampaignFake(t, f)

	// --force: no prompt, nothing on stdin.
	stdout, _, err := executeCommand("campaign", "check-urls", "--http", "--force", "--json")
	if err != nil {
		t.Fatalf("check-urls --http --force: %v", err)
	}
	reqs := tlsSrv.requestLog()
	if len(reqs) != 3 || !contains(reqs, "HEAD /no-head") || !contains(reqs, "GET /no-head") || !contains(reqs, "HEAD /gone") {
		t.Errorf("requests = %v, want HEAD+GET for /no-head and HEAD only for /gone", reqs)
	}
	got := checksByKey(decodeChecks(t, stdout))
	if got["1/aff_campaign_url"].HTTPStatus != http.StatusOK || got["2/aff_campaign_url"].HTTPStatus != http.StatusNotFound {
		t.Errorf("rows = %+v, want 200 after the GET fallback and 404", got)
	}
}

func TestCheckURLsValidatesFlagsBeforeAnyRequest(t *testing.T) {
	cases := []struct {
		name string
		args []string
		hint string
	}{
		{"bad slot", []string{"--slot", "6"}, "Slot 1 is the primary offer URL"},
		{"bad ids", []string{"--ids", "12,abc"}, "... list"},
		{"bad network", []string{"--aff-network-id", "affiliates"}, "p202 aff-network list"},
		{"zero timeout", []string{"--timeout", "0s"}, "--timeout 5s"},
		{"negative timeout", []string{"--timeout", "-1s"}, "--timeout 5s"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			res := &fakeResolver{}
			withURLProbe(t, urlProbe{lookupHost: res.lookup, dial: loopbackDial(t)})
			f := newCampaignFake()
			setupCampaignFake(t, f)
			_, _, err := executeCommand(append([]string{"campaign", "check-urls", "--json"}, tc.args...)...)
			if err == nil {
				t.Fatal("expected a validation error")
			}
			if got := exitCodeForError(err); got != ExitValidation {
				t.Errorf("exit code = %d, want %d", got, ExitValidation)
			}
			if !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("hint = %q, want it to contain %q", hintFor(err), tc.hint)
			}
			if len(f.gets) != 0 || len(res.lookups) != 0 {
				t.Errorf("validation must precede any request; gets=%v lookups=%v", f.gets, res.lookups)
			}
		})
	}
}

func TestCheckURLsNothingInScopeIsNotAnError(t *testing.T) {
	withURLProbe(t, urlProbe{lookupHost: (&fakeResolver{}).lookup, dial: loopbackDial(t)})
	f := newCampaignFake()
	setupCampaignFake(t, f)
	stdout, _, err := executeCommand("campaign", "check-urls", "--url-contains", "nowhere.invalid", "--json")
	if err != nil || len(decodeChecks(t, stdout)) != 0 {
		t.Errorf("err = %v, stdout = %s; want exit 0 and an empty data array", err, stdout)
	}
}

func TestParseOfferURL(t *testing.T) {
	cases := []struct {
		raw, key, probe, problem string
	}{
		{"https://aanicca.G2AFSE.com/click?pid=1&sub1=[[subid]]", "https://aanicca.g2afse.com:443", "https://aanicca.G2AFSE.com/click?pid=1&sub1=p202check", ""},
		{"http://x.test/[[c1]]/p?a=[[c2]]", "http://x.test:80", "http://x.test/p202check/p?a=p202check", ""},
		{"https://x.test:8443/", "https://x.test:8443", "https://x.test:8443/", ""},
		{"https://[::1]:8443/x", "https://[::1]:8443", "https://[::1]:8443/x", ""},
		{"https://[[c1]].example.com/", "", "", "click token"},
		{"example.com/offer", "", "", "no scheme"},
		{"https://x.test:abc/", "", "", "does not parse: invalid port"},
	}
	for _, tc := range cases {
		got, problem := parseOfferURL(tc.raw)
		if tc.problem != "" {
			if !strings.Contains(problem, tc.problem) {
				t.Errorf("parseOfferURL(%q) problem = %q, want %q", tc.raw, problem, tc.problem)
			}
			continue
		}
		if problem != "" || got.key() != tc.key || got.probeURL != tc.probe {
			t.Errorf("parseOfferURL(%q) = %s %q (problem %q), want %s %q", tc.raw, got.key(), got.probeURL, problem, tc.key, tc.probe)
		}
	}
}
