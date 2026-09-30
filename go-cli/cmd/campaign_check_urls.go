package cmd

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	"p202/internal/metrics"
	"p202/internal/output"
)

// URL check statuses, in the order the summary lists them.
const (
	urlCheckOK            = "ok"
	urlCheckInvalid       = "invalid_url"
	urlCheckDNSFailed     = "dns_failed"
	urlCheckConnectFailed = "connect_failed"
	urlCheckTimeout       = "timeout"
	urlCheckTLSFailed     = "tls_failed"
	urlCheckHTTPFailed    = "http_failed"
)

var urlCheckStatuses = []string{urlCheckOK, urlCheckInvalid, urlCheckDNSFailed, urlCheckConnectFailed, urlCheckTimeout, urlCheckTLSFailed, urlCheckHTTPFailed}

// checkURLsColumns is the default table order; --fields still overrides.
var checkURLsColumns = []string{"aff_campaign_id", "aff_campaign_name", "field", "url", "host", "status", "detail", "http_status", "location"}

const checkURLsWorkers = 8

// urlTokenPattern matches Prosper202 click tokens such as [[subid]] and [[c1]].
var urlTokenPattern = regexp.MustCompile(`\[\[[^\[\]]*\]\]`)

// urlTokenStandIn fills tokens so the URL parses; --http sends it as the value.
const urlTokenStandIn = "p202check"

// urlProbe is the network access check-urls uses; tests swap checkURLsNet.
type urlProbe struct {
	lookupHost func(ctx context.Context, host string) ([]string, error)
	dial       func(ctx context.Context, network, addr string) (net.Conn, error)
	rootCAs    *x509.CertPool // nil means the system roots
}

var checkURLsNet = urlProbe{
	lookupHost: net.DefaultResolver.LookupHost,
	dial:       (&net.Dialer{}).DialContext,
}

// urlTarget is what one offer URL points at: checked once per scheme+host+port.
type urlTarget struct {
	scheme, host, port string
	probeURL           string // the URL with tokens filled, as --http requests it
}

func (t urlTarget) key() string { return t.scheme + "://" + net.JoinHostPort(t.host, t.port) }

// urlCheck is one offer URL slot and what checking it found.
type urlCheck struct {
	CampaignID   string `json:"aff_campaign_id"`
	CampaignName string `json:"aff_campaign_name"`
	Field        string `json:"field"`
	URL          string `json:"url"`
	Host         string `json:"host"`
	Status       string `json:"status"`
	Detail       string `json:"detail"`
	HTTPStatus   int    `json:"http_status,omitempty"`
	Location     string `json:"location,omitempty"`

	target urlTarget
}

type hostVerdict struct{ status, detail string }

// parseOfferURL finds the scheme, host and port to check; problem is set when
// the URL cannot be checked at all.
func parseOfferURL(raw string) (t urlTarget, problem string) {
	filled := urlTokenPattern.ReplaceAllString(strings.TrimSpace(raw), urlTokenStandIn)
	u, err := url.Parse(filled)
	if err != nil {
		var ue *url.Error
		if errors.As(err, &ue) {
			err = ue.Err
		}
		return t, "does not parse: " + err.Error()
	}
	scheme := strings.ToLower(u.Scheme)
	switch {
	case scheme == "":
		return t, "no scheme: an offer URL needs http:// or https://"
	case scheme != "http" && scheme != "https":
		return t, fmt.Sprintf("scheme %q is not http or https", u.Scheme)
	}
	host := strings.ToLower(u.Hostname())
	if host == "" {
		return t, "no host"
	}
	if strings.Contains(host, urlTokenStandIn) {
		return t, "the host is a click token filled per click, so it cannot be checked"
	}
	port := u.Port()
	if port == "" {
		port = "443"
		if scheme == "http" {
			port = "80"
		}
	}
	return urlTarget{scheme: scheme, host: host, port: port, probeURL: filled}, ""
}

