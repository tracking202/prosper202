package cmd

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"errors"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

const day = 24 * time.Hour

// testCA signs throwaway leaf certificates with chosen names and dates.
type testCA struct {
	cert *x509.Certificate
	key  *ecdsa.PrivateKey
	pool *x509.CertPool
}

func newTestCA(t *testing.T, name string) *testCA {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: name, Organization: []string{"p202 tests"}},
		NotBefore:             time.Now().Add(-365 * day),
		NotAfter:              time.Now().Add(3650 * day),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	cert, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatal(err)
	}
	pool := x509.NewCertPool()
	pool.AddCert(cert)
	return &testCA{cert: cert, key: key, pool: pool}
}

func (ca *testCA) issue(t *testing.T, notBefore, notAfter time.Time, names ...string) tls.Certificate {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: names[0]},
		DNSNames:     names,
		NotBefore:    notBefore,
		NotAfter:     notAfter,
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, ca.cert, &key.PublicKey, ca.key)
	if err != nil {
		t.Fatal(err)
	}
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatal(err)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key, Leaf: leaf}
}

// healthRig is a local "tracker.test" serving the health API over TLS with a
// chosen certificate, and the order in which the check and the API reach it.
type healthRig struct {
	port     string
	res      *fakeResolver
	mu       sync.Mutex
	events   []string
	requests int
}

func (h *healthRig) log(e string) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.events = append(h.events, e)
}

func (h *healthRig) eventLog() []string {
	h.mu.Lock()
	defer h.mu.Unlock()
	return append([]string(nil), h.events...)
}

const healthBody = `{"data":{"status":"healthy","timestamp":1790000000,"api_version":"v3"}}`

// newHealthRig serves cert (nil: plain HTTP) and answers the health API with
// status and body. The probe trusts probeRoots; the API client trusts ca.
func newHealthRig(t *testing.T, cert *tls.Certificate, ca *testCA, probeRoots *x509.CertPool, status int, body string) *healthRig {
	t.Helper()
	h := &healthRig{res: &fakeResolver{hosts: map[string][]string{"tracker.test": {"127.0.0.1"}}}}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h.mu.Lock()
		h.requests++
		h.mu.Unlock()
		h.log("api " + r.URL.Path)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	srv.Config.ErrorLog = log.New(io.Discard, "", 0) // rejected handshakes are expected
	scheme := "http"
	if cert != nil {
		srv.TLS = &tls.Config{Certificates: []tls.Certificate{*cert}}
		srv.StartTLS()
		scheme = "https"
	} else {
		srv.Start()
	}
	t.Cleanup(srv.Close)
	_, h.port, _ = net.SplitHostPort(srv.Listener.Addr().String())

	dial := loopbackDial(t)
	withURLProbe(t, urlProbe{
		lookupHost: h.res.lookup,
		dial: func(ctx context.Context, network, addr string) (net.Conn, error) {
			h.log("tls check")
			return dial(ctx, network, addr)
		},
		rootCAs: probeRoots,
	})
	var roots *x509.CertPool
	if ca != nil {
		roots = ca.pool
	}
	withAPITransport(t, roots, net.JoinHostPort("127.0.0.1", h.port), h)

	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, scheme+"://tracker.test:"+h.port, "")
	return h
}

// withAPITransport points the API client (http.DefaultTransport) at addr,
// trusting roots, so tests never resolve names or leave loopback.
func withAPITransport(t *testing.T, roots *x509.CertPool, addr string, h *healthRig) {
	t.Helper()
	old := http.DefaultTransport
	tr := old.(*http.Transport).Clone()
	tr.Proxy = nil
	tr.TLSClientConfig = &tls.Config{RootCAs: roots}
	tr.DialContext = func(ctx context.Context, network, _ string) (net.Conn, error) {
		h.log("api dial")
		var d net.Dialer
		return d.DialContext(ctx, network, addr)
	}
	http.DefaultTransport = tr
	t.Cleanup(func() {
		tr.CloseIdleConnections()
		http.DefaultTransport = old
	})
}

func decodeHealth(t *testing.T, stdout string) map[string]interface{} {
	t.Helper()
	var resp struct {
		Data map[string]interface{} `json:"data"`
	}
	if err := json.Unmarshal([]byte(stdout), &resp); err != nil || resp.Data == nil {
		t.Fatalf("stdout is not a health object: %v\n%s", err, stdout)
	}
	return resp.Data
}

