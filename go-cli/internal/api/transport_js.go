//go:build js

package api

import (
	"fmt"
	"net/http"
	"time"
)

// A hosted (js/wasm) run waits less for each request than a terminal does:
// its engine holds a slot it shares with other installs' calls.
const requestTimeout = 20 * time.Second

// jsFetchRedirect is read by Go's js transport (net/http roundtrip_js.go)
// and removed before the request is sent.
const jsFetchRedirect = "js.fetch:redirect"

// platformTransport and platformCheckRedirect make the client refuse a
// redirect. A hosted run fetches through the host's fetch(), which follows
// redirects unless told otherwise, and the install's key is for the install,
// as the relay's own requests to it already treat it. The fetch is told
// "manual" (Cloudflare's fetch takes only "follow" and "manual"), so the
// redirect comes back as a response, and the client refuses to follow it.
func platformTransport(base http.RoundTripper) http.RoundTripper {
	return noRedirectTransport{base: base}
}

func platformCheckRedirect(req *http.Request, via []*http.Request) error {
	return fmt.Errorf("the install answered with a redirect to %s, which a hosted call does not follow; check the install address it was connected with", req.URL.Redacted())
}

type noRedirectTransport struct{ base http.RoundTripper }

func (t noRedirectTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	req = req.Clone(req.Context())
	req.Header.Set(jsFetchRedirect, "manual")
	base := t.base
	if base == nil {
		base = http.DefaultTransport
	}
	return base.RoundTrip(req)
}