// planURLChecks lists every non-empty offer URL slot in scope, in campaign order.
func planURLChecks(rows []map[string]interface{}, fields []string, contains string, onlyIDs map[string]bool) []urlCheck {
	var checks []urlCheck
	for _, row := range rows {
		rawID, ok := extractIntField(row, "aff_campaign_id", "id")
		if !ok {
			continue
		}
		id := fmt.Sprint(rawID)
		if len(onlyIDs) > 0 && !onlyIDs[id] {
			continue
		}
		name, _ := row["aff_campaign_name"].(string)
		for _, f := range fields {
			raw, _ := row[f].(string)
			if strings.TrimSpace(raw) == "" || (contains != "" && !containsFold(raw, contains)) {
				continue
			}
			ch := urlCheck{CampaignID: id, CampaignName: name, Field: f, URL: raw}
			if t, problem := parseOfferURL(raw); problem != "" {
				ch.Status, ch.Detail = urlCheckInvalid, problem
			} else {
				ch.target, ch.Host = t, net.JoinHostPort(t.host, t.port)
			}
			checks = append(checks, ch)
		}
	}
	return checks
}

// forEachBounded runs fn(0..n-1) with at most workers calls in flight.
func forEachBounded(n, workers int, fn func(int)) {
	var wg sync.WaitGroup
	sem := make(chan struct{}, workers)
	for i := 0; i < n; i++ {
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			fn(i)
		}()
	}
	wg.Wait()
}

// lookupError marks a DNS failure so it reports as dns_failed.
type lookupError struct{ err error }

func (e *lookupError) Error() string { return e.err.Error() }
func (e *lookupError) Unwrap() error { return e.err }

func isTimeout(err error) bool {
	var ne net.Error
	return errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &ne) && ne.Timeout())
}

// dialHost resolves host with the probe's resolver and connects to the first
// address that answers, IPv4 first. It returns the address it reached.
func (p urlProbe) dialHost(ctx context.Context, host, port string) (net.Conn, string, error) {
	addrs := []string{host}
	if net.ParseIP(host) == nil {
		var err error
		if addrs, err = p.lookupHost(ctx, host); err != nil {
			return nil, "", &lookupError{err}
		}
		if len(addrs) == 0 {
			return nil, "", &lookupError{fmt.Errorf("no addresses for %s", host)}
		}
	}
	sort.SliceStable(addrs, func(i, j int) bool {
		return net.ParseIP(addrs[i]).To4() != nil && net.ParseIP(addrs[j]).To4() == nil
	})
	var lastErr error
	for _, a := range addrs {
		addr := net.JoinHostPort(a, port)
		conn, err := p.dial(ctx, "tcp", addr)
		if err == nil {
			return conn, addr, nil
		}
		lastErr = err
		if ctx.Err() != nil {
			break
		}
	}
	return nil, "", lastErr
}

func connectVerdict(err error, t urlTarget, timeout time.Duration) hostVerdict {
	var le *lookupError
	if errors.As(err, &le) {
		var dnsErr *net.DNSError
		switch {
		case errors.As(err, &dnsErr) && dnsErr.IsNotFound:
			return hostVerdict{urlCheckDNSFailed, "no such host: " + t.host + " does not resolve"}
		case isTimeout(err):
			return hostVerdict{urlCheckDNSFailed, fmt.Sprintf("DNS lookup for %s got no answer within %s", t.host, timeout)}
		default:
			return hostVerdict{urlCheckDNSFailed, "DNS lookup failed: " + err.Error()}
		}
	}
	if isTimeout(err) {
		return hostVerdict{urlCheckTimeout, fmt.Sprintf("TCP connect to port %s got no answer within %s", t.port, timeout)}
	}
	return hostVerdict{urlCheckConnectFailed, err.Error()}
}

func tlsFailureDetail(err error) string {
	var unknown x509.UnknownAuthorityError
	var hostErr x509.HostnameError
	var invalid x509.CertificateInvalidError
	reason := "handshake failed"
	switch {
	case errors.As(err, &unknown):
		reason = "unknown authority"
	case errors.As(err, &hostErr):
		reason = "hostname mismatch"
	case errors.As(err, &invalid) && invalid.Reason == x509.Expired:
		reason = "expired"
	case errors.As(err, &invalid):
		reason = "invalid certificate"
	}
	return reason + ": " + err.Error()
}