func assertExit(t *testing.T, err error, want int) {
	t.Helper()
	if got := exitCodeForError(err); got != want {
		t.Fatalf("exit code = %d (%v), want %d", got, err, want)
	}
}

func TestSystemHealthCertOKMergesTLSFieldsBeforeTheAPICall(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	notAfter := time.Now().Add(90*day + time.Hour).Truncate(time.Second)
	cert := ca.issue(t, time.Now().Add(-day), notAfter, "tracker.test")
	h := newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)

	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitOK)
	d := decodeHealth(t, stdout)
	if d["status"] != "healthy" || d["api_version"] != "v3" || d["timestamp"] != float64(1790000000) {
		t.Errorf("the server's health fields must be kept: %v", d)
	}
	if d["tls_status"] != tlsStatusOK || d["tls_days_left"] != float64(90) ||
		d["tls_not_after"] != notAfter.UTC().Format(time.RFC3339) ||
		!strings.Contains(d["tls_issuer"].(string), "CN=p202 test CA") ||
		!strings.Contains(d["tls_detail"].(string), "TLS ok") {
		t.Errorf("tls fields = %v", d)
	}
	if ev := h.eventLog(); strings.Join(ev, ",") != "tls check,api dial,api /api/v3/system/health" {
		t.Errorf("events = %v, want the TLS check first, then the one API request", ev)
	}
	if h.res.count("tracker.test") != 1 {
		t.Errorf("tracker.test resolved %d times by the probe, want 1", h.res.count("tracker.test"))
	}
}

func TestSystemHealthExpiringFollowsCertWarnDays(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	cert := ca.issue(t, time.Now().Add(-85*day), time.Now().Add(5*day+time.Hour), "tracker.test")

	cases := []struct {
		args   []string
		status string
		exit   int
	}{
		{nil, tlsStatusExpiring, ExitPartialFailure}, // default 21
		{[]string{"--cert-warn-days", "6"}, tlsStatusExpiring, ExitPartialFailure},
		{[]string{"--cert-warn-days", "5"}, tlsStatusOK, ExitOK},
		{[]string{"--cert-warn-days", "0"}, tlsStatusOK, ExitOK},
		{[]string{"--cert-warn-days", "200000"}, tlsStatusExpiring, ExitPartialFailure}, // past time.Duration's range
	}
	for _, tc := range cases {
		t.Run(strings.Join(append([]string{"warn"}, tc.args...), " "), func(t *testing.T) {
			newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)
			stdout, _, err := executeCommand(append([]string{"system", "health", "--json"}, tc.args...)...)
			assertExit(t, err, tc.exit)
			d := decodeHealth(t, stdout)
			if d["tls_status"] != tc.status || d["tls_days_left"] != float64(5) || d["status"] != "healthy" {
				t.Errorf("health = %v, want tls_status %s with 5 days left and the server status kept", d, tc.status)
			}
			if tc.exit == ExitPartialFailure {
				if !strings.Contains(err.Error(), "expires on") || !strings.Contains(err.Error(), "in 5 day(s)") ||
					!strings.Contains(hintFor(err), "sudo certbot renew") {
					t.Errorf("error = %q, hint = %q", err, hintFor(err))
				}
			}
		})
	}
}

func TestSystemHealthExpiredCertIsReportedAsExpiredNotAsANetworkError(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	notAfter := time.Now().Add(-3*day - time.Hour)
	cert := ca.issue(t, notAfter.Add(-90*day), notAfter, "tracker.test")
	h := newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)

	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitPartialFailure)
	env := errorEnvelope(err)["error"].(map[string]interface{})
	if env["category"] != "partial_failure" || !strings.Contains(env["message"].(string), "expired on "+notAfter.UTC().Format("2006-01-02")+" (3 day(s) ago)") {
		t.Errorf("envelope = %v, want partial_failure naming the expiry date", env)
	}
	if hint, _ := env["hint"].(string); !strings.Contains(hint, "sudo certbot renew") || !strings.Contains(hint, "tracker.test") {
		t.Errorf("hint = %q, want the certbot renewal step for tracker.test", hint)
	}
	d := decodeHealth(t, stdout)
	if d["tls_status"] != tlsStatusExpired || d["tls_days_left"] != float64(-3) || !strings.HasPrefix(d["tls_detail"].(string), "expired: ") {
		t.Errorf("tls fields = %v", d)
	}
	// The API call was still made, after the check, and failed on the same certificate.
	if d["status"] != "unknown" || !strings.Contains(d["api_error"].(string), "certificate") {
		t.Errorf("health = %v, want status unknown and the API's certificate error", d)
	}
	if ev := h.eventLog(); strings.Join(ev, ",") != "tls check,api dial" || h.requests != 0 {
		t.Errorf("events = %v, requests = %d; want the check, then an API dial whose handshake failed", ev, h.requests)
	}
}

