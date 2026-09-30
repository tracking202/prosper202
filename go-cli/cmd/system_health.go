package cmd

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"p202/internal/api"
	configpkg "p202/internal/config"
)

// The other tls_status values; the certificate failures are the
// tlsStatus constants in campaign_check_urls.go.
const (
	tlsStatusOK          = "ok"
	tlsStatusExpiring    = "expiring"
	tlsStatusUnreachable = "unreachable"
	tlsStatusNotUsed     = "not_used"
)

// systemHealthTLSTimeout bounds DNS, connect and handshake together.
var systemHealthTLSTimeout = 10 * time.Second

// systemNow is the clock health and cron measure ages against; tests pin it.
var systemNow = time.Now

// certCheck is what the TLS check of the configured base URL found.
type certCheck struct {
	target   urlTarget
	status   string
	detail   string
	leaf     *x509.Certificate // nil when no certificate arrived
	daysLeft int               // whole days until NotAfter, negative once expired
}

func (c certCheck) addr() string { return net.JoinHostPort(c.target.host, c.target.port) }

// certProblem is a finding about the certificate itself, as opposed to
// "the TLS check could not reach the host".
func (c certCheck) certProblem() bool {
	return c.status != tlsStatusOK && c.status != tlsStatusNotUsed && c.status != tlsStatusUnreachable
}

// checkBaseURLCert does a verified TLS handshake with the base URL's host,
// through the same probe check-urls uses. No HTTP request is sent.
func checkBaseURLCert(p urlProbe, t urlTarget, warnDays int, timeout time.Duration) certCheck {
	cc := certCheck{target: t}
	if t.scheme != "https" {
		cc.status = tlsStatusNotUsed
		cc.detail = "the configured URL is http://, so TLS is not in use and there is no certificate to check"
		return cc
	}
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	conn, addr, err := p.dialHost(ctx, t.host, t.port)
	if err != nil {
		cc.status, cc.detail = tlsStatusUnreachable, connectVerdict(err, t, timeout).detail
		return cc
	}
	leaf, err := p.tlsHandshake(ctx, conn, t.host)
	now := systemNow()
	if leaf != nil {
		cc.leaf = leaf
		cc.daysLeft = int(leaf.NotAfter.Sub(now).Hours() / 24)
	}
	switch {
	case err != nil && (isTimeout(err) || ctx.Err() != nil):
		cc.status = tlsStatusUnreachable
		cc.detail = fmt.Sprintf("TLS handshake with %s did not finish within %s", addr, timeout)
	case err != nil:
		cc.status, _ = classifyTLSFailure(err)
		cc.detail = tlsFailureDetail(err)
		if leaf != nil && now.After(leaf.NotAfter) {
			// Past NotAfter is expired, whatever a platform verifier reported first.
			cc.status = tlsStatusExpired
		} else if leaf != nil && cc.status == tlsStatusExpired && now.Before(leaf.NotBefore) {
			// x509 reports "not yet valid" as Expired too; renewing will not fix it.
			cc.status = tlsStatusInvalid
			cc.detail = fmt.Sprintf("not yet valid: the certificate starts on %s (check this machine's clock): %v", leaf.NotBefore.UTC().Format(time.RFC3339), err)
		}
	case leaf.NotAfter.Sub(now).Hours() < float64(warnDays)*24: // float: a huge flag value must not overflow
		cc.status = tlsStatusExpiring
		cc.detail = fmt.Sprintf("TLS ok at %s, but the certificate expires within %d days", addr, warnDays)
	default:
		cc.status = tlsStatusOK
		cc.detail = fmt.Sprintf("TLS ok at %s; certificate valid until %s", addr, leaf.NotAfter.UTC().Format("2006-01-02"))
	}
	return cc
}

// fields are the tls_* keys merged into the health object. The certificate
// keys are null when no certificate was seen, so the shape never changes.
func (c certCheck) fields() map[string]interface{} {
	out := map[string]interface{}{
		"tls_status":    c.status,
		"tls_detail":    c.detail,
		"tls_not_after": nil,
		"tls_days_left": nil,
		"tls_issuer":    nil,
	}
	if c.leaf != nil {
		out["tls_not_after"] = c.leaf.NotAfter.UTC().Format(time.RFC3339)
		out["tls_days_left"] = c.daysLeft
		out["tls_issuer"] = c.leaf.Issuer.String()
	}
	return out
}