// checkHost resolves, connects and (for https) completes a verified TLS
// handshake, then closes. It never writes an HTTP byte.
func (p urlProbe) checkHost(t urlTarget, timeout time.Duration) hostVerdict {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	conn, addr, err := p.dialHost(ctx, t.host, t.port)
	if err != nil {
		return connectVerdict(err, t, timeout)
	}
	if t.scheme == "http" {
		_ = conn.Close()
		return hostVerdict{urlCheckOK, "TCP connect to " + addr + " ok"}
	}
	tc := tls.Client(conn, &tls.Config{ServerName: t.host, RootCAs: p.rootCAs})
	err = tc.HandshakeContext(ctx)
	if err != nil {
		_ = tc.Close()
		if isTimeout(err) || ctx.Err() != nil {
			return hostVerdict{urlCheckTimeout, fmt.Sprintf("TLS handshake with %s did not finish within %s", addr, timeout)}
		}
		return hostVerdict{urlCheckTLSFailed, tlsFailureDetail(err)}
	}
	notAfter := tc.ConnectionState().PeerCertificates[0].NotAfter
	_ = tc.Close()
	return hostVerdict{urlCheckOK, fmt.Sprintf("TLS ok at %s; certificate valid until %s", addr, notAfter.UTC().Format("2006-01-02"))}
}