func TestSystemHealthEachCertificateFailure(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	other := newTestCA(t, "Untrusted CA")
	now := time.Now()
	cases := []struct {
		name   string
		cert   tls.Certificate
		status string
		detail string
		hint   string
	}{
		{"wrong host", ca.issue(t, now.Add(-day), now.Add(60*day), "other.test", "www.other.test"),
			tlsStatusHostnameMismatch, "other.test", "sudo certbot --nginx -d tracker.test"},
		{"untrusted issuer", other.issue(t, now.Add(-day), now.Add(60*day), "tracker.test"),
			tlsStatusUnknownAuthority, "unknown authority: ", "fullchain.pem"},
		{"not yet valid", ca.issue(t, now.Add(2*day), now.Add(60*day), "tracker.test"),
			tlsStatusInvalid, "not yet valid: the certificate starts on ", "tls_detail says what"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			newHealthRig(t, &tc.cert, ca, ca.pool, 200, healthBody)
			stdout, _, err := executeCommand("system", "health", "--json")
			assertExit(t, err, ExitPartialFailure)
			d := decodeHealth(t, stdout)
			if d["tls_status"] != tc.status || !strings.Contains(d["tls_detail"].(string), tc.detail) || d["tls_not_after"] == nil {
				t.Errorf("health = %v, want %s with a detail containing %q", d, tc.status, tc.detail)
			}
			if !strings.Contains(hintFor(err), tc.hint) {
				t.Errorf("hint = %q, want %q", hintFor(err), tc.hint)
			}
		})
	}
}

func TestSystemHealthPlainHTTPOnTheHTTPSPortIsInvalid(t *testing.T) {
	h := newHealthRig(t, nil, nil, nil, 200, healthBody)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "https://tracker.test:"+h.port, "")

	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitPartialFailure)
	d := decodeHealth(t, stdout)
	if d["tls_status"] != tlsStatusInvalid || !strings.HasPrefix(d["tls_detail"].(string), "handshake failed: ") || d["tls_not_after"] != nil {
		t.Errorf("health = %v, want invalid with a handshake failure and no certificate", d)
	}
}

func TestSystemHealthHTTPBaseURLDoesNoTLSCheck(t *testing.T) {
	h := newHealthRig(t, nil, nil, nil, 200, healthBody)
	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitOK)
	d := decodeHealth(t, stdout)
	if d["tls_status"] != tlsStatusNotUsed || !strings.Contains(d["tls_detail"].(string), "TLS is not in use") {
		t.Errorf("health = %v, want not_used", d)
	}
	for _, k := range []string{"tls_not_after", "tls_days_left", "tls_issuer"} {
		if v, ok := d[k]; !ok || v != nil {
			t.Errorf("%s = %v (present %v), want null so the shape stays the same", k, v, ok)
		}
	}
	if ev := h.eventLog(); strings.Join(ev, ",") != "api dial,api /api/v3/system/health" {
		t.Errorf("events = %v, want no TLS check for an http URL", ev)
	}
}

func TestSystemHealthAPIFailureWithASoundCertificateKeepsTheAPIError(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	cert := ca.issue(t, time.Now().Add(-day), time.Now().Add(60*day), "tracker.test")
	h := newHealthRig(t, &cert, ca, ca.pool, 503, `{"message":"maintenance"}`)

	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitServer)
	if strings.TrimSpace(stdout) != "" {
		t.Errorf("stdout = %q, want nothing on an API failure", stdout)
	}
	if hint := hintFor(err); !strings.Contains(hint, "Server-side failure") || !strings.Contains(hint, "TLS certificate for tracker.test:"+h.port+" is fine") {
		t.Errorf("hint = %q, want the 5xx hint plus the certificate verdict", hint)
	}
	if ev := h.eventLog(); len(ev) == 0 || ev[0] != "tls check" {
		t.Errorf("events = %v, want the TLS check to run first even though the API fails", ev)
	}
}