// problemError is the exit-5 finding for any status but ok and not_used.
func (c certCheck) problemError(warnDays int) *CLIError {
	date := ""
	if c.leaf != nil {
		date = c.leaf.NotAfter.UTC().Format("2006-01-02")
	}
	host := c.target.host
	switch c.status {
	case tlsStatusExpired:
		ago := fmt.Sprintf("%d day(s) ago", -c.daysLeft)
		if c.daysLeft == 0 {
			ago = "less than a day ago"
		}
		return partialFailureError("the TLS certificate for %s expired on %s (%s): browsers refuse every https tracking link on this host", c.addr(), date, ago).
			WithHint("Renew the certificate on the server that answers for %s (Let's Encrypt: `sudo certbot renew`, then reload nginx or Apache), and find out why auto-renewal stopped with `sudo certbot renew --dry-run`. Re-run `p202 system health` to confirm.", host)
	case tlsStatusExpiring:
		return partialFailureError("the TLS certificate for %s expires on %s, in %d day(s) (--cert-warn-days is %d)", c.addr(), date, c.daysLeft, warnDays).
			WithHint("Renew it before then: `sudo certbot renew` on the server for %s. Auto-renewal normally renews 30 days ahead, so check it with `sudo certbot renew --dry-run`. --cert-warn-days sets how early this warns; 0 turns the warning off.", host)
	case tlsStatusHostnameMismatch:
		return partialFailureError("the TLS certificate served at %s does not cover %s: %s", c.addr(), host, c.detail).
			WithHint("Issue a certificate that covers %s (e.g. `sudo certbot --nginx -d %s`), or point the CLI at a name the certificate does cover with `p202 config set-url https://<name>`.", host, host)
	case tlsStatusUnknownAuthority:
		return partialFailureError("the TLS certificate for %s is not signed by an authority this machine trusts: %s", c.addr(), c.detail).
			WithHint("Install a CA-issued certificate with its full chain on the server (certbot's fullchain.pem, not cert.pem); browsers reject a self-signed certificate or a missing intermediate the same way.")
	case tlsStatusUnreachable:
		return partialFailureError("the TLS check could not reach %s, although the API answered: %s", c.addr(), c.detail).
			WithHint("Re-run `p202 system health`; if it persists, one of the addresses %s resolves to may not be serving TLS (the check tries IPv4 first).", host)
	default:
		return partialFailureError("the TLS check of %s failed: %s", c.addr(), c.detail).
			WithHint("tls_detail says what the handshake rejected; fix the certificate or the TLS setup on the server for %s, then re-run `p202 system health`.", host)
	}
}

// healthObject decodes the health response and returns it with the object
// the tls_* keys belong in (its "data" object when it has one).
func healthObject(raw []byte) (map[string]interface{}, map[string]interface{}) {
	var top map[string]interface{}
	if json.Unmarshal(raw, &top) != nil || top == nil {
		inner := map[string]interface{}{"response": string(raw)}
		return map[string]interface{}{"data": inner}, inner
	}
	if inner, ok := top["data"].(map[string]interface{}); ok {
		return top, inner
	}
	return top, top
}

func runSystemHealth(cmd *cobra.Command, _ []string) error {
	warnDays, _ := cmd.Flags().GetInt("cert-warn-days")
	if warnDays < 0 {
		return validationError("--cert-warn-days must be 0 or more, got %d", warnDays).
			WithHint("Pass how many days before expiry to warn, e.g. --cert-warn-days 21; 0 turns the early warning off.")
	}
	c, err := api.NewURLOnly()
	if err != nil {
		return err
	}
	profile, _, err := configpkg.LoadProfileWithName("")
	if err != nil {
		return err
	}
	target, problem := parseOfferURL(profile.URL)
	if problem != "" {
		return validationError("the configured URL %q cannot be checked: %s", profile.URL, problem).
			WithHint("Set the instance's base URL with `p202 config set-url https://tracking.example.com`.")
	}

	// The certificate first, on its own connection: when it is bad, the API
	// call below fails on it too, and only this check can say why.
	cert := checkBaseURLCert(checkURLsNet, target, warnDays, systemHealthTLSTimeout)
	raw, apiErr := c.Get("system/health", nil)
	if apiErr != nil && !cert.certProblem() {
		hint := api.HintFor(apiErr)
		var notFound *api.APIError
		if errors.As(apiErr, &notFound) && notFound.Status == http.StatusNotFound {
			hint = "The URL answered, but not with the Prosper202 API at /api/v3/system/health. Check it with `p202 config get` and set the instance's base URL (without /api/v3) with `p202 config set-url`."
		}
		if cert.status == tlsStatusOK {
			hint += fmt.Sprintf(" The TLS certificate for %s is fine (valid until %s), so the failure is past TLS.",
				cert.addr(), cert.leaf.NotAfter.UTC().Format("2006-01-02"))
		}
		return withHint(apiErr, "%s", strings.TrimSpace(hint))
	}

	var top, inner map[string]interface{}
	if apiErr != nil {
		inner = map[string]interface{}{"status": "unknown", "api_error": apiErr.Error()}
		top = map[string]interface{}{"data": inner}
	} else {
		top, inner = healthObject(raw)
	}
	for k, v := range cert.fields() {
		inner[k] = v
	}
	out, err := json.Marshal(top)
	if err != nil {
		return fmt.Errorf("encoding health: %w", err)
	}
	render(out)
	if cert.status != tlsStatusOK && cert.status != tlsStatusNotUsed {
		return cert.problemError(warnDays)
	}
	return nil
}