// httpStatus sends HEAD (GET only when HEAD is 405) and follows no redirect.
func (p urlProbe) httpStatus(rawURL string, timeout time.Duration) (int, string, error) {
	tr := &http.Transport{
		DialContext: func(ctx context.Context, _, addr string) (net.Conn, error) {
			host, port, err := net.SplitHostPort(addr)
			if err != nil {
				return nil, err
			}
			conn, _, err := p.dialHost(ctx, host, port)
			return conn, err
		},
		TLSClientConfig:   &tls.Config{RootCAs: p.rootCAs},
		DisableKeepAlives: true,
	}
	defer tr.CloseIdleConnections()
	client := &http.Client{
		Transport:     tr,
		Timeout:       timeout,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	resp, err := client.Head(rawURL)
	if err == nil && resp.StatusCode == http.StatusMethodNotAllowed {
		resp.Body.Close()
		resp, err = client.Get(rawURL)
	}
	if err != nil {
		return 0, "", err
	}
	resp.Body.Close()
	return resp.StatusCode, resp.Header.Get("Location"), nil
}

// checkURLHosts checks each unique host once and fills every row on it.
func checkURLHosts(p urlProbe, checks []urlCheck, timeout time.Duration) int {
	index := map[string]int{}
	var targets []urlTarget
	for _, ch := range checks {
		if ch.Status != "" {
			continue
		}
		if _, ok := index[ch.target.key()]; !ok {
			index[ch.target.key()] = len(targets)
			targets = append(targets, ch.target)
		}
	}
	verdicts := make([]hostVerdict, len(targets))
	forEachBounded(len(targets), checkURLsWorkers, func(i int) {
		verdicts[i] = p.checkHost(targets[i], timeout)
	})
	for i := range checks {
		if checks[i].Status == "" {
			v := verdicts[index[checks[i].target.key()]]
			checks[i].Status, checks[i].Detail = v.status, v.detail
		}
	}
	return len(targets)
}

// requestURLs sends one request per unique URL whose host passed.
func requestURLs(p urlProbe, checks []urlCheck, timeout time.Duration) int {
	index := map[string]int{}
	var urls []string
	for _, ch := range checks {
		if ch.Status != urlCheckOK {
			continue
		}
		if _, ok := index[ch.target.probeURL]; !ok {
			index[ch.target.probeURL] = len(urls)
			urls = append(urls, ch.target.probeURL)
		}
	}
	type result struct {
		code     int
		location string
		err      error
	}
	results := make([]result, len(urls))
	forEachBounded(len(urls), checkURLsWorkers, func(i int) {
		code, loc, err := p.httpStatus(urls[i], timeout)
		results[i] = result{code, loc, err}
	})
	for i := range checks {
		if checks[i].Status != urlCheckOK {
			continue
		}
		r := results[index[checks[i].target.probeURL]]
		if r.err != nil {
			checks[i].Status, checks[i].Detail = urlCheckHTTPFailed, "HTTP request failed: "+r.err.Error()
			continue
		}
		checks[i].HTTPStatus, checks[i].Location = r.code, r.location
	}
	return len(urls)
}

// countURLTargets returns the unique hosts and URLs the checks would touch.
func countURLTargets(checks []urlCheck) (hosts, urls int) {
	seenHost, seenURL := map[string]bool{}, map[string]bool{}
	for _, ch := range checks {
		if ch.Status != "" {
			continue
		}
		seenHost[ch.target.key()] = true
		seenURL[ch.target.probeURL] = true
	}
	return len(seenHost), len(seenURL)
}

// summarizeURLChecks is the stderr line: status counts, then HTTP codes.
func summarizeURLChecks(checks []urlCheck, hosts, requests int, withHTTP bool) string {
	counts := map[string]int{}
	codes := map[int]int{}
	for _, ch := range checks {
		counts[ch.Status]++
		if ch.HTTPStatus != 0 {
			codes[ch.HTTPStatus]++
		}
	}
	var parts []string
	for _, s := range urlCheckStatuses {
		if counts[s] > 0 {
			parts = append(parts, fmt.Sprintf("%s %d", s, counts[s]))
		}
	}
	how := "without sending any HTTP request"
	if withHTTP {
		how = fmt.Sprintf("then sent %d HTTP request(s)", requests)
	}
	line := fmt.Sprintf("Checked %d host(s) for %d offer URL(s), %s: %s.", hosts, len(checks), how, strings.Join(parts, ", "))
	if len(codes) > 0 {
		var keys []int
		for c := range codes {
			keys = append(keys, c)
		}
		sort.Ints(keys)
		var cs []string
		for _, c := range keys {
			cs = append(cs, fmt.Sprintf("%d x%d", c, codes[c]))
		}
		line += " HTTP status: " + strings.Join(cs, ", ") + "."
	}
	return line
}

func renderURLChecks(checks []urlCheck) error {
	if checks == nil {
		checks = []urlCheck{}
	}
	data, err := json.Marshal(map[string]interface{}{"data": checks})
	if err != nil {
		return fmt.Errorf("encoding URL checks: %w", err)
	}
	opts := renderOpts()
	if len(opts.Fields) == 0 {
		opts.Fields = checkURLsColumns
	}
	output.RenderWith(data, opts)
	return nil
}

func newCampaignCheckURLsCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "check-urls",
		Short: "Find offer URLs whose host is dead, without sending clicks",
		Long: "Checks campaign offer URL slots (aff_campaign_url, _2 to _5) and reports which\n" +
			"point at a host that no longer works. Opening an affiliate link registers a\n" +
			"click, so by default no HTTP request is sent: each unique host is resolved\n" +
			"(DNS), connected to (TCP) and, for https, put through a TLS handshake with\n" +
			"normal certificate verification, then the connection is closed.\n\n" +
			"status per URL: ok, invalid_url, dns_failed, connect_failed, timeout,\n" +
			"tls_failed (and http_failed with --http); detail says why.\n\n" +
			"--http also sends one HEAD request per unique URL (GET only if HEAD answers\n" +
			"405), follows no redirect, and reports http_status and location. Affiliate\n" +
			"networks may count these as clicks, so it asks first (--force skips).\n" +
			"Tokens such as [[subid]] are sent as \"" + urlTokenStandIn + "\".\n\n" +
			"Like `p202 rotator check`, it exits 5 (partial_failure) when any URL's\n" +
			"status is not ok; every row is still printed on stdout.",
		Example: "  p202 campaign check-urls\n" +
			"  p202 campaign check-urls --url-contains g2afse.com --slot 1 --timeout 10s\n" +
			"  p202 campaign check-urls --ids 279,281 --json",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) (retErr error) {
			done := metrics.Timer("check-urls", "campaigns")
			defer func() { done(retErr == nil, errString(retErr)) }()

			// Validate every flag before building a client.
			contains, _ := cmd.Flags().GetString("url-contains")
			slotRaw, _ := cmd.Flags().GetString("slot")
			fields, err := parseURLSlots(slotRaw)
			if err != nil {
				return err
			}
			var onlyIDs map[string]bool
			if raw, _ := cmd.Flags().GetString("ids"); strings.TrimSpace(raw) != "" {
				ids, perr := parseIDList(raw)
				if perr != nil {
					return perr
				}
				onlyIDs = map[string]bool{}
				for _, id := range ids {
					onlyIDs[id] = true
				}
			}
			network, _ := cmd.Flags().GetString("aff-network-id")
			network = strings.TrimSpace(network)
			if network != "" {
				if _, perr := strconv.Atoi(network); perr != nil {
					return validationError("invalid --aff-network-id %q: must be a numeric id", network).
						WithHint("Run `p202 aff-network list` for the numeric aff_network_id.")
				}
			}
			timeout, _ := cmd.Flags().GetDuration("timeout")
			if timeout <= 0 {
				return validationError("--timeout must be greater than 0, got %s", timeout).
					WithHint("Pass the time allowed per host as a duration, e.g. --timeout 5s or --timeout 1500ms.")
			}
			withHTTP, _ := cmd.Flags().GetBool("http")
			force, _ := cmd.Flags().GetBool("force")

			c, err := api.NewFromConfig()
			if err != nil {
				return err
			}
			params := map[string]string{}
			if network != "" {
				params["filter[aff_network_id]"] = network
			}
			rows, err := fetchAllRowsWithParams(c, "campaigns", params)
			if err != nil {
				return err
			}
			checks := planURLChecks(rows, fields, contains, onlyIDs)
			if len(checks) == 0 {
				if err := renderURLChecks(checks); err != nil {
					return err
				}
				output.Success("No campaign offer URL is in scope; nothing to check.")
				return nil
			}
			if withHTTP {
				hosts, urls := countURLTargets(checks)
				fmt.Fprintf(os.Stderr, "Warning: --http sends a request to up to %d offer URL(s) on %d host(s). Affiliate networks may record each one as a click on your account.\n", urls, hosts)
				if !force && !confirmPrompt("Send up to %d HTTP request(s)?", urls) {
					fmt.Fprintln(os.Stderr, "Cancelled; nothing was checked. Drop --http to check hosts without sending any request.")
					return nil
				}
			}

			probe := checkURLsNet
			hosts := checkURLHosts(probe, checks, timeout)
			requests := 0
			if withHTTP {
				requests = requestURLs(probe, checks, timeout)
			}
			if err := renderURLChecks(checks); err != nil {
				return err
			}
			output.Success("%s", summarizeURLChecks(checks, hosts, requests, withHTTP))
			failed := 0
			for _, ch := range checks {
				if ch.Status != urlCheckOK {
					failed++
				}
			}
			if failed > 0 {
				return partialFailureError("%d of %d offer URL(s) did not pass the check", failed, len(checks)).
					WithHint("Each row's detail says why. dns_failed (no such host) and tls_failed usually mean a retired link: rewrite it with `p202 campaign replace-url --match <host> --set <new url>`. timeout and connect_failed can be transient: re-run with a longer --timeout before retiring the link.")
			}
			return nil
		},
	}
	cmd.Flags().String("url-contains", "", "Only offer URLs containing this text (case-insensitive)")
	cmd.Flags().String("slot", "all", "URL slots to check: 1-5, comma-separated, or all")
	cmd.Flags().String("ids", "", "Only these campaign IDs (comma-separated)")
	cmd.Flags().String("aff-network-id", "", "Only campaigns in this affiliate network (ids from p202 aff-network list)")
	cmd.Flags().Duration("timeout", 5*time.Second, "Time allowed per host for DNS, connect and TLS together (and per --http request)")
	cmd.Flags().Bool("http", false, "Also send a HEAD request per URL and report its status; affiliate networks may count it as a click")
	cmd.Flags().BoolP("force", "f", false, "Skip the --http confirmation prompt")
	return cmd
}