func TestSystemHealthNotFoundPointsAtTheBaseURL(t *testing.T) {
	newHealthRig(t, nil, nil, nil, 404, `{"message":"not found"}`)
	_, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitValidation)
	if hint := hintFor(err); !strings.Contains(hint, "p202 config set-url") || strings.Contains(hint, "valid ids") {
		t.Errorf("hint = %q, want the base-URL fix, not the generic 404 advice", hint)
	}
}

func TestSystemHealthUnreachable(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	cert := ca.issue(t, time.Now().Add(-day), time.Now().Add(60*day), "tracker.test")

	t.Run("API answers", func(t *testing.T) {
		h := newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)
		h.res.hosts = nil // the probe's DNS fails; the API still answers
		stdout, _, err := executeCommand("system", "health", "--json")
		assertExit(t, err, ExitPartialFailure)
		d := decodeHealth(t, stdout)
		if d["tls_status"] != tlsStatusUnreachable || !strings.Contains(d["tls_detail"].(string), "no such host") || d["status"] != "healthy" {
			t.Errorf("health = %v, want unreachable with the API's status kept", d)
		}
	})
	t.Run("API down too", func(t *testing.T) {
		h := newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)
		h.res.hosts = nil
		withAPITransport(t, ca.pool, net.JoinHostPort("127.0.0.1", closedPort(t)), h)
		stdout, _, err := executeCommand("system", "health", "--json")
		assertExit(t, err, ExitNetwork) // not a certificate problem: the network error stands
		if strings.TrimSpace(stdout) != "" {
			t.Errorf("stdout = %q, want nothing", stdout)
		}
	})
}

func TestSystemHealthTLSTimeoutIsUnreachable(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	cert := ca.issue(t, time.Now().Add(-day), time.Now().Add(60*day), "tracker.test")
	h := newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)
	withURLProbe(t, urlProbe{lookupHost: h.res.lookup, dial: func(ctx context.Context, _, _ string) (net.Conn, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}})
	old := systemHealthTLSTimeout
	systemHealthTLSTimeout = 50 * time.Millisecond
	t.Cleanup(func() { systemHealthTLSTimeout = old })

	stdout, _, err := executeCommand("system", "health", "--json")
	assertExit(t, err, ExitPartialFailure)
	if d := decodeHealth(t, stdout); d["tls_status"] != tlsStatusUnreachable || !strings.Contains(d["tls_detail"].(string), "within 50ms") {
		t.Errorf("health = %v, want unreachable within 50ms", d)
	}
}

func TestSystemHealthHumanOutputShowsTheCertificate(t *testing.T) {
	ca := newTestCA(t, "p202 test CA")
	notAfter := time.Now().Add(-3*day - time.Hour)
	cert := ca.issue(t, notAfter.Add(-90*day), notAfter, "tracker.test")
	newHealthRig(t, &cert, ca, ca.pool, 200, healthBody)

	stdout, _, err := executeCommand("system", "health")
	assertExit(t, err, ExitPartialFailure)
	for _, want := range []string{"tls_status:", "expired", "tls_days_left:", "-3", "tls_issuer:", "CN=p202 test CA"} {
		if !strings.Contains(stdout, want) {
			t.Errorf("human output lacks %q:\n%s", want, stdout)
		}
	}
}

func TestSystemHealthRejectsANegativeCertWarnDaysBeforeAnyRequest(t *testing.T) {
	h := newHealthRig(t, nil, nil, nil, 200, healthBody)
	_, _, err := executeCommand("system", "health", "--cert-warn-days", "-1", "--json")
	assertExit(t, err, ExitValidation)
	if !strings.Contains(hintFor(err), "--cert-warn-days 21") {
		t.Errorf("hint = %q", hintFor(err))
	}
	if ev := h.eventLog(); len(ev) != 0 {
		t.Errorf("events = %v, want no check and no request", ev)
	}
}

func TestClassifyTLSFailureIgnoresUnrelatedErrors(t *testing.T) {
	if kind, reason := classifyTLSFailure(errors.New("remote error: tls: handshake failure")); kind != tlsStatusInvalid || reason != "handshake failed" {
		t.Errorf("classifyTLSFailure = %s, %s", kind, reason)
	}
}
