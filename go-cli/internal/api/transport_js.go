//go:build js

package api

import (
	"net/http"
	"time"
)

// A hosted (js/wasm) run waits less for each request than a terminal does:
// its engine holds a slot it shares with other installs' calls.
const requestTimeout = 20 * time.Second

// jsFetchRedirect is read by Go's js transport (net/http roundtrip_js.go)
// and removed before the request is sent.
const jsFetchRedirect = "js.fetch:redirect"

// platformTransport makes every request the client sends refuse a redirect.
// A hosted run fetches through the host's fetch(), which follows redirects
// unless told otherwise; the install's key is for the install, as the relay's
// own requests to it already treat it.
func platformTransport(base http.RoundTripper) http.RoundTripper {
	return noRedirectTransport{base: base}
}

type noRedirectTransport struct{ base http.RoundTripper }

func (t noRedirectTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	req = req.Clone(req.Context())
	req.Header.Set(jsFetchRedirect, "error")
	base := t.base
	if base == nil {
		base = http.DefaultTransport
	}
	return base.RoundTrip(req)
}
